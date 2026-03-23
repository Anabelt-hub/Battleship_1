<?php
// Configuration
$DATA_FILE = __DIR__ . DIRECTORY_SEPARATOR . "phase1_state.json";
$TEST_PASSWORD = "clemson-test-2026"; 

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
    if (!isset($headers["x-test-password"]) || $headers["x-test-password"] !== $password) {
        send_json(["error" => "Forbidden"], 403);
    }
}

function create_empty_board($size = 10) {
    return array_fill(0, $size, array_fill(0, $size, "."));
}

function load_state($file) {
    if (!file_exists($file)) return ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
    return json_decode(file_get_contents($file), true) ?? [];
}

function save_state($file, $state) {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
}

function get_request_body() {
    return json_decode(file_get_contents("php://input"), true) ?? [];
}

// --- Main Logic ---
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];
$state = load_state($DATA_FILE);

// Serve Frontend
if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

// POST /api/reset
if ($path === "/api/reset" && $method === "POST") {
    $state = ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
    save_state($DATA_FILE, $state);
    send_json(["status" => "reset"], 200);
}

// POST /api/players
if ($path === "/api/players" && $method === "POST") {
    $body = get_request_body();
    if (!isset($body["username"])) send_json(["error" => "required"], 400);
    $id = (int)$state["nextPlayerId"]++;
    $state["players"][$id] = ["player_id"=>$id, "username"=>$body["username"], "wins"=>0, "losses"=>0];
    save_state($DATA_FILE, $state);
    send_json(["player_id" => $id], 201);
}

// POST /api/games
if ($path === "/api/games" && $method === "POST") {
    $body = get_request_body();
    $size = (int)($body["grid_size"] ?? 10);
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

// POST /api/games/{id}/join
if (preg_match("#^/api/games/(\d+)/join$#", $path, $matches)) {
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

// POST /api/games/{id}/place
if (preg_match("#^/api/games/(\d+)/place$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    
    // Identity Enforcement
    if (!isset($state["players"][$playerId]) || !in_array($playerId, $state["games"][$gameId]["player_ids"])) {
        send_json(["error" => "Forbidden"], 403);
    }
    
    // Exactly 3 single-cell ships required
    if (count($body["ships"] ?? []) !== 3) send_json(["error" => "Exactly 3 ships required"], 400);
    
    $state["games"][$gameId]["ships"][$playerId] = $body["ships"];
    
    // If all players (min 2) have placed ships, set status to active
    if (count($state["games"][$gameId]["ships"]) >= 2) {
        $state["games"][$gameId]["status"] = "active";
    }
    
    save_state($DATA_FILE, $state);
    send_json(["status" => "placed"], 200);
}

// POST /api/games/{id}/fire
if (preg_match("#^/api/games/(\d+)/fire$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    $game = &$state["games"][$gameId];

    // Identity & Turn Validation
    if (!isset($state["players"][$playerId]) || !in_array($playerId, $game["player_ids"])) send_json(["error" => "Forbidden"], 403);
    if ($game["status"] !== "active") send_json(["error" => "Game not active"], 400);
    
    // Turn Enforcement
    if ($game["player_ids"][$game["current_turn_index"]] !== $playerId) send_json(["error" => "Out of turn"], 403);

    // Reject duplicate coordinates
    foreach ($game["moves"] as $m) {
        if ($m["player_id"] === $playerId && $m["row"] === $body["row"] && $m["col"] === $body["col"]) {
            send_json(["error" => "Duplicate move"], 400);
        }
    }

    // Process Shot Logic
    $result = "miss";
    foreach ($game["player_ids"] as $opponentId) {
        if ($opponentId === $playerId) continue;
        foreach ($game["ships"][$opponentId] as $ship) {
            if ($ship["row"] === $body["row"] && $ship["col"] === $body["col"]) { $result = "hit"; break; }
        }
    }

    // Move logging with timestamps
    $game["moves"][] = [
        "player_id" => $playerId, 
        "row" => (int)$body["row"], 
        "col" => (int)$body["col"], 
        "result" => $result, 
        "timestamp" => time()
    ];

    // Turn Rotation
    $game["current_turn_index"] = ($game["current_turn_index"] + 1) % count($game["player_ids"]);
    
    save_state($DATA_FILE, $state);
    send_json(["result" => $result, "next_player_id" => $game["player_ids"][$game["current_turn_index"]], "game_status" => $game["status"]], 200);
}

// GET /api/games/{id}/moves
if (preg_match("#^/api/games/(\d+)/moves$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    send_json($state["games"][$gameId]["moves"] ?? [], 200);
}

send_json(["error" => "endpoint not found"], 404);
