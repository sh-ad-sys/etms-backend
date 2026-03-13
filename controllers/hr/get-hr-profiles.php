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
    $search     = trim($_GET['search']     ?? '');
    $department = trim($_GET['department'] ?? '');
    $status     = trim($_GET['status']     ?? '');
    $role       = trim($_GET['role']       ?? '');

    $where  = ["1=1"];
    $params = [];

    if ($search) {
        $where[]           = "(u.full_name LIKE :search OR u.employee_code LIKE :search OR u.email LIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    if ($department) {
        $where[]              = "(d.name = :dept OR u.department = :dept)";
        $params[':dept']      = $department;
    }
    if ($status) {
        $where[]              = "u.status = :status";
        $params[':status']    = strtoupper($status);
    }
    if ($role) {
        $where[]              = "r.name LIKE :role";
        $params[':role']      = "%{$role}%";
    }

    $whereSQL = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT
            u.id,
            u.employee_code,
            u.full_name,
            u.email,
            u.phone,
            u.department,
            u.status,
            u.created_at,
            COALESCE(d.name, u.department, 'General') AS department_name,
            r.name  AS role_name,
            /* Attendance rate last 30 days */
            ROUND(
                COUNT(DISTINCT DATE(a.check_in)) /
                NULLIF((
                    SELECT COUNT(DISTINCT DATE(check_in))
                    FROM   v_attendance
                    WHERE  check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ), 0) * 100
            ) AS attendance_rate,
            /* Last check-in */
            MAX(a.check_in) AS last_check_in
        FROM  users u
        LEFT  JOIN departments  d  ON d.id  = u.department_id
        LEFT  JOIN roles        r  ON r.id  = u.role_id
        LEFT  JOIN v_attendance a  ON a.user_id = u.id
                                   AND a.check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        WHERE {$whereSQL}
        GROUP BY u.id, u.employee_code, u.full_name, u.email, u.phone,
                 u.department, u.status, u.created_at, department_name, role_name
        ORDER BY u.full_name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $profiles = array_map(fn($r) => [
        'id'             => (string) $r['id'],
        'employeeNo'     => $r['employee_code'] ?? '—',
        'name'           => $r['full_name'],
        'email'          => $r['email'],
        'phone'          => $r['phone']          ?? '—',
        'department'     => $r['department_name'],
        'role'           => $r['role_name']       ?? 'Staff',
        'status'         => ucfirst(strtolower($r['status'])),
        'attendanceRate' => (int) ($r['attendance_rate'] ?? 0),
        'lastCheckIn'    => $r['last_check_in']
            ? date('M d, Y h:i A', strtotime($r['last_check_in']))
            : 'Never',
        'joinedOn'       => date('M d, Y', strtotime($r['created_at'])),
    ], $rows);

    /* Filter dropdowns */
    $departments = array_column(
        $db->query("
            SELECT DISTINCT COALESCE(d.name, u.department) AS name
            FROM users u LEFT JOIN departments d ON d.id = u.department_id
            WHERE COALESCE(d.name, u.department) IS NOT NULL
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );

    $roles = array_column(
        $db->query("SELECT DISTINCT name FROM roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );

    /* Summary */
    $total      = count($profiles);
    $active     = count(array_filter($profiles, fn($p) => $p['status'] === 'Active'));
    $inactive   = $total - $active;

    ob_end_clean();
    echo json_encode([
        "success"     => true,
        "profiles"    => $profiles,
        "summary"     => compact('total', 'active', 'inactive'),
        "departments" => $departments,
        "roles"       => $roles,
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}