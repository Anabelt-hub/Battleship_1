<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, X-Test-Password");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. DATABASE CONNECTION
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

// 2. HELPER FUNCTIONS
function send_json($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

function check_test_auth($TEST_PASSWORD) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    if (($headers["x-test-password"] ?? "") !== $TEST_PASSWORD) {
        send_json(["error" => "forbidden", "message" => "Invalid test password"], 403);
    }
}

// 3. ROUTING SETUP
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];

// Root Metadata
if ($path === "/" && $method === "GET") {
    send_json([
        "name" => "Battleship API",
        "version" => "2.3.0",
        "spec_version" => "2.3",
        "environment" => "production",
        "test_mode" => true
    ]);
}

// Version
if ($path === "/api/version" && $method === "GET") {
    send_json(["api_version" => "2.3.0", "spec_version" => "2.3"]);
}

// --- 4. PRODUCTION ENDPOINTS ---

// Create Player
if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $username = $body["username"] ?? "";
    
    if (!preg_match("/^[A-Za-z0-9_]+$/", $username) || strlen($username) < 1 || strlen($username) > 30) {
        send_json(["error" => "bad_request", "message" => "Invalid username format"], 400);
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

// Player Stats
if (preg_match("#^/api/players/(\d+)/stats/?$#", $path, $m) && $method === "GET") {
    $stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ?");
    $stmt->execute([(int)$m[1]]);
    $p = $stmt->fetch();
    if (!$p) send_json(["error" => "not_found", "message" => "Player not found"], 404);
    
    $shots = (int)$p["total_shots"];
    $hits = (int)$p["total_hits"];
    $wins = (int)$p["wins"];
    $losses = (int)$p["losses"];

    send_json([
        "games_played" => $wins + $losses,
        "wins" => $wins,
        "losses" => $losses,
        "total_shots" => $shots,
        "total_hits" => $hits,
        "accuracy" => $shots > 0 ? (float)round($hits / $shots, 3) : 0.0
    ]);
}

// Create Game
if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gridSize = (int)($body["grid_size"] ?? 10);
    $maxPlayers = (int)($body["max_players"] ?? 2);
    
    if ($gridSize < 5 || $gridSize > 15 || $maxPlayers < 2 || $maxPlayers > 10) {
        send_json(["error" => "bad_request", "message" => "Invalid parameters"], 400);
    }

    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?, ?, 'waiting_setup') RETURNING game_id");
    $stmt->execute([$gridSize, $maxPlayers]);
    $res = $stmt->fetch();
    send_json(["game_id" => (int)$res["game_id"], "status" => "waiting_setup"], 201);
}

// Join Game
if (preg_match("#^/api/games/(\d+)/join/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    $stmt = $pdo->prepare("SELECT max_players, status FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_json(["error" => "not_found", "message" => "Game not found"], 404);
    if ($g["status"] !== "waiting_setup") send_json(["error" => "bad_request", "message" => "Game already started"], 400);

    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ((int)$stmt->fetch()["cnt"] >= (int)$g["max_players"]) {
        send_json(["error" => "bad_request", "message" => "Game is full"], 400);
    }

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $playerId]);
    send_json(["status" => "joined"]);
}

