<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]); exit;
}

require_once "../../config/db.php";

use Config\Database;

function loginRelativeTime(?string $value): string
{
    if (!$value) return "";
    $timestamp = strtotime($value);
    if ($timestamp === false) return $value;

    $diff = max(0, time() - $timestamp);
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . " mins ago";
    if ($diff < 86400) return floor($diff / 3600) . " hours ago";
    return floor($diff / 86400) . " days ago";
}

function loginDeviceType(string $deviceName, string $details): string
{
    $value = strtolower($deviceName . " " . $details);
    if (str_contains($value, 'mobile') || str_contains($value, 'android') || str_contains($value, 'iphone')) return 'Mobile';
    if (str_contains($value, 'tablet') || str_contains($value, 'ipad')) return 'Tablet';
    if (str_contains($value, 'laptop')) return 'Laptop';
    return 'Desktop';
}

try {
    $db = (new Database())->connect();

    $stmt = $db->query("
        SELECT
            al.id,
            al.action,
            al.details,
            al.created_at,
            u.full_name,
            u.email,
            u.department,
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
        WHERE al.action LIKE '%login%'
        ORDER BY al.created_at DESC
        LIMIT 100
    ");

    $logs = array_map(static function (array $row): array {
        $action = strtolower((string) ($row['action'] ?? ''));
        $isFailed = str_contains($action, 'failed');
        $device = $row['device_name'] ?: 'Unknown device';

        return [
            "id" => (int) $row['id'],
            "user" => $row['full_name'] ?: 'Unknown User',
            "email" => $row['email'] ?: 'Unknown email',
            "device" => loginDeviceType((string) $device, (string) ($row['details'] ?? '')),
            "deviceLabel" => $device,
            "ip" => $row['ip_address'] ?: 'IP unavailable',
            "location" => $row['department'] ?: 'Unknown location',
            "status" => $isFailed ? 'Failed' : 'Success',
            "time" => loginRelativeTime($row['created_at'] ?? null),
            "createdAt" => $row['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "logs" => $logs,
    ]);
} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
