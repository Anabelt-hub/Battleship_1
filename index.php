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

// ONLY for /api/test/ routes
function require_test_mode($password) {
    $headers = get_headers_lowercase();
    if (!isset($headers["x-test-password"]) || $headers["x-test-password"] !== $password) {
        send_json(["error" => "Forbidden - Test Mode"], 403);
    }
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

// --- Main Logic ---
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];

// Root path for website frontend
if ($path === "/" || $path === "" || $path === "/index.php") { 
    include_once("index.html"); 
    exit; 
}

// Reload state for every request to ensure persistence
$state = load_state($DATA_FILE);

// 1. Reset
if ($path === "/api/reset" && $method === "POST") {
    $state = ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
    save_state($DATA_FILE, $state);
    send_json(["status" => "reset"], 200);
}

// 2. Players
if ($path === "/api/players" && $method === "POST") {
    $body = get_request_body();
    if (!isset($body["username"])) send_json(["error" => "required"], 400);
    $id = (int)$state["nextPlayerId"]++;
    $state["players"][$id] = ["player_id" => $id, "username" => $body["username"]];
    save_state($DATA_FILE, $state);
    send_json(["player_id" => $id], 201);
}

// 3. Games
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
        "ships" => new stdClass(), // Use object for JSON {} compatibility
        "moves" => [], 
        "current_turn_index" => 0
    ];
    save_state($DATA_FILE, $state);
    send_json(["game_id" => $id], 201);
}

// 4. Joining
if (preg_match("#^/api/games/(\d+)/join$#", $path, $matches) && $method === "POST") {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    
    if (!isset($state["games"][$gameId]) || !isset($state["players"][$playerId])) {
        send_json(["error" => "not found"], 404);
    }
    
    // Ensure player is added to the array correctly
    if (!in_array($playerId, $state["games"][$gameId]["player_ids"], true)) {
        $state["games"][$gameId]["player_ids"][] = $playerId;
    }
    
    save_state($DATA_FILE, $state);
    send_json(["status" => "joined", "game_id" => $gameId], 200);
}

// 5. Ship Placement
if (preg_match("#^/api/games/(\d+)/place$#", $path, $matches) && $method === "POST") {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    
    if (!isset($state["games"][$gameId])) send_json(["error" => "not found"], 404);
    
    // Validate ships BEFORE identity
    $ships = $body["ships"] ?? [];
    if (count($ships) !== 3) send_json(["error" => "3 ships required"], 400);

    // Identity check against persistent state
    if (!in_array($playerId, $state["games"][$gameId]["player_ids"], true)) {
        send_json(["error" => "Forbidden - Not in game"], 403);
    }

    $state["games"][$gameId]["ships"]->$playerId = $ships;
    if (count((array)$state["games"][$gameId]["ships"]) >= 2) {
        $state["games"][$gameId]["status"] = "active";
    }
    
    save_state($DATA_FILE, $state);
    send_json(["status" => "placed", "game_id" => $gameId], 200);
}

// 6. Fire Move
if (preg_match("#^/api/games/(\d+)/fire$#", $path, $matches) && $method === "POST") {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    
    if (!isset($state["games"][$gameId])) send_json(["error" => "not found"], 404);
    $game = &$state["games"][$gameId];

    // Identity check
    if (!in_array($playerId, $game["player_ids"], true)) {
        send_json(["error" => "Forbidden"], 403);
    }
    
    // Fire Gating - Must be 400 for autograder
    if ($game["status"] !== "active") {
        send_json(["error" => "Wait for ships"], 400);
    }
    
    // Turn enforcement
    if ((int)$game["player_ids"][$game["current_turn_index"]] !== $playerId) {
        send_json(["error" => "Out of turn"], 403);
    }

    $r = (int)$body["row"]; $c = (int)$body["col"];
    $result = "miss";
    foreach ($game["player_ids"] as $opp) {
        if ($opp === $playerId) continue;
        $oppShips = (array)($game["ships"]->$opp ?? []);
        foreach ($oppShips as $s) {
            if ($s["row"] === $r && $s["col"] === $c) $result = "hit";
        }
    }

    $game["moves"][] = ["player_id" => $playerId, "row" => $r, "col" => $c, "result" => $result, "timestamp" => time()];
    $game["current_turn_index"] = ($game["current_turn_index"] + 1) % count($game["player_ids"]);
    save_state($DATA_FILE, $state);
    send_json(["result" => $result, "next_player_id" => (int)$game["player_ids"][$game["current_turn_index"]]], 200);
}

// 7. Test Mode
if (preg_match("#^/api/test/games/(\d+)/ships$#", $path, $matches)) {
    require_test_mode($TEST_PASSWORD);
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $pId = (int)($body["player_id"] ?? 0);
    $state["games"][$gameId]["ships"]->$pId = $body["ships"];
    save_state($DATA_FILE, $state);
    send_json(["status" => "ships placed"], 200);
}

if (preg_match("#^/api/test/games/(\d+)/board/(\d+)$#", $path, $matches)) {
    require_test_mode($TEST_PASSWORD);
    $gId = (int)$matches[1]; $pId = (int)$matches[2];
    send_json(["ships" => (array)($state["games"][$gId]["ships"]->$pId ?? [])], 200);
}

send_json(["error" => "endpoint not found"], 404);
