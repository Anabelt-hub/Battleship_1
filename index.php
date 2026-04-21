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

function get_test_password() {
    if (isset($_SERVER['HTTP_X_TEST_PASSWORD'])) return $_SERVER['HTTP_X_TEST_PASSWORD'];
    if (function_exists('getallheaders')) {
        $h = array_change_key_case(getallheaders(), CASE_LOWER);
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

if ($path === "" || $path === "index.html") { include_once("index.html"); exit; }

// GET /api
if ($path === "api" && $method === "GET") {
    send_json(["name"=>"Battleship API","version"=>"2.3.0","spec_version"=>"2.3","environment"=>"production","test_mode"=>true]);
}

// GET /api/health
if ($path === "api/health" && $method === "GET") {
    send_json(["status" => "ok", "debug_version" => "v7"]);
}

// GET /api/games (Lobby Support)
if ($path === "api/games" && $method === "GET") {
    $stmt = $pdo->prepare("SELECT game_id, status, max_players, grid_size FROM games ORDER BY game_id DESC LIMIT 50");
    $stmt->execute();
    send_json($stmt->fetchAll());
}

// POST|DELETE /api/reset
if ($path === "api/reset" && ($method === "POST" || $method === "DELETE")) {
    $pdo->exec("TRUNCATE TABLE moves, ships, game_players, games, players RESTART IDENTITY CASCADE");
    send_json(["status" => "reset"]);
}

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
    $s = $pdo->prepare("SELECT COUNT(*) as wins FROM games WHERE winner_id=? AND status='finished'");
    $s->execute([$pId]); $wins = (int)$s->fetch()["wins"];
    $s = $pdo->prepare("SELECT COUNT(DISTINCT game_id) as gp FROM game_players WHERE player_id=?");
    $s->execute([$pId]); $gp = (int)$s->fetch()["gp"];
    $losses = max(0, $gp - $wins);
    $s = $pdo->prepare("SELECT COUNT(*) as shots, SUM(CASE WHEN result='hit' THEN 1 ELSE 0 END) as hits FROM moves WHERE player_id=?");
    $s->execute([$pId]); $res = $s->fetch();
    $shots = (int)($res["shots"]??0); $hits = (int)($res["hits"]??0);
    $acc = $shots > 0 ? round($hits/$shots, 4) : 0.0;
    send_json(["player_id"=>$pId,"games_played"=>$gp,"wins"=>$wins,"losses"=>$losses,"total_shots"=>$shots,"total_hits"=>$hits,"accuracy"=>(float)$acc]);
}

// POST /api/games
if ($path === "api/games" && $method === "POST") {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    if (!isset($body["grid_size"], $body["creator_id"], $body["max_players"])) send_error("bad_request", "Missing fields", 400);
    $s = $pdo->prepare("INSERT INTO games (grid_size, max_players, status) VALUES (?,?,'waiting_setup') RETURNING game_id");
    $s->execute([(int)$body["grid_size"], (int)$body["max_players"]]);
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
        $sr = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE s.game_id=? AND s.player_id=?
            AND NOT EXISTS(SELECT 1 FROM moves mv WHERE mv.game_id=s.game_id AND mv.row=s.row AND mv.col=s.col AND mv.result='hit')");
        $sr->execute([$gameId, $pid]);
        $players[] = ["player_id"=>$pid, "ships_remaining"=>(int)$sr->fetch()["rem"]];
    }
    send_json(["game_id"=>(int)$g["game_id"],"grid_size"=>(int)$g["grid_size"],"status"=>$g["status"],"players"=>$players,"current_turn_player_id"=>$g["current_turn_player_id"]]);
}

// POST /api/games/{id}/join
if (preg_match("#^api/games/(\d+)/join$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    $pdo->prepare("INSERT INTO game_players (game_id, player_id) VALUES (?,?)")->execute([$gameId, $playerId]);
    send_json(["status"=>"joined","game_id"=>$gameId,"player_id"=>$playerId]);
}

// POST /api/games/{id}/place (12 Coordinate Check for 5, 4, 3 sequence)
if (preg_match("#^api/games/(\d+)/place/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)($body["player_id"] ?? 0);
    if (!isset($body["ships"]) || count($body["ships"]) !== 12) send_error("bad_request", "12 coordinates required (5+4+3)", 400);
    
    $pdo->beginTransaction();
    foreach ($body["ships"] as $ship) {
        $pdo->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (?,?,?,?)")
            ->execute([$gameId, $playerId, (int)$ship["row"], (int)$ship["col"]]);
    }
    
    $sp = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id=? ORDER BY player_id ASC");
    $sp->execute([$gameId]); $all = $sp->fetchAll();
    $ready = count($all) >= 2;
    foreach ($all as $p) {
        $sc = $pdo->prepare("SELECT 1 FROM ships WHERE game_id=? AND player_id=?");
        $sc->execute([$gameId, (int)$p["player_id"]]);
        if (!$sc->fetch()) { $ready = false; break; }
    }
    if ($ready) $pdo->prepare("UPDATE games SET status='playing', current_turn_player_id=? WHERE game_id=?")->execute([(int)$all[0]["player_id"], $gameId]);
    $pdo->commit();
    send_json(["status" => "placed"]);
}

// POST /api/games/{id}/fire
if (preg_match("#^api/games/(\d+)/fire/?$#", $path, $m) && $method === "POST") {
    $gameId = (int)$m[1];
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $playerId = (int)$body["player_id"];
    $r = (int)$body["row"]; $c = (int)$body["col"];

    $s = $pdo->prepare("SELECT * FROM games WHERE game_id=?"); $s->execute([$gameId]);
    $g = $s->fetch();
    if (!$g || $g["status"] !== "playing") send_error("forbidden", "Game not active", 403);
    if ((int)$g["current_turn_player_id"] !== $playerId) send_error("forbidden", "Not your turn", 403);

    $sh = $pdo->prepare("SELECT 1 FROM ships WHERE game_id=? AND player_id!=? AND row=? AND col=?");
    $sh->execute([$gameId, $playerId, $r, $c]);
    $res = $sh->fetch() ? "hit" : "miss";
    $pdo->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?,?,?,?,?)")->execute([$gameId, $playerId, $r, $c, $res]);

    $so = $pdo->prepare("SELECT player_id FROM game_players WHERE game_id=? AND player_id!=? LIMIT 1");
    $so->execute([$gameId, $playerId]); $oppId = (int)$so->fetch()["player_id"];
    $sr = $pdo->prepare("SELECT COUNT(*) as rem FROM ships s WHERE s.game_id=? AND s.player_id=?
        AND NOT EXISTS(SELECT 1 FROM moves mv WHERE mv.game_id=s.game_id AND mv.row=s.row AND mv.col=s.col AND mv.result='hit')");
    $sr->execute([$gameId, $oppId]);
    $stat = ((int)$sr->fetch()["rem"] === 0) ? "finished" : "playing";

    if ($stat === "finished") $pdo->prepare("UPDATE games SET status='finished', winner_id=?, total_moves=total_moves+1 WHERE game_id=?")->execute([$playerId, $gameId]);
    else $pdo->prepare("UPDATE games SET current_turn_player_id=?, total_moves=total_moves+1 WHERE game_id=?")->execute([$oppId, $gameId]);

    send_json(["result"=>$res,"game_status"=>$stat,"next_player_id"=>$stat==="finished" ? null : $oppId]);
}

send_error("not_found", "Endpoint not found", 404);
