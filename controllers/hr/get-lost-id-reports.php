<?php

session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

    $db = (new Database())->connect();

    $stmt = $db->query("
        SELECT
            l.id,
            l.user_id,
            l.name,
            l.employee_id,
            l.date_lost,
            l.location,
            l.notes,
            l.evidence_file,
            l.status,
            l.created_at,
            u.email,
            c.card_number
        FROM lost_id_reports l
        INNER JOIN users u ON u.id = l.user_id
        LEFT JOIN id_cards c ON c.id = (
            SELECT ic.id
            FROM id_cards ic
            WHERE ic.user_id = l.user_id
            ORDER BY ic.created_at DESC, ic.id DESC
            LIMIT 1
        )
        ORDER BY
            CASE WHEN l.status = 'PENDING' THEN 0 ELSE 1 END,
            l.created_at DESC
    ");

    $reports = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'userId' => (int) $row['user_id'],
            'fullName' => $row['name'],
            'email' => $row['email'],
            'employeeId' => $row['employee_id'],
            'cardNumber' => $row['card_number'] ?? null,
            'dateLost' => $row['date_lost'],
            'location' => $row['location'],
            'notes' => $row['notes'] ?? '',
            'evidenceFile' => $row['evidence_file'] ?? null,
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success' => true,
        'reports' => $reports,
    ]);
} catch (Throwable $e) {
    $statusCode = 500;
    if ($e->getMessage() === 'Unauthorized') {
        $statusCode = 401;
    } elseif ($e->getMessage() === 'Forbidden') {
        $statusCode = 403;
    }

    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
