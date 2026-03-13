<?php

use Config\Database;

session_start();

/* ================= CORS HEADERS ================= */

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

header("Content-Type: application/json");

/* ================= HANDLE PRE-FLIGHT REQUEST ================= */

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/* ================= IMPORT DATABASE ================= */

require_once __DIR__ . "/../config/db.php";

$db = (new Database())->connect();

/* ================= LOG ACTION ================= */

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    try {

        /* ===== AUTH CHECK ===== */

        if (!isset($_SESSION['user_id'])) {

            http_response_code(401);

            echo json_encode([
                "success" => false,
                "error" => "Unauthorized access"
            ]);

            exit;
        }

        /* ===== READ JSON INPUT ===== */

        $data = json_decode(file_get_contents("php://input"));

        if (!$data || empty($data->action)) {

            http_response_code(400);

            echo json_encode([
                "success" => false,
                "error" => "Action field is required"
            ]);

            exit;
        }

        /* ===== INSERT LOG ===== */

        $stmt = $db->prepare("
            INSERT INTO communication_logs
            (user_id, action, ip_address)
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $_SESSION['user_id'],
            htmlspecialchars($data->action),
            $_SERVER['REMOTE_ADDR']
        ]);

        echo json_encode([
            "success" => true,
            "message" => "Log recorded"
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "error" => "Server error"
        ]);
    }
}