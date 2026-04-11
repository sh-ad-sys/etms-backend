<?php ob_start();

/* ================= CORS ================= */

// Get allowed origin from environment or use default
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");
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

    $db     = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];
    ensureHrApprovalColumn($db);

    /* Map DB enum → frontend label */
    $typeMap = [
        'ANNUAL'    => 'Annual Leave',
        'SICK'      => 'Sick Leave',
        'EMERGENCY' => 'Emergency Leave',
    ];

    /* Optional filter */
    $filter  = strtoupper($_GET['status'] ?? 'ALL');
    $allowed = ['ALL', 'PENDING', 'APPROVED', 'REJECTED'];
    if (!in_array($filter, $allowed)) $filter = 'ALL';

    $where = $filter === 'ALL' ? '' : 'AND lr.final_status = :status';

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
            DATEDIFF(lr.end_date, lr.start_date) + 1 AS total_days
        FROM  leave_requests lr
        WHERE lr.user_id = :user_id
        {$where}
        ORDER BY lr.created_at DESC
    ");

    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    if ($filter !== 'ALL') $stmt->bindValue(':status', $filter);
    $stmt->execute();

    $leaves = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

        /* Normalise status to Title Case for frontend */
        $status = ucfirst(strtolower($row['final_status']));

        $leaves[] = [
            'id'                 => (string) $row['id'],
            'type'               => $typeMap[$row['leave_type']] ?? $row['leave_type'],
            'startDate'          => $row['start_date'],
            'endDate'            => $row['end_date'],
            'days'               => (int) $row['total_days'],
            'reason'             => $row['reason'],
            'status'             => $status,   // "Pending"|"Approved"|"Rejected"
            'supervisorApproval' => ucfirst(strtolower($row['supervisor_approval'])),
            'managerApproval'    => ucfirst(strtolower($row['manager_approval'])),
            'hrApproval'         => ucfirst(strtolower($row['hr_approval'])),
            'hasDocument'        => !empty($row['document_url']),
            'appliedOn'          => date('Y-m-d', strtotime($row['created_at'])),
            'reviewedOn'         => null,
            'reviewedBy'         => null,
            'supervisorRemarks'  => '',
        ];
    }

    /* Summary counts */
    $counts = $db->prepare("
        SELECT
            COUNT(*)                          AS total,
            SUM(final_status = 'APPROVED')    AS approved,
            SUM(final_status = 'PENDING')     AS pending,
            SUM(final_status = 'REJECTED')    AS rejected
        FROM leave_requests
        WHERE user_id = ?
    ");
    $counts->execute([$userId]);
    $summary = $counts->fetch(PDO::FETCH_ASSOC);

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "leaves"  => $leaves,
        "summary" => [
            "total"    => (int) $summary['total'],
            "approved" => (int) $summary['approved'],
            "pending"  => (int) $summary['pending'],
            "rejected" => (int) $summary['rejected'],
        ],
    ]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
