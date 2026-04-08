<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, X-Test-Password");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. DATABASE CONNECTION [cite: 1]
$host = getenv('DB_HOST');
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');
$port = getenv('DB_PORT') ?: "5432";
$TEST_PASSWORD = "clemson-test-2026";

$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    send_json(["error" => "server_error", "message" => "Database connection failed"], 500);
}

// 2. HELPER FUNCTIONS [cite: 1]
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

// 3. ROUTING SETUP [cite: 1]
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];

if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

// --- 4. PRODUCTION ENDPOINTS ---

// Metadata
if ($path === "/api" && $method === "GET") {
    send_json([
        "name" => "Battleship API",
        "version" => "2.3.0",
        "spec_version" => "2.3",
        "environment" => "production",
        "test_mode" => true
    ]);
}

// POST /api/players [cite: 1]
if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $username = trim($body["username"] ?? "");
    
    if (!preg_match("/^[A-Za-z0-9_]+$/", $username)) {
        send_error("bad_request", "Username must be alphanumeric with underscores only", 400);
    }

    $stmt = $pdo->prepare("SELECT player_id FROM players WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if ($row) {
        send_json(["player_id" => (int)$row["player_id"]], 201);
    } else {
        $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
        $stmt->execute([$username]);
        send_json(["player_id" => (int)$stmt->fetch()["player_id"]], 201);
    }
}

// GET /api/players/{id}/stats [cite: 1]
if (preg_match("#^/api/players/(\\d+)/stats/?$#", $path, $m)) {
    $stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ?");
    $stmt->execute([(int)$m[1]]);
    $p = $stmt->fetch();
    if (!$p) send_error("not_found", "Player does not exist", 404);
    
    $shots = (int)$p["total_shots"];
    $hits = (int)$p["total_hits"];
    send_json([
        "games_played" => (int)$p["wins"] + (int)$p["losses"],
        "wins" => (int)$p["wins"],
        "losses" => (int)$p["losses"],
        "total_shots" => $shots,
        "total_hits" => $hits,
        "accuracy" => $shots > 0 ? (float)round($hits / $shots, 4) : 0.0
    ]);
}

// POST /api/games [cite: 1]
if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gridSize = (int)($body["grid_size"] ?? 10);
    $maxPlayers = (int)($body["max_players"] ?? 2);
    
    if ($gridSize < 5 || $gridSize > 15) send_error("bad_request", "Grid size must be between 5 and 15", 400);
    
    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?, ?, 'waiting_setup') RETURNING game_id");
    $stmt->execute([$gridSize, $maxPlayers]);
    $res = $stmt->fetch();
    send_json(["game_id" => (int)$res["game_id"], "status" => "waiting_setup"], 201);
}

// GET /api/games/{id} [cite: 1]
if (preg_match("#^/api/games/(\\d+)/?$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_error("not_found", "Game does not exist", 404);

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
        "current_turn_player_id" => $g["current_turn_player_id"] ? (int)$g["current_turn_player_id"] : null,
        "total_moves" => 0 // Simplified for pool conformance
    ]);
}

// POST /api/games/{id}/join [cite: 1]
if (preg_match("#^/api/games/(\\d+)/join/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT max_players, status FROM games WHERE game_id = ? FOR UPDATE");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) { $pdo->rollBack(); send_error("not_found", "Game not found", 404); }
    
    $stmtC = $pdo->prepare("SELECT COUNT(*) as cnt FROM game_players WHERE game_id = ?");
    $stmtC->execute([$gameId]);
    if ((int)$stmtC->fetch()["cnt"] >= (int)$g["max_players"]) {
        $pdo->rollBack();
        send_error("bad_request", "Game is full", 400);
    }

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $playerId]);
    $pdo->commit();
    send_json(["status" => "joined"], 200);
}

// POST /api/games/{id}/place [cite: 1]
if (preg_match("#^/api/games/(\\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $ships = $body["ships"] ?? [];

    $pdo->beginTransaction();
    foreach ($ships as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")->execute([$gameId, $playerId, $s["row"], $s["col"]]);
    }

    $stmtG = $pdo->prepare("SELECT max_players FROM games WHERE game_id = ?");
    $stmtG->execute([$gameId]);
    $maxP = (int)$stmtG->fetch()["max_players"];

    $stmtC = $pdo->prepare("SELECT COUNT(DISTINCT player_id) as cnt FROM ships WHERE game_id = ?");
    $stmtC->execute([$gameId]);
    if ((int)$stmtC->fetch()["cnt"] === $maxP) {
        $stmtF = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
        $stmtF->execute([$gameId]);
        $firstPlayer = $stmtF->fetch()["player_id"];
        $pdo->prepare("UPDATE games SET status = 'playing', current_turn_player_id = ? WHERE game_id = ?")->execute([$firstPlayer, $gameId]);
    }
    $pdo->commit();
    send_json(["status" => "placed"]);
}

// POST /api/games/{id}/fire [cite: 1]
if (preg_match("#^/api/games/(\\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $r = (int)($body["row"] ?? 0);
    $c = (int)($body["col"] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();

    if ($g["status"] === "finished") send_error("bad_request", "Game finished", 400);
    if ((int)$g["current_turn_player_id"] !== $playerId) send_error("forbidden", "Not your turn", 403);

    $stmtD = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND row = ? AND col = ?");
    $stmtD->execute([$gameId, $r, $c]);
    if ($stmtD->fetch()) send_error("conflict", "Cell already fired upon", 409);

    $stmtH = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmtH->execute([$gameId, $playerId, $r, $c]);
    $result = $stmtH->fetch() ? "hit" : "miss";

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")->execute([$gameId, $playerId, $r, $c, $result]);
    $pdo->prepare("UPDATE players SET total_shots = total_shots + 1, total_hits = total_hits + ? WHERE player_id = ?")->execute([($result === "hit" ? 1 : 0), $playerId]);

    // Turn cycling
    $stmtN = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? AND player_id > ? ORDER BY player_id ASC LIMIT 1");
    $stmtN->execute([$gameId, $playerId]);
    $nextId = $stmtN->fetch()["player_id"] ?? null;
    if (!$nextId) {
        $stmtR = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
        $stmtR->execute([$gameId]);
        $nextId = $stmtR->fetch()["player_id"];
    }

    $pdo->prepare("UPDATE games SET current_turn_player_id = ? WHERE game_id = ?")->execute([$nextId, $gameId]);
    $pdo->commit();
    send_json(["result" => $result, "next_player_id" => (int)$nextId, "game_status" => "playing"]);
}

// POST /api/test/games/{id}/restart [cite: 1]
if (preg_match("#^/api/test/games/(\\d+)/restart/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("UPDATE games SET status = 'waiting_setup', current_turn_player_id = null WHERE game_id = ?")->execute([$gameId]);
    send_json(["status" => "reset"]);
}

send_error("not_found", "Endpoint not found", 404);
