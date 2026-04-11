<?php

session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit();

require_once "../../config/db.php";
use Config\Database;

try {

    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Unauthorized");
    }

    $userId = (int) $_SESSION['user_id'];
    session_write_close();

    $db = (new Database())->connect();

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['token'])) {
        throw new Exception("Missing QR token");
    }

    /* ================= VERIFY QR SESSION ================= */

    $stmt = $db->prepare("
        SELECT *
        FROM qr_sessions
        WHERE token = ?
        AND expires_at > NOW()
        AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([$data['token']]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        throw new Exception("Invalid or expired QR session");
    }

    /* ================= CHECK TODAY ATTENDANCE ================= */

    $today = date("Y-m-d");

    $stmt = $db->prepare("
        SELECT *
        FROM qr_attendance_logs
        WHERE user_id = ?
        AND DATE(check_in) = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([$userId, $today]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

    /* ================= CHECK IN OR CHECK OUT ================= */

    if (!$attendance) {

        /* ===== FIRST SCAN → CHECK IN ===== */

        $insert = $db->prepare("
            INSERT INTO qr_attendance_logs
            (
                user_id,
                session_id,
                check_in,
                status,
                latitude,
                longitude,
                accuracy,
                distance_meters,
                inside_geofence,
                device_id
            )
            VALUES (?, ?, NOW(), 'PRESENT', ?, ?, ?, ?, ?, NULL)
        ");

        $insert->execute([
            $userId,
            $session['id'],
            $data['lat'] ?? null,
            $data['lng'] ?? null,
            $data['accuracy'] ?? null,
            $data['distanceMeters'] ?? null,
            isset($data['inside']) && $data['inside'] ? 1 : 0
        ]);

        echo json_encode([
            "success" => true,
            "action" => "check_in",
            "message" => "Check-in recorded"
        ]);

        exit();
    }

    /* ===== SECOND SCAN → CHECK OUT ===== */

    if ($attendance['check_out'] === null) {

        $update = $db->prepare("
            UPDATE qr_attendance_logs
            SET check_out = NOW()
            WHERE id = ?
        ");

        $update->execute([$attendance['id']]);

        echo json_encode([
            "success" => true,
            "action" => "check_out",
            "message" => "Check-out recorded"
        ]);

        exit();
    }

    throw new Exception("Attendance already completed today");

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
