<?php
session_start();
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["error" => "Not authenticated"]);
    exit;
}

require_once "../config/db.php";
use Config\Database;

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['method'], $data['gps'])) {
    http_response_code(400);
    echo json_encode(["error" => "Method and GPS required"]);
    exit;
}

try {
    $db = (new Database())->connect();
    $userId = $_SESSION['user']['id'];
    $method = $data['method'];
    $gps = $data['gps'];
    $time = date("Y-m-d H:i:s");

    // Insert a new attendance record (Check-In)
    $stmt = $db->prepare("INSERT INTO attendance (user_id, date, check_in_time, gps, method, status) 
                          VALUES (:userId, CURDATE(), :time, :gps, :method, 'PRESENT')");
    $stmt->bindParam(":userId", $userId);
    $stmt->bindParam(":time", $time);
    $stmt->bindParam(":gps", $gps);
    $stmt->bindParam(":method", $method);
    $stmt->execute();

    echo json_encode(["message" => "Check-In successful"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to check in"]);
}