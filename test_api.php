<?php
header('Content-Type: application/json');

$TEST_PASSWORD = "battleship-test";
$stateFile = __DIR__ . "/test_state.json";

function get_headers_lowercase() {
    $headers = [];
    foreach (getallheaders() as $key => $value) {
        $headers[strtolower($key)] = $value;
    }
    return $headers;
}

function require_test_mode($password) {
    $headers = get_headers_lowercase();

    if (!isset($headers['x-test-mode']) || $headers['x-test-mode'] !== $password) {
        http_response_code(403);
        echo json_encode(["error"=>"Forbidden"]);
        exit;
    }
}

function create_empty_board() {
    $board = [];
    for ($r=0;$r<10;$r++) {
        $row=[];
        for ($c=0;$c<10;$c++) {
            $row[]=".";
        }
        $board[]=$row;
    }
    return $board;
}

function default_state() {
    return [
        "board"=>create_empty_board(),
        "turn"=>"player1"
    ];
}

function load_state($file) {
    if (!file_exists($file)) {
        return default_state();
    }

    $data=json_decode(file_get_contents($file),true);

    if (!$data) {
        return default_state();
    }

    return $data;
}

function save_state($file,$state) {
    file_put_contents($file,json_encode($state,JSON_PRETTY_PRINT));
}

function position_to_indexes($pos) {
    $pos=strtoupper(trim($pos));

    $rowChar=substr($pos,0,1);
    $colNum=intval(substr($pos,1));

    $row=ord($rowChar)-65;
    $col=$colNum-1;

    return [$row,$col];
}

function ship_letter($type) {

    switch(strtolower($type)) {

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

require_test_mode($TEST_PASSWORD);

$action=$_GET['action'] ?? '';
$method=$_SERVER['REQUEST_METHOD'];

if ($action==="reset" && $method==="POST") {

    $state=default_state();
    save_state($stateFile,$state);

    echo json_encode(["status"=>"game reset"]);
    exit;
}

if ($action==="reveal" && $method==="GET") {

    $state=load_state($stateFile);

    echo json_encode([
        "board"=>$state["board"],
        "turn"=>$state["turn"]
    ]);

    exit;
}

if ($action==="placeShips" && $method==="POST") {

    $input=json_decode(file_get_contents("php://input"),true);

    $state=load_state($stateFile);
    $state["board"]=create_empty_board();

    foreach($input["ships"] as $ship){

        $letter=ship_letter($ship["type"]);

        foreach($ship["positions"] as $pos){

            [$row,$col]=position_to_indexes($pos);

            $state["board"][$row][$col]=$letter;

        }
    }

    save_state($stateFile,$state);

    echo json_encode(["status"=>"ships placed"]);

    exit;
}

if ($action==="forceTurn" && $method==="POST") {

    $input=json_decode(file_get_contents("php://input"),true);

    $state=load_state($stateFile);

    $state["turn"]=$input["player"];

    save_state($stateFile,$state);

    echo json_encode(["turn"=>$state["turn"]]);

    exit;
}

http_response_code(400);
echo json_encode(["error"=>"Invalid action"]);
