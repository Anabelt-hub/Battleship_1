<?php
// Configuration
$DATA_FILE = __DIR__ . DIRECTORY_SEPARATOR . "phase1_state.json";
$TEST_PASSWORD = "clemson-test-2026"; // Required

// --- Helper Functions ---

function send_json($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

function get_headers_lowercase() {
    $headers = [];
    foreach (getallheaders() as $k => $v) {
        $headers[strtolower($k)] = $v;
    }
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

function default_state() {
    return [
        "nextPlayerId" => 1,
        "nextGameId" => 1,
        "players" => [],
        "games" => [],
        "test" => [
            "board" => create_empty_board(10),
            "turn" => "player1"
        ]
    ];
}

function load_state($file) {
    if (!file_exists($file)) return default_state();
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : default_state();
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
if ($path === "/" || $path === "" || $path === "/index.php") {
    include_once("index.html");
    exit;
}

// POST /api/reset
if ($path === "/api/reset" && $method === "POST") {
    $state = default_state();
    save_state($DATA_FILE, $state);
    send_json(["status" => "reset"], 200);
}

// POST /api/players
if ($path === "/api/players" && $method === "POST") {
    $body = get_request_body();
    if (!isset($body["username"])) send_json(["error" => "username required"], 400);
    $playerId = (int)$state["nextPlayerId"];
    $state["nextPlayerId"]++;
    $state["players"][$playerId] = [
        "player_id" => $playerId, "username" => $body["username"],
        "games_played" => 0, "wins" => 0, "losses" => 0,
        "total_shots" => 0, "total_hits" => 0, "accuracy" => 0.0
    ];
    save_state($DATA_FILE, $state);
    send_json(["player_id" => $playerId], 201);
}

// GET /api/players/{id}/stats
if (preg_match("#^/api/players/(\d+)/stats$#", $path, $matches)) {
    $id = (int)$matches[1];
    if (!isset($state["players"][$id])) send_json(["error" => "not found"], 404);
    send_json($state["players"][$id], 200);
}

// POST /api/games
if ($path === "/api/games" && $method === "POST") {
    $body = get_request_body();
    $size = (int)($body["grid_size"] ?? 10);
    if ($size < 5 || $size > 15) send_json(["error" => "invalid size"], 400);
    $gameId = (int)$state["nextGameId"];
    $state["nextGameId"]++;
    $state["games"][$gameId] = [
        "game_id" => $gameId, "grid_size" => $size, "status" => "waiting",
        "current_turn_index" => 0, "active_players" => 0, "player_ids" => []
    ];
    save_state($DATA_FILE, $state);
    send_json(["game_id" => $gameId], 201);
}

// FIX: Added GET /api/games/{id}
if (preg_match("#^/api/games/(\d+)$#", $path, $matches) && $method === "GET") {
    $gameId = (int)$matches[1];
    if (!isset($state["games"][$gameId])) send_json(["error" => "game not found"], 404);
    send_json($state["games"][$gameId], 200);
}

// POST /api/games/{id}/join
if (preg_match("#^/api/games/(\d+)/join$#", $path, $matches) && $method === "POST") {
    $gameId = (int)$matches[1];
    if (!isset($state["games"][$gameId])) send_json(["error" => "not found"], 404);
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    if (!isset($state["players"][$playerId])) send_json(["error" => "player not found"], 404);
    
    if (!in_array($playerId, $state["games"][$gameId]["player_ids"])) {
        $state["games"][$gameId]["player_ids"][] = $playerId;
        $state["games"][$gameId]["active_players"] = count($state["games"][$gameId]["player_ids"]);
    }
    save_state($DATA_FILE, $state);
    send_json(["status" => "joined", "game_id" => $gameId], 200);
}

// POST /api/test/games/{id}/ships
if (preg_match("#^/api/test/games/(\d+)/ships$#", $path, $matches) && $method === "POST") {
    require_test_mode($TEST_PASSWORD);
    $gameId = (int)$matches[1];
    if (!isset($state["games"][$gameId])) send_json(["error" => "not found"], 404);

    $body = get_request_body();
    $state["test"]["board"] = create_empty_board(10);
    foreach (($body["ships"] ?? []) as $ship) {
        $coords = $ship["coordinates"] ?? $ship["positions"] ?? [];
        foreach ($coords as $pos) {
            if (is_array($pos)) {
                $r = $pos[0]; $c = $pos[1];
            } else {
                $r = ord(strtoupper(substr($pos, 0, 1))) - ord('A');
                $c = intval(substr($pos, 1)) - 1;
            }
            if ($r >= 0 && $r < 10 && $c >= 0 && $c < 10) {
                $state["test"]["board"][$r][$c] = "S";
            }
        }
    }
    save_state($DATA_FILE, $state);
    // Explicitly return game_id fixture
    send_json(["status" => "ships placed", "game_id" => $gameId], 200);
}

send_json(["error" => "endpoint not found"], 404);
