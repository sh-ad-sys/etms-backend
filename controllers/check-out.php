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

if (!isset($data['gps'])) {
    http_response_code(400);
    echo json_encode(["error" => "GPS required"]);
    exit;
}

try {
    $db = (new Database())->connect();
    $userId = $_SESSION['user']['id'];
    $gps = $data['gps'];
    $time = date("Y-m-d H:i:s");

    // Update the latest check-in record for today with check-out time
    $stmt = $db->prepare("UPDATE attendance 
                          SET check_out_time = :time, gps_checkout = :gps
                          WHERE user_id = :userId AND date = CURDATE() AND check_out_time IS NULL
                          ORDER BY id DESC LIMIT 1");
    $stmt->bindParam(":time", $time);
    $stmt->bindParam(":gps", $gps);
    $stmt->bindParam(":userId", $userId);
    $stmt->execute();

    echo json_encode(["message" => "Check-Out successful"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to check out"]);
}