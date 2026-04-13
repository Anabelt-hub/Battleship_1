<?php
// Enable strict error reporting for debugging starfleet systems
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, X-Test-Password");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$host = getenv('DB_HOST'); 
$db = getenv('DB_NAME');
$user = getenv('DB_USER'); 
$pass = getenv('DB_PASS');
$port = getenv('DB_PORT') ?: "5432";
$TEST_PASSWORD = "clemson-test-2026";

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db;sslmode=require", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode(["error" => "server_error", "message" => "DB connection failed"]);
    exit;
}

// --- HELPERS ---
function send_json($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

function send_error($error, $message, $status = 400) {
    send_json(["error" => $error, "message" => $message], $status);
}

function require_test_auth() {
    global $TEST_PASSWORD;
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers["x-test-password"] ?? "") !== $TEST_PASSWORD) {
        send_error("forbidden", "Invalid or missing X-Test-Password header", 403);
    }
}

// --- ROUTING ---
$path = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
$path = preg_replace('#^index\.php/?#', '', $path);
$method = $_SERVER["REQUEST_METHOD"];

if ($path === "" || $path === "index.html") {
    include_once("index.html");
    exit;
}

// GET /api - Metadata
if ($path === "api" && $method === "GET") {
    send_json([
        "name" => "Battleship API", 
        "version" => "2.3.0", 
        "spec_version" => "2.3",
        "environment" => "production", 
        "test_mode" => true
    ]);
}

// GET /api/health
if ($path === "api/health" && $method === "GET") {
    send_json(["status" => "ok"]);
}

// POST /api/reset - Wipe for clean state
if ($path === "api/reset" && ($method === "POST" || $method === "DELETE")) {
    try {
        $pdo->exec("TRUNCATE TABLE moves, ships, game_players, games, players RESTART IDENTITY CASCADE");
        send_json(["status" => "reset"]);
    } catch (Throwable $e) {
        send_error("server_error", "Reset failed", 500);
    }
}

// --- PLAYERS ---

// POST /api/players
if ($path === "api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $username = trim($body["username"] ?? $body["playerName"] ?? "");

    if ($username === "") send_error("bad_request", "Missing required field: username", 400);
    if (!preg_match('/^[A-Za-z0-9_ ]+$/', $username)) send_error("bad_request", "Invalid username", 400);

    $stmt = $pdo->prepare("SELECT player_id FROM players WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) send_error("conflict", "Username already exists", 409);

    $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
    $stmt->execute([$username]);
    send_json(["player_id" => (int)$stmt->fetch()["player_id"]], 201);
}

// GET /api/players/{id}/stats
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
        "games" => $gp, 
        "wins" => $wins,
        "losses" => max(0, $gp - $wins),
        "total_shots" => (int)$moveRow["shots"],
        "shots" => (int)$moveRow["shots"],
        "total_hits" => (int)$moveRow["hits"],
        "hits" => (int)$moveRow["hits"],
        "accuracy" => $moveRow["shots"] > 0 ? round($moveRow["hits"] / $moveRow["shots"], 4) : 0.0
    ]);
}

// --- GAMES ---

// POST /api/games
if ($path === "api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    if (!isset($body["grid_size"])) send_error("bad_request", "grid_size required", 400);

    $gridSize = (int)$body["grid_size"];
    if ($gridSize < 5 || $gridSize > 20) send_error("bad_request", "Invalid grid size", 400);

    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?, 2, 'waiting_setup') RETURNING game_id");
    $stmt->execute([$gridSize]);
    send_json([
        "game_id" => (int)$stmt->fetch()["game_id"], 
        "status" => "waiting_setup",
        "grid_size" => $gridSize
    ], 201);
}

