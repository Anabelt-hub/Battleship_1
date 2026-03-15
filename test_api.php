<?php
header("Content-Type: application/json");

echo json_encode([
    "message" => "Test endpoints are routed through index.php. Use /test/reset, /test/reveal, /test/placeShips, and /test/forceTurn with the header X-Test-Mode: TEST_PASSWORD."
]);
