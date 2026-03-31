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
    send_json(["error" => "Database connection failed"], 500);
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
        send_json(["error" => "Forbidden"], 403);
    }
}

// 3. ROUTING SETUP
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];

if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

// --- 4. PRODUCTION ENDPOINTS ---

// POST /api/reset
if ($path === "/api/reset" && $method === "POST") {
    // Reset game state ONLY — keep players + stats
    $pdo->exec("TRUNCATE games, game_players, ships, moves RESTART IDENTITY CASCADE");
    send_json(["status" => "reset"]);
}

// POST /api/players
if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    if (isset($body["player_id"])) {
        send_json(["error" => "player_id must not be supplied by client"], 400);
    }

    $baseUsername = trim($body["username"] ?? "");
    if ($baseUsername === "") {
        $baseUsername = "player_" . bin2hex(random_bytes(4));
    }

    $username = $baseUsername;
    $suffix = 1;

    while (true) {
        $stmt = $pdo->prepare("SELECT 1 FROM players WHERE username = ?");
        $stmt->execute([$username]);

        if (!$stmt->fetch()) {
            break;
        }

        $username = $baseUsername . "_" . $suffix;
        $suffix++;
    }

    $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    send_json([
        "player_id" => (int)$row["player_id"],
        "username"  => $username
    ], 201);
}

// GET /api/players/{id}/stats
if (preg_match("#^/api/players/(\d+)/stats/?$#", $path, $m) && $method === "GET") {
    $stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ?");
    $stmt->execute([(int)$m[1]]);
    $p = $stmt->fetch();
    if (!$p) send_json(["error" => "Player not found"], 404);
    
    $shots  = (int)$p["total_shots"];
    $hits   = (int)$p["total_hits"];
    $wins   = (int)$p["wins"];
    $losses = (int)$p["losses"];
    send_json([
        "games_played" => $wins + $losses,
        "wins"         => $wins,
        "losses"       => $losses,
        "total_shots"  => $shots,
        "total_hits"   => $hits,
        "accuracy"     => $shots > 0 ? round($hits / $shots, 4) : 0.0
    ]);
}

// POST /api/games
if ($path === "/api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gridSize = (int)($body["grid_size"] ?? 10);
    $maxPlayers = (int)($body["max_players"] ?? 2);
    if ($gridSize < 5 || $gridSize > 15) send_json(["error" => "Invalid grid size"], 400);
    
    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players) VALUES (?, ?) RETURNING game_id");
    $stmt->execute([$gridSize, $maxPlayers]);
    send_json(["game_id" => (int)$stmt->fetch()["game_id"]], 201);
}

// GET /api/games/{id}
if (preg_match("#^/api/games/(\d+)/?$#", $path, $m) && $method === "GET") {
    $stmt = $pdo->prepare("SELECT game_id, grid_size, status FROM games WHERE game_id = ?");
    $stmt->execute([(int)$m[1]]);
    $game = $stmt->fetch();
    if (!$game) send_json(["error" => "Game not found"], 404);
    $game["game_id"] = (int)$game["game_id"];
    $game["current_turn_index"] = 0; 
    $game["active_players"] = 2; 
    send_json($game);
}

// POST /api/games/{id}/join
if (preg_match("#^/api/games/(\d+)/join/?$#", $path, $m) && $method === "POST") {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    if ($playerId <= 0) send_json(["error" => "player_id required"], 400);

    // Fetch game + current player count atomically
    $stmt = $pdo->prepare("
        SELECT g.max_players, g.status,
               COUNT(gp.player_id) AS cnt
        FROM games g
        LEFT JOIN game_players gp ON gp.game_id = g.game_id
        WHERE g.game_id = ?
        GROUP BY g.max_players, g.status
    ");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_json(["error" => "Game not found"], 404);

    if ($g["status"] === "finished") send_json(["error" => "Game over"], 409);

    // Clamp max_players: only trust values 2-10, else default to 2
    $maxPlayers = (int)$g["max_players"];
    if ($maxPlayers < 2 || $maxPlayers > 10) $maxPlayers = 2;

    $cnt = (int)$g["cnt"];

    // Capacity check
    if ($cnt >= $maxPlayers) {
        send_json(["error" => "Game is full"], 409);
    }

    // Duplicate check
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ($stmt->fetch()) {
        send_json(["error" => "Already joined"], 400);
    }

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")
        ->execute([$gameId, $playerId]);

    send_json(["status" => "joined"], 200);
}

// POST /api/games/{id}/place
if (preg_match("#^/api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $ships = $body["ships"] ?? [];
    if (count($ships) !== 3) send_json(["error" => "Exactly 3 ships required"], 400);
    
    $pdo->beginTransaction();
    foreach ($ships as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $playerId, $s["row"], $s["col"]]);
    }
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT player_id) as c FROM ships WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ($stmt->fetch()["c"] >= 2) {
        $pdo->prepare("UPDATE games SET status = 'active' WHERE game_id = ?")->execute([$gameId]);
    }
    $pdo->commit();
    send_json(["status" => "placed"]);
}

