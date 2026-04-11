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


// ── POST /api/players ─────────────────────────────────────────────────────────
if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $username = trim($body["username"] ?? "");

    if ($username === "") {
        send_error("bad_request", "username is required", 400);
    }
    if (!preg_match("/^[A-Za-z0-9_ ]+$/", $username)) {
        send_error("bad_request", "Invalid username", 400);
    }

    // Reject duplicate usernames with 409
    $stmt = $pdo->prepare("SELECT player_id FROM players WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row) {
        // Return 409 for duplicate username
        send_json(["error" => "conflict", "message" => "Username already exists", "player_id" => (int)$row["player_id"]], 409);
    } else {
        $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
        $stmt->execute([$username]);
        send_json(["player_id" => (int)$stmt->fetch()["player_id"]], 201);
    }
}

// ── GET /api/players/{id}/stats ───────────────────────────────────────────────
if (preg_match("#^/api/players/(\d+)/stats/?$#", $path, $m) && $method === "GET") {
    $playerId = (int)$m[1];

    // Check player exists
    $stmt = $pdo->prepare("SELECT player_id, username FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
    if (!$player) {
        send_error("not_found", "Player not found", 404);
    }

    // Total shots fired
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM moves WHERE player_id = ?");
    $stmt->execute([$playerId]);
    $totalShots = (int)$stmt->fetch()["total"];

    // Hits
    $stmt = $pdo->prepare("SELECT COUNT(*) as hits FROM moves WHERE player_id = ? AND result = 'hit'");
    $stmt->execute([$playerId]);
    $hits = (int)$stmt->fetch()["hits"];

    $misses = $totalShots - $hits;
    $accuracy = $totalShots > 0 ? round($hits / $totalShots, 2) : 0.0;

    // Games played (games where this player participated)
    $stmt = $pdo->prepare("SELECT COUNT(*) as games FROM game_players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    $gamesPlayed = (int)$stmt->fetch()["games"];

    // Wins
    $stmt = $pdo->prepare("SELECT COUNT(*) as wins FROM games WHERE winner_id = ?");
    $stmt->execute([$playerId]);
    $wins = (int)$stmt->fetch()["wins"];

    send_json([
        "player_id"   => $playerId,
        "username"    => $player["username"],
        "games_played"=> $gamesPlayed,
        "wins"        => $wins,
        "total_shots" => $totalShots,
        "hits"        => $hits,
        "misses"      => $misses,
        "accuracy"    => $accuracy
    ]);
}

// ── POST /api/games ───────────────────────────────────────────────────────────
if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    // Require grid_size field
    if (!isset($body["grid_size"])) {
        send_error("bad_request", "grid_size is required", 400);
    }

    $gridSize = (int)$body["grid_size"];

    if ($gridSize < MIN_GRID_SIZE || $gridSize > MAX_GRID_SIZE) {
        send_error("bad_request", "grid_size must be between " . MIN_GRID_SIZE . " and " . MAX_GRID_SIZE, 400);
    }

    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?, 2, 'waiting_setup') RETURNING game_id");
    $stmt->execute([$gridSize]);
    $gameId = (int)$stmt->fetch()["game_id"];
    send_json(["game_id" => $gameId, "grid_size" => $gridSize, "status" => "waiting_setup"], 201);
}

// ── POST /api/games/{id}/join ─────────────────────────────────────────────────
if (preg_match("#^/api/games/(\d+)/join/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    // Check game exists
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }

    // Check player exists
    $stmt = $pdo->prepare("SELECT player_id FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    if (!$stmt->fetch()) {
        send_error("not_found", "Player not found", 404);
    }

    // Check game is still in waiting_setup state
    if ($game["status"] !== "waiting_setup") {
        send_error("conflict", "Game has already started", 409);
    }

    // Check for duplicate join
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ($stmt->fetch()) {
        send_error("conflict", "Player already joined this game", 409);
    }

    // Check if game is full (max_players)
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $count = (int)$stmt->fetch()["cnt"];
    $maxPlayers = (int)($game["max_players"] ?? 2);
    if ($count >= $maxPlayers) {
        send_error("conflict", "Game is already full", 409);
    }

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $playerId]);
    send_json(["status" => "joined"]);
}

