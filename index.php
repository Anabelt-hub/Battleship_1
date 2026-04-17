<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

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

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$db;sslmode=require",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode(["error" => "server_error", "message" => "DB connection failed"]);
    exit;
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

// Robust header reader - works on Apache CGI and mod_php
function get_test_password() {
    // Method 1: $_SERVER with Apache header conversion
    if (isset($_SERVER['HTTP_X_TEST_PASSWORD'])) {
        return $_SERVER['HTTP_X_TEST_PASSWORD'];
    }
    // Method 2: getallheaders() if available
    if (function_exists('getallheaders')) {
        $h = array_change_key_case(getallheaders(), CASE_LOWER);
        if (isset($h['x-test-password'])) return $h['x-test-password'];
    }
    // Method 3: apache_request_headers()
    if (function_exists('apache_request_headers')) {
        $h = array_change_key_case(apache_request_headers(), CASE_LOWER);
        if (isset($h['x-test-password'])) return $h['x-test-password'];
    }
    return "";
}

function check_test_auth() {
    global $TEST_PASSWORD;
    if (get_test_password() !== $TEST_PASSWORD) {
        send_error("forbidden", "Invalid or missing X-Test-Password header", 403);
    }
}

function safe_update($pdo, $sql, $params) {
    try { $pdo->prepare($sql)->execute($params); return true; }
    catch (PDOException $e) { return false; }
}

$requestUri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = trim(str_replace("/index.php", "", $requestUri), "/");
$method = $_SERVER["REQUEST_METHOD"];

// Static pages
if ($path === "" || $path === "index.html") { include_once("index.html"); exit; }

// GET /api
if ($path === "api" && $method === "GET") {
    send_json(["name"=>"Battleship API","version"=>"2.3.0","spec_version"=>"2.3","environment"=>"production","test_mode"=>true]);
}

// GET /api/health
if ($path === "api/health" && $method === "GET") {
    send_json(["status" => "ok", "debug_version" => "v7"]);
}

// POST|DELETE /api/reset
if ($path === "api/reset" && ($method === "POST" || $method === "DELETE")) {
    $pdo->exec("TRUNCATE TABLE moves, ships, game_players, games, players RESTART IDENTITY CASCADE");
    send_json(["status" => "reset"]);
}

// ── TEST ENDPOINTS ────────────────────────────────────────────────────────────

// POST /api/test/games/{id}/restart
if (preg_match("#^api/test/games/(\d+)/restart$#", $path, $m) && $method === "POST") {
    check_test_auth();
    $gameId = (int)$m[1];
    $s = $pdo->prepare("SELECT 1 FROM games WHERE game_id=?");
    $s->execute([$gameId]);
    if (!$s->fetch()) send_error("not_found", "Game not found", 404);

    safe_update($pdo, "DELETE FROM moves WHERE game_id=?", [$gameId]);
    safe_update($pdo, "DELETE FROM ships WHERE game_id=?", [$gameId]);
    safe_update($pdo, "DELETE FROM game_players WHERE game_id=?", [$gameId]);
    if (!safe_update($pdo, "UPDATE games SET status='waiting_setup', current_turn_player_id=NULL, winner_id=NULL, total_moves=0 WHERE game_id=?", [$gameId])) {
        safe_update($pdo, "UPDATE games SET status='waiting_setup', current_turn_player_id=NULL WHERE game_id=?", [$gameId]);
    }
    send_json(["status" => "reset"]);
}

// POST /api/test/games/{id}/ships
if (preg_match("#^api/test/games/(\d+)/ships$#", $path, $m) && $method === "POST") {
    check_test_auth();
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $gameId = (int)$m[1];
    $playerId = (int)($body["player_id"] ?? 0);
    foreach (($body["ships"] ?? []) as $s) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?,?,?,?)")
            ->execute([$gameId, $playerId, (int)$s["row"], (int)$s["col"]]);
    }
    send_json(["status" => "ships placed"]);
}

// GET /api/test/games/{id}/board/{pid}
if (preg_match("#^api/test/games/(\d+)/board/(\d+)$#", $path, $m) && $method === "GET") {
    check_test_auth();
    $gameId = (int)$m[1]; $playerId = (int)$m[2];
    $s = $pdo->prepare("SELECT row, col FROM ships WHERE game_id=? AND player_id=? ORDER BY row,col");
    $s->execute([$gameId, $playerId]);
    $ships = [];
    foreach ($s->fetchAll() as $r) $ships[] = ["row"=>(int)$r["row"],"col"=>(int)$r["col"]];
    send_json(["game_id"=>$gameId,"player_id"=>$playerId,"ships"=>$ships]);
}

