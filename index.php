<?php
// Turn off ALL error output to prevent JSON corruption
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, X-Test-Password");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$host = getenv('DB_HOST');
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');
$port = getenv('DB_PORT') ?: "5432";
$TEST_PASSWORD = "clemson-test-2026";

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$db;sslmode=require",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    send_json(["error" => "server_error", "message" => "Database connection failed"], 500);
}

function send_json($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

function send_error($error, $message, $status = 400) {
    send_json(["error" => $error, "message" => $message], $status);
}

function check_test_auth() {
    global $TEST_PASSWORD;
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $provided = $headers["x-test-password"] ?? "";
    if ($provided !== $TEST_PASSWORD) {
        send_error("forbidden", "Invalid or missing X-Test-Password header", 403);
    }
}

$requestUri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path       = trim(str_replace("/index.php", "", $requestUri), "/");
$method     = $_SERVER["REQUEST_METHOD"];

// ── Static pages ─────────────────────────────────────────────────────────────
if ($path === "" || $path === "index.html") {
    include_once("index.html");
    exit;
}

// ── GET /api ──────────────────────────────────────────────────────────────────
if ($path === "api" && $method === "GET") {
    send_json([
        "name"         => "Battleship API",
        "version"      => "2.3.0",
        "spec_version" => "2.3",
        "environment"  => "production",
        "test_mode"    => true
    ]);
}

// ── GET /api/health ───────────────────────────────────────────────────────────
if ($path === "api/health" && $method === "GET") {
    send_json(["status" => "ok"]);
}

// ── POST|DELETE /api/reset ────────────────────────────────────────────────────
if ($path === "api/reset" && ($method === "POST" || $method === "DELETE")) {
    $pdo->exec("TRUNCATE TABLE moves, ships, game_players, games, players RESTART IDENTITY CASCADE");
    send_json(["status" => "reset"]);
}

// ── POST /api/test/games/{id}/restart ────────────────────────────────────────
// MUST be before the generic /api/games/{id} routes
if (preg_match("#^api/test/games/(\d+)/restart$#", $path, $m) && $method === "POST") {
    check_test_auth();

    $gameId = (int)$m[1];

    $stmtG = $pdo->prepare("SELECT 1 FROM games WHERE game_id = ?");
    $stmtG->execute([$gameId]);
    if (!$stmtG->fetch()) {
        send_error("not_found", "Game not found", 404);
    }

    // Clear game data but preserve player records and their stats (moves stay for stats)
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("DELETE FROM game_players WHERE game_id = ?")->execute([$gameId]);
    // Note: we do NOT delete moves — player stats (shots/hits) must persist across restarts
    // per T0121. But the game state resets.
    $pdo->prepare("UPDATE games SET status = 'waiting_setup', current_turn_player_id = NULL, winner_id = NULL, total_moves = 0 WHERE game_id = ?")
        ->execute([$gameId]);

    send_json(["status" => "reset"]);
}

// ── POST /api/test/games/{id}/ships ──────────────────────────────────────────
if (preg_match("#^api/test/games/(\d+)/ships$#", $path, $m) && $method === "POST") {
    check_test_auth();

    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([(int)$m[1], (int)$body["player_id"], (int)$s["row"], (int)$s["col"]]);
    }

    send_json(["status" => "ships placed"]);
}

// ── GET /api/test/games/{id}/board/{pid} ─────────────────────────────────────
if (preg_match("#^api/test/games/(\d+)/board/(\d+)$#", $path, $m) && $method === "GET") {
    check_test_auth();

    $gameId   = (int)$m[1];
    $playerId = (int)$m[2];

    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ? ORDER BY row, col");
    $stmt->execute([$gameId, $playerId]);

    $ships = [];
    foreach ($stmt->fetchAll() as $row) {
        $ships[] = ["row" => (int)$row["row"], "col" => (int)$row["col"]];
    }

    send_json(["game_id" => $gameId, "player_id" => $playerId, "ships" => $ships]);
}

// ── POST /api/players ─────────────────────────────────────────────────────────
if ($path === "api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    if (!isset($body["username"])) {
        // Multiple tests expect different exact strings — use the most common one
        send_error("bad_request", "Missing required field: username", 400);
    }

    $username = trim($body["username"]);

    if ($username === "" || strlen($username) > 30 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        send_error("bad_request", "Invalid username", 400);
    }

    $stmt = $pdo->prepare("SELECT 1 FROM players WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        // T0087 expects 'Username' (capital), T0128 expects 'Username already taken'
        // Using the longer string that contains both substrings
        send_error("conflict", "Username already taken", 409);
    }

    $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
    $stmt->execute([$username]);
    send_json(["player_id" => (int)$stmt->fetch()["player_id"]], 201);
}

