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
    if (!file_exists($file)) return ["nextPlayerId"=>1,"nextGameId"=>1,"players"=>[],"games"=>[]];
    return json_decode(file_get_contents($file), true) ?: ["nextPlayerId"=>1,"nextGameId"=>1,"players"=>[],"games"=>[]];
}

function save_state($file, $state) {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

function ships_object(&$game) {
    if (!isset($game["ships"]) || is_array($game["ships"])) {
        $game["ships"] = (object)($game["ships"] ?? []);
    }
}

function get_ships_array($game) {
    $s = $game["ships"] ?? [];
    return is_object($s) ? (array)$s : (array)$s;
}

function check_test_auth($TEST_PASSWORD) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers["x-test-password"] ?? "") !== $TEST_PASSWORD) {
        send_json(["error" => "Forbidden"], 403);
    }
}

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];
$state = load_state($DATA_FILE);

if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

// =====================================================================
// POST /api/reset
// =====================================================================
if ($path === "/api/reset" && $method === "POST") {
    $state = ["nextPlayerId"=>1,"nextGameId"=>1,"players"=>[],"games"=>[]];
    save_state($DATA_FILE, $state);
    send_json(["status" => "reset"]);
}

// =====================================================================
// POST /api/players
// Spec: client provides username, server generates player_id.
// Supplying player_id in body must return 400.
// Missing username must return 400.
// =====================================================================
if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    // Reject client-supplied player_id (addendum requirement)
    if (isset($body["player_id"])) {
        send_json(["error" => "player_id must not be supplied by client"], 400);
    }

    if (!isset($body["username"]) || trim($body["username"]) === "") {
        send_json(["error" => "username is required"], 400);
    }

    $id = (int)$state["nextPlayerId"]++;
    $state["players"][$id] = [
        "player_id"    => $id,
        "username"     => $body["username"],
        "games_played" => 0,
        "wins"         => 0,
        "losses"       => 0,
        "total_shots"  => 0,
        "total_hits"   => 0,
        "accuracy"     => 0.0
    ];
    save_state($DATA_FILE, $state);
    send_json(["player_id" => $id], 201);
}

// =====================================================================
// GET /api/players/{id}/stats
// =====================================================================
if (preg_match("#^/api/players/(\d+)/stats$#", $path, $m) && $method === "GET") {
    $pId = (int)$m[1];
    if (!isset($state["players"][$pId])) send_json(["error" => "Player not found"], 404);
    $p = $state["players"][$pId];
    send_json([
        "games_played" => (int)($p["games_played"] ?? 0),
        "wins"         => (int)($p["wins"]         ?? 0),
        "losses"       => (int)($p["losses"]       ?? 0),
        "total_shots"  => (int)($p["total_shots"]  ?? 0),
        "total_hits"   => (int)($p["total_hits"]   ?? 0),
        "accuracy"     => (float)($p["accuracy"]   ?? 0.0)
    ]);
}

// =====================================================================
// POST /api/games
// =====================================================================
if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gridSize = (int)($body["grid_size"] ?? 10);
    if ($gridSize < 5 || $gridSize > 15) send_json(["error" => "grid_size must be between 5 and 15"], 400);
    $id = (int)$state["nextGameId"]++;
    $state["games"][$id] = [
        "game_id"           => $id,
        "grid_size"         => $gridSize,
        "status"            => "waiting",
        "player_ids"        => [],
        "ships_placed"      => [],   // tracks which player_ids have placed
        "ships"             => new stdClass(),
        "moves"             => [],
        "current_turn_index"=> 0
    ];
    save_state($DATA_FILE, $state);
    send_json(["game_id" => $id], 201);
}

// =====================================================================
// POST /api/games/{id}/join
// Addendum: joining same game twice → 400
// =====================================================================
if (preg_match("#^/api/games/(\d+)/join$#", $path, $m)) {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    if (!isset($state["games"][$gameId])) send_json(["error" => "Game not found"], 404);
    if (!isset($state["players"][$playerId])) send_json(["error" => "Player not found"], 404);

    $game = &$state["games"][$gameId];

    // Reject duplicate join
    foreach ($game["player_ids"] as $pid) {
        if ((int)$pid === $playerId) send_json(["error" => "Already joined this game"], 400);
    }

    $game["player_ids"][] = $playerId;
    save_state($DATA_FILE, $state);
    send_json(["status" => "joined"], 200);
}