// GET /api/games/{id}
if (preg_match('#^api/games/(\d+)$#', $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_error("not_found", "Game not found", 404);

    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmtP->execute([$gameId]);
    $playerRows = $stmtP->fetchAll();

    $players = [];
    foreach ($playerRows as $row) {
        $pid = (int)$row["player_id"];
        $stmtS = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE game_id = ? AND player_id = ? AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit')");
        $stmtS->execute([$gameId, $pid]);
        $players[] = ["player_id" => $pid, "ships_remaining" => (int)$stmtS->fetch()["rem"]];
    }

    send_json([
        "game_id" => (int)$g["game_id"],
        "grid_size" => (int)$g["grid_size"],
        "status" => $g["status"],
        "players" => $players,
        "current_turn_player_id" => $g["current_turn_player_id"] ? (int)$g["current_turn_player_id"] : null
    ]);
}

// POST /api/games/{id}/join
if (preg_match('#^api/games/(\d+)/join$#', $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $pId = (int)($body["player_id"] ?? 0);

    $stmt = $pdo->prepare("SELECT status, max_players FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_error("not_found", "Game not found", 404);
    if ($g["status"] !== "waiting_setup") send_error("conflict", "Game started", 409);

    $stmtDup = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmtDup->execute([$gameId, $pId]);
    if ($stmtDup->fetch()) send_error("conflict", "Already joined", 409);

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $pId]);
    send_json(["status" => "joined", "game_id" => $gameId, "player_id" => $pId]);
}

// POST /api/games/{id}/place
if (preg_match('#^api/games/(\d+)/place$#', $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $pId = (int)($body["player_id"] ?? 0);
    $ships = $body["ships"] ?? [];

    $stmt = $pdo->prepare("SELECT status FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ($stmt->fetch()["status"] !== "waiting_setup") send_error("conflict", "Placement closed", 409);

    $stmtDup = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id = ?");
    $stmtDup->execute([$gameId, $pId]);
    if ($stmtDup->fetch()) send_error("conflict", "Ships already placed", 409);

    if (count($ships) !== 3) send_error("bad_request", "Exactly 3 ships required", 400);

    $pdo->beginTransaction();
    foreach ($ships as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")->execute([$gameId, $pId, (int)$s["row"], (int)$s["col"]]);
    }

    // Auto-transition to playing
    $stmtC = $pdo->prepare("SELECT COUNT(DISTINCT player_id) as c FROM ships WHERE game_id = ?");
    $stmtC->execute([$gameId]);
    if ((int)$stmtC->fetch()["c"] >= 2) {
        $first = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
        $first->execute([$gameId]);
        $fp = (int)$first->fetch()["player_id"];
        $pdo->prepare("UPDATE games SET status='playing', current_turn_player_id=? WHERE game_id=?")->execute([$fp, $gameId]);
    }
    $pdo->commit();
    send_json(["status" => "placed"]);
}

// POST /api/games/{id}/fire
if (preg_match('#^api/games/(\d+)/fire$#', $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $pId = (int)($body["player_id"] ?? 0);
    $r = (int)($body["row"] ?? -1);
    $c = (int)($body["col"] ?? -1);

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_error("not_found", "Game not found", 404);
    if ($g["status"] !== "playing") send_error("forbidden", "Not playing state", 403);
    if ((int)$g["current_turn_player_id"] !== $pId) send_error("forbidden", "Not your turn", 403);

    $stmtDup = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND player_id = ? AND row = ? AND col = ?");
    $stmtDup->execute([$gameId, $pId, $r, $c]);
    if ($stmtDup->fetch()) send_error("conflict", "Already targeted", 409);

    $stmtH = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmtH->execute([$gameId, $pId, $r, $c]);
    $result = $stmtH->fetch() ? "hit" : "miss";

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")->execute([$gameId, $pId, $r, $c, $result]);

    // Check winner and rotate turn
    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmtP->execute([$gameId]);
    $ids = array_column($stmtP->fetchAll(), "player_id");
    
    // Check if opponent is defeated
    $oppId = ($ids[0] == $pId) ? $ids[1] : $ids[0];
    $stmtOpp = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE game_id = ? AND player_id = ? AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit')");
    $stmtOpp->execute([$gameId, $oppId]);
    
    $gameStatus = ((int)$stmtOpp->fetch()["rem"] === 0) ? "finished" : "playing";
    $nextId = ($gameStatus === "playing") ? $oppId : null;

    if ($gameStatus === "finished") {
        $pdo->prepare("UPDATE games SET status='finished', winner_id=?, current_turn_player_id=NULL WHERE game_id=?")->execute([$pId, $gameId]);
    } else {
        $pdo->prepare("UPDATE games SET current_turn_player_id=? WHERE game_id=?")->execute([$nextId, $gameId]);
    }

    send_json(["result" => $result, "game_status" => $gameStatus, "next_player_id" => $nextId]);
}

// --- TEST ENDPOINTS ---

// POST /api/test/games/{id}/restart
if (preg_match('#^api/test/games/(\d+)/restart$#', $path, $m) && $method === "POST") {
    require_test_auth();
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([(int)$m[1]]);
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([(int)$m[1]]);
    $pdo->prepare("UPDATE games SET status='waiting_setup', current_turn_player_id=NULL, winner_id=NULL WHERE game_id=?")->execute([(int)$m[1]]);
    send_json(["status" => "reset"]);
}

// GET /api/test/games/{id}/board/{player_id}
if (preg_match('#^api/test/games/(\d+)/board/(\d+)$#', $path, $m) && $method === "GET") {
    require_test_auth();
    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([(int)$m[1], (int)$m[2]]);
    send_json(["ships" => $stmt->fetchAll()]);
}

send_error("not_found", "Endpoint not found", 404);
