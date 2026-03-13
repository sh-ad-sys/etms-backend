<?php

use Config\Database;

session_start();

/* ================= CORS HEADERS ================= */

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

header("Content-Type: application/json");

/* ================= HANDLE PREFLIGHT REQUEST ================= */

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/* ================= IMPORT DATABASE ================= */

require_once __DIR__ . "/../config/db.php";

$db = (new Database())->connect();

/* ================= GET ANNOUNCEMENTS ================= */

if ($_SERVER['REQUEST_METHOD'] === "GET") {

    try {

        $stmt = $db->prepare("
            SELECT 
                a.*,
                u.full_name AS creator_name
            FROM announcements a
            LEFT JOIN users u ON a.created_by = u.id
            ORDER BY a.created_at DESC
        ");

        $stmt->execute();

        echo json_encode([
            "success" => true,
            "announcements" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "error" => "Failed to fetch announcements"
        ]);
    }
}

/* ================= CREATE ANNOUNCEMENT ================= */

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    try {

        /* === AUTH CHECK === */

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);

            echo json_encode([
                "success" => false,
                "error" => "Unauthorized"
            ]);

            exit;
        }

        $data = json_decode(file_get_contents("php://input"));

        if (!$data || empty($data->title) || empty($data->message)) {

            http_response_code(400);

            echo json_encode([
                "success" => false,
                "error" => "Title and message are required"
            ]);

            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO announcements
            (title, message, created_by, target_role)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            htmlspecialchars($data->title),
            htmlspecialchars($data->message),
            $_SESSION['user_id'],
            $data->target_role ?? "ALL"
        ]);

        echo json_encode([
            "success" => true,
            "message" => "Announcement created successfully"
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "error" => "Server error occurred"
        ]);
    }
}