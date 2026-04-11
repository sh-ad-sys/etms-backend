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

function ensureHrApprovalColumn(PDO $db): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $column = $db->query("SHOW COLUMNS FROM leave_requests LIKE 'hr_approval'")->fetch(PDO::FETCH_ASSOC);

    if (!$column) {
        $db->exec("
            ALTER TABLE leave_requests
            ADD COLUMN hr_approval VARCHAR(20) NOT NULL DEFAULT 'PENDING'
            AFTER manager_approval
        ");
    }

    $checked = true;
}

try {
    if (!isset($_SESSION['user']['role'])) {
        throw new Exception("Unauthorized");
    }

    $role = strtoupper(trim((string) $_SESSION['user']['role']));
    if ($role !== 'HR' && $role !== 'ADMIN') {
        throw new Exception("Forbidden");
    }

    $db = (new Database())->connect();
    ensureHrApprovalColumn($db);

    $filter  = strtoupper($_GET['status'] ?? 'ALL');
    $allowed = ['ALL', 'PENDING', 'APPROVED', 'REJECTED'];
    if (!in_array($filter, $allowed, true)) $filter = 'ALL';

    $where = $filter === 'ALL' ? '' : 'AND lr.hr_approval = :status';

    $stmt = $db->prepare("
        SELECT
            lr.id,
            lr.leave_type,
            lr.start_date,
            lr.end_date,
            lr.reason,
            lr.document_url,
            lr.supervisor_approval,
            lr.manager_approval,
            lr.hr_approval,
            lr.final_status,
            lr.created_at,
            DATEDIFF(lr.end_date, lr.start_date) + 1 AS total_days,
            u.full_name,
            u.employee_code,
            u.department,
            d.name AS department_name
        FROM leave_requests lr
        JOIN users u ON u.id = lr.user_id
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE lr.supervisor_approval = 'APPROVED'
          AND lr.manager_approval = 'APPROVED'
          {$where}
        ORDER BY
            FIELD(lr.hr_approval, 'PENDING', 'APPROVED', 'REJECTED'),
            lr.created_at DESC
    ");

    if ($filter !== 'ALL') $stmt->bindValue(':status', $filter);
    $stmt->execute();

    $typeMap = [
        'ANNUAL'    => 'Annual Leave',
        'SICK'      => 'Sick Leave',
        'EMERGENCY' => 'Emergency Leave',
    ];

    $requests = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $requests[] = [
            'id'                 => (string) $row['id'],
            'workerName'         => $row['full_name'],
            'employeeCode'       => $row['employee_code'] ?? '',
            'department'         => $row['department_name'] ?? $row['department'] ?? '',
            'leaveType'          => $typeMap[$row['leave_type']] ?? $row['leave_type'],
            'startDate'          => $row['start_date'],
            'endDate'            => $row['end_date'],
            'totalDays'          => (int) $row['total_days'],
            'reason'             => $row['reason'],
            'hasDocument'        => !empty($row['document_url']),
            'supervisorApproval' => $row['supervisor_approval'],
            'managerApproval'    => $row['manager_approval'],
            'hrApproval'         => $row['hr_approval'],
            'finalStatus'        => $row['final_status'],
            'appliedOn'          => date('Y-m-d', strtotime($row['created_at'])),
        ];
    }

    $countsStmt = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(supervisor_approval = 'APPROVED' AND manager_approval = 'APPROVED' AND hr_approval = 'PENDING')  AS pending,
            SUM(supervisor_approval = 'APPROVED' AND manager_approval = 'APPROVED' AND hr_approval = 'APPROVED') AS approved,
            SUM(supervisor_approval = 'APPROVED' AND manager_approval = 'APPROVED' AND hr_approval = 'REJECTED') AS rejected
        FROM leave_requests
    ");
    $counts = $countsStmt->fetch(PDO::FETCH_ASSOC);

    ob_end_clean();
    echo json_encode([
        "success"  => true,
        "requests" => $requests,
        "summary"  => [
            "total"    => (int) ($counts['total'] ?? 0),
            "pending"  => (int) ($counts['pending'] ?? 0),
            "approved" => (int) ($counts['approved'] ?? 0),
            "rejected" => (int) ($counts['rejected'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    $statusCode = 500;
    if ($e->getMessage() === 'Unauthorized') {
        $statusCode = 401;
    } elseif ($e->getMessage() === 'Forbidden') {
        $statusCode = 403;
    }

    ob_end_clean(); http_response_code($statusCode);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
