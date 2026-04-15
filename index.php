<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, X-Test-Password");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$host = getenv('DB_HOST');
$db = getenv('DB_NAME');
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
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
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

function check_test_auth($TEST_PASSWORD) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers["x-test-password"] ?? "") !== $TEST_PASSWORD) {
        send_error("forbidden", "Invalid or missing X-Test-Password header", 403);
    }
}

$requestUri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = trim(str_replace("/index.php", "", $requestUri), "/");
$method = $_SERVER["REQUEST_METHOD"];

if ($path === "" || $path === "index.html") {
    include_once("index.html");
    exit;
}

if ($path === "api" && $method === "GET") {
    send_json([
        "name" => "Battleship API",
        "version" => "2.3.0",
        "spec_version" => "2.3",
        "environment" => "production",
        "test_mode" => true
    ]);
}

if ($path === "api/health" && $method === "GET") {
    send_json(["status" => "ok"]);
}

if ($path === "api/reset" && ($method === "POST" || $method === "DELETE")) {
    $pdo->exec("TRUNCATE TABLE moves, ships, game_players, games, players RESTART IDENTITY CASCADE");
    send_json(["status" => "reset"]);
}

if ($path === "api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $username = trim($body["username"] ?? $body["playerName"] ?? "");

    if ($username === "") {
        send_error("bad_request", "username required", 400);
    }

    // 1. Check if the player already exists
    $stmt = $pdo->prepare("SELECT player_id FROM players WHERE username = ?");
    $stmt->execute([$username]);
    $existingPlayer = $stmt->fetch();

    if ($existingPlayer) {
        // SUCCESS: Return existing ID so stats and history persist
        send_json([
            "player_id" => (int)$existingPlayer["player_id"],
            "username" => $username,
            "message" => "Welcome back, Captain."
        ], 200);
        exit; // Stop execution here
    }

    // 2. Otherwise, create a new player (PostgreSQL RETURNING syntax)
    $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
    $stmt->execute([$username]);
    $newPlayer = $stmt->fetch();
    
    send_json([
        "player_id" => (int)$newPlayer["player_id"],
        "username" => $username
    ], 201);
}

if ($path === "api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gridSize = (int)($body["grid_size"] ?? 10);

    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?, 2, 'waiting_setup') RETURNING game_id");
    $stmt->execute([$gridSize]);

    send_json([
        "game_id" => (int)$stmt->fetch()["game_id"],
        "status" => "waiting_setup"
    ], 201);
}

if (preg_match("#^api/games/(\d+)/join$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")
        ->execute([$gameId, $playerId]);

    send_json(["status" => "joined"]);
}

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
        $players[] = [
            "player_id" => (int)$rowP["player_id"],
            "ships_remaining" => 3
        ];
    }

    send_json([
        "game_id" => (int)$g["game_id"],
        "status" => $g["status"],
        "players" => $players,
        "current_turn_player_id" => $g["current_turn_player_id"] ? (int)$g["current_turn_player_id"] : null
    ]);
}

if (preg_match("#^api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    if (!isset($body["ships"]) || !is_array($body["ships"])) {
        send_error("bad_request", "Invalid ships payload", 400);
    }

    $playerId = (int)($body["player_id"] ?? 0);

    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")
        ->execute([$gameId, $playerId]);

    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $playerId, (int)$s["row"], (int)$s["col"]]);
    }

    $stmtF = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
    $stmtF->execute([$gameId]);
    $fp = (int)($stmtF->fetch()["player_id"] ?? $playerId);

    $pdo->prepare("UPDATE games SET status = 'playing', current_turn_player_id = ? WHERE game_id = ?")
        ->execute([$fp, $gameId]);

    $pdo->commit();
    send_json(["status" => "placed"]);
}