// ── PLAYER ENDPOINTS ──────────────────────────────────────────────────────────

// POST /api/players
if ($path === "api/players" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    if (!isset($body["username"])) send_error("bad_request", "Missing required field: username", 400);
    $username = trim($body["username"]);
    if ($username === "" || strlen($username) > 30 || !preg_match('/^[a-zA-Z0-9_]+$/', $username))
        send_error("bad_request", "Invalid username", 400);
    $s = $pdo->prepare("SELECT 1 FROM players WHERE username=?"); $s->execute([$username]);
    if ($s->fetch()) send_error("conflict", "Username already taken", 409);
    $s = $pdo->prepare("INSERT INTO players (username) VALUES (?) RETURNING player_id");
    $s->execute([$username]);
    send_json(["player_id" => (int)$s->fetch()["player_id"]], 201);
}

// GET /api/players/{id}/stats
if (preg_match('#^api/players/(\d+)/stats$#', $path, $m) && $method === "GET") {
    $pId = (int)$m[1];
    $s = $pdo->prepare("SELECT 1 FROM players WHERE player_id=?"); $s->execute([$pId]);
    if (!$s->fetch()) send_error("not_found", "Player not found", 404);

    // wins - try with winner_id, fall back to 0 if column missing
    $wins = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) as wins FROM games WHERE winner_id=? AND status='finished'");
        $s->execute([$pId]); $wins = (int)$s->fetch()["wins"];
    } catch (PDOException $e) {}

    // games_played
    $games_played = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(DISTINCT g.game_id) as gp FROM games g
            WHERE g.status='finished' AND (
                EXISTS(SELECT 1 FROM game_players gp2 WHERE gp2.game_id=g.game_id AND gp2.player_id=?)
                OR EXISTS(SELECT 1 FROM moves mv WHERE mv.game_id=g.game_id AND mv.player_id=?)
            )");
        $s->execute([$pId, $pId]); $games_played = (int)$s->fetch()["gp"];
    } catch (PDOException $e) {}

    $losses = max(0, $games_played - $wins);

    $s = $pdo->prepare("SELECT COUNT(*) as shots, SUM(CASE WHEN result='hit' THEN 1 ELSE 0 END) as hits FROM moves WHERE player_id=?");
    $s->execute([$pId]); $res = $s->fetch();
    $shots = (int)($res["shots"]??0); $hits = (int)($res["hits"]??0);
    $accuracy = $shots > 0 ? round($hits/$shots, 4) : 0.0;

    send_json(["player_id"=>$pId,"games_played"=>$games_played,"wins"=>$wins,"losses"=>$losses,
               "total_shots"=>$shots,"total_hits"=>$hits,"accuracy"=>(float)$accuracy]);
}

// ── GAME ENDPOINTS ────────────────────────────────────────────────────────────

// POST /api/games
if ($path === "api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    if (!isset($body["grid_size"]) || !isset($body["creator_id"]) || !isset($body["max_players"]))
        send_error("bad_request", "missing required fields", 400);
    $gridSize = $body["grid_size"]; $maxPlayers = (int)$body["max_players"];
    if (!is_numeric($gridSize) || (int)$gridSize < 5 || (int)$gridSize > 15)
        send_error("bad_request", "grid_size must be between 5 and 15", 400);
    if ($maxPlayers < 2) send_error("bad_request", "max_players must be at least 2", 400);
    $cId = (int)$body["creator_id"];
    $s = $pdo->prepare("SELECT 1 FROM players WHERE player_id=?"); $s->execute([$cId]);
    if (!$s->fetch()) send_error("not_found", "creator_id does not exist", 404);
    $gridSize = (int)$gridSize;
    $s = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?,?,'waiting_setup') RETURNING game_id");
    $s->execute([$gridSize, $maxPlayers]);
    send_json(["game_id"=>(int)$s->fetch()["game_id"],"status"=>"waiting_setup"], 201);
}