// ── GET /api/games/{id} ───────────────────────────────────────────────────────
if (preg_match("#^/api/games/(\d+)/?$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();

    if (!$g) {
        send_error("not_found", "Game $gameId not found", 404);
    }

    $turnPlayerId = array_key_exists("current_turn_player_id", $g) ? $g["current_turn_player_id"] : null;

    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ?");
    $stmtP->execute([$gameId]);
    $players = [];
    foreach ($stmtP->fetchAll() as $rowP) {
        $stmtS = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE game_id = ? AND player_id = ? AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit')");
        $stmtS->execute([$gameId, $rowP["player_id"]]);
        $players[] = ["player_id" => (int)$rowP["player_id"], "ships_remaining" => (int)$stmtS->fetch()["rem"]];
    }

    send_json([
        "game_id"               => (int)$g["game_id"],
        "grid_size"             => (int)$g["grid_size"],
        "status"                => $g["status"],
        "players"               => $players,
        "current_turn_player_id"=> $turnPlayerId ? (int)$turnPlayerId : null,
        "total_moves"           => 0
    ]);
}

// ── GET /api/games/{id}/moves ─────────────────────────────────────────────────
if (preg_match("#^/api/games/(\d+)/moves/?$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];

    // Check game exists
    $stmt = $pdo->prepare("SELECT game_id FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if (!$stmt->fetch()) {
        send_error("not_found", "Game not found", 404);
    }

    $stmt = $pdo->prepare("SELECT move_id, player_id, row, col, result FROM moves WHERE game_id = ? ORDER BY move_id ASC");
    $stmt->execute([$gameId]);
    $moves = $stmt->fetchAll();

    // Cast types
    $out = [];
    foreach ($moves as $mv) {
        $out[] = [
            "move_id"   => (int)$mv["move_id"],
            "player_id" => (int)$mv["player_id"],
            "row"       => (int)$mv["row"],
            "col"       => (int)$mv["col"],
            "result"    => $mv["result"]
        ];
    }

    send_json(["game_id" => $gameId, "moves" => $out]);
}

// ── POST /api/games/{id}/place ────────────────────────────────────────────────
if (preg_match("#^/api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    if (!isset($body["ships"]) || !is_array($body["ships"])) {
        send_error("bad_request", "Invalid ships payload");
    }

    $playerId = (int)($body["player_id"] ?? 0);

    // Check game exists
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }

    $gridSize = (int)$game["grid_size"];

    // Reject second placement by same player (409)
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ((int)$stmt->fetch()["cnt"] > 0) {
        send_error("conflict", "Ships already placed for this player", 409);
    }

    // Validate coordinates
    $seen = [];
    foreach ($body["ships"] as $s) {
        $r = (int)$s["row"]; $c = (int)$s["col"];
        if ($r < 0 || $r >= $gridSize || $c < 0 || $c >= $gridSize) {
            send_error("bad_request", "Ship coordinate out of bounds", 400);
        }
        $key = "$r,$c";
        if (isset($seen[$key])) {
            send_error("bad_request", "Duplicate ship coordinates", 400);
        }
        $seen[$key] = true;
    }

    // Require at least 1 ship
    if (count($body["ships"]) === 0) {
        send_error("bad_request", "Must place at least one ship", 400);
    }

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")->execute([$gameId, $playerId]);
    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $playerId, (int)$s["row"], (int)$s["col"]]);
    }

    // Start game if both players have placed ships
    $stmtF = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
    $stmtF->execute([$gameId]);
    $fp = (int)($stmtF->fetch()["player_id"] ?? $playerId);
    $pdo->prepare("UPDATE games SET status = 'playing', current_turn_player_id = ? WHERE game_id = ?")
        ->execute([$fp, $gameId]);

    $pdo->commit();
    send_json(["status" => "placed"]);
}

