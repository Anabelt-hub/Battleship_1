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

// ONLY for /api/test/ routes
function require_test_mode($password) {
    $headers = [];
    foreach (getallheaders() as $k => $v) { $headers[strtolower($k)] = $v; }
    if (!isset($headers["x-test-password"]) || $headers["x-test-password"] !== $password) {
        send_json(["error" => "Forbidden - Test Mode"], 403);
    }
}

function load_state($file) {
    clearstatcache(); // FORCES PHP to see the latest file on disk
    if (!file_exists($file)) return ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
    $raw = file_get_contents($file);
    return json_decode($raw, true) ?: ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
}

function save_state($file, $state) {
    // LOCK_EX prevents two requests from writing at the same time
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

function get_request_body() {
    return json_decode(file_get_contents("php://input"), true) ?? [];
}

// --- Main Logic ---
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];
$state = load_state($DATA_FILE);

if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

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
    $state["games"][$id] = ["game_id"=>$id, "grid_size"=>$size, "status"=>"waiting", "player_ids"=>[], "ships"=>[], "moves"=>[], "current_turn_index"=>0];
    save_state($DATA_FILE, $state);
    send_json(["game_id" => $id], 201);
}

// 4. Join
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

// 5. Place Ships (Production)
if (preg_match("#^/api/games/(\d+)/place$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    
    // FORCE fresh state read to catch the 'join'
    clearstatcache();
    $state = load_state($DATA_FILE);
    
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    
    // USE the ships the autograder (or user) sends in the POST body
    $ships = $body["ships"] ?? [];

    // 1. Check if Game exists
    if (!isset($state["games"][$gameId])) send_json(["error" => "not found"], 404);
    
    // 2. Validate Ship Count (Exactly 3)
    if (count($ships) !== 3) {
        send_json(["error" => "Exactly 3 ships required"], 400);
    }

    // 3. Identity Check (Must be in the game)
    if (!in_array($playerId, $state["games"][$gameId]["player_ids"])) {
        send_json(["error" => "Forbidden - Not in game"], 403);
    }

    // 4. SAVE THE MANUAL SHIPS
    $state["games"][$gameId]["ships"][$playerId] = $ships;
    
    // Activate game if 2+ players have placed ships
    if (count($state["games"][$gameId]["ships"]) >= 2) {
        $state["games"][$gameId]["status"] = "active";
    }

    save_state($DATA_FILE, $state);
    send_json(["status" => "placed", "game_id" => $gameId], 200);
}

// 6. Fire
if (preg_match("#^/api/games/(\d+)/fire$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);

    // 1. Check if Game Exists (404)
    if (!isset($state["games"][$gameId])) {
        send_json(["error" => "Game not found"], 404);
    }
    $game = &$state["games"][$gameId];

    // 2. CHECK STATUS FIRST (400/409)
    // If we aren't "active", we stop here and return 400.
    if ($game["status"] !== "active") {
        send_json(["error" => "Firing is not allowed until all ships are placed"], 400);
    }

    // 3. NOW Check Identity (403)
    if (!in_array($playerId, $game["player_ids"], true)) {
        send_json(["error" => "Forbidden - You are not in this game"], 403);
    }

    // 4. NOW Check Turn (403)
    if ((int)$game["player_ids"][$game["current_turn_index"]] !== $playerId) {
        send_json(["error" => "Out of turn"], 403);
    }

    $game["moves"][] = ["player_id"=>$playerId, "row"=>$r, "col"=>$c, "result"=>$result, "timestamp"=>time()];
    $game["current_turn_index"] = ($game["current_turn_index"] + 1) % count($game["player_ids"]);
    save_state($DATA_FILE, $state);
    send_json(["result"=>$result, "next_player_id"=>$game["player_ids"][$game["current_turn_index"]]], 200);
}

// --- TEST MODE ---
if (preg_match("#^/api/test/games/(\d+)/ships$#", $path, $matches)) {
    require_test_mode($TEST_PASSWORD);
    $body = get_request_body();
    $state["games"][(int)$matches[1]]["ships"][(int)$body["player_id"]] = $body["ships"];
    save_state($DATA_FILE, $state);
    send_json(["status" => "ships placed"], 200);
}

if (preg_match("#^/api/test/games/(\d+)/board/(\d+)$#", $path, $matches)) {
    require_test_mode($TEST_PASSWORD);
    send_json(["ships" => $state["games"][(int)$matches[1]]["ships"][(int)$matches[2]] ?? []], 200);
}

send_json(["error" => "endpoint not found"], 404);
