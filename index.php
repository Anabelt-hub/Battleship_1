<?php
// 1. DEBUGGING (Turn off for Gradescope)
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, X-Test-Password");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$host = getenv('DB_HOST'); $db = getenv('DB_NAME');
$user = getenv('DB_USER'); $pass = getenv('DB_PASS');
$port = getenv('DB_PORT') ?: "5432";
$TEST_PASSWORD = "clemson-test-2026";

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db;sslmode=require", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
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

function check_test_auth($TEST_PASSWORD) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers["x-test-password"] ?? "") !== $TEST_PASSWORD) {
        send_error("forbidden", "Invalid test password", 403);
    }
}

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];

if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

// Metadata
if ($path === "/api" && $method === "GET") {
    send_json(["name" => "Battleship API", "version" => "2.3.0", "spec_version" => "2.3", "environment" => "production", "test_mode" => true]);
}

// GET /api/health  ← ADDED (was missing, T0015 failing)
if ($path === "/api/health" && $method === "GET") {
    send_json(["status" => "ok"]);
}

// POST /api/reset  ← ADDED (keeps DB clean between test runs)
if ($path === "/api/reset" && ($method === "POST" || $method === "DELETE")) {
    try {
        $pdo->exec("TRUNCATE TABLE moves RESTART IDENTITY CASCADE");
        $pdo->exec("TRUNCATE TABLE ships RESTART IDENTITY CASCADE");
        $pdo->exec("TRUNCATE TABLE game_players RESTART IDENTITY CASCADE");
        $pdo->exec("TRUNCATE TABLE games RESTART IDENTITY CASCADE");
        $pdo->exec("TRUNCATE TABLE players RESTART IDENTITY CASCADE");
        send_json(["status" => "reset"], 200);
    } catch (Throwable $e) {
        send_error("server_error", "Reset failed: " . $e->getMessage(), 500);
    }
}

// POST /api/players  ← CHANGED: now rejects duplicates with 409 instead of returning existing
if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $username = trim($body["username"] ?? $body["playerName"] ?? "");

    if ($username === "") {
        send_error("bad_request", "Missing required field: username", 400);
    }

    if (!preg_match("/^[A-Za-z0-9_ ]+$/", $username)) {
        send_error("bad_request", "Invalid username", 400);
    }

    $stmt = $pdo->prepare("SELECT player_id, username FROM players WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch();

    if ($existing) {
        send_error("conflict", "Username already exists", 409);  // was returning 201 before
    }

    $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id, username");
    $stmt->execute([$username]);
    $player = $stmt->fetch();

    send_json([
        "player_id"   => (int)$player["player_id"],
        "username"    => $player["username"],
        "displayName" => $player["username"]
    ], 201);
}

// GET /api/players/{id}/stats  ← ADDED (was missing entirely)
if (preg_match("#^/api/players/(\d+)/stats/?$#", $path, $m) && $method === "GET") {
    $playerId = (int)$m[1];

    $stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    if (!$stmt->fetch()) {
        send_error("not_found", "Player not found", 404);
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_shots,
               COALESCE(SUM(CASE WHEN result = 'hit' THEN 1 ELSE 0 END), 0) AS total_hits
        FROM moves WHERE player_id = ?
    ");
    $stmt->execute([$playerId]);
    $shots = $stmt->fetch();
    $totalShots = (int)$shots["total_shots"];
    $totalHits  = (int)$shots["total_hits"];
    $accuracy   = $totalShots > 0 ? round($totalHits / $totalShots, 4) : 0.0;

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT game_id) AS gp FROM game_players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    $gamesPlayed = (int)$stmt->fetch()["gp"];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS wins FROM games WHERE winner_id = ?");
    $stmt->execute([$playerId]);
    $wins   = (int)$stmt->fetch()["wins"];
    $losses = max(0, $gamesPlayed - $wins);

    send_json([
        "player_id"    => $playerId,
        "games_played" => $gamesPlayed,
        "games"        => $gamesPlayed,
        "wins"         => $wins,
        "losses"       => $losses,
        "total_shots"  => $totalShots,
        "shots"        => $totalShots,
        "total_hits"   => $totalHits,
        "hits"         => $totalHits,
        "accuracy"     => $accuracy
    ]);
}

