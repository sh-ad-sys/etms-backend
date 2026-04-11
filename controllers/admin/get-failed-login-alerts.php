<?php

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => 'localhost',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "error" => "Not authenticated",
    ]);
    exit();
}

require_once "../../config/db.php";

use Config\Database;

function formatRelativeTime(string $value): string
{
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    $diff = max(0, time() - $timestamp);

    if ($diff < 60) {
        return "Just now";
    }

    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);
        return $minutes . " min" . ($minutes === 1 ? "" : "s") . " ago";
    }

    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . " hour" . ($hours === 1 ? "" : "s") . " ago";
    }

    $days = (int) floor($diff / 86400);
    return $days . " day" . ($days === 1 ? "" : "s") . " ago";
}

function resolveSeverity(int $minutesAgo): string
{
    if ($minutesAgo <= 10) {
        return "Critical";
    }

    if ($minutesAgo <= 60) {
        return "Warning";
    }

    return "Info";
}

try {
    $db = (new Database())->connect();

    $stmt = $db->query("
        SELECT
            al.id,
            al.user_id,
            al.details,
            al.created_at,
            u.email,
            u.full_name,
            d.device_name,
            d.ip_address
        FROM audit_logs al
        LEFT JOIN users u
            ON u.id = al.user_id
        LEFT JOIN devices d
            ON d.user_id = al.user_id
           AND d.last_login = (
                SELECT MAX(d2.last_login)
                FROM devices d2
                WHERE d2.user_id = al.user_id
           )
        WHERE al.action = 'login_failed'
        ORDER BY al.created_at DESC
        LIMIT 50
    ");

    $alerts = array_map(static function (array $row): array {
        $details = (string) ($row['details'] ?? '');
        $matchedEmail = null;

        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $details, $matches) === 1) {
            $matchedEmail = $matches[0];
        }

        $createdAt = (string) $row['created_at'];
        $minutesAgo = (int) floor(max(0, time() - strtotime($createdAt)) / 60);

        return [
            "id" => (int) $row['id'],
            "user" => $row['email']
                ?: $matchedEmail
                ?: ($row['full_name'] ?: "Unknown user"),
            "ip" => $row['ip_address'] ?: "IP unavailable",
            "device" => $row['device_name'] ?: "Unknown device",
            "time" => formatRelativeTime($createdAt),
            "status" => resolveSeverity($minutesAgo),
            "createdAt" => $createdAt,
            "details" => $details,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        "success" => true,
        "alerts" => $alerts,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage(),
    ]);
}
