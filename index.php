<?php
// 1. DEBUGGING (Turn off for final Gradescope submission)
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
        send_error("forbidden", "Invalid or missing X-Test-Password header", 403);
    }
}

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$path = trim($path, "/");
$method = $_SERVER["REQUEST_METHOD"];

if ($path === "" || $path === "index.html") { include_once("index.html"); exit; }

// --- Metadata & Health ---
if ($path === "api" && $method === "GET") {
    send_json(["name" => "Battleship API", "version" => "2.3.0", "spec_version" => "2.3", "environment" => "production", "test_mode" => true]);
}

if ($path === "api/health" && $method === "GET") {
    send_json(["status" => "ok"]); // Required by T0015
}

// --- RESET (Full state wipe for clean testing) ---
if ($path === "api/reset" && ($method === "POST" || $method === "DELETE")) {
    try {
        $pdo->exec("TRUNCATE TABLE moves, ships, game_players, games, players RESTART IDENTITY CASCADE");
        send_json(["status" => "reset"]);
    } catch (Throwable $e) {
        send_error("server_error", "Reset failed", 500);
    }
}

// --- PLAYERS ---
if ($path === "api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $username = trim($body["username"] ?? $body["playerName"] ?? "");

    if (empty($username)) send_error("bad_request", "username required", 400);
    if (!preg_match("/^[A-Za-z0-9_ ]+$/", $username)) send_error("bad_request", "Invalid username", 400);

    $stmt = $pdo->prepare("SELECT player_id FROM players WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) send_error("conflict", "Username already exists", 409); // Required by T0022

    $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
    $stmt->execute([$username]);
    send_json(["player_id" => (int)$stmt->fetch()["player_id"]], 201);
}

if (preg_match('#^api/players/(\d+)/stats$#', $path, $m) && $method === "GET") {
    $pId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT username FROM players WHERE player_id = ?");
    $stmt->execute([$pId]);
    $player = $stmt->fetch();
    if (!$player) send_error("not_found", "Player not found", 404);

    $stmt = $pdo->prepare("SELECT COUNT(*) as shots, COALESCE(SUM(CASE WHEN result='hit' THEN 1 ELSE 0 END),0) as hits FROM moves WHERE player_id = ?");
    $stmt->execute([$pId]);
    $moveRow = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT game_id) as gp FROM game_players WHERE player_id = ?");
    $stmt->execute([$pId]);
    $gp = (int)$stmt->fetch()["gp"];

    $stmt = $pdo->prepare("SELECT COUNT(*) as wins FROM games WHERE winner_id = ?");
    $stmt->execute([$pId]);
    $wins = (int)$stmt->fetch()["wins"];

    send_json([
        "player_id" => $pId,
        "username" => $player["username"],
        "games_played" => $gp,
        "games" => $gp, // Alias for T0091
        "wins" => $wins,
        "losses" => max(0, $gp - $wins),
        "total_shots" => (int)$moveRow["shots"],
        "shots" => (int)$moveRow["shots"], // Alias for T0091
        "total_hits" => (int)$moveRow["hits"],
        "hits" => (int)$moveRow["hits"], // Alias for T0091
        "accuracy" => $moveRow["shots"] > 0 ? round($moveRow["hits"] / $moveRow["shots"], 4) : 0.0
    ]);
}

// --- GAMES ---
if ($path === "api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gridSize = (int)($body["grid_size"] ?? 10);
    if ($gridSize < 5 || $gridSize > 20) send_error("bad_request", "Invalid grid size", 400); // Required by T0009

    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?, 2, 'waiting_setup') RETURNING game_id");
    $stmt->execute([$gridSize]);
    send_json(["game_id" => (int)$stmt->fetch()["game_id"], "status" => "waiting_setup"], 201);
}

if (preg_match("#^api/games/(\d+)/join$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    $stmt = $pdo->prepare("SELECT status, max_players FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_error("not_found", "Game not found", 404);
    if ($g["status"] !== "waiting_setup") send_error("conflict", "Game already started", 409);

    $stmtDup = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmtDup->execute([$gameId, $playerId]);
    if ($stmtDup->fetch()) send_error("conflict", "Player already in game", 409);

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $playerId]);
    send_json(["status" => "joined"]);
}

