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
    $search     = trim($_GET['search']  ?? '');
    $roleFilter = trim($_GET['role']    ?? '');
    $statusFilter = trim($_GET['status'] ?? '');
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $perPage    = (int)($_GET['perPage'] ?? 10);

    $where  = ["1=1"];
    $params = [];

    if ($search) {
        $where[]           = "(u.full_name LIKE :search OR u.email LIKE :search OR u.employee_code LIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    if ($roleFilter) {
        $where[]           = "r.name = :role";
        $params[':role']   = $roleFilter;
    }
    if ($statusFilter) {
        $where[]              = "u.status = :status";
        $params[':status']    = strtoupper($statusFilter);
    }

    $whereSQL = implode(' AND ', $where);

    /* Total count */
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE {$whereSQL}
    ");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    /* Paginated rows */
    $offset = ($page - 1) * $perPage;
    $stmt   = $db->prepare("
        SELECT
            u.id,
            u.employee_code,
            u.full_name,
            u.email,
            u.phone,
            u.status,
            u.created_at,
            r.name  AS role_name,
            r.id    AS role_id,
            COALESCE(d.name, u.department, 'General') AS department
        FROM  users u
        LEFT  JOIN roles       r ON r.id = u.role_id
        LEFT  JOIN departments d ON d.id = u.department_id
        WHERE {$whereSQL}
        ORDER BY u.full_name ASC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $users = array_map(fn($r) => [
        'id'          => (string) $r['id'],
        'employeeCode'=> $r['employee_code'] ?? '',
        'name'        => $r['full_name'],
        'email'       => $r['email'],
        'phone'       => $r['phone'] ?? '',
        'role'        => $r['role_name'] ?? 'Staff',
        'roleId'      => $r['role_id'],
        'department'  => $r['department'],
        'status'      => ucfirst(strtolower($r['status'])),
        'joinedOn'    => date('M d, Y', strtotime($r['created_at'])),
    ], $rows);

    /* Roles for dropdowns */
    $roles = array_column(
        $db->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
        'name', 'id'
    );

    ob_end_clean();
    echo json_encode([
        "success"    => true,
        "users"      => $users,
        "total"      => $total,
        "page"       => $page,
        "perPage"    => $perPage,
        "totalPages" => (int) ceil($total / $perPage),
        "roles"      => array_values(array_map(fn($id, $name) => compact('id','name'),
                            array_keys($roles), $roles)),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}