// GET /api/games/{id}
if (preg_match("#^api/games/(\d+)$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $s = $pdo->prepare("SELECT * FROM games WHERE game_id=?"); $s->execute([$gameId]);
    $g = $s->fetch();
    if (!$g) send_error("not_found", "Game not found", 404);

    $s = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id=?"); $s->execute([$gameId]);
    $players = [];
    foreach ($s->fetchAll() as $row) {
        $pid = (int)$row["player_id"];
        $sr = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s
            WHERE s.game_id=? AND s.player_id=?
            AND NOT EXISTS(SELECT 1 FROM moves mv WHERE mv.game_id=s.game_id AND mv.row=s.row AND mv.col=s.col AND mv.result='hit')");
        $sr->execute([$gameId, $pid]);
        $players[] = ["player_id"=>$pid, "ships_remaining"=>(int)$sr->fetch()["rem"]];
    }
    $ctp = ($g["current_turn_player_id"] !== null) ? (int)$g["current_turn_player_id"] : null;
    $total_moves = isset($g["total_moves"]) ? (int)$g["total_moves"] : 0;
    send_json(["game_id"=>(int)$g["game_id"],"grid_size"=>(int)$g["grid_size"],"status"=>$g["status"],
               "players"=>$players,"current_turn_player_id"=>$ctp,"total_moves"=>$total_moves]);
}

// POST /api/games/{id}/join
if (preg_match("#^api/games/(\d+)/join$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    $s = $pdo->prepare("SELECT * FROM games WHERE game_id=?"); $s->execute([$gameId]);
    $game = $s->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    $s = $pdo->prepare("SELECT 1 FROM players WHERE player_id=?"); $s->execute([$playerId]);
    if (!$s->fetch()) send_error("not_found", "Player not found", 404);

    $s = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id=? AND player_id=?"); $s->execute([$gameId, $playerId]);
    if ($s->fetch()) send_error("bad_request", "Player already in game", 400);

    $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM game_players WHERE game_id=?"); $s->execute([$gameId]);
    if ((int)$s->fetch()["cnt"] >= (int)$game["max_players"]) send_error("bad_request", "Game is full", 400);

    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?,?)")->execute([$gameId, $playerId]);
    send_json(["status"=>"joined","game_id"=>$gameId,"player_id"=>$playerId]);
}

// POST /api/games/{id}/place
if (preg_match("#^api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);

    if (!isset($body["ships"]) || !is_array($body["ships"]))
        send_error("bad_request", "Invalid ships payload", 400);

    $s = $pdo->prepare("SELECT * FROM games WHERE game_id=?"); $s->execute([$gameId]);
    $game = $s->fetch();
    if (!$game) send_error("not_found", "Game not found", 404);

    // Already placed → 409
    $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM ships WHERE game_id=? AND player_id=?");
    $s->execute([$gameId, $playerId]);
    if ((int)$s->fetch()["cnt"] > 0) send_error("conflict", "Ships already placed", 409);

    $gridSize = (int)$game["grid_size"];
    $ships = $body["ships"];
    $seen = [];
    foreach ($ships as $ship) {
        $r = (int)($ship["row"] ?? -1); $c = (int)($ship["col"] ?? -1);
        if ($r < 0 || $r >= $gridSize || $c < 0 || $c >= $gridSize)
            send_error("bad_request", "Invalid ship coordinates", 400);
        $key = "$r,$c";
        if (isset($seen[$key])) send_error("bad_request", "Duplicate ship coordinates", 400);
        $seen[$key] = true;
    }

    $pdo->beginTransaction();
    foreach ($ships as $ship) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?,?,?,?)")
            ->execute([$gameId, $playerId, (int)$ship["row"], (int)$ship["col"]]);
    }

    // Transition to playing when all joined players have placed
    $sp = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id=? ORDER BY player_id ASC");
    $sp->execute([$gameId]); $allPlayers = $sp->fetchAll();
    $allPlaced = count($allPlayers) >= 2;
    foreach ($allPlayers as $p) {
        $sc = $pdo->prepare("SELECT COUNT(*) as cnt FROM ships WHERE game_id=? AND player_id=?");
        $sc->execute([$gameId, (int)$p["player_id"]]);
        if ((int)$sc->fetch()["cnt"] === 0) { $allPlaced = false; break; }
    }
    if ($allPlaced) {
        $firstPlayer = (int)$allPlayers[0]["player_id"];
        $pdo->prepare("UPDATE games SET status='playing', current_turn_player_id=? WHERE game_id=?")
            ->execute([$firstPlayer, $gameId]);
    }
    $pdo->commit();
    send_json(["status" => "placed"]);
}

