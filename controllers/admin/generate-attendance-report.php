<?php
/* ================= CORS ================= */
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header('Content-Type: application/json');

/* ================= PRE-FLIGHT ================= */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/JWTAuth.php';

use Middleware\JWTAuth;

// Validate JWT token and ensure user is Admin or HR
$token = JWTAuth::requireAuth();
$userRole = ucfirst(strtolower($token['role'] ?? ''));

if (!in_array($userRole, ['Admin', 'Hr'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only Admin and HR can access reports']);
    exit;
}

try {
    $database = new \Config\Database();
    $conn = $database->connect();
    
    $period = $_GET['period'] ?? 'daily';
    $date = $_GET['date'] ?? date('Y-m-d');
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid date format']);
        exit;
    }
    
    // Generate report based on period
    $report = generateReport($conn, $period, $date);
    
    echo json_encode([
        'success' => true,
        'report' => $report
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function generateReport($conn, $period, $date) {
    $startDate = $date;
    $endDate = $date;
    
    // Calculate date range based on period
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
    
    // Get daily attendance data
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
    $dailyData = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    $totalRecords = count($dailyData);
    $totalStaff = !empty($dailyData) ? $dailyData[0]['total_staff'] : 0;
    $totalPresent = array_sum(array_column($dailyData, 'users_present'));
    $totalLate = array_sum(array_column($dailyData, 'late_count'));
    $totalAbsent = ($totalStaff * $totalRecords) - $totalPresent;
    $avgAttendanceRate = $totalRecords > 0 ? ($totalPresent / ($totalStaff * $totalRecords)) * 100 : 0;
    
    // Format daily data for display
    $formattedData = [];
    foreach ($dailyData as $day) {
        $present = (int)$day['users_present'];
        $total = (int)$day['total_staff'];
        $absent = $total - $present;
        $late = (int)$day['late_count'];
        $attendanceRate = $total > 0 ? ($present / $total) * 100 : 0;
        
        $formattedData[] = [
            'date' => $day['date'],
            'total_staff' => $total,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'attendance_rate' => $attendanceRate
        ];
    }
    
    return [
        'summary' => [
            'period' => $period === 'daily' ? $date : ($period === 'weekly' ? "Week of $startDate" : "Month of " . date('F Y', strtotime($date))),
            'total_records' => $totalRecords,
            'avg_attendance_rate' => $avgAttendanceRate,
            'total_staff' => $totalStaff,
            'start_date' => $startDate,
            'end_date' => $endDate
        ],
        'daily_data' => $formattedData
    ];
}
?>