// Game Detail
if (preg_match("#^/api/games/(\d+)/?$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_json(["error" => "not_found", "message" => "Game not found"], 404);

    $stmt = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmt->execute([$gameId]);
    $players = $stmt->fetchAll();

    $playerList = [];
    foreach ($players as $p) {
        $stmtS = $pdo->prepare("SELECT COUNT(*) as ships FROM ships s WHERE game_id = ? AND player_id = ? AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit')");
        $stmtS->execute([$gameId, $p["player_id"]]);
        $playerList[] = ["player_id" => (int)$p["player_id"], "ships_remaining" => (int)$stmtS->fetch()["ships"]];
    }

    $stmtM = $pdo->prepare("SELECT COUNT(*) as cnt FROM moves WHERE game_id = ?");
    $stmtM->execute([$gameId]);

    send_json([
        "game_id" => (int)$g["game_id"],
        "grid_size" => (int)$g["grid_size"],
        "status" => $g["status"],
        "players" => $playerList,
        "current_turn_player_id" => $g["current_turn_player_id"] ? (int)$g["current_turn_player_id"] : null,
        "total_moves" => (int)$stmtM->fetch()["cnt"]
    ]);
}

// Place Ships
if (preg_match("#^/api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $ships = $body["ships"] ?? [];

    $stmt = $pdo->prepare("SELECT status, max_players FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if ($g["status"] !== "waiting_setup") send_json(["error" => "forbidden", "message" => "Not in setup phase"], 403);

    $pdo->beginTransaction();
    foreach ($ships as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")->execute([$gameId, $playerId, $s["row"], $s["col"]]);
    }

    // Auto-transition
    $stmtP = $pdo->prepare("SELECT COUNT(DISTINCT player_id) as cnt FROM ships WHERE game_id = ?");
    $stmtP->execute([$gameId]);
    if ((int)$stmtP->fetch()["cnt"] == (int)$g["max_players"]) {
        $stmtFirst = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
        $stmtFirst->execute([$gameId]);
        $firstId = $stmtFirst->fetch()["player_id"];
        $pdo->prepare("UPDATE games SET status = 'playing', current_turn_player_id = ? WHERE game_id = ?")->execute([$firstId, $gameId]);
    }
    $pdo->commit();
    send_json(["status" => "placed"]);
}

// Fire Shot
if (preg_match("#^/api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $r = (int)($body["row"] ?? 0);
    $c = (int)($body["col"] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();

    if ($g["status"] === "finished") send_json(["error" => "bad_request", "message" => "Game finished"], 400);
    if ((int)$g["current_turn_player_id"] !== $playerId) send_json(["error" => "forbidden", "message" => "Not your turn"], 403);

    $stmtDup = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND row = ? AND col = ?");
    $stmtDup->execute([$gameId, $r, $c]);
    if ($stmtDup->fetch()) send_json(["error" => "conflict", "message" => "Cell already fired upon"], 409);

    $stmtHit = $pdo->prepare("SELECT player_id FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmtHit->execute([$gameId, $playerId, $r, $c]);
    $hit = $stmtHit->fetch();
    $result = $hit ? "hit" : "miss";

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")->execute([$gameId, $playerId, $r, $c, $result]);
    $pdo->prepare("UPDATE players SET total_shots = total_shots + 1, total_hits = total_hits + ? WHERE player_id = ?")->execute([($result === "hit" ? 1 : 0), $playerId]);

    // Determine Next Player
    $stmtNext = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? AND player_id > ? ORDER BY player_id ASC LIMIT 1");
    $stmtNext->execute([$gameId, $playerId]);
    $nextId = $stmtNext->fetch()["player_id"] ?? null;
    if (!$nextId) {
        $stmtReset = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
        $stmtReset->execute([$gameId]);
        $nextId = $stmtReset->fetch()["player_id"];
    }

    // Win Logic
    $stmtActive = $pdo->prepare("SELECT player_id FROM game_players gp WHERE game_id = ? AND EXISTS (SELECT 1 FROM ships s WHERE s.game_id = gp.game_id AND s.player_id = gp.player_id AND NOT EXISTS (SELECT 1 FROM moves m WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit'))");
    $stmtActive->execute([$gameId]);
    $activePlayers = $stmtActive->fetchAll();

    $gameStatus = "playing";
    $winnerId = null;
    if (count($activePlayers) === 1) {
        $gameStatus = "finished";
        $winnerId = (int)$activePlayers[0]["player_id"];
        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ?, current_turn_player_id = null WHERE game_id = ?")->execute([$winnerId, $gameId]);
        $pdo->prepare("UPDATE players SET wins = wins + 1 WHERE player_id = ?")->execute([$winnerId]);
    } else {
        $pdo->prepare("UPDATE games SET current_turn_player_id = ? WHERE game_id = ?")->execute([$nextId, $gameId]);
    }

    $pdo->commit();
    send_json(["result" => $result, "next_player_id" => ($gameStatus === "finished" ? null : (int)$nextId), "game_status" => $gameStatus, "winner_id" => $winnerId]);
}

// --- 5. TEST MODE ENDPOINTS ---

if (preg_match("#^/api/test/games/(\d+)/restart/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("UPDATE games SET status = 'waiting_setup', current_turn_player_id = null, winner_id = null WHERE game_id = ?")->execute([$gameId]);
    send_json(["status" => "reset"]);
}

send_json(["error" => "not_found", "message" => "Endpoint not found"], 404);
