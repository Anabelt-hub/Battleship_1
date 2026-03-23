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
    if (!file_exists($file)) return ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[], "test"=>["board"=>create_empty_board(10)]];
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

// API Endpoints
if ($path === "/api/reset" && $method === "POST") {
    $state = ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[], "test"=>["board"=>create_empty_board(10)]];
    save_state($DATA_FILE, $state);
    send_json(["status" => "reset"], 200);
}

if ($path === "/api/players" && $method === "POST") {
    $body = get_request_body();
    if (!isset($body["username"])) send_json(["error" => "required"], 400);
    $id = (int)$state["nextPlayerId"]++;
    $state["players"][$id] = ["player_id"=>$id, "username"=>$body["username"], "games_played"=>0, "wins"=>0, "losses"=>0, "total_shots"=>0, "total_hits"=>0, "accuracy"=>0.0];
    save_state($DATA_FILE, $state);
    send_json(["player_id" => $id], 201);
}

if ($path === "/api/games" && $method === "POST") {
    $body = get_request_body();
    $size = (int)($body["grid_size"] ?? 10);
    if ($size < 5 || $size > 15) send_json(["error" => "invalid size"], 400);
    $id = (int)$state["nextGameId"]++;
    $state["games"][$id] = ["game_id"=>$id, "grid_size"=>$size, "status"=>"waiting", "active_players"=>0, "player_ids"=>[]];
    save_state($DATA_FILE, $state);
    send_json(["game_id" => $id], 201);
}

if (preg_match("#^/api/games/(\d+)$#", $path, $matches) && $method === "GET") {
    $id = (int)$matches[1];
    if (!isset($state["games"][$id])) send_json(["error" => "not found"], 404);
    send_json($state["games"][$id], 200);
}

if (preg_match("#^/api/games/(\d+)/join$#", $path, $matches) && $method === "POST") {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    if (!isset($state["games"][$gameId]) || !isset($state["players"][$playerId])) send_json(["error" => "not found"], 404);
    if (!in_array($playerId, $state["games"][$gameId]["player_ids"])) {
        $state["games"][$gameId]["player_ids"][] = $playerId;
        $state["games"][$gameId]["active_players"] = count($state["games"][$gameId]["player_ids"]);
    }
    save_state($DATA_FILE, $state);
    send_json(["status" => "joined", "game_id" => $gameId, "player_id" => $playerId], 200);
}

// --- Test Mode Endpoints ---
// POST /api/test/games/{id}/ships
if (preg_match("#^/api/test/games/(\d+)/ships$#", $path, $matches) && $method === "POST") {
    require_test_mode($TEST_PASSWORD);
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["playerId"] ?? $body["player_id"] ?? 0); // Handle both camelCase and snake_case

    if (!isset($state["games"][$gameId])) send_json(["error" => "game not found"], 404);

    // Deterministic placement logic
    foreach (($body["ships"] ?? []) as $ship) {
        $coords = $ship["coordinates"] ?? $ship["positions"] ?? [];
        foreach ($coords as $pos) {
            if (is_array($pos)) { $r = $pos[0]; $c = $pos[1]; }
            else { $r = ord(strtoupper(substr($pos, 0, 1))) - ord('A'); $c = intval(substr($pos, 1)) - 1; }
            if ($r >= 0 && $r < 10 && $c >= 0 && $c < 10) { $state["test"]["board"][$r][$c] = "S"; }
        }
    }
    save_state($DATA_FILE, $state);
    
    // FIX: Return BOTH game_id and player_id as integers
    send_json([
        "status" => "ships placed",
        "game_id" => $gameId,
        "player_id" => $playerId
    ], 200);
}

send_json(["error" => "endpoint not found"], 404);