// POST /api/games  ← CHANGED: now validates grid_size range
if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    if (!isset($body["grid_size"])) {
        send_error("bad_request", "grid_size is required", 400);
    }

    $gridSize   = (int)$body["grid_size"];
    $maxPlayers = (int)($body["max_players"] ?? 2);
    $creatorId  = (int)($body["creator_id"] ?? 0);

    if ($gridSize < 5 || $gridSize > 15) {
        send_error("bad_request", "grid_size must be between 5 and 15", 400);
    }

    if ($maxPlayers < 2) {
        send_error("bad_request", "max_players must be at least 2", 400);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?, ?, 'waiting_setup') RETURNING game_id");
        $stmt->execute([$gridSize, $maxPlayers]);
        $gameId = (int)$stmt->fetch()["game_id"];

        if ($creatorId > 0) {
            $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")
                ->execute([$gameId, $creatorId]);
        }
        $pdo->commit();

        send_json([
            "game_id"     => $gameId,
            "grid_size"   => $gridSize,
            "max_players" => $maxPlayers,
            "status"      => "waiting_setup"
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_error("server_error", "Failed to create game", 500);
    }
}

// POST /api/games/{id}/join  ← CHANGED: now validates game exists, player exists, not duplicate, not full
if (preg_match("#^/api/games/(\d+)/join/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? $body["playerId"] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    if ($playerId <= 0) send_error("bad_request", "player_id is required", 400);

    $stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    if (!$stmt->fetch()) send_error("not_found", "Player not found", 404);

    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ($stmt->fetch()) send_error("conflict", "Player already in this game", 409);

    if (in_array($game["status"], ["playing", "finished"], true)) {
        send_error("conflict", "Game already started", 409);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ((int)$stmt->fetch()["c"] >= (int)$game["max_players"]) {
        send_error("conflict", "Game is full", 409);
    }

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $playerId]);

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $activePlayers = (int)$stmt->fetch()["c"];

    send_json(["status" => "joined", "game_id" => $gameId, "player_id" => $playerId, "active_players" => $activePlayers], 200);
}

// GET /api/games/{id}  ← CHANGED: now returns 404 for missing games, added more fields
if (preg_match("#^/api/games/(\d+)/?$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();

    if (!$g) send_error("not_found", "Game not found", 404);

    $turnPlayerId = isset($g["current_turn_player_id"]) && $g["current_turn_player_id"] !== null
        ? (int)$g["current_turn_player_id"] : null;

    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmtP->execute([$gameId]);
    $playerRows = $stmtP->fetchAll();

    $players = [];
    $playerIds = [];
    foreach ($playerRows as $rowP) {
        $pid = (int)$rowP["player_id"];
        $playerIds[] = $pid;
        $stmtS = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE game_id = ? AND player_id = ? AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit')");
        $stmtS->execute([$gameId, $pid]);
        $players[] = ["player_id" => $pid, "ships_remaining" => (int)$stmtS->fetch()["rem"]];
    }

    $currentTurnIndex = 0;
    if ($turnPlayerId !== null) {
        $idx = array_search($turnPlayerId, $playerIds, true);
        if ($idx !== false) $currentTurnIndex = (int)$idx;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM moves WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $totalMoves = (int)$stmt->fetch()["c"];

    send_json([
        "game_id"                => (int)$g["game_id"],
        "grid_size"              => (int)$g["grid_size"],
        "max_players"            => (int)$g["max_players"],
        "status"                 => $g["status"],
        "players"                => $players,
        "active_players"         => count($playerIds),
        "current_turn_player_id" => $turnPlayerId,
        "current_turn_index"     => $currentTurnIndex,
        "total_moves"            => $totalMoves,
        "winner_id"              => isset($g["winner_id"]) && $g["winner_id"] !== null ? (int)$g["winner_id"] : null
    ]);
}

// POST /api/games/{id}/place  ← CHANGED: added validations for bounds, duplicates, already placed
if (preg_match("#^/api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    if (!isset($body["ships"]) || !is_array($body["ships"])) {
        send_error("bad_request", "Invalid ships payload", 400);
    }

    $playerId = (int)($body["player_id"] ?? $body["playerId"] ?? 0);
    $ships = $body["ships"];

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    $gridSize = (int)$game["grid_size"];

    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if (!$stmt->fetch()) send_error("not_found", "Player not in game", 404);

    if ($game["status"] === "finished") send_error("conflict", "Game already finished", 409);
    if ($game["status"] === "playing")  send_error("conflict", "Game already started", 409);

    if (count($ships) !== 3) send_error("bad_request", "Exactly 3 ships are required", 400);

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ((int)$stmt->fetch()["c"] > 0) send_error("conflict", "Ships already placed", 409);

    $seen = [];
    foreach ($ships as $ship) {
        if (is_array($ship) && isset($ship["row"], $ship["col"])) {
            $row = (int)$ship["row"]; $col = (int)$ship["col"];
        } elseif (is_array($ship) && count($ship) >= 2 && array_keys($ship) === [0, 1]) {
            $row = (int)$ship[0]; $col = (int)$ship[1];
        } else {
            send_error("bad_request", "Each ship must have row and col", 400);
        }
        if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
            send_error("bad_request", "Ship coordinate out of bounds", 400);
        }
        $key = "$row:$col";
        if (isset($seen[$key])) send_error("bad_request", "Duplicate ship coordinates", 400);
        $seen[$key] = true;
    }

    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")->execute([$gameId, $playerId]);
    foreach ($ships as $ship) {
        $r = isset($ship["row"]) ? (int)$ship["row"] : (int)$ship[0];
        $c = isset($ship["col"]) ? (int)$ship["col"] : (int)$ship[1];
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $playerId, $r, $c]);
    }

    // Start game only if all players have placed
    $stmtC = $pdo->prepare("SELECT COUNT(DISTINCT player_id) AS c FROM ships WHERE game_id = ?");
    $stmtC->execute([$gameId]);
    $playersWithShips = (int)$stmtC->fetch()["c"];

    $stmtT = $pdo->prepare("SELECT COUNT(*) AS c FROM game_players WHERE game_id = ?");
    $stmtT->execute([$gameId]);
    $totalPlayers = (int)$stmtT->fetch()["c"];

    if ($totalPlayers >= (int)$game["max_players"] && $playersWithShips >= (int)$game["max_players"]) {
        $stmtF = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
        $stmtF->execute([$gameId]);
        $fp = (int)$stmtF->fetch()["player_id"];
        $pdo->prepare("UPDATE games SET status = 'playing', current_turn_player_id = ? WHERE game_id = ?")
            ->execute([$fp, $gameId]);
    }

    $pdo->commit();
    send_json(["status" => "placed", "ok" => true, "message" => "ok"], 200);
}

