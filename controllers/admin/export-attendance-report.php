<?php
/* ================= CORS ================= */
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/JWTAuth.php';

use Middleware\JWTAuth;

$token = JWTAuth::requireAuth();
$userRole = ucfirst(strtolower($token['role'] ?? ''));

if (!in_array($userRole, ['Admin', 'Hr'], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Only Admin and HR can export reports']);
    exit;
}

$database = new \Config\Database();
$conn = $database->connect();

$period = $_GET['period'] ?? 'daily';
$date = $_GET['date'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

[$startDate, $endDate] = getDateRange($period, $date);
$dailyData = getDailyAttendance($conn, $startDate, $endDate);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance-report-' . $period . '-' . $date . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Date', 'Total Staff', 'Present', 'Absent', 'Late', 'Attendance Rate']);

foreach ($dailyData as $day) {
    $present = (int) $day['users_present'];
    $total = (int) $day['total_staff'];
    $absent = $total - $present;
    $late = (int) $day['late_count'];
    $attendanceRate = $total > 0 ? round(($present / $total) * 100, 1) : 0;

    fputcsv($output, [
        $day['date'],
        $total,
        $present,
        $absent,
        $late,
        $attendanceRate . '%'
    ]);
}

fclose($output);
exit;

function getDateRange(string $period, string $date): array {
    $startDate = $date;
    $endDate = $date;

    if ($period === 'weekly') {
        $dateObj = new DateTime($date);
        $dateObj->modify('monday this week');
        $startDate = $dateObj->format('Y-m-d');
        $dateObj->modify('sunday this week');
        $endDate = $dateObj->format('Y-m-d');
    } elseif ($period === 'monthly') {
        $dateObj = new DateTime($date);
        $dateObj->modify('first day of this month');
        $startDate = $dateObj->format('Y-m-d');
        $dateObj->modify('last day of this month');
        $endDate = $dateObj->format('Y-m-d');
    }

    return [$startDate, $endDate];
}

function getDailyAttendance(PDO $conn, string $startDate, string $endDate): array {
    $query = "
        SELECT 
            DATE(a.check_in_time) as date,
            COUNT(DISTINCT a.user_id) as users_present,
            SUM(CASE WHEN TIME(a.check_in_time) > '09:00:00' THEN 1 ELSE 0 END) as late_count,
            (SELECT COUNT(DISTINCT id) FROM users WHERE role = 'Staff') as total_staff
        FROM attendance a
        WHERE DATE(a.check_in_time) BETWEEN ? AND ?
        GROUP BY DATE(a.check_in_time)
        ORDER BY DATE(a.check_in_time) ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([$startDate, $endDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
