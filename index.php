<?php
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

// Create Player
if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $username = trim($body["username"] ?? "");
    if (!preg_match("/^[A-Za-z0-9_ ]+$/", $username)) send_error("bad_request", "Invalid username", 400);

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

// Create Game
if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gridSize = (int)($body["grid_size"] ?? 10);
    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?, 2, 'waiting_setup') RETURNING game_id");
    $stmt->execute([$gridSize]);
    send_json(["game_id" => (int)$stmt->fetch()["game_id"], "status" => "waiting_setup"], 201);
}

// Join Game
if (preg_match("#^/api/games/(\d+)/join/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $playerId]);
    send_json(["status" => "joined"]);
}

// Place Ships
if (preg_match("#^/api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $pdo->beginTransaction();
    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")->execute([$gameId, $playerId, $s["row"], $s["col"]]);
    }
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT player_id) as c FROM ships WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ((int)$stmt->fetch()["c"] >= 2) {
        $pdo->prepare("UPDATE games SET status = 'playing' WHERE game_id = ?")->execute([$gameId]);
    }
    $pdo->commit();
    send_json(["status" => "placed"]);
}

// Fire
if (preg_match("#^/api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $r = (int)$body["row"]; $c = (int)$body["col"];

    $stmt = $pdo->prepare("SELECT ship_id FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmt->execute([$gameId, $playerId, $r, $c]);
    $result = $stmt->fetch() ? "hit" : "miss";

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")->execute([$gameId, $playerId, $r, $c, $result]);
    
    // Check if game ended
    $stmt = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE game_id = ? AND player_id != ? AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit')");
    $stmt->execute([$gameId, $playerId]);
    $gameStatus = ((int)$stmt->fetch()["rem"] === 0) ? "finished" : "playing";
    if ($gameStatus === "finished") {
        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ? WHERE game_id = ?")->execute([$playerId, $gameId]);
    }

    send_json(["result" => $result, "game_status" => $gameStatus, "next_player_id" => 0]);
}

// Restart (Test Mode)
if (preg_match("#^/api/test/games/(\d+)/restart/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("UPDATE games SET status = 'waiting_setup' WHERE game_id = ?")->execute([$gameId]);
    send_json(["status" => "reset"]);
}

// Ships (Test Mode)
if (preg_match("#^/api/test/games/(\d+)/ships/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $pId = (int)$body["player_id"];
    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")->execute([$gameId, $pId, $s["row"], $s["col"]]);
    }
    send_json(["status" => "ships placed"]);
}

// Board (Test Mode)
if (preg_match("#^/api/test/games/(\d+)/board/(\d+)/?$#", $path, $m) && $method === "GET") {
    check_test_auth($TEST_PASSWORD);
    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([(int)$m[1], (int)$m[2]]);
    send_json(["ships" => $stmt->fetchAll()]);
}

send_error("not_found", "Not found", 404);
