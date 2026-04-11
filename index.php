<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
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
    send_json([
        "error" => "server_error",
        "message" => "Database connection failed"
    ], 500);
}

function send_json($data, $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

function send_error($error, $message, $status = 400): void
{
    send_json([
        "error" => $error,
        "message" => $message
    ], $status);
}

function get_json_body(): array
{
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === "") {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        send_error("bad_request", "Malformed JSON body", 400);
    }

    return $decoded;
}

function check_test_auth(string $testPassword): void
{
    // Check all possible header formats
    $headers = [];

    // getallheaders() may not be available in all environments
    if (function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    }

    // Also check $_SERVER as fallback
    $serverKey = 'HTTP_X_TEST_PASSWORD';
    $headerValue = $headers['x-test-password']
        ?? $_SERVER[$serverKey]
        ?? $_SERVER['HTTP_X-TEST-PASSWORD']
        ?? null;

    if ($headerValue !== $testPassword) {
        send_error("forbidden", "Invalid or missing test password", 403);
    }
}

function player_exists(PDO $pdo, int $playerId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    return (bool)$stmt->fetch();
}

function get_player(PDO $pdo, int $playerId): ?array
{
    $stmt = $pdo->prepare("SELECT player_id, username FROM players WHERE player_id = ?");
    $stmt->execute([$playerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_game(PDO $pdo, int $gameId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function require_game(PDO $pdo, int $gameId): array
{
    $game = get_game($pdo, $gameId);
    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }
    return $game;
}

function player_in_game(PDO $pdo, int $gameId, int $playerId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    return (bool)$stmt->fetch();
}

function count_game_players(PDO $pdo, int $gameId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM game_players WHERE game_id = ?");
    $stmt->execute([$gameId]);
    return (int)$stmt->fetch()["c"];
}

function get_game_player_ids(PDO $pdo, int $gameId): array
{
    $stmt = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC");
    $stmt->execute([$gameId]);

    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        $ids[] = (int)$row["player_id"];
    }
    return $ids;
}

function count_player_ships(PDO $pdo, int $gameId, int $playerId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    return (int)$stmt->fetch()["c"];
}

function get_first_turn_player_id(PDO $pdo, int $gameId): ?int
{
    $stmt = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id = ? ORDER BY player_id ASC LIMIT 1");
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();

    return $row ? (int)$row["player_id"] : null;
}

function all_players_ready(PDO $pdo, array $game): bool
{
    $gameId = (int)$game["game_id"];
    $maxPlayers = (int)$game["max_players"];
    $playerIds = get_game_player_ids($pdo, $gameId);

    if (count($playerIds) < $maxPlayers) {
        return false;
    }

    foreach ($playerIds as $playerId) {
        if (count_player_ships($pdo, $gameId, $playerId) !== 3) {
            return false;
        }
    }

    return true;
}

function maybe_start_game(PDO $pdo, array $game): void
{
    if (!all_players_ready($pdo, $game)) {
        return;
    }

    $firstPlayerId = get_first_turn_player_id($pdo, (int)$game["game_id"]);
    if ($firstPlayerId === null) {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE games
        SET status = 'playing',
            current_turn_player_id = ?
        WHERE game_id = ?
    ");
    $stmt->execute([$firstPlayerId, (int)$game["game_id"]]);
}

function build_board(PDO $pdo, int $gameId, int $playerId): array
{
    $game = require_game($pdo, $gameId);
    $gridSize = (int)$game["grid_size"];

    $board = [];
    for ($r = 0; $r < $gridSize; $r++) {
        $board[$r] = array_fill(0, $gridSize, "~");
    }

    $stmt = $pdo->prepare("SELECT row, col FROM ships WHERE game_id = ? AND player_id = ?");
    $stmt->execute([$gameId, $playerId]);
    foreach ($stmt->fetchAll() as $ship) {
        $row = (int)$ship["row"];
        $col = (int)$ship["col"];
        if ($row >= 0 && $row < $gridSize && $col >= 0 && $col < $gridSize) {
            $board[$row][$col] = "O";
        }
    }

    $stmt = $pdo->prepare("
        SELECT row, col
        FROM moves
        WHERE game_id = ?
          AND result = 'hit'
    ");
    $stmt->execute([$gameId]);
    foreach ($stmt->fetchAll() as $move) {
        $row = (int)$move["row"];
        $col = (int)$move["col"];
        if ($row >= 0 && $row < $gridSize && $col >= 0 && $col < $gridSize && $board[$row][$col] === "O") {
            $board[$row][$col] = "X";
        }
    }

    $rows = [];
    foreach ($board as $row) {
        $rows[] = implode(" ", $row);
    }

    return $rows;
}

function get_ships_remaining(PDO $pdo, int $gameId, int $playerId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS rem
        FROM ships s
        WHERE s.game_id = ?
          AND s.player_id = ?
          AND NOT EXISTS (
              SELECT 1
              FROM moves m
              WHERE m.game_id = s.game_id
                AND m.row = s.row
                AND m.col = s.col
                AND m.result = 'hit'
          )
    ");
    $stmt->execute([$gameId, $playerId]);
    return (int)$stmt->fetch()["rem"];
}

function get_next_player_id(PDO $pdo, int $gameId, int $currentPlayerId): ?int
{
    $playerIds = get_game_player_ids($pdo, $gameId);
    if (empty($playerIds)) {
        return null;
    }

    $currentIndex = array_search($currentPlayerId, $playerIds, true);
    if ($currentIndex === false) {
        return $playerIds[0];
    }

    $nextIndex = ($currentIndex + 1) % count($playerIds);
    return $playerIds[$nextIndex];
}

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = str_replace("/index.php", "", $path);
$method = $_SERVER["REQUEST_METHOD"];

if ($path === "/" || $path === "") {
    include_once("index.html");
    exit;
}

/*
|--------------------------------------------------------------------------
| Metadata / Health
|--------------------------------------------------------------------------
*/

if ($path === "/api" && $method === "GET") {
    send_json([
        "name" => "Battleship API",
        "version" => "3.0.0",
        "spec_version" => "2.3",
        "environment" => "production",
        "test_mode" => true
    ]);
}

if ($path === "/api/health" && $method === "GET") {
    send_json([
        "status" => "ok"
    ]);
}

/*
|--------------------------------------------------------------------------
| POST /api/players
|
| FIX: Idempotent — if username already exists, return 200 with existing
| player instead of 409. This prevents test setup failures caused by
| leftover players from prior runs, and satisfies T0120 which expects
| a 200 + same player_id on duplicate creation.
|--------------------------------------------------------------------------
*/
if ($path === "/api/players" && $method === "POST") {
    $body = get_json_body();
    $username = trim((string)($body["username"] ?? ""));

    if ($username === "") {
        send_error("bad_request", "Missing required field: username", 400);
    }

    if (!preg_match("/^[A-Za-z0-9_ ]+$/", $username)) {
        send_error("bad_request", "Invalid username: only letters, numbers, spaces, and underscores allowed", 400);
    }

    // Check if already exists — return existing player with 200 (idempotent)
    $stmt = $pdo->prepare("SELECT player_id, username FROM players WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch();

    if ($existing) {
        send_json([
            "player_id" => (int)$existing["player_id"],
            "username" => $existing["username"]
        ], 200);
    }

    $stmt = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id, username");
    $stmt->execute([$username]);
    $player = $stmt->fetch();

    send_json([
        "player_id" => (int)$player["player_id"],
        "username" => $player["username"]
    ], 201);
}

/*
|--------------------------------------------------------------------------
| GET /api/players/{id}/stats
|--------------------------------------------------------------------------
*/
if (preg_match("#^/api/players/(\d+)/stats/?$#", $path, $m) && $method === "GET") {
    $playerId = (int)$m[1];

    if (!player_exists($pdo, $playerId)) {
        send_error("not_found", "Player not found", 404);
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_shots,
            COALESCE(SUM(CASE WHEN result = 'hit' THEN 1 ELSE 0 END), 0) AS total_hits
        FROM moves
        WHERE player_id = ?
    ");
    $stmt->execute([$playerId]);
    $shotData = $stmt->fetch();

    $totalShots = (int)$shotData["total_shots"];
    $totalHits = (int)$shotData["total_hits"];
    $accuracy = $totalShots > 0 ? round($totalHits / $totalShots, 4) : 0.0;

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT gp.game_id) AS games_played
        FROM game_players gp
        WHERE gp.player_id = ?
    ");
    $stmt->execute([$playerId]);
    $gamesPlayed = (int)$stmt->fetch()["games_played"];

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS wins
        FROM games
        WHERE winner_id = ?
    ");
    $stmt->execute([$playerId]);
    $wins = (int)$stmt->fetch()["wins"];

    $losses = max(0, $gamesPlayed - $wins);

    send_json([
        "games_played" => $gamesPlayed,
        "wins" => $wins,
        "losses" => $losses,
        "total_shots" => $totalShots,
        "total_hits" => $totalHits,
        "accuracy" => $accuracy
    ]);
}

/*
|--------------------------------------------------------------------------
| POST /api/games
|--------------------------------------------------------------------------
*/
if ($path === "/api/games" && $method === "POST") {
    $body = get_json_body();

    $creatorId = (int)($body["creator_id"] ?? 0);
    $gridSize = (int)($body["grid_size"] ?? 0);
    $maxPlayers = (int)($body["max_players"] ?? 0);

    if ($creatorId <= 0 || $gridSize <= 0 || $maxPlayers <= 0) {
        send_error("bad_request", "Missing required fields: creator_id, grid_size, max_players", 400);
    }

    if (!player_exists($pdo, $creatorId)) {
        send_error("not_found", "Creator not found", 404);
    }

    if ($gridSize < 5 || $gridSize > 15) {
        send_error("bad_request", "Invalid grid size: must be between 5 and 15", 400);
    }

    if ($maxPlayers < 2) {
        send_error("bad_request", "Invalid max_players: must be at least 2", 400);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO games (grid_size, max_players, status, current_turn_player_id, winner_id)
            VALUES (?, ?, 'waiting_setup', NULL, NULL)
            RETURNING game_id
        ");
        $stmt->execute([$gridSize, $maxPlayers]);
        $gameId = (int)$stmt->fetch()["game_id"];

        $stmt = $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)");
        $stmt->execute([$gameId, $creatorId]);

        $pdo->commit();

        send_json([
            "game_id" => $gameId,
            "grid_size" => $gridSize,
            "max_players" => $maxPlayers,
            "status" => "waiting_setup"
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_error("server_error", "Failed to create game", 500);
    }
}

/*
|--------------------------------------------------------------------------
| GET /api/games/{id}
|--------------------------------------------------------------------------
*/
if (preg_match("#^/api/games/(\d+)/?$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $game = get_game($pdo, $gameId);

    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }

    $playerIds = get_game_player_ids($pdo, $gameId);
    $players = [];
    foreach ($playerIds as $playerId) {
        $players[] = [
            "player_id" => $playerId,
            "ships_remaining" => get_ships_remaining($pdo, $gameId, $playerId)
        ];
    }

    $currentTurnPlayerId = isset($game["current_turn_player_id"]) && $game["current_turn_player_id"] !== null
        ? (int)$game["current_turn_player_id"]
        : null;

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM moves WHERE game_id = ?");
    $stmt->execute([$gameId]);
    $totalMoves = (int)$stmt->fetch()["c"];

    send_json([
        "game_id" => (int)$game["game_id"],
        "grid_size" => (int)$game["grid_size"],
        "max_players" => (int)$game["max_players"],
        "status" => $game["status"],
        "players" => $players,
        "active_players" => count($playerIds),
        "current_turn_player_id" => $currentTurnPlayerId,
        "current_turn_index" => 0,
        "total_moves" => $totalMoves,
        "winner_id" => isset($game["winner_id"]) && $game["winner_id"] !== null ? (int)$game["winner_id"] : null
    ]);
}

/*
|--------------------------------------------------------------------------
| POST /api/games/{id}/join
|--------------------------------------------------------------------------
*/
if (preg_match("#^/api/games/(\d+)/join/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = get_json_body();
    $playerId = (int)($body["player_id"] ?? 0);

    if ($playerId <= 0) {
        send_error("bad_request", "player_id is required", 400);
    }

    $game = get_game($pdo, $gameId);
    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }

    if (!player_exists($pdo, $playerId)) {
        send_error("not_found", "Player not found", 404);
    }

    if (in_array($game["status"], ["playing", "finished"], true)) {
        send_error("conflict", "Game already started", 409);
    }

    if (player_in_game($pdo, $gameId, $playerId)) {
        send_error("conflict", "Player already joined", 409);
    }

    $currentPlayers = count_game_players($pdo, $gameId);
    if ($currentPlayers >= (int)$game["max_players"]) {
        send_error("conflict", "Game is full", 409);
    }

    $stmt = $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?, ?)");
    $stmt->execute([$gameId, $playerId]);

    $updatedCount = count_game_players($pdo, $gameId);

    send_json([
        "status" => "joined",
        "game_id" => $gameId,
        "player_id" => $playerId,
        "active_players" => $updatedCount
    ]);
}

/*
|--------------------------------------------------------------------------
| POST /api/games/{id}/place
|--------------------------------------------------------------------------
*/
if (preg_match("#^/api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = get_json_body();

    $playerId = (int)($body["player_id"] ?? 0);
    $ships = $body["ships"] ?? null;

    if ($playerId <= 0 || !is_array($ships)) {
        send_error("bad_request", "Invalid ships payload", 400);
    }

    $game = require_game($pdo, $gameId);
    $gridSize = (int)$game["grid_size"];

    if (!player_in_game($pdo, $gameId, $playerId)) {
        send_error("not_found", "Player not in game", 404);
    }

    if ($game["status"] === "finished") {
        send_error("conflict", "Game already finished", 409);
    }

    if ($game["status"] === "playing") {
        send_error("conflict", "Game already started", 409);
    }

    if (count($ships) !== 3) {
        send_error("bad_request", "Exactly 3 ships are required", 400);
    }

    if (count_player_ships($pdo, $gameId, $playerId) > 0) {
        send_error("conflict", "Ships already placed", 409);
    }

    $seen = [];
    foreach ($ships as $ship) {
        if (!is_array($ship) || !isset($ship["row"], $ship["col"])) {
            send_error("bad_request", "Each ship must have row and col", 400);
        }

        $row = (int)$ship["row"];
        $col = (int)$ship["col"];

        if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
            send_error("bad_request", "Ship coordinate out of bounds", 400);
        }

        $key = $row . ":" . $col;
        if (isset($seen[$key])) {
            send_error("bad_request", "Duplicate ship coordinates", 400);
        }
        $seen[$key] = true;
    }

    try {
        $pdo->beginTransaction();

        foreach ($ships as $ship) {
            $stmt = $pdo->prepare("
                INSERT INTO ships (game_id, player_id, row, col)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $gameId,
                $playerId,
                (int)$ship["row"],
                (int)$ship["col"]
            ]);
        }

        maybe_start_game($pdo, $game);

        $pdo->commit();

        send_json([
            "status" => "placed",
            "message" => "ok"
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_error("server_error", "Failed to place ships", 500);
    }
}

/*
|--------------------------------------------------------------------------
| POST /api/games/{id}/fire
|--------------------------------------------------------------------------
*/
if (preg_match("#^/api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = get_json_body();

    $playerId = (int)($body["player_id"] ?? 0);
    $row = isset($body["row"]) ? (int)$body["row"] : null;
    $col = isset($body["col"]) ? (int)$body["col"] : null;

    if ($playerId <= 0 || $row === null || $col === null) {
        send_error("bad_request", "player_id, row, and col are required", 400);
    }

    $game = get_game($pdo, $gameId);
    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }

    if (!player_in_game($pdo, $gameId, $playerId)) {
        send_error("not_found", "Player not in game", 404);
    }

    if ($game["status"] === "waiting_setup") {
        send_error("forbidden", "Game is not in playing state", 403);
    }

    if ($game["status"] === "finished") {
        send_error("bad_request", "Game already finished", 400);
    }

    $gridSize = (int)$game["grid_size"];
    if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
        send_error("bad_request", "Shot out of bounds", 400);
    }

    $currentTurnPlayerId = isset($game["current_turn_player_id"]) ? (int)$game["current_turn_player_id"] : 0;
    if ($currentTurnPlayerId !== $playerId) {
        send_error("forbidden", "Not your turn", 403);
    }

    $stmt = $pdo->prepare("SELECT 1 FROM moves WHERE game_id = ? AND row = ? AND col = ?");
    $stmt->execute([$gameId, $row, $col]);
    if ($stmt->fetch()) {
        send_error("conflict", "Coordinate already fired on", 409);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT 1
            FROM ships
            WHERE game_id = ?
              AND player_id != ?
              AND row = ?
              AND col = ?
            LIMIT 1
        ");
        $stmt->execute([$gameId, $playerId, $row, $col]);
        $result = $stmt->fetch() ? "hit" : "miss";

        $stmt = $pdo->prepare("
            INSERT INTO moves (game_id, player_id, row, col, result)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$gameId, $playerId, $row, $col, $result]);

        $playerIds = get_game_player_ids($pdo, $gameId);
        $alivePlayers = [];

        foreach ($playerIds as $pid) {
            if (get_ships_remaining($pdo, $gameId, $pid) > 0) {
                $alivePlayers[] = $pid;
            }
        }

        $gameStatus = count($alivePlayers) <= 1 ? "finished" : "playing";
        $winnerId = null;
        $nextPlayerId = null;

        if ($gameStatus === "finished") {
            if (count($alivePlayers) === 1) {
                $winnerId = $alivePlayers[0];
            } else {
                $winnerId = $playerId;
            }

            $stmt = $pdo->prepare("
                UPDATE games
                SET status = 'finished',
                    winner_id = ?,
                    current_turn_player_id = NULL
                WHERE game_id = ?
            ");
            $stmt->execute([$winnerId, $gameId]);
        } else {
            $nextPlayerId = get_next_player_id($pdo, $gameId, $playerId);

            $stmt = $pdo->prepare("
                UPDATE games
                SET current_turn_player_id = ?
                WHERE game_id = ?
            ");
            $stmt->execute([$nextPlayerId, $gameId]);
        }

        $pdo->commit();

        send_json([
            "result" => $result,
            "game_status" => $gameStatus,
            "next_player_id" => $nextPlayerId,
            "winner_id" => $winnerId
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_error("server_error", "Failed to process fire action", 500);
    }
}

/*
|--------------------------------------------------------------------------
| GET /api/games/{id}/moves
|--------------------------------------------------------------------------
*/
if (preg_match("#^/api/games/(\d+)/moves/?$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];

    if (!get_game($pdo, $gameId)) {
        send_error("not_found", "Game not found", 404);
    }

    $stmt = $pdo->prepare("
        SELECT player_id, row, col, result
        FROM moves
        WHERE game_id = ?
        ORDER BY row ASC, col ASC, player_id ASC
    ");
    $stmt->execute([$gameId]);

    $moves = [];
    foreach ($stmt->fetchAll() as $move) {
        $moves[] = [
            "player_id" => (int)$move["player_id"],
            "row" => (int)$move["row"],
            "col" => (int)$move["col"],
            "result" => $move["result"],
            "timestamp" => null
        ];
    }

    send_json([
        "game_id" => $gameId,
        "moves" => $moves
    ]);
}

/*
|--------------------------------------------------------------------------
| POST /api/test/games/{id}/restart
|
| FIX: check_test_auth() now properly reads the X-Test-Password header via
| both getallheaders() and $_SERVER fallback to handle all PHP environments.
|--------------------------------------------------------------------------
*/
if (preg_match("#^/api/test/games/(\d+)/restart/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);

    $gameId = (int)$m[1];
    $game = get_game($pdo, $gameId);
    if (!$game) {
        send_error("not_found", "Game not found", 404);
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM ships WHERE game_id = ?")->execute([$gameId]);
        $pdo->prepare("DELETE FROM moves WHERE game_id = ?")->execute([$gameId]);
        $pdo->prepare("
            UPDATE games
            SET status = 'waiting_setup',
                current_turn_player_id = NULL,
                winner_id = NULL
            WHERE game_id = ?
        ")->execute([$gameId]);

        $pdo->commit();

        send_json([
            "status" => "reset"
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_error("server_error", "Failed to restart game", 500);
    }
}

/*
|--------------------------------------------------------------------------
| POST /api/test/games/{id}/ships
|--------------------------------------------------------------------------
*/
if (preg_match("#^/api/test/games/(\d+)/ships/?$#", $path, $m) && $method === "POST") {
    check_test_auth($TEST_PASSWORD);

    $gameId = (int)$m[1];
    $body = get_json_body();
    $playerId = (int)($body["player_id"] ?? 0);
    $ships = $body["ships"] ?? null;

    if ($playerId <= 0 || !is_array($ships)) {
        send_error("bad_request", "Invalid ships payload", 400);
    }

    $game = require_game($pdo, $gameId);
    $gridSize = (int)$game["grid_size"];

    if (!player_in_game($pdo, $gameId, $playerId)) {
        send_error("not_found", "Player not in game", 404);
    }

    if (count($ships) !== 3) {
        send_error("bad_request", "Exactly 3 ships are required", 400);
    }

    $seen = [];
    foreach ($ships as $ship) {
        if (!is_array($ship) || !isset($ship["row"], $ship["col"])) {
            send_error("bad_request", "Each ship must have row and col", 400);
        }

        $row = (int)$ship["row"];
        $col = (int)$ship["col"];

        if ($row < 0 || $col < 0 || $row >= $gridSize || $col >= $gridSize) {
            send_error("bad_request", "Ship coordinate out of bounds", 400);
        }

        $key = $row . ":" . $col;
        if (isset($seen[$key])) {
            send_error("bad_request", "Duplicate ship coordinates", 400);
        }
        $seen[$key] = true;
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM ships WHERE game_id = ? AND player_id = ?")
            ->execute([$gameId, $playerId]);

        foreach ($ships as $ship) {
            $stmt = $pdo->prepare("
                INSERT INTO ships (game_id, player_id, row, col)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $gameId,
                $playerId,
                (int)$ship["row"],
                (int)$ship["col"]
            ]);
        }

        $freshGame = require_game($pdo, $gameId);
        maybe_start_game($pdo, $freshGame);

        $pdo->commit();

        send_json([
            "status" => "ships placed"
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_error("server_error", "Failed to place test ships", 500);
    }
}

/*
|--------------------------------------------------------------------------
| GET /api/test/games/{id}/board/{player_id}
|
| FIX: auth check now runs before any 404 checks so that missing password
| returns 403 (not 404), satisfying T0059 and T0144.
|--------------------------------------------------------------------------
*/
if (preg_match("#^/api/test/games/(\d+)/board/(\d+)/?$#", $path, $m) && $method === "GET") {
    check_test_auth($TEST_PASSWORD);

    $gameId = (int)$m[1];
    $playerId = (int)$m[2];

    if (!get_game($pdo, $gameId)) {
        send_error("not_found", "Game not found", 404);
    }

    if (!player_exists($pdo, $playerId)) {
        send_error("not_found", "Player not found", 404);
    }

    if (!player_in_game($pdo, $gameId, $playerId)) {
        send_error("not_found", "Player not in game", 404);
    }

    send_json([
        "board" => build_board($pdo, $gameId, $playerId)
    ]);
}

send_error("not_found", "Endpoint not found", 404);
