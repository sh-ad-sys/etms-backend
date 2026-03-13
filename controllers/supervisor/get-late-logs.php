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

    $statusFilter = $_GET['status'] ?? 'all';
    $dateFrom     = $_GET['from']   ?? '';
    $dateTo       = $_GET['to']     ?? '';
    $search       = trim($_GET['search'] ?? '');

    $where  = ["a.status = 'LATE'"];
    $params = [];

    if ($statusFilter === 'excused')   { $where[] = "a.excused = 1"; }
    if ($statusFilter === 'unexcused') { $where[] = "a.excused = 0"; }

    if ($dateFrom) { $where[] = "DATE(a.check_in) >= :from"; $params[':from'] = $dateFrom; }
    if ($dateTo)   { $where[] = "DATE(a.check_in) <= :to";   $params[':to']   = $dateTo;   }

    if ($search) {
        $where[]          = "(u.full_name LIKE :search OR u.department LIKE :search OR d.name LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    $whereSQL = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT
            a.id,
            a.check_in,
            a.check_out,
            a.reason,
            a.excused,
            s.start_time,
            GREATEST(0, TIMESTAMPDIFF(MINUTE,
                CONCAT(DATE(a.check_in), ' ', IFNULL(s.start_time, '08:00:00')),
                a.check_in
            )) AS minutes_late,
            u.full_name,
            u.employee_code,
            u.department,
            d.name AS department_name
        FROM  v_attendance   a
        JOIN  users        u ON u.id  = a.user_id
        LEFT  JOIN departments d ON d.id = u.department_id
        LEFT  JOIN shifts      s ON s.id = a.shift_id
        WHERE {$whereSQL}
        ORDER BY a.check_in DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = date('Y-m-d');

    $logs = array_map(fn($r) => [
        'id'           => (string) $r['id'],
        'name'         => $r['full_name'],
        'employeeCode' => $r['employee_code'] ?? '',
        'department'   => $r['department_name'] ?? $r['department'] ?? '',
        'date'         => date('Y-m-d', strtotime($r['check_in'])),
        'checkIn'      => date('h:i A', strtotime($r['check_in'])),
        'checkOut'     => $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : null,
        'minutesLate'  => (int) $r['minutes_late'],
        'reason'       => $r['reason'] ?? '',
        'status'       => (bool)$r['excused'] ? 'Excused' : 'Unexcused',
        'excused'      => (bool)$r['excused'],
    ], $rows);

    $todayCount = count(array_filter($logs, fn($l) => $l['date'] === $today));
    $excused    = count(array_filter($logs, fn($l) =>  $l['excused']));
    $unexcused  = count($logs) - $excused;

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "logs"    => $logs,
        "summary" => [
            "totalToday" => $todayCount,
            "total"      => count($logs),
            "excused"    => $excused,
            "unexcused"  => $unexcused,
        ],
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}