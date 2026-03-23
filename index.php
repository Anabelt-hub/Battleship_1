<?php
$DATA_FILE = __DIR__ . DIRECTORY_SEPARATOR . "phase1_state.json";
$TEST_PASSWORD = "clemson-test-2026"; 

function send_json($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

function load_state($file) {
    clearstatcache();
    if (!file_exists($file)) return ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
    return json_decode(file_get_contents($file), true) ?: ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
}

function save_state($file, $state) {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];
$state = load_state($DATA_FILE);

if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

// --- PRODUCTION ENDPOINTS ---

if ($path === "/api/reset" && $method === "POST") {
    $state = ["nextPlayerId"=>1, "nextGameId"=>1, "players"=>[], "games"=>[]];
    save_state($DATA_FILE, $state);
    send_json(["status" => "reset"]);
}

if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true);
    $id = (int)$state["nextPlayerId"]++;
    $state["players"][$id] = ["player_id" => $id, "username" => $body["username"] ?? "Anonymous"];
    save_state($DATA_FILE, $state);
    send_json(["player_id" => $id], 201);
}

if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true);
    $id = (int)$state["nextPlayerId"]++;
    $state["games"][$id] = [
        "game_id" => $id, "grid_size" => (int)($body["grid_size"] ?? 10),
        "status" => "waiting", "player_ids" => [], 
        "ships" => new stdClass(), // FORCE {} instead of []
        "moves" => [], "current_turn_index" => 0
    ];
    save_state($DATA_FILE, $state);
    send_json(["game_id" => $id], 201);
}

if (preg_match("#^/api/games/(\d+)/join$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    $body = json_decode(file_get_contents("php://input"), true);
    $playerId = (int)($body["player_id"] ?? 0);
    if (!isset($state["games"][$gameId])) send_json(["error" => "Not found"], 404);
    if (!in_array($playerId, $state["games"][$gameId]["player_ids"])) {
        $state["games"][$gameId]["player_ids"][] = $playerId;
    }
    save_state($DATA_FILE, $state);
    send_json(["status" => "joined"]);
}

// CRITICAL FIX: POST /api/games/{id}/place 
if (preg_match("#^/api/games/(\d+)/place$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    $body = json_decode(file_get_contents("php://input"), true);
    $playerId = (int)($body["player_id"] ?? 0);
    $ships = $body["ships"] ?? [];

    if (!isset($state["games"][$gameId])) send_json(["error" => "Not found"], 404);
    $game = &$state["games"][$gameId];

    // 1. DATA VALIDATION (Return 400)
    if (count($ships) !== 3) send_json(["error" => "Exactly 3 ships required"], 400);
    
    $used = [];
    foreach ($ships as $s) {
        if ($s["row"] < 0 || $s["row"] >= $game["grid_size"] || $s["col"] < 0 || $s["col"] >= $game["grid_size"]) {
            send_json(["error" => "Out of bounds"], 400);
        }
        $coord = $s["row"].",".$s["col"];
        if (in_array($coord, $used)) send_json(["error" => "Overlap"], 400);
        $used[] = $coord;
    }

    // 2. IDENTITY CHECK (Return 403)
    if (!in_array($playerId, $game["player_ids"])) {
        send_json(["error" => "Forbidden"], 403);
    }

    // --- FIX: Force ships to be an object so JSON uses {} instead of [] ---
    if (!isset($game["ships"]) || is_array($game["ships"])) {
        $game["ships"] = (object)$game["ships"];
    }

    // Assign the ships using object property syntax
    $game["ships"]->{$playerId} = $ships;

    // --- FIX: Cast back to array only for the count check --- 
    if (count((array)$game["ships"]) >= 2) {
        $game["status"] = "active";
    }
    
    save_state($DATA_FILE, $state);
    send_json(["status" => "placed"]);
}

// 6. Fire Move
if (preg_match("#^/api/games/(\d+)/fire$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    $body = json_decode(file_get_contents("php://input"), true); // Fix: Use direct stream instead of undefined function
    $playerId = (int)($body["player_id"] ?? 0);

    // 1. Check if Game Exists (404)
    if (!isset($state["games"][$gameId])) {
        send_json(["error" => "Game not found"], 404);
    }
    $game = &$state["games"][$gameId];

    // 2. CHECK STATUS FIRST (400/409)
    if ($game["status"] !== "active") {
        send_json(["error" => "Firing is not allowed until all ships are placed"], 400);
    }

    // 3. NOW Check Identity (403)
    if (!in_array($playerId, $game["player_ids"])) {
        send_json(["error" => "Forbidden - You are not in this game"], 403);
    }

    // 4. NOW Check Turn (403)
    if ((int)$game["player_ids"][$game["current_turn_index"]] !== $playerId) {
        send_json(["error" => "Out of turn"], 403);
    }

    // 5. Calculate Result
    $r = (int)($body["row"] ?? 0);
    $c = (int)($body["col"] ?? 0);
    $result = "miss";

    // Check against all opponents in the game
    foreach ($game["player_ids"] as $oppId) {
        if ($oppId == $playerId) continue;
        if (isset($game["ships"][$oppId])) {
            foreach ($game["ships"][$oppId] as $ship) {
                if ((int)$ship['row'] === $r && (int)$ship['col'] === $c) {
                    $result = "hit";
                    break 2;
                }
            }
        }
    }

    // 6. Log Move with Timestamp
    $game["moves"][] = [
        "player_id" => $playerId,
        "row" => $r,
        "col" => $c,
        "result" => $result,
        "timestamp" => time() // Required for Checkpoint B
    ];

    // 7. Rotate Turn
    $game["current_turn_index"] = ($game["current_turn_index"] + 1) % count($game["player_ids"]);
    
    save_state($DATA_FILE, $state);
    
    // Return required response format
    send_json([
        "result" => $result,
        "next_player_id" => (int)$game["player_ids"][$game["current_turn_index"]],
        "game_status" => $game["status"]
    ], 200);
}
// --- TEST ENDPOINTS ---
if (preg_match("#^/api/test/games/(\d+)/ships$#", $path, $matches)) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers["x-test-password"] ?? "") !== $TEST_PASSWORD) send_json(["error"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents("php://input"), true);
    $state["games"][(int)$matches[1]]["ships"][(int)$body["player_id"]] = $body["ships"];
    save_state($DATA_FILE, $state);
    send_json(["status" => "ships placed"]);
}

send_json(["error" => "Not found"], 404);
