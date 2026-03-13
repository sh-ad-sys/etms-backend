<?php

header("Content-Type: application/json");

session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../controllers/check-auth.php";

use Config\Database; // ⭐ IMPORT NAMESPACE

$conn = (new Database())->connect(); // ⭐ Create instance first

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["id"])) {
    echo json_encode([
        "success" => false,
        "message" => "Notification ID required"
    ]);
    exit;
}

$notification_id = $data["id"];

$stmt = $conn->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE id = ?
");

$stmt->execute([$notification_id]);

echo json_encode([
    "success" => true
]);