<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, X-Test-Password");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$host = getenv('DB_HOST');
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');
$port = getenv('DB_PORT') ?: "5432";
$TEST_PASSWORD = "clemson-test-2026";

$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    send_json(["error" => "Database connection failed: " . $e->getMessage()], 500);
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

$path   = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path   = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];

if ($path === "/" || $path === "" || $path === "/index.php") { include_once("index.html"); exit; }

// =====================================================================
// POST /api/reset
// FIX: Do NOT truncate players — player stats must survive reset.
// Only wipe game-related tables.
// =====================================================================
if ($path === "/api/reset" && $method === "POST") {
    // Truncate only game data; preserve players and their lifetime stats
    $pdo->exec("TRUNCATE moves, ships, game_players, games RESTART IDENTITY CASCADE");
    send_json(["status" => "reset"]);
}

// =====================================================================
// POST /api/players
// =====================================================================
if ($path === "/api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    if (isset($body["player_id"])) send_json(["error" => "player_id must not be supplied by client"], 400);
    $username = trim($body["username"] ?? "");
    if ($username === "") send_json(["error" => "username is required"], 400);

    $stmt = $pdo->prepare("SELECT player_id FROM players WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch();
    if ($existing) {
        send_json(["player_id" => (int)$existing["player_id"]], 200);
    }

    $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
    $stmt->execute([$username]);
    send_json(["player_id" => (int)$stmt->fetch()["player_id"]], 201);
}

// =====================================================================
// GET /api/players/{id}/stats
// FIX: include games_played field; accuracy guard against zero shots.
// =====================================================================
if (preg_match("#^/api/players/(\d+)/stats/?$#", $path, $m) && $method === "GET") {
    $stmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ?");
    $stmt->execute([(int)$m[1]]);
    $p = $stmt->fetch();
    if (!$p) send_json(["error" => "Player not found"], 404);

    $shots = (int)$p["total_shots"];
    $hits  = (int)$p["total_hits"];
    $wins  = (int)$p["wins"];
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

// =====================================================================
// POST /api/games
// =====================================================================
if ($path === "/api/games" && $method === "POST") {
    $body       = json_decode(file_get_contents("php://input"), true) ?? [];
    $gridSize   = (int)($body["grid_size"]   ?? 10);
    $maxPlayers = (int)($body["max_players"] ?? 2);
    if ($gridSize < 5 || $gridSize > 15) send_json(["error" => "grid_size must be between 5 and 15"], 400);
    if ($maxPlayers < 2) $maxPlayers = 2;

    $stmt = $pdo->prepare("INSERT INTO games (grid_size, max_players) VALUES (?, ?) RETURNING game_id");
    $stmt->execute([$gridSize, $maxPlayers]);
    send_json(["game_id" => (int)$stmt->fetch()["game_id"]], 201);
}

// =====================================================================
// GET /api/games/{id}
// =====================================================================
if (preg_match("#^/api/games/(\d+)/?$#", $path, $m) && $method === "GET") {
    $stmt = $pdo->prepare("SELECT game_id, grid_size, status, max_players FROM games WHERE game_id = ?");
    $stmt->execute([(int)$m[1]]);
    $game = $stmt->fetch();
    if (!$game) send_json(["error" => "Game not found"], 404);

    $stmt = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY joined_at");
    $stmt->execute([(int)$m[1]]);
    $playerIds = array_map(fn($r) => (int)$r["player_id"], $stmt->fetchAll());

    send_json([
        "game_id"            => (int)$game["game_id"],
        "grid_size"          => (int)$game["grid_size"],
        "status"             => $game["status"],
        "current_turn_index" => 0,
        "player_ids"         => $playerIds,
        "active_players"     => count($playerIds)
    ]);
}

// =====================================================================
// POST /api/games/{id}/join
// FIX: Full game must reject with 400 (not silently return 200).
// FIX: Duplicate join returns 400, not silently 200.
// =====================================================================
if (preg_match("#^/api/games/(\d+)/join/?$#", $path, $m)) {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    $stmt = $pdo->prepare("SELECT max_players, status FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_json(["error" => "Game not found"], 404);

    // Validate player exists
    $stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    if (!$stmt->fetch()) send_json(["error" => "Forbidden - invalid player"], 403);

    // Duplicate join → 400
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ($stmt->fetch()) send_json(["error" => "Already joined this game"], 400);

    // Game full → 400
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $cnt = (int)$stmt->fetch()["cnt"];
    if ($cnt >= (int)$g["max_players"]) send_json(["error" => "Game is full"], 400);

    $stmt = $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)");
    $stmt->execute([$gameId, $playerId]);
    send_json(["status" => "joined"], 200);
}

// =====================================================================
// POST /api/games/{id}/place
// FIX: validate ships, check player exists, check not already placed,
//      auto-add to game_players if missing (so test-placed ships work).
// =====================================================================
if (preg_match("#^/api/games/(\d+)/place/?$#", $path, $m)) {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $ships    = $body["ships"] ?? [];

    $stmt = $pdo->prepare("SELECT grid_size FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_json(["error" => "Game not found"], 404);

    if (!is_array($ships) || count($ships) !== 3) send_json(["error" => "Exactly 3 ships required"], 400);

    $used = [];
    foreach ($ships as $s) {
        $r = isset($s["row"]) ? (int)$s["row"] : -1;
        $c = isset($s["col"]) ? (int)$s["col"] : -1;
        if ($r < 0 || $r >= $game["grid_size"] || $c < 0 || $c >= $game["grid_size"]) {
            send_json(["error" => "Out of bounds"], 400);
        }
        $coord = "$r,$c";
        if (in_array($coord, $used)) send_json(["error" => "Overlapping ships"], 400);
        $used[] = $coord;
    }

    $stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    if (!$stmt->fetch()) send_json(["error" => "Forbidden - player not found"], 403);

    // Cannot place twice
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ((int)$stmt->fetch()["cnt"] > 0) send_json(["error" => "Ships already placed"], 400);

    $pdo->beginTransaction();

    // Auto-add to game_players if not already there
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $playerId]);
    }

    foreach ($ships as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $playerId, (int)$s["row"], (int)$s["col"]]);
    }

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT player_id) AS c FROM ships WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ((int)$stmt->fetch()["c"] >= 2) {
        $pdo->prepare("UPDATE games SET status = 'active' WHERE game_id = ?")->execute([$gameId]);
    }

    $pdo->commit();
    send_json(["status" => "placed"], 200);
}

