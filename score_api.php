<?php
// score_api.php — Server-side JSON persistence for Battleship Exam
// Stores aggregate stats that must persist across refresh and Apache restart.

header('Content-Type: application/json');

$action = isset($_GET['action']) ? $_GET['action'] : 'get';
$file = __DIR__ . DIRECTORY_SEPARATOR . 'scoreboard.json';

$default = array(
  "wins" => 0,
  "losses" => 0,
  "shots" => 0
);

function safe_read_scoreboard($file, $default) {
  if (!file_exists($file)) {
    return $default;
  }
  $raw = file_get_contents($file);
  if ($raw === false || trim($raw) === "") {
    return $default;
  }
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return $default;
  }
  // normalize
  return array(
    "wins" => intval($data["wins"] ?? 0),
    "losses" => intval($data["losses"] ?? 0),
    "shots" => intval($data["shots"] ?? 0),
  );
}

function safe_write_scoreboard($file, $data) {
  // Use a temp file + rename for atomic write
  $tmp = $file . ".tmp";
  $json = json_encode($data, JSON_PRETTY_PRINT);

  $fp = fopen($tmp, "wb");
  if ($fp === false) return false;

  // lock while writing temp
  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    return false;
  }

  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  return rename($tmp, $file);
}

// Ensure file exists at least once
if (!file_exists($file)) {
  safe_write_scoreboard($file, $default);
}

// Read-modify-write with locking on the main file
$fpMain = fopen($file, "c+");
if ($fpMain === false) {
  echo json_encode(array("ok" => false, "error" => "Unable to open scoreboard.json"));
  exit;
}

if (!flock($fpMain, LOCK_EX)) {
  fclose($fpMain);
  echo json_encode(array("ok" => false, "error" => "Unable to lock scoreboard.json"));
  exit;
}

// Read current
rewind($fpMain);
$raw = stream_get_contents($fpMain);
$current = $default;
if ($raw !== false && trim($raw) !== "") {
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    $current = array(
      "wins" => intval($decoded["wins"] ?? 0),
      "losses" => intval($decoded["losses"] ?? 0),
      "shots" => intval($decoded["shots"] ?? 0),
    );
  }
}

// Apply action
switch ($action) {
  case "recordWin":
    $current["wins"] += 1;
    break;
  case "recordLoss":
    $current["losses"] += 1;
    break;
  case "recordShot":
    $current["shots"] += 1;
    break;
  case "reset":
    $current = $default;
    break;
  case "get":
  default:
    // no-op
    break;
}

// Write back
// Truncate + write in-place
rewind($fpMain);
ftruncate($fpMain, 0);
fwrite($fpMain, json_encode($current, JSON_PRETTY_PRINT));
fflush($fpMain);

flock($fpMain, LOCK_UN);
fclose($fpMain);

echo json_encode(array(
  "ok" => true,
  "wins" => $current["wins"],
  "losses" => $current["losses"],
  "shots" => $current["shots"],
));

