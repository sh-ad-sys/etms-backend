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

try {

    $db    = (new Database())->connect();
    $today = date('Y-m-d');

    /* ══════════════════════════════════════════
       1. KPI — Active Users
    ══════════════════════════════════════════ */
    $activeUsers = (int) $db->query("
        SELECT COUNT(*) FROM users WHERE status = 'ACTIVE'
    ")->fetchColumn();

    /* ══════════════════════════════════════════
       2. KPI — Pending Permissions
       Roles with no users assigned (unused) OR
       users with no role assigned
    ══════════════════════════════════════════ */
    $pendingPermissions = (int) $db->query("
        SELECT COUNT(*) FROM users WHERE role_id IS NULL AND status = 'ACTIVE'
    ")->fetchColumn();

    /* ══════════════════════════════════════════
       3. KPI — Security Alerts (unresolved)
    ══════════════════════════════════════════ */
    $securityAlerts = (int) $db->query("
        SELECT COUNT(*) FROM security_alerts WHERE status = 'open'
    ")->fetchColumn();

    /* ══════════════════════════════════════════
       4. KPI — Failed Logins today
    ══════════════════════════════════════════ */
    $failedLogins = (int) $db->query("
        SELECT COUNT(*) FROM audit_logs
        WHERE action LIKE '%failed%login%'
        OR    action LIKE '%login_failed%'
        AND   DATE(created_at) = '{$today}'
    ")->fetchColumn();

    /* ══════════════════════════════════════════
       5. KPI — Suspended Accounts
    ══════════════════════════════════════════ */
    $suspendedAccounts = (int) $db->query("
        SELECT COUNT(*) FROM users WHERE status = 'SUSPENDED'
    ")->fetchColumn();

    /* ══════════════════════════════════════════
       6. KPI — Registered Devices
    ══════════════════════════════════════════ */
    $registeredDevices = (int) $db->query("
        SELECT COUNT(*) FROM devices
    ")->fetchColumn();

    /* ══════════════════════════════════════════
       7. User role breakdown
    ══════════════════════════════════════════ */
    $roleStmt = $db->query("
        SELECT r.name AS role, COUNT(u.id) AS cnt
        FROM   users u
        JOIN   roles r ON r.id = u.role_id
        WHERE  u.status = 'ACTIVE'
        GROUP  BY r.name
        ORDER  BY cnt DESC
    ");
    $roleBreakdown = array_map(fn($r) => [
        'role'  => $r['role'],
        'count' => (int) $r['cnt'],
    ], $roleStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       8. Recent audit logs (last 8 entries)
    ══════════════════════════════════════════ */
    $auditStmt = $db->query("
        SELECT
            al.action,
            al.entity,
            al.details,
            al.created_at,
            u.full_name  AS actor,
            u.employee_code
        FROM  audit_logs al
        LEFT  JOIN users u ON u.id = al.user_id
        ORDER BY al.created_at DESC
        LIMIT 8
    ");
    $recentAudit = array_map(fn($r) => [
        'action'  => $r['action'],
        'entity'  => $r['entity']  ?? '',
        'details' => $r['details'] ?? '',
        'actor'   => $r['actor']   ?? 'System',
        'empCode' => $r['employee_code'] ?? '',
        'time'    => date('M d, h:i A', strtotime($r['created_at'])),
    ], $auditStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       9. Recent security alerts (last 5)
    ══════════════════════════════════════════ */
    $alertStmt = $db->query("
        SELECT
            sa.title,
            sa.description,
            sa.severity,
            sa.status,
            sa.created_at,
            u.full_name AS actor
        FROM  security_alerts sa
        LEFT  JOIN users u ON u.id = sa.user_id
        ORDER BY sa.created_at DESC
        LIMIT 5
    ");
    $recentAlerts = array_map(fn($r) => [
        'type'     => 'alert',
        'message'  => $r['title'],
        'details'  => $r['description'],
        'severity' => $r['severity'],
        'resolved' => $r['status'] === 'resolved',
        'actor'    => $r['actor'] ?? '',
        'time'     => date('M d, h:i A', strtotime($r['created_at'])),
    ], $alertStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       10. Login activity trend (last 7 days)
    ══════════════════════════════════════════ */
    $loginTrendStmt = $db->query("
        SELECT
            DATE_FORMAT(created_at,'%a') AS day,
            DATE(created_at)             AS att_date,
            COUNT(*)                     AS logins
        FROM  audit_logs
        WHERE action LIKE '%login%'
        AND   created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY att_date, day
        ORDER BY att_date ASC
    ");
    $loginTrend = array_map(fn($r) => [
        'day'    => $r['day'],
        'logins' => (int) $r['logins'],
    ], $loginTrendStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       11. Shifts summary
    ══════════════════════════════════════════ */
    $shifts = $db->query("
        SELECT id, name, start_time, end_time, grace_period FROM shifts ORDER BY start_time
    ")->fetchAll(PDO::FETCH_ASSOC);

    $shiftSummary = array_map(fn($s) => [
        'name'       => $s['name'],
        'startTime'  => date('h:i A', strtotime($s['start_time'])),
        'endTime'    => date('h:i A', strtotime($s['end_time'])),
        'grace'      => (int) $s['grace_period'],
    ], $shifts);

    ob_end_clean();
    echo json_encode([
        "success" => true,

        "kpis" => [
            "activeUsers"        => $activeUsers,
            "pendingPermissions" => $pendingPermissions,
            "securityAlerts"     => $securityAlerts,
            "failedLogins"       => $failedLogins,
            "suspendedAccounts"  => $suspendedAccounts,
            "registeredDevices"  => $registeredDevices,
        ],

        "roleBreakdown" => $roleBreakdown,
        "recentAudit"   => $recentAudit,
        "recentAlerts"  => $recentAlerts,
        "loginTrend"    => $loginTrend,
        "shiftSummary"  => $shiftSummary,

        "generatedAt" => date('h:i A'),
        "date"        => date('D, d M Y'),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}