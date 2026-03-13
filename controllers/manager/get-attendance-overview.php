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

    $db         = (new Database())->connect();
    $today      = date('Y-m-d');
    $search     = trim($_GET['search'] ?? '');
    $department = trim($_GET['department'] ?? '');
    $status     = trim($_GET['status'] ?? 'all');

    /* ── All active users LEFT JOINed with today's attendance ── */
    $where  = ["u.status = 'ACTIVE'"];
    $params = [];

    if ($search) {
        $where[]           = "(u.full_name LIKE :search OR u.employee_code LIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    if ($department) {
        $where[]              = "(d.name = :dept OR u.department = :dept)";
        $params[':dept']      = $department;
    }

    $whereSQL = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT
            u.id,
            u.full_name,
            u.employee_code,
            u.department,
            d.name      AS department_name,
            a.check_in,
            a.check_out,
            a.status    AS att_status,
            lr.final_status AS leave_status
        FROM  users u
        LEFT  JOIN departments d ON d.id = u.department_id
        LEFT  JOIN v_attendance  a ON a.user_id = u.id
                                 AND DATE(a.check_in) = :today
        LEFT  JOIN leave_requests lr ON lr.user_id = u.id
                                     AND lr.start_date <= :today2
                                     AND lr.end_date   >= :today3
                                     AND lr.final_status = 'APPROVED'
        WHERE {$whereSQL}
        ORDER BY u.full_name ASC
    ");

    $params[':today']  = $today;
    $params[':today2'] = $today;
    $params[':today3'] = $today;
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $records = [];

    foreach ($rows as $row) {

        /* Derive frontend status */
        if ($row['leave_status'] === 'APPROVED') {
            $frontendStatus = 'On Leave';
        } elseif ($row['att_status'] === 'PRESENT') {
            $frontendStatus = 'Present';
        } elseif ($row['att_status'] === 'LATE') {
            $frontendStatus = 'Late';
        } elseif ($row['att_status'] === 'OUTSIDE_GEOFENCE') {
            $frontendStatus = 'Outside';
        } else {
            $frontendStatus = 'Absent';
        }

        /* Apply status filter after derivation */
        if ($status !== 'all' && strtolower($frontendStatus) !== strtolower($status)) continue;

        $records[] = [
            'id'           => (string) $row['id'],
            'name'         => $row['full_name'],
            'employeeCode' => $row['employee_code'] ?? '',
            'department'   => $row['department_name'] ?? $row['department'] ?? 'General',
            'checkIn'      => $row['check_in']  ? date('h:i A', strtotime($row['check_in']))  : '—',
            'checkOut'     => $row['check_out'] ? date('h:i A', strtotime($row['check_out'])) : '—',
            'status'       => $frontendStatus,
        ];
    }

    /* Summary — always across all staff regardless of filter */
    $allStatuses = array_column(
        array_map(fn($r) => ['s' => $r['status']], $records),
        's'
    );

    /* Recount from full unfiltered rows for accurate KPIs */
    $total   = count($rows);
    $present = 0; $late = 0; $absent = 0; $onLeave = 0; $outside = 0;

    foreach ($rows as $row) {
        if ($row['leave_status'] === 'APPROVED')      $onLeave++;
        elseif ($row['att_status'] === 'PRESENT')     $present++;
        elseif ($row['att_status'] === 'LATE')        $late++;
        elseif ($row['att_status'] === 'OUTSIDE_GEOFENCE') $outside++;
        else                                           $absent++;
    }

    /* Department list for filter dropdown */
    $deptStmt = $db->query("
        SELECT DISTINCT COALESCE(d.name, u.department) AS name
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.status = 'ACTIVE'
        AND   COALESCE(d.name, u.department) IS NOT NULL
        ORDER BY name ASC
    ");
    $departments = array_column($deptStmt->fetchAll(PDO::FETCH_ASSOC), 'name');

    ob_end_clean();
    echo json_encode([
        "success"     => true,
        "records"     => $records,
        "summary"     => [
            "total"   => $total,
            "present" => $present,
            "late"    => $late,
            "absent"  => $absent,
            "onLeave" => $onLeave,
            "outside" => $outside,
        ],
        "departments" => $departments,
        "date"        => date('D, d M Y'),
        "asOf"        => date('h:i A'),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}