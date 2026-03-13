<?php

/* ================= SESSION ================= */

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => 'localhost',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

/* ================= HEADERS ================= */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

/* ================= AUTH ================= */

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]);
    exit;
}

require_once "../../config/db.php";
use Config\Database;

try {

    $db       = (new Database())->connect();
    $workerId = (int) $_SESSION['user_id'];

    /* Optional filter: ?status=pending|reviewed|approved|all */
    $status  = $_GET['status'] ?? 'all';
    $allowed = ['pending', 'reviewed', 'approved', 'all'];
    if (!in_array($status, $allowed)) $status = 'all';

    $where = $status === 'all' ? '' : 'AND f.status = :status';

    $stmt = $db->prepare("
        SELECT
            f.id,
            f.task,
            f.department,
            f.performance,
            f.safety_compliance,
            f.quality_score,
            f.remarks,
            f.status,
            f.evaluation_date,
            u.full_name AS supervisor_name
        FROM  task_feedback f
        JOIN  users u ON u.id = f.supervisor_id
        WHERE f.worker_id = :worker_id
        {$where}
        ORDER BY f.evaluation_date DESC
    ");

    $stmt->bindValue(':worker_id', $workerId, PDO::PARAM_INT);
    if ($status !== 'all') $stmt->bindValue(':status', $status);
    $stmt->execute();

    $feedbacks = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $feedbacks[] = [
            'id'               => (string) $row['id'],
            'task'             => $row['task'],
            'department'       => $row['department'],
            'supervisor'       => $row['supervisor_name'],
            'performance'      => (int) $row['performance'],
            'safetyCompliance' => (int) $row['safety_compliance'],
            'qualityScore'     => (int) $row['quality_score'],
            'remarks'          => $row['remarks'] ?? '',
            'status'           => $row['status'],
            'date'             => $row['evaluation_date'],
        ];
    }

    /* Overall averages */
    $avg = [
        'performance'      => 0,
        'safetyCompliance' => 0,
        'qualityScore'     => 0,
    ];

    if (count($feedbacks)) {
        $avg['performance']      = round(array_sum(array_column($feedbacks, 'performance'))      / count($feedbacks), 1);
        $avg['safetyCompliance'] = round(array_sum(array_column($feedbacks, 'safetyCompliance')) / count($feedbacks), 1);
        $avg['qualityScore']     = round(array_sum(array_column($feedbacks, 'qualityScore'))     / count($feedbacks), 1);
    }

    echo json_encode([
        "success"   => true,
        "feedbacks" => $feedbacks,
        "averages"  => $avg,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}