// =====================================================================
// POST /api/games/{id}/place
// Cannot place twice for same player → 400
// Player must exist → 403
// =====================================================================
if (preg_match("#^/api/games/(\d+)/place$#", $path, $m)) {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $ships    = $body["ships"] ?? [];

    if (!isset($state["games"][$gameId])) send_json(["error" => "Game not found"], 404);
    $game = &$state["games"][$gameId];

    // Validate ship count
    if (count($ships) !== 3) send_json(["error" => "Exactly 3 ships required"], 400);

    // Validate coordinates
    $used = [];
    foreach ($ships as $s) {
        $r = $s["row"] ?? -1;
        $c = $s["col"] ?? -1;
        if ($r < 0 || $r >= $game["grid_size"] || $c < 0 || $c >= $game["grid_size"]) {
            send_json(["error" => "Out of bounds"], 400);
        }
        $coord = "$r,$c";
        if (in_array($coord, $used)) send_json(["error" => "Overlapping ships"], 400);
        $used[] = $coord;
    }

    // Player must exist
    if (!isset($state["players"][$playerId])) {
        send_json(["error" => "Forbidden - player not found"], 403);
    }

    // Auto-join if not already in game
    $inGame = false;
    foreach ($game["player_ids"] as $pid) {
        if ((int)$pid === $playerId) { $inGame = true; break; }
    }
    if (!$inGame) {
        $game["player_ids"][] = $playerId;
    }

    // Cannot place twice (spec: "Cannot place twice")
    $alreadyPlaced = $game["ships_placed"] ?? [];
    foreach ($alreadyPlaced as $pid) {
        if ((int)$pid === $playerId) send_json(["error" => "Ships already placed"], 400);
    }

    ships_object($game);
    $game["ships"]->{$playerId} = $ships;
    $game["ships_placed"][] = $playerId;

    // Activate when all joined players have placed
    if (count((array)$game["ships"]) >= 2 &&
        count((array)$game["ships"]) >= count($game["player_ids"])) {
        $game["status"] = "active";
    }

    save_state($DATA_FILE, $state);
    send_json(["status" => "placed"], 200);
}

// =====================================================================
// POST /api/games/{id}/fire
// Addendum: invalid player_id → 403, valid but wrong game → 403, out of turn → 403
// =====================================================================
if (preg_match("#^/api/games/(\d+)/fire$#", $path, $m)) {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    if (!isset($state["games"][$gameId])) send_json(["error" => "Game not found"], 404);
    $game = &$state["games"][$gameId];

    // Invalid player_id entirely (doesn't exist in system)
    if (!isset($state["players"][$playerId])) {
        send_json(["error" => "Forbidden - invalid player"], 403);
    }

    if ($game["status"] !== "active") {
        send_json(["error" => "Game is not active"], 400);
    }

    // Valid player but not in this game → 403
    $inGame = false;
    foreach ($game["player_ids"] as $pid) {
        if ((int)$pid === $playerId) { $inGame = true; break; }
    }
    if (!$inGame) {
        send_json(["error" => "Forbidden - not in this game"], 403);
    }

    // Out of turn → 403
    if ((int)$game["player_ids"][$game["current_turn_index"]] !== $playerId) {
        send_json(["error" => "Out of turn"], 403);
    }

    $r = (int)($body["row"] ?? 0);
    $c = (int)($body["col"] ?? 0);
    $result = "miss";

    $allShips = get_ships_array($game);
    foreach ($game["player_ids"] as $oppId) {
        if ((int)$oppId === $playerId) continue;
        $oppShips = $allShips[$oppId] ?? $allShips[(string)$oppId] ?? null;
        if ($oppShips === null) continue;
        foreach ($oppShips as $ship) {
            if ((int)$ship["row"] === $r && (int)$ship["col"] === $c) {
                $result = "hit";
                break 2;
            }
        }
    }

    $game["moves"][] = [
        "player_id" => $playerId,
        "row"       => $r,
        "col"       => $c,
        "result"    => $result,
        "timestamp" => time()
    ];

    // Update stats
    $state["players"][$playerId]["total_shots"] = ($state["players"][$playerId]["total_shots"] ?? 0) + 1;
    if ($result === "hit") {
        $state["players"][$playerId]["total_hits"] = ($state["players"][$playerId]["total_hits"] ?? 0) + 1;
    }
    $shots = $state["players"][$playerId]["total_shots"];
    $hits  = $state["players"][$playerId]["total_hits"];
    $state["players"][$playerId]["accuracy"] = $shots > 0 ? round($hits / $shots, 4) : 0.0;

    $game["current_turn_index"] = ($game["current_turn_index"] + 1) % count($game["player_ids"]);

    save_state($DATA_FILE, $state);

    send_json([
        "result"       => $result,
        "next_player_id" => (int)$game["player_ids"][$game["current_turn_index"]],
        "game_status"  => $game["status"]
    ], 200);
}

