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
    $now   = date('Y-m-d H:i:s');

    /* ══════════════════════════════════════════
       1. KPI CARDS
    ══════════════════════════════════════════ */

    /* Staff Present today */
    $presentStmt = $db->prepare("
        SELECT COUNT(DISTINCT user_id) AS cnt
        FROM   attendance
        WHERE  DATE(check_in) = ?
        AND    status IN ('PRESENT', 'LATE')
    ");
    $presentStmt->execute([$today]);
    $staffPresent = (int) $presentStmt->fetchColumn();

    /* Late today */
    $lateStmt = $db->prepare("
        SELECT COUNT(DISTINCT user_id) AS cnt
        FROM   attendance
        WHERE  DATE(check_in) = ?
        AND    status = 'LATE'
    ");
    $lateStmt->execute([$today]);
    $lateToday = (int) $lateStmt->fetchColumn();

    /* Missing check-ins:
       Active users with no attendance record today */
    $missingStmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM   users u
        WHERE  u.status = 'ACTIVE'
        AND    NOT EXISTS (
            SELECT 1 FROM v_attendance a
            WHERE  a.user_id        = u.id
            AND    DATE(a.check_in) = ?
        )
    ");
    $missingStmt->execute([$today]);
    $missingCheckins = (int) $missingStmt->fetchColumn();

    /* Pending approvals = pending leave requests */
    $pendingStmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM   leave_requests
        WHERE  supervisor_approval = 'PENDING'
    ");
    $pendingStmt->execute();
    $pendingApprovals = (int) $pendingStmt->fetchColumn();

    /* ══════════════════════════════════════════
       2. WEEKLY ATTENDANCE TREND (last 7 days)
    ══════════════════════════════════════════ */

    $weekStmt = $db->prepare("
        SELECT
            DATE(check_in)                              AS att_date,
            DAYNAME(check_in)                           AS day_name,
            COUNT(DISTINCT user_id)                     AS present,
            SUM(status = 'LATE')                        AS late,
            SUM(status = 'ABSENT')                      AS absent
        FROM  v_attendance
        WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(check_in)
        ORDER BY att_date ASC
    ");
    $weekStmt->execute();
    $weekRows = $weekStmt->fetchAll(PDO::FETCH_ASSOC);

    $attendanceData = array_map(fn($r) => [
        'day'     => substr($r['day_name'], 0, 3),   // "Mon", "Tue" …
        'date'    => $r['att_date'],
        'present' => (int) $r['present'],
        'late'    => (int) $r['late'],
        'absent'  => (int) $r['absent'],
    ], $weekRows);

    /* ══════════════════════════════════════════
       3. PUNCTUALITY BREAKDOWN (today)
    ══════════════════════════════════════════ */

    $punctStmt = $db->prepare("
        SELECT
            SUM(status = 'PRESENT')          AS on_time,
            SUM(status = 'LATE')             AS late,
            SUM(status = 'ABSENT')           AS absent,
            SUM(status = 'OUTSIDE_GEOFENCE') AS outside
        FROM v_attendance
        WHERE DATE(check_in) = ?
    ");
    $punctStmt->execute([$today]);
    $punct = $punctStmt->fetch(PDO::FETCH_ASSOC);

    $punctualityData = [
        ['name' => 'On Time', 'value' => (int) ($punct['on_time']  ?? 0)],
        ['name' => 'Late',    'value' => (int) ($punct['late']     ?? 0)],
        ['name' => 'Absent',  'value' => (int) ($punct['absent']   ?? 0)],
        ['name' => 'Outside', 'value' => (int) ($punct['outside']  ?? 0)],
    ];

    /* ══════════════════════════════════════════
       4. WEEKLY LATE TREND (last 4 weeks)
    ══════════════════════════════════════════ */

    $lateTrendStmt = $db->prepare("
        SELECT
            WEEK(check_in, 1)           AS week_num,
            MIN(DATE(check_in))         AS week_start,
            COUNT(*)                    AS late
        FROM  v_attendance
        WHERE status    = 'LATE'
        AND   check_in >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK)
        GROUP BY WEEK(check_in, 1)
        ORDER BY week_num ASC
        LIMIT 4
    ");
    $lateTrendStmt->execute();
    $lateTrendRows = $lateTrendStmt->fetchAll(PDO::FETCH_ASSOC);

    /* Label as W1…W4 relative to result order */
    $weeklyLateTrend = [];
    foreach (array_values($lateTrendRows) as $i => $r) {
        $weeklyLateTrend[] = [
            'week' => 'W' . ($i + 1),
            'late' => (int) $r['late'],
        ];
    }

    /* ══════════════════════════════════════════
       5. APPROVAL QUEUE COUNTS
    ══════════════════════════════════════════ */

    $leaveQueueStmt = $db->prepare("
        SELECT COUNT(*) FROM leave_requests WHERE supervisor_approval = 'PENDING'
    ");
    $leaveQueueStmt->execute();
    $leaveQueue = (int) $leaveQueueStmt->fetchColumn();

    /* ══════════════════════════════════════════
       6. RECENT LATE ARRIVALS (for a quick list)
    ══════════════════════════════════════════ */

    $recentLateStmt = $db->prepare("
        SELECT
            u.full_name,
            a.check_in,
            a.status
        FROM  v_attendance a
        JOIN  users u ON u.id = a.user_id
        WHERE a.status    = 'LATE'
        AND   DATE(a.check_in) = ?
        ORDER BY a.check_in DESC
        LIMIT 5
    ");
    $recentLateStmt->execute([$today]);
    $recentLate = array_map(fn($r) => [
        'name'    => $r['full_name'],
        'checkIn' => date('h:i A', strtotime($r['check_in'])),
    ], $recentLateStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       RESPONSE
    ══════════════════════════════════════════ */

    ob_end_clean();
    echo json_encode([
        "success" => true,

        "kpis" => [
            "staffPresent"    => $staffPresent,
            "lateToday"       => $lateToday,
            "missingCheckins" => $missingCheckins,
            "pendingApprovals"=> $pendingApprovals,
        ],

        "attendanceData"  => $attendanceData,
        "punctualityData" => $punctualityData,
        "weeklyLateTrend" => $weeklyLateTrend,

        "approvalQueue" => [
            "leaveRequests" => $leaveQueue,
        ],

        "recentLate" => $recentLate,

        "generatedAt" => $now,
    ]);

} catch (Exception $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}