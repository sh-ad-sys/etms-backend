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

    $db          = (new Database())->connect();
    $currentYear = date('Y');
    $thisMonth   = date('Y-m');
    $today       = date('Y-m-d');

    /* ══════════════════════════════════════════
       1. KPI — Total Workforce
    ══════════════════════════════════════════ */
    $workforce = $db->query("
        SELECT COUNT(*) FROM users WHERE status = 'ACTIVE'
    ")->fetchColumn();

    /* ══════════════════════════════════════════
       2. KPI — Overtime Hours this month
       Overtime = check_out time > shift end_time
    ══════════════════════════════════════════ */
    $overtimeStmt = $db->query("
        SELECT
            COALESCE(SUM(
                GREATEST(0,
                    TIMESTAMPDIFF(MINUTE,
                        CONCAT(DATE(a.check_out), ' ', s.end_time),
                        a.check_out
                    )
                )
            ), 0) AS total_ot_minutes
        FROM  v_attendance a
        JOIN  shifts s ON s.id = a.shift_id
        WHERE a.check_out IS NOT NULL
        AND   DATE_FORMAT(a.check_in, '%Y-%m') = '{$thisMonth}'
        AND   TIME(a.check_out) > s.end_time
    ");
    $totalOtMinutes = (int) $overtimeStmt->fetchColumn();
    $totalOtHours   = round($totalOtMinutes / 60, 1);

    /* ══════════════════════════════════════════
       3. KPI — Overtime cost (estimated KES 150/hr)
    ══════════════════════════════════════════ */
    $otRate       = 150;   // KES per overtime hour — adjust as needed
    $overtimeCost = round($totalOtHours * $otRate);

    /* ══════════════════════════════════════════
       4. KPI — Overall Compliance Score
       Compliance = % of working days staff checked in (last 30 days)
    ══════════════════════════════════════════ */
    $workingDays = $db->query("
        SELECT COUNT(DISTINCT DATE(check_in))
        FROM v_attendance
        WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetchColumn();

    $complianceStmt = $db->query("
        SELECT
            u.id,
            COUNT(DISTINCT DATE(a.check_in)) AS days_present
        FROM  users u
        LEFT  JOIN v_attendance a ON a.user_id = u.id
            AND a.check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        WHERE u.status = 'ACTIVE'
        GROUP BY u.id
    ");
    $compRows       = $complianceStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalStaff     = count($compRows);
    $complianceScore = 0;

    if ($totalStaff > 0 && $workingDays > 0) {
        $totalPresent    = array_sum(array_column($compRows, 'days_present'));
        $complianceScore = round(($totalPresent / ($totalStaff * $workingDays)) * 100);
    }

    /* ══════════════════════════════════════════
       5. Monthly Attendance + Productivity Trend
       Last 4 months
    ══════════════════════════════════════════ */
    $trendStmt = $db->query("
        SELECT
            DATE_FORMAT(check_in, '%b')                              AS month,
            DATE_FORMAT(check_in, '%Y-%m')                           AS month_key,
            ROUND(AVG(status IN ('PRESENT','LATE')) * 100)           AS attendance,
            ROUND(AVG(status = 'PRESENT') * 100)                     AS productivity
        FROM  v_attendance
        WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL 4 MONTH)
        GROUP BY month_key, month
        ORDER BY month_key ASC
        LIMIT 4
    ");
    $monthlyTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
    $monthlyTrend = array_map(fn($r) => [
        'month'        => $r['month'],
        'attendance'   => (int) $r['attendance'],
        'productivity' => (int) $r['productivity'],
    ], $monthlyTrend);

    /* ══════════════════════════════════════════
       6. Overtime Cost by Department (this month)
    ══════════════════════════════════════════ */
    $deptOtStmt = $db->query("
        SELECT
            COALESCE(d.name, u.department, 'General') AS dept,
            ROUND(
                COALESCE(SUM(
                    GREATEST(0,
                        TIMESTAMPDIFF(MINUTE,
                            CONCAT(DATE(a.check_out), ' ', s.end_time),
                            a.check_out
                        )
                    )
                ), 0) / 60 * {$otRate}
            ) AS cost
        FROM  v_attendance a
        JOIN  users      u ON u.id  = a.user_id
        LEFT  JOIN departments d ON d.id = u.department_id
        JOIN  shifts     s ON s.id  = a.shift_id
        WHERE a.check_out IS NOT NULL
        AND   DATE_FORMAT(a.check_in,'%Y-%m') = '{$thisMonth}'
        AND   TIME(a.check_out) > s.end_time
        GROUP BY dept
        ORDER BY cost DESC
        LIMIT 6
    ");
    $overtimeCostByDept = array_map(fn($r) => [
        'dept' => $r['dept'],
        'cost' => (int) $r['cost'],
    ], $deptOtStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       7. Compliance Risk Distribution
       Based on attendance rate per user (last 30 days)
       Compliant ≥ 80%, At Risk 50–79%, Critical < 50%
    ══════════════════════════════════════════ */
    $compliant = 0; $atRisk = 0; $critical = 0;

    foreach ($compRows as $row) {
        $rate = $workingDays > 0 ? ($row['days_present'] / $workingDays) * 100 : 0;
        if      ($rate >= 80) $compliant++;
        elseif  ($rate >= 50) $atRisk++;
        else                  $critical++;
    }

    $complianceRisk = [
        ['name' => 'Compliant', 'value' => $compliant],
        ['name' => 'At Risk',   'value' => $atRisk],
        ['name' => 'Critical',  'value' => $critical],
    ];

    /* ══════════════════════════════════════════
       8. Executive Insights (auto-generated)
    ══════════════════════════════════════════ */
    $insights = [];

    /* Attendance trend vs previous month */
    if (count($monthlyTrend) >= 2) {
        $last    = $monthlyTrend[count($monthlyTrend) - 1]['attendance'];
        $prev    = $monthlyTrend[count($monthlyTrend) - 2]['attendance'];
        $diff    = $last - $prev;
        $arrow   = $diff >= 0 ? 'up' : 'down';
        $insights[] = [
            'type'    => $arrow,
            'message' => "Attendance " . ($diff >= 0 ? "improved" : "dropped") . " by " . abs($diff) . "% compared to last month.",
        ];
    }

    /* Top overtime department */
    if (!empty($overtimeCostByDept)) {
        $top = $overtimeCostByDept[0];
        $insights[] = [
            'type'    => 'warning',
            'message' => "{$top['dept']} department has the highest overtime cost this month (KES " . number_format($top['cost']) . ").",
        ];
    }

    /* Critical compliance */
    if ($critical > 0) {
        $pct = $totalStaff > 0 ? round(($critical / $totalStaff) * 100) : 0;
        $insights[] = [
            'type'    => 'danger',
            'message' => "{$critical} staff ({$pct}%) flagged under critical compliance risk based on attendance.",
        ];
    }

    /* Leave approvals pending */
    $pendingLeave = $db->query("
        SELECT COUNT(*) FROM leave_requests
        WHERE manager_approval = 'PENDING'
        AND   supervisor_approval = 'APPROVED'
    ")->fetchColumn();

    if ((int)$pendingLeave > 0) {
        $insights[] = [
            'type'    => 'info',
            'message' => "{$pendingLeave} leave request(s) approved by supervisor and awaiting your final approval.",
        ];
    }

    /* ══════════════════════════════════════════
       9. Pending Manager Leave Approvals count
    ══════════════════════════════════════════ */
    $pendingApprovals = (int) $pendingLeave;

    ob_end_clean();
    echo json_encode([
        "success" => true,

        "kpis" => [
            "totalWorkforce"   => (int) $workforce,
            "totalOtHours"     => $totalOtHours,
            "overtimeCost"     => $overtimeCost,
            "complianceScore"  => $complianceScore,
            "pendingApprovals" => $pendingApprovals,
        ],

        "monthlyTrend"       => $monthlyTrend,
        "overtimeCostByDept" => $overtimeCostByDept,
        "complianceRisk"     => $complianceRisk,
        "insights"           => $insights,

        "generatedAt" => date('h:i A'),
        "date"        => date('D, d M Y'),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}