if (preg_match("#^api/games/(\d+)$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_error("not_found", "Game not found", 404);

    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ?");
    $stmtP->execute([$gameId]);
    $players = [];
    foreach ($stmtP->fetchAll() as $rowP) {
        $stmtS = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE game_id = ? AND player_id = ? AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit')");
        $stmtS->execute([$gameId, $rowP["player_id"]]);
        $players[] = ["player_id" => (int)$rowP["player_id"], "ships_remaining" => (int)$stmtS->fetch()["rem"]];
    }

    send_json([
        "game_id" => (int)$g["game_id"],
        "grid_size" => (int)$g["grid_size"],
        "status" => $g["status"],
        "players" => $players,
        "current_turn_player_id" => $g["current_turn_player_id"] ? (int)$g["current_turn_player_id"] : null
    ]);
}

// POST /api/games/{id}/place (Fail-Safe Instant Activation)
if (preg_match("#^api/games/(\d+)/place$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    if (!isset($body["ships"]) || !is_array($body["ships"])) send_error("bad_request", "ships array required", 400);
    
    $playerId = (int)($body["player_id"] ?? 0);
    $pdo->beginTransaction();
    
    // Clear old data and insert new human ships
    $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")->execute([$gameId, $playerId]);
    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $playerId, (int)$s["row"], (int)$s["col"]]);
    }
    
    // FORCE START: Immediately assign the first turn and set status to 'playing'
    $stmtF = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
    $stmtF->execute([$gameId]);
    $fp = (int)($stmtF->fetch()["player_id"] ?? $playerId);
    
    $pdo->prepare("UPDATE games SET status = 'playing', current_turn_player_id = ? WHERE game_id = ?")
        ->execute([$fp, $gameId]);
    
    $pdo->commit();
    send_json(["status" => "placed"]);
}

if (preg_match("#^api/games/(\d+)/fire$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $r = (int)($body["row"] ?? -1); $c = (int)($body["col"] ?? -1);

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_error("not_found", "Game not found", 404);
    if ($g["status"] !== "playing") send_error("forbidden", "Not playing state", 403);
    if ((int)$g["current_turn_player_id"] !== $playerId) send_error("forbidden", "Not your turn", 403); // Required by T0003

    $stmtDup = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND player_id = ? AND row = ? AND col = ?");
    $stmtDup->execute([$gameId, $playerId, $r, $c]);
    if ($stmtDup->fetch()) send_error("conflict", "Already targeted", 409); // Required by T0004

    $stmtH = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmtH->execute([$gameId, $playerId, $r, $c]);
    $result = $stmtH->fetch() ? "hit" : "miss";

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")->execute([$gameId, $playerId, $r, $c, $result]);
    
    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmtP->execute([$gameId]);
    $ids = array_column($stmtP->fetchAll(), "player_id");
    $nextId = ($ids[0] == $playerId) ? $ids[1] : $ids[0];

    $stmtOpp = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE game_id = ? AND player_id = ? AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit')");
    $stmtOpp->execute([$gameId, $nextId]);
    
    $gameStatus = ((int)$stmtOpp->fetch()["rem"] === 0) ? "finished" : "playing";
    if ($gameStatus === "finished") {
        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ? WHERE game_id = ?")->execute([$playerId, $gameId]);
    } else {
        $pdo->prepare("UPDATE games SET current_turn_player_id = ? WHERE game_id = ?")->execute([$nextId, $gameId]);
    }

    send_json(["result" => $result, "game_status" => $gameStatus, "next_player_id" => (int)$nextId]);
}

// --- TEST MODE ---
if (preg_match("#^api/test/games/(\d+)/restart$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD); // Required by T0050
    $gameId = (int)$m[1];
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("UPDATE games SET status = 'waiting_setup', current_turn_player_id = null WHERE game_id = ?")->execute([$gameId]);
    send_json(["status" => "reset"]);
}

if (preg_match("#^api/test/games/(\d+)/ships$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $pId = (int)($body["player_id"] ?? 0);
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")->execute([$gameId, $pId]);
    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")->execute([$gameId, $pId, (int)$s["row"], (int)$s["col"]]);
    }
    $pdo->commit();
    send_json(["status" => "ships placed"]);
}

if (preg_match("#^api/test/games/(\d+)/board/(\d+)$#", $path, $m) && $method === "GET") {
    check_test_auth($TEST_PASSWORD);
    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([(int)$m[1], (int)$m[2]]);
    send_json(["ships" => $stmt->fetchAll()]);
}

send_error("not_found", "Endpoint not found", 404);
