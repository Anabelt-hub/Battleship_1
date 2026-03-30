<?php
// index.php
$host = getenv('DB_HOST');
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

// The dsn now uses the variables you just set
$dsn = "pgsql:host=$host;port=5432;dbname=$db;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    send_json(["error" => "Database connection failed"], 500);
}

function send_json($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
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

if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

// --- 1. SYSTEM CONTROL ---

if ($path === "/api/reset" && $method === "POST") {
    // Truncate all tables except players to preserve lifetime stats
    $pdo->exec("TRUNCATE games, game_players, ships, moves RESTART IDENTITY CASCADE");
    send_json(["status" => "reset"]);
}

// --- 2. PLAYER ENDPOINTS ---

if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    
    // Addendum Rule: Reject client-supplied playerId
    if (isset($body["player_id"])) send_json(["error" => "player_id must not be supplied by client"], 400);
    
    $username = trim($body["username"] ?? "");
    if ($username === "") send_json(["error" => "username is required"], 400);

    // Addendum Rule: Reuse persistent identity across games
    $stmt = $pdo->prepare("SELECT player_id FROM players WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        send_json(["player_id" => (int)$existing["player_id"]], 200);
    } else {
        $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
        $stmt->execute([$username]);
        send_json(["player_id" => (int)$stmt->fetch(PDO::FETCH_ASSOC)["player_id"]], 201);
    }
}

if (preg_match("#^/api/players/(\d+)/stats$#", $path, $m)) {
    $stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ?");
    $stmt->execute([(int)$m[1]]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) send_json(["error" => "Player not found"], 404);
    
    $shots = (int)$p["total_shots"];
    $hits = (int)$p["total_hits"];
    // Accuracy Rule: accuracy = total_hits / total_shots
    send_json([
        "wins" => (int)$p["wins"],
        "losses" => (int)$p["losses"],
        "total_shots" => $shots,
        "total_hits" => $hits,
        "accuracy" => $shots > 0 ? round($hits / $shots, 3) : 0.0
    ]);
}

// --- 3. GAME LIFECYCLE ---

if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gridSize = (int)($body["grid_size"] ?? 10);
    $maxPlayers = (int)($body["max_players"] ?? 2);
    
    if ($gridSize < 5 || $gridSize > 15) send_json(["error" => "Invalid grid size"], 400);

    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players) VALUES (?, ?) RETURNING game_id");
    $stmt->execute([$gridSize, $maxPlayers]);
    send_json(["game_id" => (int)$stmt->fetch(PDO::FETCH_ASSOC)["game_id"]], 201);
}

if (preg_match("#^/api/games/(\d+)/join$#", $path, $m)) {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    try {
        // DB-Level Rule: Reject duplicate join in same game
        $stmt = $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)");
        $stmt->execute([$gameId, $playerId]);
        send_json(["status" => "joined"]);
    } catch (PDOException $e) {
        if ($e->getCode() == '23505') send_json(["error" => "Already joined"], 400);
        send_json(["error" => "Join failed"], 400);
    }
}

// --- 4. FIRE & WIN CONDITION ---

if (preg_match("#^/api/games/(\d+)/fire$#", $path, $m)) {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $r = (int)($body["row"] ?? 0);
    $c = (int)($body["col"] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Win Rule: Firing into a finished game returns 409
    if ($game["status"] === "finished") send_json(["error" => "Game over"], 409);

    // Identity Rule: Reject move with valid player_id but wrong game
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if (!$stmt->fetch()) send_json(["error" => "Forbidden - not in this game"], 403);

    // Hit Detection
    $stmt = $pdo->prepare("SELECT * FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmt->execute([$gameId, $playerId, $r, $c]);
    $hit = $stmt->fetch();
    $result = $hit ? "hit" : "miss";

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")
        ->execute([$gameId, $playerId, $r, $c, $result]);
    
    $pdo->prepare("UPDATE players SET total_shots = total_shots + 1, total_hits = total_hits + ? WHERE player_id = ?")
        ->execute([($result === "hit" ? 1 : 0), $playerId]);
    
    // Win Condition: Sinking all opponent ships
    $stmt = $pdo->prepare("SELECT COUNT(*) as remaining FROM ships s 
                           LEFT JOIN moves m ON s.row = m.row AND s.col = m.col AND s.game_id = m.game_id
                           WHERE s.game_id = ? AND s.player_id != ? AND m.move_id IS NULL");
    $stmt->execute([$gameId, $playerId]);
    
    $gameStatus = "active";
    $winnerId = null;
    if ($stmt->fetch(PDO::FETCH_ASSOC)["remaining"] == 0 && $result === "hit") {
        $gameStatus = "finished";
        $winnerId = $playerId;
        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ? WHERE game_id = ?")->execute([$playerId, $gameId]);
        $pdo->prepare("UPDATE players SET wins = wins + 1 WHERE player_id = ?")->execute([$playerId]);
    }
    
    $pdo->commit();
    send_json(["result" => $result, "game_status" => $gameStatus, "winner_id" => $winnerId]);
}

// --- 5. TEST MODE ENDPOINTS ---

if (preg_match("#^/api/test/games/(\d+)/restart$#", $path, $m)) {
    check_test_auth($TEST_PASSWORD);
    $gId = (int)$m[1];
    // Restart Rule: Clears ships and moves; stats remain unchanged
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gId]);
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([$gId]);
    $pdo->prepare("UPDATE games SET status = 'waiting' WHERE game_id = ?")->execute([$gId]);
    send_json(["status" => "restarted"]);
}

send_json(["error" => "Not found"], 404);
