<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

/* ================= CORS ================= */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ================= DATABASE ================= */

require_once "../../config/db.php";
use Config\Database;

try {

    $db = (new Database())->connect();

    if (!$db) {
        throw new Exception("Database connection failed");
    }

    /* ================= TIMEZONE ================= */

    date_default_timezone_set("Africa/Nairobi");

    /* ================= SESSION USER ================= */

    $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
    session_write_close();

    /* ================= GENERATE TOKEN ================= */

    $token = bin2hex(random_bytes(32));

    /* ================= EXPIRY ================= */

    $expires = date("Y-m-d H:i:s", strtotime("+30 seconds"));

    /* ================= INSERT SESSION ================= */

    $stmt = $db->prepare("
        INSERT INTO qr_sessions
        (token, type, created_by, created_at, expires_at, is_active, status)
        VALUES (?, 'attendance', ?, NOW(), ?, 1, 'ACTIVE')
    ");

    $stmt->execute([
        $token,
        $createdBy,
        $expires
    ]);

    /* ================= RESPONSE ================= */

    echo json_encode([
        "success" => true,
        "token" => $token,
        "expires" => $expires
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
