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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ================= AUTH ================= */

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

require_once "../../config/db.php";
use Config\Database;

try {

    $db     = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];

    /* ── Optional filter: ?status=open|resolved|all ── */
    $status = $_GET['status'] ?? 'all';
    $allowed = ['open', 'resolved', 'all'];
    if (!in_array($status, $allowed)) $status = 'all';

    if ($status === 'all') {
        $stmt = $db->prepare("
            SELECT id, title, description, severity, status, created_at, resolved_at
            FROM   security_alerts
            WHERE  user_id = ?
            ORDER  BY created_at DESC
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $db->prepare("
            SELECT id, title, description, severity, status, created_at, resolved_at
            FROM   security_alerts
            WHERE  user_id = ?
            AND    status  = ?
            ORDER  BY created_at DESC
        ");
        $stmt->execute([$userId, $status]);
    }

    $alerts = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $alerts[] = [
            'id'          => (string) $row['id'],
            'title'       => $row['title'],
            'description' => $row['description'],
            'severity'    => $row['severity'],
            'status'      => $row['status'],
            'timestamp'   => formatTimestamp($row['created_at']),
            'resolvedAt'  => $row['resolved_at']
                ? formatTimestamp($row['resolved_at'])
                : null,
        ];
    }

    echo json_encode(["success" => true, "alerts" => $alerts]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}

/* ================= HELPERS ================= */

function formatTimestamp(string $datetime): string
{
    $ts   = strtotime($datetime);
    $now  = time();
    $diff = $now - $ts;

    if ($diff < 60)                     return 'Just now';
    if ($diff < 3600)                   return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400)                  return 'Today at '    . date('h:i A', $ts);
    if ($diff < 172800)                 return 'Yesterday at '. date('h:i A', $ts);
    if ($diff < 604800)                 return floor($diff / 86400) . ' days ago';
    return date('M d, Y', $ts);
}