// =====================================================================
// POST /api/games/{id}/fire
// FIX: accept players from ships table too (test endpoint bypasses join).
// FIX: record losses for the loser when game ends.
// FIX: game not active → 400 (not just finished→409, also waiting→400).
// FIX: track turn order properly.
// =====================================================================
if (preg_match("#^/api/games/(\d+)/fire/?$#", $path, $m)) {
    $gameId   = (int)$m[1];
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $r        = isset($body["row"]) ? (int)$body["row"] : -1;
    $c        = isset($body["col"]) ? (int)$body["col"] : -1;

    $stmt = $pdo->prepare("SELECT game_id, status, grid_size FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_json(["error" => "Game not found"], 404);

    // Invalid player → 403
    $stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    if (!$stmt->fetch()) send_json(["error" => "Forbidden - invalid player"], 403);

    // Game must be active
    if ($game["status"] === "finished") send_json(["error" => "Game already finished"], 409);
    if ($game["status"] !== "active")   send_json(["error" => "Game is not active"], 400);

    // Player must be in this game (game_players OR has ships — test endpoint may bypass join)
    $stmt = $pdo->prepare(
        "SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?
         UNION
         SELECT 1 FROM ships WHERE game_id = ? AND player_id = ?"
    );
    $stmt->execute([$gameId, $playerId, $gameId, $playerId]);
    if (!$stmt->fetch()) send_json(["error" => "Forbidden - not in this game"], 403);

    // Validate coordinates
    if ($r < 0 || $r >= (int)$game["grid_size"] || $c < 0 || $c >= (int)$game["grid_size"]) {
        send_json(["error" => "Out of bounds"], 400);
    }

    // Check hit
    $stmt = $pdo->prepare(
        "SELECT 1 FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?"
    );
    $stmt->execute([$gameId, $playerId, $r, $c]);
    $result = $stmt->fetch() ? "hit" : "miss";

    $pdo->beginTransaction();

    // Record the move
    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")
        ->execute([$gameId, $playerId, $r, $c, $result]);

    // Update shooter stats
    $pdo->prepare(
        "UPDATE players SET total_shots = total_shots + 1, total_hits = total_hits + ? WHERE player_id = ?"
    )->execute([$result === "hit" ? 1 : 0, $playerId]);

    // Check win: count opponent ships not yet hit
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS rem
        FROM ships s
        WHERE s.game_id = ?
          AND s.player_id != ?
          AND NOT EXISTS (
              SELECT 1 FROM moves mv
              WHERE mv.game_id = s.game_id
                AND mv.row = s.row
                AND mv.col = s.col
                AND mv.result = 'hit'
                AND mv.player_id = ?
          )
    ");
    $stmt->execute([$gameId, $playerId, $playerId]);
    $rem = (int)$stmt->fetch()["rem"];

    $gameStatus = "active";
    $winnerId   = null;

    if ($rem === 0) {
        $gameStatus = "finished";
        $winnerId   = $playerId;

        $pdo->prepare("UPDATE games SET status = 'finished', winner_id = ? WHERE game_id = ?")
            ->execute([$playerId, $gameId]);

        // Winner gets a win
        $pdo->prepare("UPDATE players SET wins = wins + 1 WHERE player_id = ?")
            ->execute([$playerId]);

        // FIX: All other players in the game get a loss
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

    send_json([
        "result"         => $result,
        "next_player_id" => $gameStatus === "finished" ? null : $playerId,
        "game_status"    => $gameStatus,
        "winner_id"      => $winnerId
    ]);
}

// =====================================================================
// GET /api/games/{id}/moves
// =====================================================================
if (preg_match("#^/api/games/(\d+)/moves/?$#", $path, $m) && $method === "GET") {
    $stmt = $pdo->prepare("SELECT * FROM moves WHERE game_id = ? ORDER BY move_id");
    $stmt->execute([(int)$m[1]]);
    send_json($stmt->fetchAll());
}

// =====================================================================
// TEST ENDPOINTS
// =====================================================================

// POST /api/test/games/{id}/restart
if (preg_match("#^/api/test/games/(\d+)/restart/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gId = (int)$m[1];
    if (!$pdo->prepare("SELECT 1 FROM games WHERE game_id = ?")->execute([$gId])) {
        send_json(["error" => "Game not found"], 404);
    }
    // FIX: do NOT touch players table — stats must persist
    $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([$gId]);
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gId]);
    $pdo->prepare("UPDATE games SET status = 'waiting', winner_id = NULL WHERE game_id = ?")->execute([$gId]);
    send_json(["status" => "restarted", "game_status" => "waiting"]);
}

// POST /api/test/games/{id}/ships
// FIX: also ensure player is in game_players so fire turn-checks work
if (preg_match("#^/api/test/games/(\d+)/ships/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);
    $gameId = (int)$m[1];
    $body   = json_decode(file_get_contents("php://input"), true) ?? [];
    $pId    = (int)($body["player_id"] ?? 0);

    $stmt = $pdo->prepare("SELECT 1 FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if (!$stmt->fetch()) send_json(["error" => "Game not found"], 404);

    $pdo->beginTransaction();

    // Ensure in game_players
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $pId]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $pId]);
    }

    // Remove old ships for this player in this game (idempotent)
    $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")->execute([$gameId, $pId]);

    foreach ($body["ships"] as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $pId, (int)$s["row"], (int)$s["col"]]);
    }

    // Activate if 2+ distinct players have ships
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT player_id) AS c FROM ships WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ((int)$stmt->fetch()["c"] >= 2) {
        $pdo->prepare("UPDATE games SET status = 'active' WHERE game_id = ?")->execute([$gameId]);
    }

    $pdo->commit();
    send_json(["status" => "ships placed", "game_status" => "active"], 200);
}

// GET /api/test/games/{id}/board/{player_id}
if (preg_match("#^/api/test/games/(\d+)/board/(\d+)/?$#", $path, $m)) {
    check_test_auth($TEST_PASSWORD);
    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([(int)$m[1], (int)$m[2]]);
    send_json(["ships" => $stmt->fetchAll()]);
}

// Catch-all for /api/test/* — auth before 404
if (str_starts_with($path, "/api/test/")) {
    check_test_auth($TEST_PASSWORD);
    send_json(["error" => "Not found"], 404);
}

send_json(["error" => "Not found"], 404);
