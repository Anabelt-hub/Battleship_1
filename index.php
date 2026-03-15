<?php
header("Content-Type: application/json");

$DATA_FILE = __DIR__ . DIRECTORY_SEPARATOR . "phase1_state.json";
$TEST_PASSWORD = "battleship-test";

function send_json($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function get_headers_lowercase() {
    $headers = [];
    foreach (getallheaders() as $k => $v) {
        $headers[strtolower($k)] = $v;
    }
    return $headers;
}

function require_test_mode($password) {
    $headers = get_headers_lowercase();
    if (!isset($headers["x-test-mode"]) || $headers["x-test-mode"] !== $password) {
        send_json(["error" => "Forbidden"], 403);
    }
}

function create_empty_board($size = 10) {
    $board = [];
    for ($r = 0; $r < $size; $r++) {
        $row = [];
        for ($c = 0; $c < $size; $c++) {
            $row[] = ".";
        }
        $board[] = $row;
    }
    return $board;
}

function default_state() {
    return [
        "nextPlayerId" => 1,
        "nextGameId" => 1,
        "players" => [],
        "games" => [],
        "test" => [
            "board" => create_empty_board(10),
            "turn" => "player1"
        ]
    ];
}

function load_state($file) {
    if (!file_exists($file)) {
        return default_state();
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === "") {
        return default_state();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return default_state();
    }

    if (!isset($data["players"]) || !isset($data["games"]) || !isset($data["test"])) {
        return default_state();
    }

    return $data;
}

function save_state($file, $state) {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
}

function get_request_body() {
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === "") {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function position_to_indexes($pos) {
    $pos = strtoupper(trim($pos));
    $rowChar = substr($pos, 0, 1);
    $colNum = intval(substr($pos, 1));

    $row = ord($rowChar) - ord('A');
    $col = $colNum - 1;

    if ($row < 0 || $row > 9 || $col < 0 || $col > 9) {
        return null;
    }

    return [$row, $col];
}

function ship_letter($type) {
    switch (strtolower($type)) {
        case "carrier":
            return "C";
        case "battleship":
            return "B";
        case "cruiser":
            return "R";
        case "submarine":
            return "S";
        case "destroyer":
            return "D";
        default:
            return "?";
    }
}

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$method = $_SERVER["REQUEST_METHOD"];

$state = load_state($DATA_FILE);

if ($path === "/" && $method === "GET") {
    if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . "index.html")) {
        header("Content-Type: text/html");
        readfile(__DIR__ . DIRECTORY_SEPARATOR . "index.html");
        exit;
    }
    send_json(["message" => "Battleship API is running"]);
}

/*
|--------------------------------------------------------------------------
| Phase 1 core endpoints
|--------------------------------------------------------------------------
*/

if ($path === "/players" && $method === "POST") {
    $body = get_request_body();

    if (!isset($body["username"]) || trim($body["username"]) === "") {
        send_json(["error" => "username required"], 400);
    }

    $playerId = "player-" . $state["nextPlayerId"];
    $state["nextPlayerId"]++;

    $player = [
        "id" => $playerId,
        "username" => trim($body["username"]),
        "wins" => 0,
        "losses" => 0,
        "shots" => 0
    ];

    $state["players"][$playerId] = $player;
    save_state($DATA_FILE, $state);

    send_json($player, 201);
}

if ($path === "/games" && $method === "POST") {
    $body = get_request_body();
    $gridSize = isset($body["gridSize"]) ? intval($body["gridSize"]) : 10;

    if ($gridSize < 5) {
        send_json(["error" => "grid too small"], 400);
    }

    if ($gridSize > 20) {
        send_json(["error" => "grid too large"], 400);
    }

    $gameId = "game-" . $state["nextGameId"];
    $state["nextGameId"]++;

    $game = [
        "id" => $gameId,
        "status" => "waiting",
        "gridSize" => $gridSize,
        "players" => [],
        "createdAt" => time()
    ];

    $state["games"][$gameId] = $game;
    save_state($DATA_FILE, $state);

    send_json($game, 201);
}

