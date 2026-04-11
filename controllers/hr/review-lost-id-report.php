<?php

session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../../config/db.php";

use Config\Database;

try {
    if (!isset($_SESSION['user_id'], $_SESSION['user']['role'])) {
        throw new Exception("Unauthorized");
    }

    $role = strtoupper(trim((string) $_SESSION['user']['role']));
    if ($role !== 'HR' && $role !== 'ADMIN') {
        throw new Exception("Forbidden");
    }

    $payload = json_decode(file_get_contents("php://input"), true);
    $reportId = (int) ($payload['reportId'] ?? 0);
    $action = strtoupper(trim((string) ($payload['action'] ?? '')));

    if ($reportId <= 0) {
        throw new Exception("Invalid report");
    }

    if (!in_array($action, ['APPROVED', 'REJECTED'], true)) {
        throw new Exception("Invalid action");
    }

    $db = (new Database())->connect();
    $db->beginTransaction();

    $reportStmt = $db->prepare("
        SELECT id, user_id, status
        FROM lost_id_reports
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $reportStmt->execute([$reportId]);
    $report = $reportStmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        throw new Exception("Lost ID report not found");
    }

    if (strtoupper((string) $report['status']) !== 'PENDING') {
        throw new Exception("This report has already been reviewed");
    }

    $db->prepare("
        UPDATE lost_id_reports
        SET status = ?
        WHERE id = ?
    ")->execute([$action, $reportId]);

    $cardStatus = $action === 'APPROVED' ? 'Lost' : 'Active';
    $historyNote = $action === 'APPROVED'
        ? 'Lost ID report approved by HR'
        : 'Lost ID report rejected by HR';

    $db->prepare("
        UPDATE id_cards
        SET status = ?
        WHERE user_id = ?
    ")->execute([$cardStatus, $report['user_id']]);

    try {
        $db->prepare("
            INSERT INTO id_status_history (user_id, status, note, created_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([
            $report['user_id'],
            $cardStatus,
            $historyNote,
        ]);
    } catch (Throwable $historyError) {
        // History logging should not block the review workflow.
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'status' => $action,
        'cardStatus' => $cardStatus,
        'message' => $action === 'APPROVED'
            ? 'Lost ID report approved'
            : 'Lost ID report rejected',
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    $statusCode = 500;
    if ($e->getMessage() === 'Forbidden') {
        $statusCode = 403;
    } elseif ($e->getMessage() === 'Unauthorized') {
        $statusCode = 401;
    } elseif (
        in_array($e->getMessage(), [
            'Invalid report',
            'Invalid action',
            'Lost ID report not found',
            'This report has already been reviewed',
        ], true)
    ) {
        $statusCode = 400;
    }

    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
