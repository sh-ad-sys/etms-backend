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
            r.id,
            r.user_id,
            r.notes,
            r.file_path,
            r.status,
            r.created_at,
            u.full_name,
            u.email,
            c.card_number
        FROM id_replacement_requests r
        INNER JOIN users u ON u.id = r.user_id
        LEFT JOIN id_cards c ON c.id = (
            SELECT ic.id
            FROM id_cards ic
            WHERE ic.user_id = r.user_id
            ORDER BY ic.created_at DESC, ic.id DESC
            LIMIT 1
        )
        ORDER BY
            CASE WHEN r.status = 'PENDING' THEN 0 ELSE 1 END,
            r.created_at DESC
    ");

    $requests = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'userId' => (int) $row['user_id'],
            'fullName' => $row['full_name'],
            'email' => $row['email'],
            'cardNumber' => $row['card_number'] ?? null,
            'notes' => $row['notes'] ?? '',
            'filePath' => $row['file_path'] ?? null,
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success' => true,
        'requests' => $requests,
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
