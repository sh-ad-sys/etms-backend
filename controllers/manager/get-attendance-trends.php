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

    /* ── 1. KPIs ── */
    $totalWorkers = (int) $db->query("
        SELECT COUNT(*) FROM users WHERE status = 'ACTIVE'
    ")->fetchColumn();

    $liveStmt = $db->prepare("
        SELECT SUM(status IN ('PRESENT','LATE')) AS present
        FROM   v_attendance
        WHERE  DATE(check_in) = ?
    ");
    $liveStmt->execute([$today]);
    $livePresent    = (int) $liveStmt->fetchColumn();
    $absentToday    = max(0, $totalWorkers - $livePresent);
    $attendanceRate = $totalWorkers > 0
        ? round(($livePresent / $totalWorkers) * 100) : 0;
    $riskLevel = $attendanceRate >= 80 ? 'Low' : ($attendanceRate >= 60 ? 'Medium' : 'High');

    /* ── 2. Weekly trend (this week Mon–today) ── */
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekStmt  = $db->prepare("
        SELECT
            DATE_FORMAT(check_in,'%a')  AS day_name,
            SUM(status='PRESENT')       AS present,
            SUM(status='LATE')          AS late,
            SUM(status='ABSENT')        AS absent
        FROM  v_attendance
        WHERE DATE(check_in) BETWEEN ? AND ?
        GROUP BY DATE(check_in), day_name
        ORDER BY DATE(check_in) ASC
    ");
    $weekStmt->execute([$weekStart, $today]);
    $weeklyTrend = array_map(fn($r) => [
        'day'     => $r['day_name'],
        'present' => (int)$r['present'],
        'late'    => (int)$r['late'],
        'absent'  => (int)$r['absent'],
    ], $weekStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ── 3. Absenteeism risk forecast by day-of-week (last 8 weeks) ── */
    $forecastStmt = $db->prepare("
        SELECT
            DATE_FORMAT(check_in,'%a')      AS day_label,
            DAYOFWEEK(check_in)             AS dow_num,
            COUNT(*)                        AS total,
            SUM(status='ABSENT')            AS absent_count
        FROM  v_attendance
        WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
        GROUP BY day_label, dow_num
        ORDER BY dow_num ASC
    ");
    $forecastStmt->execute();
    $forecastData = array_map(fn($r) => [
        'day'  => $r['day_label'],
        'risk' => $r['total'] > 0
            ? (int)round(($r['absent_count'] / $r['total']) * 100) : 0,
    ], $forecastStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ── 4. Department breakdown (today) ── */
    $deptStmt = $db->prepare("
        SELECT
            COALESCE(d.name, u.department, 'General') AS dept,
            COUNT(*)                                   AS total,
            SUM(a.status = 'PRESENT')                 AS present,
            SUM(a.status = 'LATE')                    AS late,
            SUM(a.status IS NULL OR a.status NOT IN ('PRESENT','LATE')) AS absent
        FROM  users u
        LEFT  JOIN departments  d ON d.id     = u.department_id
        LEFT  JOIN v_attendance a ON a.user_id = u.id
                                 AND DATE(a.check_in) = :today
        WHERE u.status = 'ACTIVE'
        GROUP BY dept
        ORDER BY total DESC
        LIMIT 10
    ");
    $deptStmt->execute([':today' => $today]);
    $deptBreakdown = array_map(fn($r) => [
        'dept'    => $r['dept'],
        'total'   => (int)$r['total'],
        'present' => (int)$r['present'],
        'late'    => (int)$r['late'],
        'absent'  => (int)$r['absent'],
    ], $deptStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ── 5. Hourly check-in flow (today) ── */
    $hourlyStmt = $db->prepare("
        SELECT
            DATE_FORMAT(check_in,'%H:00') AS time,
            COUNT(*)                       AS workers
        FROM  v_attendance
        WHERE DATE(check_in) = ?
        AND   status IN ('PRESENT','LATE')
        GROUP BY time
        ORDER BY time ASC
    ");
    $hourlyStmt->execute([$today]);
    $hourlyFlow = array_map(fn($r) => [
        'time'    => $r['time'],
        'workers' => (int)$r['workers'],
    ], $hourlyStmt->fetchAll(PDO::FETCH_ASSOC));

    ob_end_clean();
    echo json_encode([
        "success"       => true,
        "kpis"          => compact('totalWorkers','livePresent','absentToday','attendanceRate','riskLevel'),
        "weeklyTrend"   => $weeklyTrend,
        "forecastData"  => $forecastData,
        "deptBreakdown" => $deptBreakdown,
        "hourlyFlow"    => $hourlyFlow,
        "generatedAt"   => date('h:i A'),
        "date"          => date('D, d M Y'),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}