// ── POST /api/games/{id}/fire ─────────────────────────────────────────────────
if (preg_match("#^/api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $r = (int)($body["row"] ?? -1);
    $c = (int)($body["col"] ?? -1);

    // Check game exists
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }

    $gridSize = (int)$game["grid_size"];

    // Reject if game not in playing state
    if ($game["status"] !== "playing") {
        send_error("bad_request", "Game is not in playing state", 400);
    }

    // Reject out-of-bounds
    if ($r < 0 || $r >= $gridSize || $c < 0 || $c >= $gridSize) {
        send_error("bad_request", "Coordinates out of bounds", 400);
    }

    // Reject out-of-turn
    $currentTurn = $game["current_turn_player_id"] ?? null;
    if ($currentTurn !== null && (int)$currentTurn !== $playerId) {
        send_error("forbidden", "It is not your turn", 403);
    }

    // Reject duplicate fire
    $stmtD = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND player_id = ? AND row = ? AND col = ?");
    $stmtD->execute([$gameId, $playerId, $r, $c]);
    if ($stmtD->fetch()) {
        send_error("conflict", "Already fired at this coordinate", 409);
    }

    // Determine hit or miss
    $stmtH = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmtH->execute([$gameId, $playerId, $r, $c]);
    $result = $stmtH->fetch() ? "hit" : "miss";

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")
        ->execute([$gameId, $playerId, $r, $c, $result]);

    // Check for active players (players with remaining ships)
    $stmt = $pdo->prepare("SELECT player_id FROM game_players gp WHERE game_id = ? AND EXISTS (SELECT 1 FROM ships s WHERE s.game_id = gp.game_id AND s.player_id = gp.player_id AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit'))");
    $stmt->execute([$gameId]);
    $active = $stmt->fetchAll();

    $gameStatus = (count($active) <= 1) ? "finished" : "playing";
    $winnerId = null;
    $nextPlayerId = 0;

    if ($gameStatus === "finished") {
        $winnerId = count($active) === 1 ? (int)$active[0]["player_id"] : $playerId;
        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ?, current_turn_player_id = NULL WHERE game_id = ?")
            ->execute([$winnerId, $gameId]);
    } else {
        // Alternate turn to the other player
        $stmtPlayers = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? AND player_id != ? LIMIT 1");
        $stmtPlayers->execute([$gameId, $playerId]);
        $nextRow = $stmtPlayers->fetch();
        $nextPlayerId = $nextRow ? (int)$nextRow["player_id"] : $playerId;
        $pdo->prepare("UPDATE games SET current_turn_player_id = ? WHERE game_id = ?")
            ->execute([$nextPlayerId, $gameId]);
    }

    send_json([
        "result"         => $result,
        "game_status"    => $gameStatus,
        "next_player_id" => $nextPlayerId
    ]);
}

// ── POST /api/test/games/{id}/restart ─────────────────────────────────────────
if (preg_match("#^/api/test/games/(\d+)/restart/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("UPDATE games SET status = 'waiting_setup', current_turn_player_id = null WHERE game_id = ?")->execute([$gameId]);
    send_json(["status" => "reset"]);
}

// ── POST /api/test/games/{id}/ships ───────────────────────────────────────────
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

// ── GET /api/test/games/{id}/board/{player_id} ────────────────────────────────
if (preg_match("#^/api/test/games/(\d+)/board/(\d+)/?$#", $path, $m) && $method === "GET") {
    check_test_auth($TEST_PASSWORD);
    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([(int)$m[1], (int)$m[2]]);
    send_json(["ships" => $stmt->fetchAll()]);
}

send_error("not_found", "Endpoint not found", 404);
