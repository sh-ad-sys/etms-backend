<?php ob_start();

/* ================= CORS ================= */

// Get allowed origin from environment or use default
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean(); http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]); exit;
}

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

    $userId    = (int) $_SESSION['user_id'];
    $leaveType = trim($_POST['leaveType'] ?? '');
    $startDate = trim($_POST['startDate'] ?? '');
    $endDate   = trim($_POST['endDate']   ?? '');
    $reason    = trim($_POST['reason']    ?? '');

    /* ── Map frontend label → DB enum ── */
    $typeMap = [
        'Annual Leave'    => 'ANNUAL',
        'Sick Leave'      => 'SICK',
        'Emergency Leave' => 'EMERGENCY',
    ];

    if (!isset($typeMap[$leaveType])) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid leave type"]); exit;
    }
    if (!$startDate || !$endDate || !$reason) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Start date, end date and reason are required"]); exit;
    }

    $start = new DateTime($startDate);
    $end   = new DateTime($endDate);

    if ($end < $start) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "End date cannot be before start date"]); exit;
    }

    $totalDays   = (int) $end->diff($start)->days + 1;
    $dbLeaveType = $typeMap[$leaveType];
    $db          = (new Database())->connect();
    ensureHrApprovalColumn($db);

    /* ── Check overlapping requests ── */
    $overlap = $db->prepare("
        SELECT COUNT(*) FROM leave_requests
        WHERE  user_id      = ?
        AND    final_status != 'REJECTED'
        AND    start_date   <= ?
        AND    end_date     >= ?
    ");
    $overlap->execute([$userId, $endDate, $startDate]);

    if ((int) $overlap->fetchColumn() > 0) {
        ob_end_clean(); http_response_code(409);
        echo json_encode(["success" => false, "error" => "You already have a leave request overlapping these dates"]); exit;
    }

    /* ── Handle optional file upload ── */
    $documentUrl = null;

    if (!empty($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            ob_end_clean(); http_response_code(400);
            echo json_encode(["success" => false, "error" => "Only PDF, JPG and PNG allowed"]); exit;
        }
        if ($_FILES['document']['size'] > 5 * 1024 * 1024) {
            ob_end_clean(); http_response_code(400);
            echo json_encode(["success" => false, "error" => "Document must be under 5MB"]); exit;
        }

        $uploadDir = __DIR__ . '/../../uploads/leave/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $fileName    = 'leave_' . $userId . '_' . time() . '.' . $ext;
        $documentUrl = 'uploads/leave/' . $fileName;

        if (!move_uploaded_file($_FILES['document']['tmp_name'], $uploadDir . $fileName)) {
            ob_end_clean(); http_response_code(500);
            echo json_encode(["success" => false, "error" => "Failed to save document"]); exit;
        }
    }

    /* ── Insert using your actual column names ── */
    $stmt = $db->prepare("
        INSERT INTO leave_requests
            (user_id, leave_type, start_date, end_date, reason, document_url,
             supervisor_approval, manager_approval, hr_approval, final_status)
        VALUES
            (:user_id, :leave_type, :start_date, :end_date, :reason, :document_url,
             'PENDING', 'PENDING', 'PENDING', 'PENDING')
    ");

    $stmt->execute([
        ':user_id'      => $userId,
        ':leave_type'   => $dbLeaveType,
        ':start_date'   => $startDate,
        ':end_date'     => $endDate,
        ':reason'       => $reason,
        ':document_url' => $documentUrl,
    ]);

    ob_end_clean();
    echo json_encode([
        "success"   => true,
        "message"   => "Leave request submitted successfully. Awaiting approval.",
        "requestId" => (string) $db->lastInsertId(),
        "totalDays" => $totalDays,
    ]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
