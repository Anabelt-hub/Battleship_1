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
    if (!isset($body["username"]) || trim($body["username"]) === "") {
        send_json(["error" => "username is required"], 400);
    }
    $id = (int)$state["nextPlayerId"]++;
    $state["players"][$id] = [
        "player_id" => $id,
        "username" => $body["username"],
        "games_played" => 0,
        "wins" => 0,
        "losses" => 0,
        "total_shots" => 0,
        "total_hits" => 0,
        "accuracy" => 0.0
    ];
    save_state($DATA_FILE, $state);
    send_json(["player_id" => $id], 201);
}

if (preg_match("#^/api/players/(\d+)/stats$#", $path, $matches) && $method === "GET") {
    $pId = (int)$matches[1];
    if (!isset($state["players"][$pId])) send_json(["error" => "Player not found"], 404);
    $p = $state["players"][$pId];
    send_json([
        "games_played" => (int)($p["games_played"] ?? 0),
        "wins"         => (int)($p["wins"] ?? 0),
        "losses"       => (int)($p["losses"] ?? 0),
        "total_shots"  => (int)($p["total_shots"] ?? 0),
        "total_hits"   => (int)($p["total_hits"] ?? 0),
        "accuracy"     => (float)($p["accuracy"] ?? 0.0)
    ]);
}

if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true);
    $gridSize = (int)($body["grid_size"] ?? 10);
    if ($gridSize < 5 || $gridSize > 15) send_json(["error" => "grid_size must be between 5 and 15"], 400);
    $id = (int)$state["nextGameId"]++;
    $state["games"][$id] = [
        "game_id" => $id, "grid_size" => $gridSize,
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

    // 2. IDENTITY CHECK — player must exist
    if (!isset($state["players"][$playerId])) {
        send_json(["error" => "Forbidden - player not found"], 403);
    }

    // Auto-join if not already in game
    $playerInGame = false;
    foreach ($game["player_ids"] as $pid) {
        if ((int)$pid === (int)$playerId) { $playerInGame = true; break; }
    }
    if (!$playerInGame) {
        $game["player_ids"][] = $playerId;
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
    $body = json_decode(file_get_contents("php://input"), true);
    $playerId = (int)($body["player_id"] ?? 0);

    if (!isset($state["games"][$gameId])) send_json(["error" => "Game not found"], 404);
    $game = &$state["games"][$gameId];

    if ($game["status"] !== "active") {
        send_json(["error" => "Firing is not allowed until all ships are placed"], 400);
    }

    $playerInGame = false;
    foreach ($game["player_ids"] as $pid) {
        if ((int)$pid === (int)$playerId) { $playerInGame = true; break; }
    }
    if (!$playerInGame) {
        send_json(["error" => "Forbidden - You are not in this game"], 403);
    }

    if ((int)$game["player_ids"][$game["current_turn_index"]] !== $playerId) {
        send_json(["error" => "Out of turn"], 403);
    }

    $r = (int)($body["row"] ?? 0);
    $c = (int)($body["col"] ?? 0);
    $result = "miss";

    // Check against all opponents — ships keys may be int or string after JSON round-trip
    $allShips = is_object($game["ships"]) ? (array)$game["ships"] : (array)($game["ships"] ?? []);
    foreach ($game["player_ids"] as $oppId) {
        if ((int)$oppId === (int)$playerId) continue;

        // Try both int and string key since JSON decode may store as string
        $opponentShips = $allShips[$oppId] ?? $allShips[(string)$oppId] ?? null;
        if ($opponentShips === null) continue;

        foreach ($opponentShips as $ship) {
            if ((int)$ship['row'] === $r && (int)$ship['col'] === $c) {
                $result = "hit";
                break 2;
            }
        }
    }
    $game["moves"][] = [
        "player_id" => $playerId,
        "row" => $r, "col" => $c,
        "result" => $result,
        "timestamp" => time()
    ];

    $game["current_turn_index"] = ($game["current_turn_index"] + 1) % count($game["player_ids"]);
    save_state($DATA_FILE, $state);
    
    send_json([
        "result" => $result,
        "next_player_id" => (int)$game["player_ids"][$game["current_turn_index"]],
        "game_status" => $game["status"]
    ], 200);
}

// --- GET Single Game Status ---
if (preg_match("#^/api/games/(\d+)$#", $path, $matches)) {
    $gameId = (int)$matches[1];
    
    // Check if the game exists in your state
    if (!isset($state["games"][$gameId])) {
        send_json(["error" => "Game not found"], 404);
    }
    
    // Return the full game object so the frontend can check .status
    send_json($state["games"][$gameId]);
}

// --- TEST ENDPOINTS ---
if (preg_match("#^/api/test/games/(\d+)/ships$#", $path, $matches)) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers["x-test-password"] ?? "") !== $TEST_PASSWORD) send_json(["error"=>"Forbidden"], 403);
    
    $body = json_decode(file_get_contents("php://input"), true);
    $gId = (int)$matches[1];
    $pId = (int)($body["player_id"] ?? 0);

    if (!isset($state["games"][$gId])) send_json(["error" => "Not found"], 404);
    
    if (!isset($state["games"][$gId]["ships"]) || is_array($state["games"][$gId]["ships"])) {
        $state["games"][$gId]["ships"] = (object)$state["games"][$gId]["ships"];
    }

    $state["games"][$gId]["ships"]->{$pId} = $body["ships"];

    if (count((array)$state["games"][$gId]["ships"]) >= 2) {
        $state["games"][$gId]["status"] = "active";
    }

    save_state($DATA_FILE, $state);
    send_json(["status" => "ships placed", "game_status" => $state["games"][$gId]["status"]]);
}

// --- GET board reveal ---
if (preg_match("#^/api/test/games/(\d+)/board/(\d+)$#", $path, $matches)) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers["x-test-password"] ?? "") !== $TEST_PASSWORD) send_json(["error" => "Forbidden"], 403);

    $gId = (int)$matches[1];
    $pId = (int)$matches[2];

    if (!isset($state["games"][$gId])) send_json(["error" => "Game not found"], 404);

    $ships = $state["games"][$gId]["ships"];
    $shipsArr = is_object($ships) ? (array)$ships : (is_array($ships) ? $ships : []);
    $playerShips = $shipsArr[$pId] ?? $shipsArr[(string)$pId] ?? [];

    send_json(["player_id" => $pId, "ships" => $playerShips]);
}

// --- Catch-all: any /api/test/* path not matched above must still auth-check before 404 ---
if (str_starts_with($path, "/api/test/")) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers["x-test-password"] ?? "") !== $TEST_PASSWORD) send_json(["error" => "Forbidden"], 403);
    send_json(["error" => "Not found"], 404);
}

send_json(["error" => "Not found"], 404);
