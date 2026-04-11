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

function auditRelativeTime(?string $value): string
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

function auditSeverity(string $action): string
{
    $value = strtolower($action);
    if (str_contains($value, 'delete') || str_contains($value, 'reset') || str_contains($value, 'suspend')) return 'High';
    if (str_contains($value, 'fail') || str_contains($value, 'warning')) return 'Medium';
    return 'Low';
}

try {
    $db = (new Database())->connect();

    $stmt = $db->query("
        SELECT
            al.id,
            al.action,
            al.entity,
            al.details,
            al.created_at,
            u.email
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        ORDER BY al.created_at DESC
        LIMIT 100
    ");

    $logs = array_map(static function (array $row): array {
        return [
            "id" => (int) $row['id'],
            "user" => $row['email'] ?: 'System',
            "action" => $row['action'] ?: 'Unknown action',
            "module" => $row['entity'] ?: 'General',
            "ip" => 'System',
            "time" => auditRelativeTime($row['created_at'] ?? null),
            "severity" => auditSeverity((string) ($row['action'] ?? '')),
            "createdAt" => $row['created_at'],
            "details" => $row['details'] ?? '',
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