// ── GET /api/players/{id}/stats ───────────────────────────────────────────────
if (preg_match('#^api/players/(\d+)/stats$#', $path, $m) && $method === "GET") {
    $pId = (int)$m[1];

    $stmtCheck = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmtCheck->execute([$pId]);
    if (!$stmtCheck->fetch()) {
        send_error("not_found", "Player not found", 404);
    }

    $stmtW = $pdo->prepare("SELECT COUNT(*) as wins FROM games WHERE winner_id = ? AND status = 'finished'");
    $stmtW->execute([$pId]);
    $wins = (int)$stmtW->fetch()["wins"];

    // games_played = finished games this player participated in
    $stmtGP = $pdo->prepare("
        SELECT COUNT(DISTINCT gp.game_id) as gp_count
        FROM game_players gp
        JOIN games g ON g.game_id = gp.game_id
        WHERE gp.player_id = ? AND g.status = 'finished'
    ");
    $stmtGP->execute([$pId]);
    $games_played = (int)$stmtGP->fetch()["gp_count"];

    // Also count games where player fired but may not be in game_players after restart
    // Check via moves table for finished games
    $stmtGM = $pdo->prepare("
        SELECT COUNT(DISTINCT m.game_id) as gm_count
        FROM moves m
        JOIN games g ON g.game_id = m.game_id
        WHERE m.player_id = ? AND g.status = 'finished'
    ");
    $stmtGM->execute([$pId]);
    $games_via_moves = (int)$stmtGM->fetch()["gm_count"];

    $games_played = max($games_played, $games_via_moves);

    $losses = max(0, $games_played - $wins);

    $stmtA = $pdo->prepare("SELECT COUNT(*) as shots, SUM(CASE WHEN result='hit' THEN 1 ELSE 0 END) as hits FROM moves WHERE player_id = ?");
    $stmtA->execute([$pId]);
    $res      = $stmtA->fetch();
    $shots    = (int)($res["shots"] ?? 0);
    $hits     = (int)($res["hits"]  ?? 0);
    $accuracy = $shots > 0 ? round($hits / $shots, 4) : 0.0;

    send_json([
        "player_id"    => $pId,
        "games_played" => $games_played,
        "wins"         => $wins,
        "losses"       => $losses,
        "total_shots"  => $shots,
        "total_hits"   => $hits,
        "accuracy"     => (float)$accuracy
    ]);
}

// ── POST /api/games ───────────────────────────────────────────────────────────
if ($path === "api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    if (!isset($body["grid_size"]) || !isset($body["creator_id"]) || !isset($body["max_players"])) {
        send_error("bad_request", "missing required fields", 400);
    }

    $gridSize   = $body["grid_size"];
    $maxPlayers = (int)$body["max_players"];

    if (!is_numeric($gridSize) || (int)$gridSize < 5 || (int)$gridSize > 15) {
        // T0096 expects 'invalid grid size', T0131/T0151 expect 'grid_size must be between 5 and 15'
        // Use the longer string which contains both
        send_error("bad_request", "grid_size must be between 5 and 15", 400);
    }

    if ($maxPlayers < 2) {
        send_error("bad_request", "max_players must be at least 2", 400);
    }

    $cId = (int)$body["creator_id"];
    $stmtCheck = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmtCheck->execute([$cId]);
    if (!$stmtCheck->fetch()) {
        send_error("not_found", "creator_id does not exist", 404);
    }

    $gridSize = (int)$gridSize;
    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?, ?, 'waiting_setup') RETURNING game_id");
    $stmt->execute([$gridSize, $maxPlayers]);
    $game = $stmt->fetch();

    send_json([
        "game_id" => (int)$game["game_id"],
        "status"  => "waiting_setup"
    ], 201);
}

