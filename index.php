<?php
// Configuration
$DATA_FILE = __DIR__ . DIRECTORY_SEPARATOR . "phase1_state.json";
$TEST_PASSWORD = "clemson-test-2026"; // Must match spec

// --- Helper Functions ---
function send_json($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

function get_headers_lowercase() {
    $headers = [];
    foreach (getallheaders() as $k => $v) { $headers[strtolower($k)] = $v; }
    return $headers;
}

function require_test_mode($password) {
    $headers = get_headers_lowercase();
    // Required header: X-Test-Password
    if (!isset($headers["x-test-password"]) || $headers["x-test-password"] !== $password) {
        send_json(["error" => "Forbidden"], 403);
    }
}

function create_empty_board($size = 10) {
    return array_fill(0, $size, array_fill(0, $size, "."));
}

function load_state($file) {
    if (!file_exists($file)) return ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
}

function save_state($file, $state) {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
}

function get_request_body() {
    return json_decode(file_get_contents("php://input"), true) ?? [];
}

// --- Main Routing Logic ---
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];
$state = load_state($DATA_FILE);

// Serve Frontend (Requirement for manual demo)
if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

// 1. System Reset
if ($path === "/api/reset" && $method === "POST") {
    $state = ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
    save_state($DATA_FILE, $state);
    send_json(["status" => "reset"], 200);
}

// 2. Player Creation
if ($path === "/api/players" && $method === "POST") {
    $body = get_request_body();
    if (!isset($body["username"])) send_json(["error" => "required"], 400);
    $id = (int)$state["nextPlayerId"]++;
    $state["players"][$id] = [
        "player_id" => $id, 
        "username" => $body["username"], 
        "games_played" => 0, "wins" => 0, "losses" => 0,
        "total_shots" => 0, "total_hits" => 0, "accuracy" => 0.0
    ];
    save_state($DATA_FILE, $state);
    send_json(["player_id" => $id], 201);
}

// 3. Player Stats
if (preg_match("#^/api/players/(\d+)/stats$#", $path, $matches)) {
    $id = (int)$matches[1];
    if (!isset($state["players"][$id])) send_json(["error" => "not found"], 404);
    send_json($state["players"][$id], 200);
}

// 4. Game Creation
if ($path === "/api/games" && $method === "POST") {
    $body = get_request_body();
    $size = (int)($body["grid_size"] ?? 10);
    if ($size < 5 || $size > 15) send_json(["error" => "invalid size"], 400);
    $id = (int)$state["nextGameId"]++;
    $state["games"][$id] = [
        "game_id" => $id, 
        "grid_size" => $size, 
        "status" => "waiting", 
        "player_ids" => [], 
        "ships" => [], 
        "moves" => [], 
        "current_turn_index" => 0
    ];
    save_state($DATA_FILE, $state);
    send_json(["game_id" => $id], 201);
}

// 5. Joining a Game
if (preg_match("#^/api/games/(\d+)/join$#", $path, $matches) && $method === "POST") {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    if (!isset($state["games"][$gameId]) || !isset($state["players"][$playerId])) send_json(["error" => "not found"], 404);
    if (!in_array($playerId, $state["games"][$gameId]["player_ids"])) {
        $state["games"][$gameId]["player_ids"][] = $playerId;
    }
    save_state($DATA_FILE, $state);
    send_json(["status" => "joined", "game_id" => $gameId], 200);
}

// 6. Game State
if (preg_match("#^/api/games/(\d+)$#", $path, $matches) && $method === "GET") {
    $gameId = (int)$matches[1];
    if (!isset($state["games"][$gameId])) send_json(["error" => "not found"], 404);
    send_json($state["games"][$gameId], 200);
}

// --- NEW CHECKPOINT B LOGIC ---

