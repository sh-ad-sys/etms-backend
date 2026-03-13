<?php

/* ================= SESSION ================= */

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => 'localhost',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

/* ================= HEADERS ================= */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

/* ================= PRE-FLIGHT ================= */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ================= DB ================= */

require_once "../../config/db.php";
use Config\Database;

try {

    /* ================= AUTH CHECK ================= */

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Unauthorized"]);
        exit;
    }

    $db        = (new Database())->connect();
    $userId    = (int) $_SESSION['user_id'];
    $currentIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $currentUA = $_SERVER['HTTP_USER_AGENT'] ?? '';

    /* ================= FETCH DEVICES (7 DAYS) ================= */

    $stmt = $db->prepare("
        SELECT id, device_name, ip_address, user_agent, last_login
        FROM devices
        WHERE user_id = ?
          AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY last_login DESC
    ");

    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $devices = [];

    foreach ($rows as $row) {

        $uaLower = strtolower($row['user_agent'] ?? '');

        $type = (
            strpos($uaLower, 'android') !== false ||
            strpos($uaLower, 'iphone')  !== false ||
            strpos($uaLower, 'mobile')  !== false
        ) ? 'mobile' : 'desktop';

        /* Match on both IP and UA for accurate current-device detection */
        $isCurrent = (
            $row['ip_address'] === $currentIP &&
            $row['user_agent'] === $currentUA
        );

        $devices[] = [
            'id'         => (string) $row['id'],
            'name'       => $row['device_name'] ?: 'Unknown Device',
            'type'       => $type,
            'ip'         => $row['ip_address'],
            'location'   => 'Detected Device',
            'lastActive' => date('M d, Y H:i', strtotime($row['last_login'])),
            'status'     => $isCurrent ? 'active' : 'history',
            'current'    => $isCurrent,
        ];
    }

    echo json_encode([
        "success" => true,
        "devices" => $devices
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}