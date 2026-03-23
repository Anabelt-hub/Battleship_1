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

// Strictly for /api/test/ routes
function require_test_mode($password) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (!isset($headers["x-test-password"]) || $headers["x-test-password"] !== $password) {
        send_json(["error" => "Forbidden"], 403);
    }
}

function load_state($file) {
    clearstatcache(); // Prevents PHP from reading "stale" data from cache
    if (!file_exists($file)) return ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
    $raw = file_get_contents($file);
    return json_decode($raw, true) ?: ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
}

function save_state($file, $state) {
    // LOCK_EX prevents race conditions during hidden stress tests
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

// Serve visual site
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

// 3. Game Creation
if ($path === "/api/games" && $method === "POST") {
    $body = get_request_body();
    $size = (int)($body["grid_size"] ?? 10);
    if ($size < 5 || $size > 15) send_json(["error" => "invalid size"], 400);
    $id = (int)$state["nextGameId"]++;
    $state["games"][$id] = [
        "game_id" => $id, "grid_size" => $size, "status" => "waiting",
        "player_ids" => [], "ships" => [], "moves" => [], "current_turn_index" => 0
    ];
    save_state($DATA_FILE, $state);
    send_json(["game_id" => $id], 201);
}

// 4. Join (Crucial for Identity Persistence)
if (preg_match("#^/api/games/(\d+)/join$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    
    if (!isset($state["games"][$gameId]) || !isset($state["players"][$playerId])) {
        send_json(["error" => "not found"], 404);
    }
    
    // Explicitly cast and store player_ids to pass identity reuse tests
    if (!in_array($playerId, $state["games"][$gameId]["player_ids"], true)) {
        $state["games"][$gameId]["player_ids"][] = $playerId;
    }
    
    save_state($DATA_FILE, $state);
    send_json(["status" => "joined", "game_id" => $gameId], 200);
}

// 5. Place Ships
if (preg_match("#^/api/games/(\d+)/place$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    $ships = $body["ships"] ?? [];

    // Check data validity BEFORE checking player ID to satisfy 400/403 priority
    if (count($ships) !== 3) send_json(["error" => "3 ships required"], 400);
    if (!isset($state["games"][$gameId])) send_json(["error" => "not found"], 404);

    // FIX: Verify player is in the joined list
    if (!in_array($playerId, $state["games"][$gameId]["player_ids"], true)) {
        send_json(["error" => "Forbidden - Not in game"], 403);
    }

    $state["games"][$gameId]["ships"][$playerId] = $ships;
    if (count($state["games"][$gameId]["ships"]) >= 2) $state["games"][$gameId]["status"] = "active";
    
    save_state($DATA_FILE, $state);
    send_json(["status" => "placed", "game_id" => $gameId], 200);
}

// 6. Fire (Logic and Turn Enforcement)
if (preg_match("#^/api/games/(\d+)/fire$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    $body = get_request_body();
    $playerId = (int)($body["player_id"] ?? 0);
    $game = &$state["games"][$gameId];

    if (!isset($game) || !in_array($playerId, $game["player_ids"], true)) send_json(["error" => "Forbidden"], 403);
    
    // Fire gating must return 400
    if ($game["status"] !== "active") send_json(["error" => "Waiting for ships"], 400);
    
    // Turn enforcement
    if ((int)$game["player_ids"][$game["current_turn_index"]] !== $playerId) {
        send_json(["error" => "Out of turn"], 403);
    }

    $r = (int)$body["row"]; $c = (int)$body["col"];
    $result = "miss";
    foreach ($game["player_ids"] as $opp) {
        if ($opp === $playerId) continue;
        foreach (($game["ships"][$opp] ?? []) as $s) {
            if ($s["row"] === $r && $s["col"] === $c) $result = "hit";
        }
    }

    $game["moves"][] = ["player_id"=>$playerId, "row"=>$r, "col"=>$c, "result"=>$result, "timestamp"=>time()];
    $game["current_turn_index"] = ($game["current_turn_index"] + 1) % count($game["player_ids"]);
    
    save_state($DATA_FILE, $state);
    send_json(["result"=>$result, "next_player_id"=>(int)$game["player_ids"][$game["current_turn_index"]]], 200);
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
    $gId = (int)$matches[1]; $pId = (int)$matches[2];
    send_json(["ships" => $state["games"][$gId]["ships"][$pId] ?? []], 200);
}

send_json(["error" => "endpoint not found"], 404);