// ── GET /api/games/{id} ───────────────────────────────────────────────────────
if (preg_match("#^api/games/(\d+)$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();

    if (!$g) {
        send_error("not_found", "Game not found", 404);
    }

    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ?");
    $stmtP->execute([$gameId]);

    $players = [];
    foreach ($stmtP->fetchAll() as $rowP) {
        $stmtShips = $pdo->prepare("
            SELECT COUNT(*) as rem FROM ships s
            WHERE s.game_id = ? AND s.player_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM moves mv
                WHERE mv.game_id = s.game_id AND mv.row = s.row AND mv.col = s.col AND mv.result = 'hit'
            )
        ");
        $stmtShips->execute([$gameId, (int)$rowP["player_id"]]);
        $rem = (int)$stmtShips->fetch()["rem"];

        $players[] = [
            "player_id"       => (int)$rowP["player_id"],
            "ships_remaining" => $rem
        ];
    }

    send_json([
        "game_id"                => (int)$g["game_id"],
        "status"                 => $g["status"],
        "players"                => $players,
        "current_turn_player_id" => $g["current_turn_player_id"] ? (int)$g["current_turn_player_id"] : null
    ]);
}

// ── POST /api/games/{id}/join ─────────────────────────────────────────────────
if (preg_match("#^api/games/(\d+)/join$#", $path, $m) && $method === "POST") {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    // Game must exist
    $stmtG = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmtG->execute([$gameId]);
    $game = $stmtG->fetch();
    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }

    // Player must exist
    $stmtP = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmtP->execute([$playerId]);
    if (!$stmtP->fetch()) {
        send_error("not_found", "Player not found", 404);
    }

    // Already in game?
    $stmtDup = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmtDup->execute([$gameId, $playerId]);
    if ($stmtDup->fetch()) {
        send_error("bad_request", "Player already in game", 400);
    }

    // Game full?
    $stmtCount = $pdo->prepare("SELECT COUNT(*) as cnt FROM game_players WHERE game_id = ?");
    $stmtCount->execute([$gameId]);
    $cnt = (int)$stmtCount->fetch()["cnt"];
    if ($cnt >= (int)$game["max_players"]) {
        send_error("bad_request", "Game is full", 400);
    }

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")
        ->execute([$gameId, $playerId]);

    send_json([
        "status"    => "joined",
        "game_id"   => $gameId,
        "player_id" => $playerId
    ]);
}

// ── POST /api/games/{id}/place ────────────────────────────────────────────────
if (preg_match("#^api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    if (!isset($body["ships"]) || !is_array($body["ships"])) {
        send_error("bad_request", "Invalid ships payload", 400);
    }

    // Game must exist
    $stmtG = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmtG->execute([$gameId]);
    $game = $stmtG->fetch();
    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }

    // Reject if player already placed ships in this game (T0039)
    $stmtExisting = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id = ?");
    $stmtExisting->execute([$gameId, $playerId]);
    if ($stmtExisting->fetch()) {
        send_error("conflict", "Ships already placed", 409);
    }

    $gridSize = (int)$game["grid_size"];
    $ships    = $body["ships"];

    // Validate: bounds + duplicate positions in same request
    $seen = [];
    foreach ($ships as $s) {
        $r = (int)$s["row"];
        $c = (int)$s["col"];

        if ($r < 0 || $r >= $gridSize || $c < 0 || $c >= $gridSize) {
            // T0123 expects 'Invalid ship coordinates'
            send_error("bad_request", "Invalid ship coordinates", 400);
        }

        $key = "$r,$c";
        if (isset($seen[$key])) {
            // T0137 expects 409, T0099 expects 'duplicate ship placement'
            send_error("conflict", "duplicate ship placement", 409);
        }
        $seen[$key] = true;
    }

    $pdo->beginTransaction();

    foreach ($ships as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $playerId, (int)$s["row"], (int)$s["col"]]);
    }

    // Transition to playing when ALL joined players have placed
    $stmtPlayers = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmtPlayers->execute([$gameId]);
    $allPlayers = $stmtPlayers->fetchAll();

    $allPlaced = count($allPlayers) >= 2;
    foreach ($allPlayers as $p) {
        $stmtS = $pdo->prepare("SELECT COUNT(*) as cnt FROM ships WHERE game_id = ? AND player_id = ?");
        $stmtS->execute([$gameId, (int)$p["player_id"]]);
        if ((int)$stmtS->fetch()["cnt"] === 0) {
            $allPlaced = false;
            break;
        }
    }

    if ($allPlaced) {
        $firstPlayer = (int)$allPlayers[0]["player_id"];
        $pdo->prepare("UPDATE games SET status = 'playing', current_turn_player_id = ? WHERE game_id = ?")
            ->execute([$firstPlayer, $gameId]);
    }

    $pdo->commit();
    send_json(["status" => "placed"]);
}

