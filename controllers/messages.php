<?php

use Config\Database;

session_start();

/* ================= CORS HEADERS ================= */

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

header("Content-Type: application/json");

/* ================= HANDLE PRE-FLIGHT REQUEST ================= */

if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/* ================= DATABASE CONNECTION ================= */

require_once __DIR__ . "/../config/db.php";

$db = (new Database())->connect();

$method = $_SERVER['REQUEST_METHOD'];

/* ================= GET MESSAGES ================= */

if ($method === "GET") {

    try {

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode([
                "success" => false,
                "error" => "Unauthorized"
            ]);
            exit;
        }

        $stmt = $db->prepare("
            SELECT messages.*,
            u1.full_name AS sender_name,
            u2.full_name AS receiver_name
            FROM messages
            JOIN users u1 ON messages.sender_id = u1.id
            JOIN users u2 ON messages.receiver_id = u2.id
            ORDER BY messages.created_at DESC
        ");

        $stmt->execute();

        echo json_encode([
            "success" => true,
            "messages" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "error" => "Failed to fetch messages"
        ]);
    }
}

/* ================= SEND MESSAGE ================= */

if ($method === "POST") {

    try {

        if (!isset($_SESSION['user_id'])) {

            http_response_code(401);

            echo json_encode([
                "success" => false,
                "error" => "Unauthorized"
            ]);

            exit;
        }

        $data = json_decode(file_get_contents("php://input"));

        if (!$data || empty($data->receiver_id) || empty($data->message)) {

            http_response_code(400);

            echo json_encode([
                "success" => false,
                "error" => "Receiver and message are required"
            ]);

            exit;
        }

        /* Insert Message */

        $stmt = $db->prepare("
            INSERT INTO messages
            (thread_id, sender_id, receiver_id, message, is_read)
            VALUES (?, ?, ?, ?, 0)
        ");

        $stmt->execute([
            $data->thread_id ?? 1,
            $_SESSION['user_id'],
            $data->receiver_id,
            htmlspecialchars($data->message)
        ]);

        echo json_encode([
            "success" => true,
            "message" => "Message sent"
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "error" => "Server error"
        ]);
    }
}