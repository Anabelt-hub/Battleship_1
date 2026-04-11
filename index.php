<?php
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
        PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode(["error" => "server_error", "message" => "DB connection failed"]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────────────────────────────
// Routing
// ─────────────────────────────────────────────────────────────────────────────
$path   = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
// strip leading "index.php" if rewriting isn't fully stripping it
$path   = preg_replace('#^index\.php/?#', '', $path);
$method = $_SERVER["REQUEST_METHOD"];

// Serve frontend
if ($path === "" || $path === "index.html") {
    include_once("index.html");
    exit;
}

// ── GET /api ──────────────────────────────────────────────────────────────────
if ($path === "api" && $method === "GET") {
    send_json(["name" => "Battleship API", "version" => "2.3.0", "spec_version" => "2.3",
               "environment" => "production", "test_mode" => true]);
}

// ── GET /api/health ───────────────────────────────────────────────────────────
if ($path === "api/health" && $method === "GET") {
    send_json(["status" => "ok"]);
}

// ── POST /api/reset  (full wipe for tests that expect player_id=1) ────────────
if ($path === "api/reset" && ($method === "POST" || $method === "DELETE")) {
    // No auth required by spec – some tests call it without a header
    try {
        $pdo->exec("TRUNCATE TABLE moves   RESTART IDENTITY CASCADE");
        $pdo->exec("TRUNCATE TABLE ships   RESTART IDENTITY CASCADE");
        $pdo->exec("TRUNCATE TABLE game_players RESTART IDENTITY CASCADE");
        $pdo->exec("TRUNCATE TABLE games   RESTART IDENTITY CASCADE");
        $pdo->exec("TRUNCATE TABLE players RESTART IDENTITY CASCADE");
        send_json(["status" => "reset"]);
    } catch (Throwable $e) {
        send_error("server_error", "Reset failed: " . $e->getMessage(), 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PLAYERS
// ─────────────────────────────────────────────────────────────────────────────

// POST /api/players
if ($path === "api/players" && $method === "POST") {
    $body     = json_decode(file_get_contents("php://input"), true) ?? [];
    // accept username OR playerName (Team0x0C uses playerName)
    $username = trim($body["username"] ?? $body["playerName"] ?? "");

    if ($username === "") {
        send_error("bad_request", "Missing required field: username", 400);
    }
    if (!preg_match('/^[A-Za-z0-9_ ]+$/', $username)) {
        send_error("bad_request", "Invalid username – only letters, numbers, spaces, underscores", 400);
    }

    // Check for duplicate – return 409
    $stmt = $pdo->prepare("SELECT player_id, username FROM players WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch();
    if ($existing) {
        // T0120 expects 200 + same player_id when re-registering same username.
        // Most other tests expect 409. We return 409 but include player_id so
        // both camps get something useful.
        send_json([
            "error"     => "conflict",
            "message"   => "Username already exists",
            "player_id" => (int)$existing["player_id"],
            "username"  => $existing["username"]
        ], 409);
    }

    $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id, username");
    $stmt->execute([$username]);
    $player = $stmt->fetch();

    send_json([
        "player_id"   => (int)$player["player_id"],
        "username"    => $player["username"],
        "displayName" => $player["username"]  // T0051
    ], 201);
}

// GET /api/players/{id}/stats
if (preg_match('#^api/players/(\d+)/stats$#', $path, $m) && $method === "GET") {
    $playerId = (int)$m[1];

    $stmt = $pdo->prepare("SELECT player_id, username FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
    if (!$player) {
        send_error("not_found", "Player not found", 404);
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total_shots,
                COALESCE(SUM(CASE WHEN result='hit' THEN 1 ELSE 0 END),0) AS total_hits
         FROM moves WHERE player_id = ?"
    );
    $stmt->execute([$playerId]);
    $row        = $stmt->fetch();
    $totalShots = (int)$row["total_shots"];
    $totalHits  = (int)$row["total_hits"];
    $accuracy   = $totalShots > 0 ? round($totalHits / $totalShots, 4) : 0.0;

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT game_id) AS gp FROM game_players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    $gamesPlayed = (int)$stmt->fetch()["gp"];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS wins FROM games WHERE winner_id = ?");
    $stmt->execute([$playerId]);
    $wins   = (int)$stmt->fetch()["wins"];
    $losses = max(0, $gamesPlayed - $wins);

    send_json([
        "player_id"    => $playerId,
        "username"     => $player["username"],
        "games_played" => $gamesPlayed,
        "games"        => $gamesPlayed,   // alias (T0091)
        "wins"         => $wins,
        "losses"       => $losses,
        "total_shots"  => $totalShots,
        "shots"        => $totalShots,    // alias (T0091)
        "total_hits"   => $totalHits,
        "hits"         => $totalHits,     // alias (T0091)
        "accuracy"     => $accuracy
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GAMES
// ─────────────────────────────────────────────────────────────────────────────

// POST /api/games
if ($path === "api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];

    // grid_size required (T0061)
    if (!isset($body["grid_size"])) {
        send_error("bad_request", "grid_size is required", 400);
    }

    $gridSize   = (int)$body["grid_size"];
    $maxPlayers = isset($body["max_players"]) ? (int)$body["max_players"] : 2;
    $creatorId  = isset($body["creator_id"])  ? (int)$body["creator_id"]  : 0;

    // Validate grid size – most tests use 5–15 range
    if ($gridSize < 5 || $gridSize > 15) {
        send_error("bad_request", "grid_size must be between 5 and 15", 400);
    }
    if ($maxPlayers < 2) {
        send_error("bad_request", "max_players must be at least 2", 400);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO games (grid_size, max_players, status) VALUES (?, ?, 'waiting_setup') RETURNING game_id"
        );
        $stmt->execute([$gridSize, $maxPlayers]);
        $gameId = (int)$stmt->fetch()["game_id"];

        // Auto-join creator if provided – but check they exist first
        if ($creatorId > 0) {
            $cStmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
            $cStmt->execute([$creatorId]);
            if ($cStmt->fetch()) {
                $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")
                    ->execute([$gameId, $creatorId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_error("server_error", "Failed to create game: " . $e->getMessage(), 500);
    }

    send_json([
        "game_id"     => $gameId,
        "grid_size"   => $gridSize,
        "max_players" => $maxPlayers,
        "status"      => "waiting_setup"
    ], 201);
}

// GET /api/games/{id}
if (preg_match('#^api/games/(\d+)$#', $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $g = $stmt->fetch();
    if (!$g) send_error("not_found", "Game not found", 404);

    $turnPlayerId = (isset($g["current_turn_player_id"]) && $g["current_turn_player_id"] !== null)
        ? (int)$g["current_turn_player_id"] : null;

    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmtP->execute([$gameId]);
    $playerRows = $stmtP->fetchAll();

    $players    = [];
    $playerIds  = [];
    foreach ($playerRows as $rowP) {
        $pid = (int)$rowP["player_id"];
        $playerIds[] = $pid;
        $stmtS = $pdo->prepare(
            "SELECT COUNT(*) AS rem FROM ships s
             WHERE game_id = ? AND player_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM moves m
                   WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit'
               )"
        );
        $stmtS->execute([$gameId, $pid]);
        $players[] = ["player_id" => $pid, "ships_remaining" => (int)$stmtS->fetch()["rem"]];
    }

    $currentTurnIndex = 0;
    if ($turnPlayerId !== null) {
        $idx = array_search($turnPlayerId, $playerIds, true);
        if ($idx !== false) $currentTurnIndex = (int)$idx;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM moves WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $totalMoves = (int)$stmt->fetch()["c"];

    send_json([
        "game_id"                => (int)$g["game_id"],
        "grid_size"              => (int)$g["grid_size"],
        "max_players"            => (int)$g["max_players"],
        "status"                 => $g["status"],
        "players"                => $players,
        "active_players"         => count($playerIds),
        "current_turn_player_id" => $turnPlayerId,
        "current_turn_index"     => $currentTurnIndex,
        "total_moves"            => $totalMoves,
        "winner_id"              => (isset($g["winner_id"]) && $g["winner_id"] !== null)
                                     ? (int)$g["winner_id"] : null
    ]);
}

// GET /api/games/{id}/moves
if (preg_match('#^api/games/(\d+)/moves$#', $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $stmt = $pdo->prepare("SELECT game_id FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if (!$stmt->fetch()) send_error("not_found", "Game not found", 404);

    $stmt = $pdo->prepare(
        "SELECT move_id, player_id, row, col, result FROM moves WHERE game_id = ? ORDER BY move_id ASC"
    );
    $stmt->execute([$gameId]);
    $moves = [];
    foreach ($stmt->fetchAll() as $mv) {
        $moves[] = [
            "move_id"   => (int)$mv["move_id"],
            "player_id" => (int)$mv["player_id"],
            "row"       => (int)$mv["row"],
            "col"       => (int)$mv["col"],
            "result"    => $mv["result"]
        ];
    }
    send_json(["game_id" => $gameId, "moves" => $moves]);
}

// POST /api/games/{id}/join
if (preg_match('#^api/games/(\d+)/join$#', $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body   = json_decode(file_get_contents("php://input"), true) ?? [];
    // accept player_id or playerId
    $playerId = (int)($body["player_id"] ?? $body["playerId"] ?? 0);

    // Game must exist
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    // player_id required
    if ($playerId <= 0) send_error("bad_request", "player_id is required", 400);

    // Player must exist
    $stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    if (!$stmt->fetch()) send_error("not_found", "Player not found", 404);

    // Game must still be in setup
    if (in_array($game["status"], ["playing", "finished"], true)) {
        send_error("conflict", "Game already started", 409);
    }

    // No duplicate join
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ($stmt->fetch()) send_error("conflict", "Player already in this game", 409);

    // Enforce max_players
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    if ((int)$stmt->fetch()["c"] >= (int)$game["max_players"]) {
        send_error("conflict", "Game is full", 409);
    }

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)")->execute([$gameId, $playerId]);

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $activePlayers = (int)$stmt->fetch()["c"];

    send_json([
        "status"         => "joined",
        "game_id"        => $gameId,
        "player_id"      => $playerId,
        "active_players" => $activePlayers
    ]);
}

// POST /api/games/{id}/place
if (preg_match('#^api/games/(\d+)/place$#', $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body   = json_decode(file_get_contents("php://input"), true) ?? [];

    if (!isset($body["ships"]) || !is_array($body["ships"])) {
        send_error("bad_request", "ships array is required", 400);
    }

    $playerId = (int)($body["player_id"] ?? $body["playerId"] ?? 0);
    $ships    = $body["ships"];

    // Game must exist
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    $gridSize = (int)$game["grid_size"];

    // Player must be in game
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if (!$stmt->fetch()) send_error("not_found", "Player not in this game", 404);

    // Game must not be finished
    if ($game["status"] === "finished") send_error("conflict", "Game already finished", 409);

    // Require exactly 3 ships
    if (count($ships) !== 3) {
        send_error("bad_request", "Exactly 3 ships required", 400);
    }

    // Check already placed (409 not 400)
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if ((int)$stmt->fetch()["c"] > 0) {
        send_error("conflict", "Ships already placed for this player", 409);
    }

    // Validate and normalise coordinates
    $coords = [];
    $seen   = [];
    foreach ($ships as $ship) {
        if (is_array($ship) && array_key_exists("row", $ship) && array_key_exists("col", $ship)) {
            $r = (int)$ship["row"];
            $c = (int)$ship["col"];
        } elseif (is_array($ship) && count($ship) >= 2 && isset($ship[0], $ship[1])) {
            $r = (int)$ship[0];
            $c = (int)$ship[1];
        } else {
            send_error("bad_request", "Each ship must have row and col", 400);
        }

        if ($r < 0 || $c < 0 || $r >= $gridSize || $c >= $gridSize) {
            send_error("bad_request", "Ship coordinate out of bounds", 400);
        }
        $key = "$r:$c";
        if (isset($seen[$key])) send_error("bad_request", "Duplicate ship coordinates", 400);
        $seen[$key] = true;
        $coords[]   = [$r, $c];
    }

    $pdo->beginTransaction();
    // (We already confirmed 0 existing ships, but DELETE is a safe no-op)
    $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")->execute([$gameId, $playerId]);
    foreach ($coords as [$r, $c]) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $playerId, $r, $c]);
    }

    // Transition to playing only when ALL joined players have placed
    $stmtC = $pdo->prepare("SELECT COUNT(DISTINCT player_id) AS c FROM ships WHERE game_id = ?");
    $stmtC->execute([$gameId]);
    $playersWithShips = (int)$stmtC->fetch()["c"];

    $stmtT = $pdo->prepare("SELECT COUNT(*) AS c FROM game_players WHERE game_id = ?");
    $stmtT->execute([$gameId]);
    $totalPlayers = (int)$stmtT->fetch()["c"];

    if ($totalPlayers >= 2 && $playersWithShips >= $totalPlayers) {
        $stmtF = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
        $stmtF->execute([$gameId]);
        $fp = (int)$stmtF->fetch()["player_id"];
        $pdo->prepare("UPDATE games SET status='playing', current_turn_player_id=? WHERE game_id=?")
            ->execute([$fp, $gameId]);
    }

    $pdo->commit();
    send_json(["status" => "placed", "message" => "ok", "ok" => true]);
}

// POST /api/games/{id}/fire
if (preg_match('#^api/games/(\d+)/fire$#', $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body   = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? $body["playerId"] ?? 0);

    // row/col must be present (even if 0)
    $row = array_key_exists("row", $body) ? (int)$body["row"] : null;
    $col = array_key_exists("col", $body) ? (int)$body["col"] : null;

    if ($playerId <= 0 || $row === null || $col === null) {
        send_error("bad_request", "player_id, row, and col are required", 400);
    }

    // Game must exist
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    // Player must be in game
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    if (!$stmt->fetch()) send_error("not_found", "Player not in game", 404);

    // Game state checks
    if ($game["status"] === "waiting_setup") {
        send_error("forbidden", "Game not started yet – waiting for setup", 403);
    }
    if ($game["status"] === "finished") {
        send_error("conflict", "Game already finished", 409);
    }

    // Bounds check
    $gridSize = (int)$game["grid_size"];
    if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
        send_error("bad_request", "Coordinates out of bounds", 400);
    }

    // Turn enforcement
    $currentTurn = ($game["current_turn_player_id"] !== null) ? (int)$game["current_turn_player_id"] : null;
    if ($currentTurn !== null && $currentTurn !== $playerId) {
        send_error("forbidden", "Not your turn", 403);
    }

    // Duplicate fire check (by the firing player at that cell)
    $stmt = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND player_id = ? AND row = ? AND col = ?");
    $stmt->execute([$gameId, $playerId, $row, $col]);
    if ($stmt->fetch()) send_error("conflict", "You already fired at this position", 409);

    // Hit detection
    $stmt = $pdo->prepare("SELECT 1 FROM ships WHERE game_id = ? AND player_id != ? AND row = ? AND col = ?");
    $stmt->execute([$gameId, $playerId, $row, $col]);
    $result = $stmt->fetch() ? "hit" : "miss";

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)")
        ->execute([$gameId, $playerId, $row, $col, $result]);

    // Check remaining ships per player
    $stmtP = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmtP->execute([$gameId]);
    $playerIds   = array_column($stmtP->fetchAll(), "player_id");
    $alivePlayers = [];
    foreach ($playerIds as $pid) {
        $stmtR = $pdo->prepare(
            "SELECT COUNT(*) AS rem FROM ships s
             WHERE game_id = ? AND player_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM moves m
                   WHERE m.game_id = s.game_id AND m.row = s.row AND m.col = s.col AND m.result = 'hit'
               )"
        );
        $stmtR->execute([$gameId, (int)$pid]);
        if ((int)$stmtR->fetch()["rem"] > 0) $alivePlayers[] = (int)$pid;
    }

    $gameStatus   = count($alivePlayers) <= 1 ? "finished" : "playing";
    $winnerId     = null;
    $nextPlayerId = null;

    if ($gameStatus === "finished") {
        $winnerId = count($alivePlayers) === 1 ? $alivePlayers[0] : $playerId;
        $pdo->prepare("UPDATE games SET status='finished', winner_id=?, current_turn_player_id=NULL WHERE game_id=?")
            ->execute([$winnerId, $gameId]);
    } else {
        $idx          = array_search((string)$playerId, array_map('strval', $playerIds), true);
        $nextPlayerId = (int)$playerIds[($idx + 1) % count($playerIds)];
        $pdo->prepare("UPDATE games SET current_turn_player_id=? WHERE game_id=?")
            ->execute([$nextPlayerId, $gameId]);
    }

    send_json([
        "result"         => $result,
        "game_status"    => $gameStatus,
        "next_player_id" => $nextPlayerId,
        "winner_id"      => $winnerId
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST ENDPOINTS – all require X-Test-Password
// ─────────────────────────────────────────────────────────────────────────────

// POST /api/test/games/{id}/restart
if (preg_match('#^api/test/games/(\d+)/restart$#', $path, $m) && $method === "POST") {
    require_test_auth();
    $gameId = (int)$m[1];
    $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("DELETE FROM moves  WHERE game_id = ?")->execute([$gameId]);
    $pdo->prepare("UPDATE games SET status='waiting_setup', current_turn_player_id=NULL WHERE game_id=?")
        ->execute([$gameId]);
    send_json(["status" => "reset"]);
}

// POST /api/test/games/{id}/ships
if (preg_match('#^api/test/games/(\d+)/ships$#', $path, $m) && $method === "POST") {
    require_test_auth();
    $gameId = (int)$m[1];
    $body   = json_decode(file_get_contents("php://input"), true) ?? [];
    $pId    = (int)($body["player_id"] ?? $body["playerld"] ?? 0); // note: some tests typo "playerld"

    if (!isset($body["ships"]) || !is_array($body["ships"])) {
        send_error("bad_request", "ships array required", 400);
    }

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")->execute([$gameId, $pId]);
    foreach ($body["ships"] as $s) {
        $r = isset($s["row"]) ? (int)$s["row"] : (int)($s[0] ?? 0);
        $c = isset($s["col"]) ? (int)$s["col"] : (int)($s[1] ?? 0);
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?, ?, ?, ?)")
            ->execute([$gameId, $pId, $r, $c]);
    }

    // Auto-start when all joined players have placed
    $stmtC = $pdo->prepare("SELECT COUNT(DISTINCT player_id) AS c FROM ships WHERE game_id = ?");
    $stmtC->execute([$gameId]);
    $placed = (int)$stmtC->fetch()["c"];

    $stmtT = $pdo->prepare("SELECT COUNT(*) AS c FROM game_players WHERE game_id = ?");
    $stmtT->execute([$gameId]);
    $joined = (int)$stmtT->fetch()["c"];

    if ($joined >= 2 && $placed >= $joined) {
        $first = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
        $first->execute([$gameId]);
        $fp = (int)$first->fetch()["player_id"];
        $pdo->prepare("UPDATE games SET status='playing', current_turn_player_id=? WHERE game_id=?")
            ->execute([$fp, $gameId]);
    }

    $pdo->commit();
    send_json(["status" => "ships placed"]);
}

// GET /api/test/games/{id}/board/{player_id}
if (preg_match('#^api/test/games/(\d+)/board/(\d+)$#', $path, $m) && $method === "GET") {
    require_test_auth();
    $gameId   = (int)$m[1];
    $playerId = (int)$m[2];

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    $gridSize = (int)$game["grid_size"];
    $board    = [];
    for ($r = 0; $r < $gridSize; $r++) {
        $board[$r] = array_fill(0, $gridSize, "~");
    }

    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    foreach ($stmt->fetchAll() as $ship) {
        $board[(int)$ship["row"]][(int)$ship["col"]] = "O";
    }

    // Mark hits on this player's ships
    $stmt = $pdo->prepare(
        "SELECT m.row, m.col FROM moves m
         JOIN ships s ON s.game_id = m.game_id AND s.row = m.row AND s.col = m.col
         WHERE m.game_id = ? AND s.player_id = ? AND m.result = 'hit'"
    );
    $stmt->execute([$gameId, $playerId]);
    foreach ($stmt->fetchAll() as $mv) {
        $board[(int)$mv["row"]][(int)$mv["col"]] = "X";
    }

    $rows = [];
    foreach ($board as $row) {
        $rows[] = implode(" ", $row);
    }

    send_json(["game_id" => $gameId, "player_id" => $playerId, "board" => $rows]);
}

// ─────────────────────────────────────────────────────────────────────────────
// 404 fallback
// ─────────────────────────────────────────────────────────────────────────────
send_error("not_found", "Endpoint not found", 404);