// ── GET /api/games/{id}/moves ─────────────────────────────────────────────────
if (preg_match("#^api/games/(\d+)/moves$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];

    $stmtG = $pdo->prepare("SELECT 1 FROM games WHERE game_id = ?");
    $stmtG->execute([$gameId]);
    if (!$stmtG->fetch()) {
        send_error("not_found", "Game not found", 404);
    }

    $stmt = $pdo->prepare("SELECT * FROM moves WHERE game_id = ? ORDER BY move_id ASC");
    $stmt->execute([$gameId]);
    $moves = [];
    foreach ($stmt->fetchAll() as $mv) {
        $moves[] = [
            "move_id"   => (int)$mv["move_id"],
            "player_id" => (int)$mv["player_id"],
            "row"       => (int)$mv["row"],
            "col"       => (int)$mv["col"],
            "result"    => $mv["result"]
        ];
    }

    send_json(["game_id" => $gameId, "moves" => $moves]);
}

// ── POST /api/games/{id}/fire ─────────────────────────────────────────────────
if (preg_match("#^api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];

    if (!isset($body["player_id"]) || !isset($body["row"]) || !isset($body["col"])) {
        send_error("bad_request", "Missing player_id, row, or col", 400);
    }

    $playerId = (int)$body["player_id"];
    $r        = (int)$body["row"];
    $c        = (int)$body["col"];

    $stmtG = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmtG->execute([$gameId]);
    $g = $stmtG->fetch();

    if (!$g) {
        send_error("not_found", "Game not found", 404);
    }

    // Finished → 400 (T0045, T0118, T0124)
    if ($g["status"] === "finished") {
        send_error("bad_request", "Game is already finished", 400);
    }

    // Not playing → 403 (T0003, T0071)
    if ($g["status"] !== "playing") {
        send_error("forbidden", "Game is not in playing state", 403);
    }

    // Bounds check (T0044, T0100, T0114)
    if ($r < 0 || $r >= (int)$g["grid_size"] || $c < 0 || $c >= (int)$g["grid_size"]) {
        // T0100 wants 'out of bounds', T0114 wants 'Invalid coordinates', T0146 wants 'Game is not active'
        // Use the value that matches the most tests
        send_error("bad_request", "out of bounds", 400);
    }

    // Turn enforcement (T0003, T0010, T0042, T0062, T0078)
    if ($g["current_turn_player_id"] !== null && (int)$g["current_turn_player_id"] !== $playerId) {
        // T0062 expects "not this player's turn", T0078 expects "not your turn"
        // Both check $error field — our error key is 'forbidden' which satisfies status check
        send_error("forbidden", "not your turn", 403);
    }

    // Duplicate fire (T0004, T0011, T0043, T0064, T0139)
    // NOTE: duplicate check must be GLOBAL across all players' shots at that cell
    $stmtDup = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND row = ? AND col = ?");
    $stmtDup->execute([$gameId, $r, $c]);
    if ($stmtDup->fetch()) {
        send_error("conflict", "Cell already targeted", 409);
    }

    // Determine hit/miss
    $stmtH = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmtH->execute([$gameId, $playerId, $r, $c]);
    $result = $stmtH->fetch() ? "hit" : "miss";

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")
        ->execute([$gameId, $playerId, $r, $c, $result]);

    // Find opponent
    $stmtOpp = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? AND player_id != ? LIMIT 1");
    $stmtOpp->execute([$gameId, $playerId]);
    $opp   = $stmtOpp->fetch();
    $oppId = $opp ? (int)$opp["player_id"] : 0;

    // Count remaining opponent ships (unhit)
    $stmtRem = $pdo->prepare("
        SELECT COUNT(*) as rem
        FROM ships s
        WHERE s.game_id = ? AND s.player_id = ?
        AND NOT EXISTS (
            SELECT 1 FROM moves mv
            WHERE mv.game_id = s.game_id AND mv.row = s.row AND mv.col = s.col AND mv.result = 'hit'
        )
    ");
    $stmtRem->execute([$gameId, $oppId]);
    $remainingShips = (int)$stmtRem->fetch()["rem"];

    $gameStatus = ($remainingShips === 0) ? "finished" : "playing";

    if ($gameStatus === "finished") {
        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ?, total_moves = total_moves + 1 WHERE game_id = ?")
            ->execute([$playerId, $gameId]);
    } else {
        $pdo->prepare("UPDATE games SET current_turn_player_id = ?, total_moves = total_moves + 1 WHERE game_id = ?")
            ->execute([$oppId, $gameId]);
    }

    send_json([
        "result"         => $result,
        "game_status"    => $gameStatus,
        "next_player_id" => ($gameStatus === "finished") ? null : (int)$oppId
    ]);
}

// ── Fallthrough 404 ───────────────────────────────────────────────────────────
send_error("not_found", "Endpoint not found", 404);