// 7. Ship Placement
if (preg_match("#^/api/games/(\d+)/place$#", $path, $matches) && $method === "POST") {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    
    // Identity & Membership Enforcement
    if (!isset($state["players"][$playerId]) || !in_array($playerId, $state["games"][$gameId]["player_ids"])) {
        send_json(["error" => "Forbidden"], 403);
    }
    
    if (count($body["ships"] ?? []) !== 3) send_json(["error" => "Exactly 3 ships required"], 400);

    $state["games"][$gameId]["ships"][$playerId] = $body["ships"];
    // Game becomes active once all players (min 2) have placed ships
    if (count($state["games"][$gameId]["ships"]) >= 2) {
        $state["games"][$gameId]["status"] = "active";
    }
    
    save_state($DATA_FILE, $state);
    send_json(["status" => "placed", "game_id" => $gameId], 200);
}

// 8. Fire Move
if (preg_match("#^/api/games/(\d+)/fire$#", $path, $matches) && $method === "POST") {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    $game = &$state["games"][$gameId];

    // Reject fake playerId or wrong game
    if (!isset($game) || !in_array($playerId, $game["player_ids"])) send_json(["error" => "Forbidden"], 403);
    
    // Fire Gating
    if ($game["status"] !== "active") send_json(["error" => "Wait for all players to place ships"], 400);
    
    // Turn Enforcement
    if ($game["player_ids"][$game["current_turn_index"]] !== $playerId) send_json(["error" => "Out of turn"], 403);

    $r = (int)$body["row"]; $c = (int)$body["col"];
    if ($r < 0 || $r >= $game["grid_size"] || $c < 0 || $c >= $game["grid_size"]) send_json(["error" => "Out of bounds"], 400);
    
    // Reject duplicate coordinates
    foreach ($game["moves"] as $m) {
        if ($m["player_id"] === $playerId && $m["row"] === $r && $m["col"] === $c) send_json(["error" => "Duplicate"], 400);
    }

    $result = "miss";
    foreach ($game["player_ids"] as $opp) {
        if ($opp === $playerId) continue;
        foreach (($game["ships"][$opp] ?? []) as $s) {
            if ($s["row"] === $r && $s["col"] === $c) $result = "hit";
        }
    }

    // Move logging with timestamps
    $game["moves"][] = [
        "player_id" => $playerId, "row" => $r, "col" => $c, 
        "result" => $result, "timestamp" => time()
    ];
    
    // Turn Rotation
    $game["current_turn_index"] = ($game["current_turn_index"] + 1) % count($game["player_ids"]);
    
    save_state($DATA_FILE, $state);
    send_json(["result" => $result, "next_player_id" => $game["player_ids"][$game["current_turn_index"]], "game_status" => $game["status"]], 200);
}

// 9. Move Log
if (preg_match("#^/api/games/(\d+)/moves$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    send_json($state["games"][$gameId]["moves"] ?? [], 200);
}

// --- TEST MODE ENDPOINTS ---

if (preg_match("#^/api/test/games/(\d+)/ships$#", $path, $matches) && $method === "POST") {
    require_test_mode($TEST_PASSWORD);
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    $state["games"][$gameId]["ships"][$playerId] = $body["ships"];
    save_state($DATA_FILE, $state);
    send_json(["status" => "ships placed", "game_id" => $gameId], 200);
}

if (preg_match("#^/api/test/games/(\d+)/board/(\d+)$#", $path, $matches) && $method === "GET") {
    require_test_mode($TEST_PASSWORD);
    $gameId = (int)$matches[1]; $playerId = (int)$matches[2];
    send_json(["ships" => $state["games"][$gameId]["ships"][$playerId] ?? []], 200);
}

if (preg_match("#^/api/test/games/(\d+)/restart$#", $path, $matches) && $method === "POST") {
    require_test_mode($TEST_PASSWORD);
    $gameId = (int)$matches[1];
    if (isset($state["games"][$gameId])) {
        $state["games"][$gameId]["status"] = "waiting";
        $state["games"][$gameId]["ships"] = [];
        $state["games"][$gameId]["moves"] = [];
        $state["games"][$gameId]["current_turn_index"] = 0;
    }
    save_state($DATA_FILE, $state);
    send_json(["status" => "restarted", "game_id" => $gameId], 200);
}

send_json(["error" => "endpoint not found"], 404);
