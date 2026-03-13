<?php

session_start();

/* ================= CORS HEADERS ================= */

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

/* ================= HANDLE PRE-FLIGHT ================= */

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/* ================= DATABASE ================= */

require_once __DIR__ . "/../config/db.php";

use Config\Database;

try {
    $db = (new Database())->connect();
} catch (Exception $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed"
    ]);
    exit;
}

/* ================= CHECK LOGIN ================= */

if (!isset($_SESSION['user_id'])) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "error" => "Unauthorized - No session"
    ]);

    exit;
}

$user_id = $_SESSION['user_id'];

/* ================= FETCH NOTIFICATIONS ================= */

try {

    $stmt = $db->prepare("
        SELECT *
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");

    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================= COUNT UNREAD ================= */

    $unreadStmt = $db->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE user_id = ?
        AND is_read = 0
    ");

    $unreadStmt->execute([$user_id]);
    $unreadCount = $unreadStmt->fetchColumn();

    /* ================= RETURN RESPONSE ================= */

    echo json_encode([
        "success" => true,
        "logged_user" => $user_id,
        "unread_count" => (int)$unreadCount,
        "notifications" => $notifications
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "error" => "Failed to fetch notifications"
    ]);
}