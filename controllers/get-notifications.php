<?php
session_start();
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");

$userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);

if (!$userId) {
    http_response_code(401);
    echo json_encode(["error" => "Not authenticated"]);
    exit;
}

$userId = (int) $userId;
session_write_close();

require_once "../config/db.php";
use Config\Database;

try {
    $db = (new Database())->connect();

    $stmt = $db->prepare("SELECT id, type, title, message, time, is_new AS isNew, action_label AS actionLabel 
                          FROM notifications 
                          WHERE user_id = :userId 
                          ORDER BY time DESC LIMIT 20");
    $stmt->bindParam(":userId", $userId);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["notifications" => $notifications]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to fetch notifications"]);
}
