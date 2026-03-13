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
       1. Fetch all shifts
    ══════════════════════════════════════════ */
    $shifts = $db->query("
        SELECT id, name, start_time, end_time, grace_period
        FROM   shifts
        ORDER  BY start_time ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $totalWorkers = (int) $db->query("
        SELECT COUNT(*) FROM users WHERE status = 'ACTIVE'
    ")->fetchColumn();

    /* ══════════════════════════════════════════
       2. For each shift: count workers who
          checked in within that shift's window
          (start_time - grace to end_time)
    ══════════════════════════════════════════ */
    $shiftEfficiency = [];
    $totalPresent    = 0;
    $activeShifts    = 0;

    foreach ($shifts as $shift) {
        $grace     = (int) ($shift['grace_period'] ?? 0);
        $windowStart = date('H:i:s', strtotime($shift['start_time']) - ($grace * 60));
        $windowEnd   = $shift['end_time'];

        /* Workers who checked in today within this shift's window */
        $countStmt = $db->prepare("
            SELECT
                COUNT(*)                    AS checked_in,
                SUM(status = 'PRESENT')     AS on_time,
                SUM(status = 'LATE')        AS late
            FROM v_attendance
            WHERE DATE(check_in) = ?
            AND   TIME(check_in) BETWEEN ? AND ?
        ");
        $countStmt->execute([$today, $windowStart, $windowEnd]);
        $row = $countStmt->fetch(PDO::FETCH_ASSOC);

        $checkedIn   = (int) ($row['checked_in'] ?? 0);
        $onTime      = (int) ($row['on_time']    ?? 0);
        $late        = (int) ($row['late']        ?? 0);

        /* Utilization = checked_in / totalWorkers * 100
           (capped at 100%) */
        $utilization = $totalWorkers > 0
            ? min(100, round(($checkedIn / $totalWorkers) * 100))
            : 0;

        /* Idle = workers NOT checked in for this shift */
        $idle = max(0, $totalWorkers - $checkedIn);

        if ($checkedIn > 0) $activeShifts++;
        $totalPresent = max($totalPresent, $checkedIn);

        $shiftEfficiency[] = [
            'shift'       => $shift['name'],
            'utilization' => $utilization,
            'checkedIn'   => $checkedIn,
            'onTime'      => $onTime,
            'late'        => $late,
            'idle'        => $idle,
            'startTime'   => date('h:i A', strtotime($shift['start_time'])),
            'endTime'     => date('h:i A', strtotime($shift['end_time'])),
        ];
    }

    /* ══════════════════════════════════════════
       3. Overall KPIs
    ══════════════════════════════════════════ */
    $overallUtilization = count($shiftEfficiency) > 0
        ? round(array_sum(array_column($shiftEfficiency, 'utilization')) / count($shiftEfficiency))
        : 0;

    $idleWorkers = max(0, $totalWorkers - $totalPresent);
    $idlePct     = $totalWorkers > 0
        ? round(($idleWorkers / $totalWorkers) * 100) : 0;

    /* ══════════════════════════════════════════
       4. Department workforce distribution today
    ══════════════════════════════════════════ */
    $deptStmt = $db->prepare("
        SELECT
            COALESCE(d.name, u.department, 'General') AS name,
            COUNT(DISTINCT a.user_id)                  AS value
        FROM  v_attendance a
        JOIN  users        u ON u.id  = a.user_id
        LEFT  JOIN departments d ON d.id = u.department_id
        WHERE DATE(a.check_in) = ?
        AND   a.status IN ('PRESENT','LATE')
        GROUP BY name
        ORDER BY value DESC
        LIMIT 8
    ");
    $deptStmt->execute([$today]);
    $departmentShift = array_map(fn($r) => [
        'name'  => $r['name'],
        'value' => (int) $r['value'],
    ], $deptStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       5. Weekly shift utilization trend (last 7 days)
    ══════════════════════════════════════════ */
    $weeklyStmt = $db->prepare("
        SELECT
            DATE_FORMAT(check_in,'%a')  AS day,
            DATE(check_in)              AS att_date,
            COUNT(DISTINCT user_id)     AS workers
        FROM  v_attendance
        WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND   status IN ('PRESENT','LATE')
        GROUP BY att_date, day
        ORDER BY att_date ASC
    ");
    $weeklyStmt->execute();
    $weeklyTrend = array_map(fn($r) => [
        'day'         => $r['day'],
        'workers'     => (int) $r['workers'],
        'utilization' => $totalWorkers > 0
            ? min(100, round(((int)$r['workers'] / $totalWorkers) * 100))
            : 0,
    ], $weeklyStmt->fetchAll(PDO::FETCH_ASSOC));

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "kpis"    => [
            "overallUtilization" => $overallUtilization,
            "idlePct"            => $idlePct,
            "activeShifts"       => $activeShifts,
            "totalWorkers"       => $totalWorkers,
            "presentToday"       => $totalPresent,
        ],
        "shiftEfficiency"  => $shiftEfficiency,
        "departmentShift"  => $departmentShift,
        "weeklyTrend"      => $weeklyTrend,
        "generatedAt"      => date('h:i A'),
        "date"             => date('D, d M Y'),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}