if (preg_match("#^/games/([^/]+)/join$#", $path, $matches) && $method === "POST") {
    $gameId = $matches[1];
    $body = get_request_body();

    if (!isset($state["games"][$gameId])) {
        send_json(["error" => "game not found"], 404);
    }

    $game = $state["games"][$gameId];

    if (!isset($body["playerId"]) || !isset($state["players"][$body["playerId"]])) {
        send_json(["error" => "player not found"], 404);
    }

    $playerId = $body["playerId"];

    if (!in_array($playerId, $game["players"], true)) {
        $game["players"][] = $playerId;
    }

    if (count($game["players"]) >= 2) {
        $game["status"] = "ready";
    }

    $state["games"][$gameId] = $game;
    save_state($DATA_FILE, $state);

    send_json($game, 200);
}

/*
|--------------------------------------------------------------------------
| Test mode endpoints from appendix
|--------------------------------------------------------------------------
*/

if ($path === "/test/reset" && $method === "POST") {
    require_test_mode($TEST_PASSWORD);

    $state = default_state();
    save_state($DATA_FILE, $state);

    send_json(["status" => "game reset"], 200);
}

if ($path === "/test/reveal" && $method === "GET") {
    require_test_mode($TEST_PASSWORD);

    send_json([
        "board" => $state["test"]["board"],
        "turn" => $state["test"]["turn"]
    ], 200);
}

if ($path === "/test/placeShips" && $method === "POST") {
    require_test_mode($TEST_PASSWORD);

    $body = get_request_body();

    if (!isset($body["ships"]) || !is_array($body["ships"])) {
        send_json(["error" => "Invalid ships payload"], 400);
    }

    $state["test"]["board"] = create_empty_board(10);

    foreach ($body["ships"] as $ship) {
        if (!isset($ship["type"]) || !isset($ship["positions"]) || !is_array($ship["positions"])) {
            send_json(["error" => "Invalid ship format"], 400);
        }

        $letter = ship_letter($ship["type"]);

        foreach ($ship["positions"] as $pos) {
            $coords = position_to_indexes($pos);
            if ($coords === null) {
                send_json(["error" => "Invalid board position: " . $pos], 400);
            }

            [$row, $col] = $coords;
            $state["test"]["board"][$row][$col] = $letter;
        }
    }

    save_state($DATA_FILE, $state);
    send_json(["status" => "ships placed"], 200);
}

if ($path === "/test/forceTurn" && $method === "POST") {
    require_test_mode($TEST_PASSWORD);

    $body = get_request_body();

    if (!isset($body["player"]) || trim($body["player"]) === "") {
        send_json(["error" => "Missing player"], 400);
    }

    $state["test"]["turn"] = $body["player"];
    save_state($DATA_FILE, $state);

    send_json(["turn" => $state["test"]["turn"]], 200);
}

/*
|--------------------------------------------------------------------------
| Optional compatibility endpoints if the grader still calls your old file
|--------------------------------------------------------------------------
*/

if ($path === "/test_api.php" && isset($_GET["action"])) {
    $action = $_GET["action"];

    if ($action === "reset" && $method === "POST") {
        require_test_mode($TEST_PASSWORD);
        $state = default_state();
        save_state($DATA_FILE, $state);
        send_json(["status" => "game reset"], 200);
    }

    if ($action === "reveal" && $method === "GET") {
        require_test_mode($TEST_PASSWORD);
        send_json([
            "board" => $state["test"]["board"],
            "turn" => $state["test"]["turn"]
        ], 200);
    }

    if ($action === "placeShips" && $method === "POST") {
        require_test_mode($TEST_PASSWORD);
        $body = get_request_body();

        if (!isset($body["ships"]) || !is_array($body["ships"])) {
            send_json(["error" => "Invalid ships payload"], 400);
        }

        $state["test"]["board"] = create_empty_board(10);

        foreach ($body["ships"] as $ship) {
            $letter = ship_letter($ship["type"]);
            foreach ($ship["positions"] as $pos) {
                $coords = position_to_indexes($pos);
                if ($coords === null) {
                    send_json(["error" => "Invalid board position"], 400);
                }
                [$row, $col] = $coords;
                $state["test"]["board"][$row][$col] = $letter;
            }
        }

        save_state($DATA_FILE, $state);
        send_json(["status" => "ships placed"], 200);
    }

    if ($action === "forceTurn" && $method === "POST") {
        require_test_mode($TEST_PASSWORD);
        $body = get_request_body();
        if (!isset($body["player"])) {
            send_json(["error" => "Missing player"], 400);
        }
        $state["test"]["turn"] = $body["player"];
        save_state($DATA_FILE, $state);
        send_json(["turn" => $state["test"]["turn"]], 200);
    }

    send_json(["error" => "Invalid action"], 400);
}

send_json(["error" => "not found", "path" => $path], 404);