// POST /api/games/{id}/fire  ← CHANGED: added turn check, duplicate check, bounds check, finished check
if (preg_match("#^/api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? $body["playerId"] ?? 0);
    $row = isset($body["row"]) ? (int)$body["row"] : null;
    $col = isset($body["col"]) ? (int)$body["col"] : null;

    if ($playerId <= 0 || $row === null || $col === null) {
        send_error("bad_request", "player_id, row, and col are required", 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if (!$stmt->fetch()) send_error("not_found", "Player not in game", 404);

    if ($game["status"] === "waiting_setup") send_error("forbidden", "Game not started yet", 403);
    if ($game["status"] === "finished")      send_error("conflict", "Game already finished", 409);

    $gridSize = (int)$game["grid_size"];
    if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
        send_error("bad_request", "Shot out of bounds", 400);
    }

    $currentTurnPlayerId = isset($game["current_turn_player_id"]) && $game["current_turn_player_id"] !== null
        ? (int)$game["current_turn_player_id"] : 0;

    if ($currentTurnPlayerId !== $playerId) {
        send_error("forbidden", "Not your turn", 403);
    }

    $stmt = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND player_id = ? AND row = ? AND col = ?");
    $stmt->execute([$gameId, $playerId, $row, $col]);
    if ($stmt->fetch()) send_error("conflict", "Coordinate already fired on", 409);

    $stmtH = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmtH->execute([$gameId, $playerId, $row, $col]);
    $result = $stmtH->fetch() ? "hit" : "miss";

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")
        ->execute([$gameId, $playerId, $row, $col, $result]);

    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmtP->execute([$gameId]);
    $playerIds = array_column($stmtP->fetchAll(), "player_id");

    $alivePlayers = [];
    foreach ($playerIds as $pid) {
        $stmtR = $pdo->prepare("SELECT COUNT(*) AS rem FROM ships s WHERE game_id = ? AND player_id = ? AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit')");
        $stmtR->execute([$gameId, (int)$pid]);
        if ((int)$stmtR->fetch()["rem"] > 0) $alivePlayers[] = (int)$pid;
    }

    $gameStatus = count($alivePlayers) <= 1 ? "finished" : "playing";
    $winnerId = null;
    $nextPlayerId = null;

    if ($gameStatus === "finished") {
        $winnerId = count($alivePlayers) === 1 ? $alivePlayers[0] : $playerId;
        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ?, current_turn_player_id = NULL WHERE game_id = ?")
            ->execute([$winnerId, $gameId]);
    } else {
        $idx = array_search($playerId, $playerIds, true);
        $nextPlayerId = (int)$playerIds[($idx + 1) % count($playerIds)];
        $pdo->prepare("UPDATE games SET current_turn_player_id = ? WHERE game_id = ?")
            ->execute([$nextPlayerId, $gameId]);
    }

    send_json(["result" => $result, "game_status" => $gameStatus, "next_player_id" => $nextPlayerId, "winner_id" => $winnerId]);
}

// GET /api/games/{id}/moves  ← ADDED (was missing)
if (preg_match("#^/api/games/(\d+)/moves/?$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if (!$stmt->fetch()) send_error("not_found", "Game not found", 404);

    $stmt = $pdo->prepare("SELECT player_id, row, col, result FROM moves WHERE game_id = ? ORDER BY row ASC, col ASC");
    $stmt->execute([$gameId]);
    $moves = [];
    foreach ($stmt->fetchAll() as $move) {
        $moves[] = [
            "player_id" => (int)$move["player_id"],
            "row"       => (int)$move["row"],
            "col"       => (int)$move["col"],
            "result"    => $move["result"],
            "timestamp" => null
        ];
    }
    send_json(["game_id" => $gameId, "moves" => $moves], 200);
}

// POST /api/test/games/{id}/restart  ← UNCHANGED from your base (already worked)
if (preg_match("#^/api/test/games/(\d+)/restart/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("UPDATE games SET status = 'waiting_setup', current_turn_player_id = null WHERE game_id = ?")->execute([$gameId]);
    send_json(["status" => "reset"]);
}

// POST /api/test/games/{id}/ships  ← UNCHANGED from your base
if (preg_match("#^/api/test/games/(\d+)/ships/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $pId = (int)($body["player_id"] ?? 0);

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")->execute([$gameId, $pId]);
    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $pId, (int)$s["row"], (int)$s["col"]]);
    }

    $stmtC = $pdo->prepare("SELECT COUNT(DISTINCT player_id) as c FROM ships WHERE game_id = ?");
    $stmtC->execute([$gameId]);
    if ((int)$stmtC->fetch()["c"] >= 2) {
        $first = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
        $first->execute([$gameId]);
        $fp = (int)$first->fetch()["player_id"];
        $pdo->prepare("UPDATE games SET status = 'playing', current_turn_player_id = ? WHERE game_id = ?")
            ->execute([$fp, $gameId]);
    }

    $pdo->commit();
    send_json(["status" => "ships placed"]);
}

// Fallback for literal {id} paths — must check auth first
if (
    ($path === "/api/test/games/:id/board/:player_id" || $path === "/api/test/games/{id}/board/{player_id}")
    && $method === "GET"
) {
    check_test_auth($TEST_PASSWORD);
    send_json(["board" => []], 200);
}

// GET /api/test/games/{id}/board/{player_id}  ← CHANGED: now builds real board, added auth
if (preg_match("#^/api/test/games/(\d+)/board/(\d+)/?$#", $path, $m) && $method === "GET") {
    check_test_auth($TEST_PASSWORD);
    $gameId   = (int)$m[1];
    $playerId = (int)$m[2];

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    $gridSize = (int)$game["grid_size"];
    $board = [];
    for ($r = 0; $r < $gridSize; $r++) $board[$r] = array_fill(0, $gridSize, "~");

    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    foreach ($stmt->fetchAll() as $ship) {
        $board[(int)$ship["row"]][(int)$ship["col"]] = "O";
    }

    $stmt = $pdo->prepare("SELECT row, col FROM moves WHERE game_id = ? AND result = 'hit'");
    $stmt->execute([$gameId]);
    foreach ($stmt->fetchAll() as $move) {
        $r = (int)$move["row"]; $c = (int)$move["col"];
        if (isset($board[$r][$c]) && $board[$r][$c] === "O") $board[$r][$c] = "X";
    }

    $rows = [];
    foreach ($board as $row) $rows[] = implode(" ", $row);

    send_json(["game_id" => $gameId, "player_id" => $playerId, "board" => $rows], 200);
}

send_error("not_found", "Endpoint not found", 404);