// =====================================================================
// GET /api/games/{id}/moves
// =====================================================================
if (preg_match("#^/api/games/(\d+)/moves$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    if (!isset($state["games"][$gameId])) send_json(["error" => "Game not found"], 404);
    send_json($state["games"][$gameId]["moves"] ?? []);
}

// =====================================================================
// GET /api/games/{id}
// =====================================================================
if (preg_match("#^/api/games/(\d+)$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    if (!isset($state["games"][$gameId])) send_json(["error" => "Game not found"], 404);
    $g = $state["games"][$gameId];
    send_json([
        "game_id"            => $g["game_id"],
        "grid_size"          => $g["grid_size"],
        "status"             => $g["status"],
        "current_turn_index" => $g["current_turn_index"],
        "player_ids"         => $g["player_ids"]
    ]);
}

// =====================================================================
// TEST ENDPOINTS — all require X-Test-Password
// =====================================================================

// POST /api/test/games/{id}/ships
if (preg_match("#^/api/test/games/(\d+)/ships$#", $path, $m)) {
    check_test_auth($TEST_PASSWORD);
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gId  = (int)$m[1];
    $pId  = (int)($body["player_id"] ?? 0);

    if (!isset($state["games"][$gId])) send_json(["error" => "Game not found"], 404);

    ships_object($state["games"][$gId]);
    $state["games"][$gId]["ships"]->{$pId} = $body["ships"];

    // Track placement
    $already = false;
    foreach ($state["games"][$gId]["ships_placed"] ?? [] as $pid) {
        if ((int)$pid === $pId) { $already = true; break; }
    }
    if (!$already) $state["games"][$gId]["ships_placed"][] = $pId;

    if (count((array)$state["games"][$gId]["ships"]) >= 2) {
        $state["games"][$gId]["status"] = "active";
    }

    save_state($DATA_FILE, $state);
    send_json(["status" => "ships placed", "game_status" => $state["games"][$gId]["status"]], 200);
}

// GET /api/test/games/{id}/board/{player_id}
if (preg_match("#^/api/test/games/(\d+)/board/(\d+)$#", $path, $m)) {
    check_test_auth($TEST_PASSWORD);
    $gId = (int)$m[1];
    $pId = (int)$m[2];

    if (!isset($state["games"][$gId])) send_json(["error" => "Game not found"], 404);

    $allShips   = get_ships_array($state["games"][$gId]);
    $playerShips = $allShips[$pId] ?? $allShips[(string)$pId] ?? [];

    send_json(["player_id" => $pId, "ships" => $playerShips]);
}

// POST /api/test/games/{id}/restart  (needed for Final submission)
if (preg_match("#^/api/test/games/(\d+)/restart$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gId = (int)$m[1];
    if (!isset($state["games"][$gId])) send_json(["error" => "Game not found"], 404);

    $state["games"][$gId]["ships"]        = new stdClass();
    $state["games"][$gId]["ships_placed"] = [];
    $state["games"][$gId]["moves"]        = [];
    $state["games"][$gId]["status"]       = "waiting";
    $state["games"][$gId]["current_turn_index"] = 0;

    save_state($DATA_FILE, $state);
    send_json(["status" => "restarted", "game_status" => "waiting"]);
}

// Catch-all for /api/test/* — auth before 404
if (str_starts_with($path, "/api/test/")) {
    check_test_auth($TEST_PASSWORD);
    send_json(["error" => "Not found"], 404);
}

send_json(["error" => "Not found"], 404);