// GET /api/games/{id}/moves
if (preg_match("#^api/games/(\d+)/moves$#", $path, $m) && $method === "GET") {
    $gameId = (int)$m[1];
    $s = $pdo->prepare("SELECT 1 FROM games WHERE game_id=?"); $s->execute([$gameId]);
    if (!$s->fetch()) send_error("not_found", "Game not found", 404);
    $s = $pdo->prepare("SELECT * FROM moves WHERE game_id=? ORDER BY move_id ASC"); $s->execute([$gameId]);
    $moves = [];
    foreach ($s->fetchAll() as $mv) {
        $moves[] = ["move_id"=>(int)$mv["move_id"],"player_id"=>(int)$mv["player_id"],
                    "row"=>(int)$mv["row"],"col"=>(int)$mv["col"],"result"=>$mv["result"]];
    }
    send_json(["game_id"=>$gameId,"moves"=>$moves]);
}

// POST /api/games/{id}/fire
if (preg_match("#^api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    if (!isset($body["player_id"]) || !isset($body["row"]) || !isset($body["col"]))
        send_error("bad_request", "Missing player_id, row, or col", 400);

    $playerId = (int)$body["player_id"];
    $r = (int)$body["row"]; $c = (int)$body["col"];

    $s = $pdo->prepare("SELECT * FROM games WHERE game_id=?"); $s->execute([$gameId]);
    $g = $s->fetch();
    if (!$g) send_error("not_found", "Game not found", 404);

    if ($g["status"] === "finished") send_error("bad_request", "Game is already finished", 400);
    if ($g["status"] !== "playing")  send_error("forbidden", "Game is not in playing state", 403);

    if ($r < 0 || $r >= (int)$g["grid_size"] || $c < 0 || $c >= (int)$g["grid_size"])
        send_error("bad_request", "out of bounds", 400);

    // Duplicate check BEFORE turn check (any player at this cell)
    $sd = $pdo->prepare("SELECT 1 FROM moves WHERE game_id=? AND row=? AND col=?");
    $sd->execute([$gameId, $r, $c]);
    if ($sd->fetch()) send_error("conflict", "Cell already targeted", 409);

    // Turn check
    $currentTurn = $g["current_turn_player_id"];
    if ($currentTurn !== null) {
        if ((int)$currentTurn !== $playerId) send_error("forbidden", "not your turn", 403);
    } else {
        $sf = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id=? ORDER BY player_id ASC LIMIT 1");
        $sf->execute([$gameId]); $firstRow = $sf->fetch();
        if ($firstRow && (int)$firstRow["player_id"] !== $playerId)
            send_error("forbidden", "not your turn", 403);
    }

    // Hit or miss
    $sh = $pdo->prepare("SELECT 1 FROM ships WHERE game_id=? AND player_id!=? AND row=? AND col=?");
    $sh->execute([$gameId, $playerId, $r, $c]);
    $result = $sh->fetch() ? "hit" : "miss";

    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?,?,?,?,?)")
        ->execute([$gameId, $playerId, $r, $c, $result]);

    // Find opponent — game_players first, then ships fallback
    $so = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id=? AND player_id!=? LIMIT 1");
    $so->execute([$gameId, $playerId]); $opp = $so->fetch();
    if (!$opp) {
        $so2 = $pdo->prepare("SELECT DISTINCT player_id FROM ships WHERE game_id=? AND player_id!=? LIMIT 1");
        $so2->execute([$gameId, $playerId]); $opp = $so2->fetch();
    }
    $oppId = $opp ? (int)$opp["player_id"] : 0;

    // Count remaining opponent ships
    $sr = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE s.game_id=? AND s.player_id=?
        AND NOT EXISTS(SELECT 1 FROM moves mv WHERE mv.game_id=s.game_id AND mv.row=s.row AND mv.col=s.col AND mv.result='hit')");
    $sr->execute([$gameId, $oppId]);
    $remaining = (int)$sr->fetch()["rem"];

    $gameStatus = ($oppId > 0 && $remaining === 0) ? "finished" : "playing";

    if ($gameStatus === "finished") {
        if (!safe_update($pdo, "UPDATE games SET status='finished', winner_id=?, total_moves=total_moves+1 WHERE game_id=?", [$playerId, $gameId]))
            safe_update($pdo, "UPDATE games SET status='finished' WHERE game_id=?", [$gameId]);
    } else {
        if (!safe_update($pdo, "UPDATE games SET current_turn_player_id=?, total_moves=total_moves+1 WHERE game_id=?", [$oppId, $gameId]))
            safe_update($pdo, "UPDATE games SET current_turn_player_id=? WHERE game_id=?", [$oppId, $gameId]);
    }

    send_json(["result"=>$result,"game_status"=>$gameStatus,
               "next_player_id"=>$gameStatus==="finished" ? null : (int)$oppId]);
}

// Fallthrough 404
send_error("not_found", "Endpoint not found", 404);
