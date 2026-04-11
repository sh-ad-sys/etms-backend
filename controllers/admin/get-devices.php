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

function formatRelativeTime(?string $value): string
{
    if (!$value) return "No activity";
    $timestamp = strtotime($value);
    if ($timestamp === false) return $value;

    $diff = max(0, time() - $timestamp);
    if ($diff < 60) return "Just now";
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

function inferDeviceType(string $deviceName, string $userAgent): string
{
    $value = strtolower($deviceName . " " . $userAgent);
    if (str_contains($value, 'biometric') || str_contains($value, 'scanner')) return 'Biometric';
    if (str_contains($value, 'tablet') || str_contains($value, 'ipad')) return 'Tablet';
    if (str_contains($value, 'mobile') || str_contains($value, 'android') || str_contains($value, 'iphone')) return 'Mobile';
    if (str_contains($value, 'laptop')) return 'Laptop';
    return 'Desktop';
}

function inferAccessState(?string $lastLogin): string
{
    if (!$lastLogin) return 'Pending';
    $timestamp = strtotime($lastLogin);
    if ($timestamp === false) return 'Pending';

    $minutes = (time() - $timestamp) / 60;
    if ($minutes <= 15) return 'Approved';
    if ($minutes <= 1440) return 'Pending';
    return 'Blocked';
}

function inferTrackingState(?string $lastLogin): string
{
    if (!$lastLogin) return 'Offline';
    $timestamp = strtotime($lastLogin);
    if ($timestamp === false) return 'Offline';

    $minutes = (time() - $timestamp) / 60;
    if ($minutes <= 15) return 'Online';
    if ($minutes <= 1440) return 'Offline';
    return 'Suspicious';
}

try {
    $db = (new Database())->connect();

    $stmt = $db->query("
        SELECT
            d.id,
            d.device_name,
            d.ip_address,
            d.user_agent,
            d.last_login,
            u.full_name,
            u.email
        FROM devices d
        LEFT JOIN users u ON u.id = d.user_id
        ORDER BY d.last_login DESC
        LIMIT 100
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $devices = array_map(static function (array $row): array {
        $type = inferDeviceType((string) ($row['device_name'] ?? ''), (string) ($row['user_agent'] ?? ''));
        $state = inferAccessState($row['last_login'] ?? null);

        return [
            "id" => (int) $row['id'],
            "name" => $row['device_name'] ?: 'Unknown device',
            "type" => $type,
            "ip" => $row['ip_address'] ?: 'IP unavailable',
            "lastActive" => formatRelativeTime($row['last_login'] ?? null),
            "status" => strtolower($state),
            "trackingStatus" => inferTrackingState($row['last_login'] ?? null),
            "location" => $row['email'] ? $row['email'] : 'Tracked device',
            "owner" => $row['full_name'] ?: $row['email'] ?: 'Unassigned',
            "createdAt" => $row['last_login'],
        ];
    }, $rows);

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "devices" => $devices,
    ]);
} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
