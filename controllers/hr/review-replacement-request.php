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
    $requestId = (int) ($payload['requestId'] ?? 0);
    $action = strtoupper(trim((string) ($payload['action'] ?? '')));

    if ($requestId <= 0) {
        throw new Exception("Invalid request");
    }

    if (!in_array($action, ['APPROVED', 'REJECTED'], true)) {
        throw new Exception("Invalid action");
    }

    $db = (new Database())->connect();
    $db->beginTransaction();

    $requestStmt = $db->prepare("
        SELECT id, user_id, status
        FROM id_replacement_requests
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $requestStmt->execute([$requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new Exception("Replacement request not found");
    }

    if (strtoupper((string) $request['status']) !== 'PENDING') {
        throw new Exception("This request has already been reviewed");
    }

    $db->prepare("
        UPDATE id_replacement_requests
        SET status = ?
        WHERE id = ?
    ")->execute([$action, $requestId]);

    $cardStatus = $action === 'APPROVED' ? 'Active' : 'Lost';
    $historyNote = $action === 'APPROVED'
        ? 'Replacement request approved by HR'
        : 'Replacement request rejected by HR';

    $db->prepare("
        UPDATE id_cards
        SET status = ?
        WHERE user_id = ?
    ")->execute([$cardStatus, $request['user_id']]);

    try {
        $db->prepare("
            INSERT INTO id_status_history (user_id, status, note, created_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([
            $request['user_id'],
            $cardStatus,
            $historyNote,
        ]);
    } catch (Throwable $historyError) {
        // History logging should not block the approval workflow.
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'status' => $action,
        'cardStatus' => $cardStatus,
        'message' => $action === 'APPROVED'
            ? 'Replacement request approved'
            : 'Replacement request rejected',
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
            'Invalid request',
            'Invalid action',
            'Replacement request not found',
            'This request has already been reviewed',
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