// POST /api/games/{id}/fire
if (preg_match("#^api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $r = (int)$body["row"]; $c = (int)$body["col"];

    // 1. Record the move
    $stmtH = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmtH->execute([$gameId, $playerId, $r, $c]);
    $result = $stmtH->fetch() ? "hit" : "miss";

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")
        ->execute([$gameId, $playerId, $r, $c, $result]);
    
    // 2. CHECK IF OPPONENT HAS ANY SHIPS LEFT (Crucial Fix)
    // Find who the opponent is
    $stmtOpp = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? AND player_id != ? LIMIT 1");
    $stmtOpp->execute([$gameId, $playerId]);
    $oppId = $stmtOpp->fetch()["player_id"];

    // Count ships of the opponent that have NOT been hit
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) as rem 
        FROM ships s 
        WHERE s.game_id = ? AND s.player_id = ? 
        AND NOT EXISTS (
            SELECT 1 FROM moves m 
            WHERE m.game_id = s.game_id 
            AND m.row = s.row AND m.col = s.col 
            AND m.result = 'hit'
        )
    ");
    $stmtCheck->execute([$gameId, $oppId]);
    $remainingShips = (int)$stmtCheck->fetch()["rem"];

    // 3. Update status if game is over
    $gameStatus = ($remainingShips === 0) ? "finished" : "playing";
    
    if ($gameStatus === "finished") {
        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ? WHERE game_id = ?")
            ->execute([$playerId, $gameId]);
    }

    send_json([
        "result" => $result, 
        "game_status" => $gameStatus, 
        "next_player_id" => 0 // Simplified for Phase 2
    ]);
}

if (preg_match("#^api/test/games/(\d+)/restart$#", $path, $m)) {
    check_test_auth($TEST_PASSWORD);

    $pdo->prepare("UPDATE games SET status = 'waiting_setup' WHERE game_id = ?")
        ->execute([(int)$m[1]]);

    send_json(["status" => "reset"]);
}

if (preg_match("#^api/test/games/(\d+)/ships$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);

    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([(int)$m[1], (int)$body["player_id"], (int)$s["row"], (int)$s["col"]]);
    }

    send_json(["status" => "ships placed"]);
}

if (preg_match("#^api/test/games/(\d+)/board/(\d+)$#", $path, $m) && $method === "GET") {
    check_test_auth($TEST_PASSWORD);

    $gameId = (int)$m[1];
    $playerId = (int)$m[2];

    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ? ORDER BY row, col");
    $stmt->execute([$gameId, $playerId]);

    $ships = [];
    foreach ($stmt->fetchAll() as $row) {
        $ships[] = [
            "row" => (int)$row["row"],
            "col" => (int)$row["col"]
        ];
    }

    send_json([
        "game_id" => $gameId,
        "player_id" => $playerId,
        "ships" => $ships
    ]);
}

if (preg_match('#^api/players/(\d+)/stats$#', $path, $m) && $method === "GET") {
    $pId = (int)$m[1];
    
    // 1. Get Wins (Cast to int)
    $stmtW = $pdo->prepare("SELECT COUNT(*) as wins FROM games WHERE winner_id = ? AND status = 'finished'");
    $stmtW->execute([$pId]);
    $wins = (int)$stmtW->fetch()["wins"];

    // 2. Get Losses (Cast to int)
    $stmtL = $pdo->prepare("
        SELECT COUNT(*) as losses 
        FROM games g
        JOIN game_players gp ON g.game_id = gp.game_id
        WHERE gp.player_id = ? 
        AND g.status = 'finished' 
        AND (g.winner_id != ? OR g.winner_id IS NULL)
    ");
    $stmtL.execute([$pId, $pId]);
    $losses = (int)$stmtL->fetch()["losses"];

    // 3. Get Shots & Hits
    $stmtA = $pdo->prepare("SELECT COUNT(*) as shots, SUM(CASE WHEN result='hit' THEN 1 ELSE 0 END) as hits FROM moves WHERE player_id = ?");
    $stmtA->execute([$pId]);
    $res = $stmtA->fetch();
    $shots = (int)($res["shots"] ?? 0);
    $hits = (int)($res["hits"] ?? 0);
    
    // Calculate Accuracy as a float
    $accuracy = $shots > 0 ? (float)($hits / $shots) : 0.0;

    send_json([
        "player_id" => $pId,
        "wins" => $wins,
        "losses" => $losses,
        "total_shots" => $shots,
        "accuracy" => $accuracy
    ]);
}
send_error("not_found", "Endpoint not found", 404);