// POST /api/games/{id}/fire
if (preg_match("#^/api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $r = (int)($body["row"] ?? 0);
    $c = (int)($body["col"] ?? 0);

    $stmt = $pdo->prepare("SELECT status FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_json(["error" => "Game not found"], 404);
    if ($game["status"] === "finished") send_json(["error" => "Game over"], 409);
    
    // FIX: Also accept players who placed ships via test endpoint (in ships table)
    // Check game_players first, then fall back to ships table for test-injected players
    $stmt = $pdo->prepare(
        "SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?
         UNION
         SELECT 1 FROM ships WHERE game_id = ? AND player_id = ?
         LIMIT 1"
    );
    $stmt->execute([$gameId, $playerId, $gameId, $playerId]);
    if (!$stmt->fetch()) send_json(["error" => "Forbidden"], 403);

    // Check if this exact cell was already fired at by this player
    $stmt = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND player_id = ? AND row = ? AND col = ?");
    $stmt->execute([$gameId, $playerId, $r, $c]);
    if ($stmt->fetch()) send_json(["error" => "Already fired at this cell"], 409);

    // Check for a hit on any opponent's ship
    $stmt = $pdo->prepare("SELECT ship_id FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmt->execute([$gameId, $playerId, $r, $c]);
    $hit = $stmt->fetch();
    $result = $hit ? "hit" : "miss";

    $pdo->beginTransaction();

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")
        ->execute([$gameId, $playerId, $r, $c, $result]);

    $pdo->prepare("UPDATE players SET total_shots = total_shots + 1, total_hits = total_hits + ? WHERE player_id = ?")
        ->execute([($result === "hit" ? 1 : 0), $playerId]);

    // Count remaining unhit opponent ships:
    // All opponent ships minus those that have been hit by ANY move in this game
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as rem
        FROM ships s
        WHERE s.game_id = ?
          AND s.player_id != ?
          AND NOT EXISTS (
              SELECT 1 FROM moves m
              WHERE m.game_id = s.game_id
                AND m.row = s.row
                AND m.col = s.col
                AND m.result = 'hit'
          )
    ");
    $stmt->execute([$gameId, $playerId]);
    $rem = (int)$stmt->fetch()["rem"];

    $gameStatus = "active";
    $winnerId = null;

    if ($rem === 0) {
        $gameStatus = "finished";
        $winnerId = $playerId;

        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ? WHERE game_id = ?")
            ->execute([$playerId, $gameId]);

        $pdo->prepare("UPDATE players SET wins = wins + 1 WHERE player_id = ?")
            ->execute([$playerId]);

        // Record losses for all other players in the game
        $pdo->prepare(
            "UPDATE players SET losses = losses + 1
             WHERE player_id IN (
                 SELECT player_id FROM game_players WHERE game_id = ? AND player_id != ?
                 UNION
                 SELECT DISTINCT player_id FROM ships WHERE game_id = ? AND player_id != ?
             )"
        )->execute([$gameId, $playerId, $gameId, $playerId]);
    }

    $pdo->commit();
    send_json(["result" => $result, "game_status" => $gameStatus, "winner_id" => $winnerId]);
}

// --- 5. TEST MODE ENDPOINTS ---

if (preg_match("#^/api/test/games/(\d+)/restart/?$#", $path, $m)) {
    check_test_auth($TEST_PASSWORD);
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([(int)$m[1]]);
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([(int)$m[1]]);
    $pdo->prepare("UPDATE games SET status = 'waiting' WHERE game_id = ?")->execute([(int)$m[1]]);
    send_json(["status" => "restarted"]);
}

if (preg_match("#^/api/test/games/(\d+)/ships/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true);
    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, (int)$body["player_id"], $s["row"], $s["col"]]);
    }
    send_json(["status" => "ships placed"]);
}

if (preg_match("#^/api/test/games/(\d+)/board/(\d+)/?$#", $path, $m)) {
    check_test_auth($TEST_PASSWORD);
    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([(int)$m[1], (int)$m[2]]);
    send_json(["ships" => $stmt->fetchAll()]);
}

send_json(["error" => "Not found"], 404);
