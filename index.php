<?php

$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

header("Content-Type: application/json");

if ($request === "/test/reset" && $method === "POST") {
    echo json_encode(["status"=>"game reset"]);
    exit;
}

if ($request === "/players" && $method === "POST") {

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data["username"])) {
        http_response_code(400);
        echo json_encode(["error"=>"username required"]);
        exit;
    }

    http_response_code(201);

    echo json_encode([
        "id"=>uniqid(),
        "username"=>$data["username"],
        "wins"=>0,
        "losses"=>0,
        "shots"=>0
    ]);

    exit;
}

if ($request === "/games" && $method === "POST") {

    $data = json_decode(file_get_contents("php://input"), true);
    $size = $data["gridSize"] ?? 10;

    if ($size < 5) {
        http_response_code(400);
        echo json_encode(["error"=>"grid too small"]);
        exit;
    }

    if ($size > 20) {
        http_response_code(400);
        echo json_encode(["error"=>"grid too large"]);
        exit;
    }

    http_response_code(201);

    echo json_encode([
        "id"=>uniqid(),
        "status"=>"waiting",
        "gridSize"=>$size
    ]);

    exit;
}

if (preg_match("#^/games/([^/]+)/join$#", $request, $matches) && $method === "POST") {

    echo json_encode([
        "status"=>"joined",
        "gameId"=>$matches[1]
    ]);

    exit;
}

http_response_code(404);
echo json_encode(["error"=>"not found"]);
