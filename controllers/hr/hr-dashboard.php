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

    $db = (new Database())->connect();

    /* ══════════════════════════════════════════
       1. KPI CARDS
    ══════════════════════════════════════════ */

    $totalEmployees = (int) $db->query("
        SELECT COUNT(*) FROM users WHERE status = 'ACTIVE'
    ")->fetchColumn();

    /* Active cases = pending ID replacements + pending lost ID reports */
    $activeCases = (int) $db->query("
        SELECT
            (SELECT COUNT(*) FROM id_replacement_requests WHERE status = 'PENDING') +
            (SELECT COUNT(*) FROM lost_id_reports          WHERE status = 'PENDING')
    ")->fetchColumn();

    /* Violations = staff with attendance rate < 70% in last 30 days */
    $workingDays = (int) $db->query("
        SELECT COUNT(DISTINCT DATE(check_in))
        FROM   v_attendance
        WHERE  check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetchColumn();

    $violations = 0;
    if ($workingDays > 0) {
        $violations = (int) $db->query("
            SELECT COUNT(*) FROM (
                SELECT u.id,
                       COUNT(DISTINCT DATE(a.check_in)) AS days_present
                FROM   users u
                LEFT   JOIN v_attendance a ON a.user_id = u.id
                       AND a.check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                WHERE  u.status = 'ACTIVE'
                GROUP  BY u.id
                HAVING (days_present / {$workingDays}) * 100 < 70
            ) AS violators
        ")->fetchColumn();
    }

    /* Compliance score = % of staff with attendance rate >= 80% */
    $complianceScore = 0;
    if ($totalEmployees > 0 && $workingDays > 0) {
        $compliant = (int) $db->query("
            SELECT COUNT(*) FROM (
                SELECT u.id,
                       COUNT(DISTINCT DATE(a.check_in)) AS days_present
                FROM   users u
                LEFT   JOIN v_attendance a ON a.user_id = u.id
                       AND a.check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                WHERE  u.status = 'ACTIVE'
                GROUP  BY u.id
                HAVING (days_present / {$workingDays}) * 100 >= 80
            ) AS comp
        ")->fetchColumn();
        $complianceScore = round(($compliant / $totalEmployees) * 100);
    }

    /* ══════════════════════════════════════════
       2. ATTENDANCE TREND (last 5 months)
    ══════════════════════════════════════════ */

    $attTrendStmt = $db->query("
        SELECT
            DATE_FORMAT(check_in,'%b') AS month,
            DATE_FORMAT(check_in,'%Y-%m') AS month_key,
            COUNT(DISTINCT user_id)    AS present
        FROM  v_attendance
        WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        AND   status IN ('PRESENT','LATE')
        GROUP BY month_key, month
        ORDER BY month_key ASC
        LIMIT 5
    ");
    $attendanceTrend = array_map(fn($r) => [
        'month'   => $r['month'],
        'present' => (int) $r['present'],
    ], $attTrendStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       3. LEAVE TREND (last 5 months)
    ══════════════════════════════════════════ */

    $leaveTrendStmt = $db->query("
        SELECT
            DATE_FORMAT(created_at,'%b')    AS month,
            DATE_FORMAT(created_at,'%Y-%m') AS month_key,
            COUNT(*)                        AS `leave`
        FROM  leave_requests
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY month_key, month
        ORDER BY month_key ASC
        LIMIT 5
    ");
    $leaveTrend = array_map(fn($r) => [
        'month' => $r['month'],
        'leave' => (int) $r['leave'],
    ], $leaveTrendStmt->fetchAll(PDO::FETCH_ASSOC));
    /* ══════════════════════════════════════════
       4. COMPLIANCE SCORE TREND (last 5 months)
    ══════════════════════════════════════════ */

    $compTrendStmt = $db->query("
        SELECT
            DATE_FORMAT(m.month_start,'%b')    AS month,
            DATE_FORMAT(m.month_start,'%Y-%m') AS month_key,
            ROUND(
                COUNT(DISTINCT CASE WHEN a.status IN ('PRESENT','LATE') THEN a.user_id END)
                / NULLIF(COUNT(DISTINCT u.id), 0) * 100
            ) AS score
        FROM (
            SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL n MONTH),'%Y-%m-01') AS month_start
            FROM (SELECT 4 n UNION SELECT 3 UNION SELECT 2 UNION SELECT 1 UNION SELECT 0) nums
        ) m
        CROSS JOIN users u
        LEFT  JOIN v_attendance a ON a.user_id = u.id
              AND DATE_FORMAT(a.check_in,'%Y-%m') = DATE_FORMAT(m.month_start,'%Y-%m')
        WHERE u.status = 'ACTIVE'
        GROUP BY month_key, month
        ORDER BY month_key ASC
    ");
    $complianceTrend = array_map(fn($r) => [
        'month' => $r['month'],
        'score' => (int) ($r['score'] ?? 0),
    ], $compTrendStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       5. RECENT HR ACTIVITY (audit_logs + ID events)
    ══════════════════════════════════════════ */

    $activityStmt = $db->query("
        SELECT
            'audit'        AS source,
            al.action      AS title,
            al.details     AS description,
            al.created_at,
            u.full_name    AS actor
        FROM  audit_logs al
        LEFT  JOIN users u ON u.id = al.user_id
        UNION ALL
        SELECT
            'lost_id'      AS source,
            CONCAT('Lost ID Report: ', l.name) AS title,
            CONCAT('Location: ', l.location)   AS description,
            l.created_at,
            NULL           AS actor
        FROM  lost_id_reports l
        WHERE l.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        UNION ALL
        SELECT
            'id_replace'   AS source,
            'ID Replacement Request' AS title,
            CONCAT('Status: ', r.status) AS description,
            r.created_at,
            u2.full_name   AS actor
        FROM  id_replacement_requests r
        LEFT  JOIN users u2 ON u2.id = r.user_id
        WHERE r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $recentActivity = array_map(fn($r) => [
        'source'      => $r['source'],
        'title'       => $r['title'],
        'description' => $r['description'] ?? '',
        'actor'       => $r['actor'] ?? '',
        'time'        => date('M d, h:i A', strtotime($r['created_at'])),
    ], $activityStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       6. ID CARD SUMMARY
    ══════════════════════════════════════════ */

    $idStmt = $db->query("
        SELECT
            SUM(status = 'Active')    AS active,
            SUM(status = 'Lost')      AS lost,
            SUM(status = 'Suspended') AS suspended,
            SUM(status = 'Pending')   AS pending
        FROM id_cards
    ")->fetch(PDO::FETCH_ASSOC);

    $pendingReplacements = (int) $db->query("
        SELECT COUNT(*) FROM id_replacement_requests WHERE status = 'PENDING'
    ")->fetchColumn();

    $pendingLostReports = (int) $db->query("
        SELECT COUNT(*) FROM lost_id_reports WHERE status = 'PENDING'
    ")->fetchColumn();

    /* ══════════════════════════════════════════
       7. EMPLOYMENT STATUS BREAKDOWN
    ══════════════════════════════════════════ */

    $statusStmt = $db->query("
        SELECT status, COUNT(*) AS cnt
        FROM   users
        GROUP  BY status
    ");
    $employmentStatus = [];
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $employmentStatus[$row['status']] = (int) $row['cnt'];
    }

    ob_end_clean();
    echo json_encode([
        "success" => true,

        "kpis" => [
            "totalEmployees"  => $totalEmployees,
            "activeCases"     => $activeCases,
            "violations"      => $violations,
            "complianceScore" => $complianceScore,
        ],

        "attendanceTrend"  => $attendanceTrend,
        "leaveTrend"       => $leaveTrend,
        "complianceTrend"  => $complianceTrend,
        "recentActivity"   => $recentActivity,

        "idSummary" => [
            "active"              => (int) ($idStmt['active']    ?? 0),
            "lost"                => (int) ($idStmt['lost']      ?? 0),
            "suspended"           => (int) ($idStmt['suspended'] ?? 0),
            "pending"             => (int) ($idStmt['pending']   ?? 0),
            "pendingReplacements" => $pendingReplacements,
            "pendingLostReports"  => $pendingLostReports,
        ],

        "employmentStatus" => $employmentStatus,

        "generatedAt" => date('h:i A'),
        "date"        => date('D, d M Y'),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}