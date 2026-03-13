<?php

session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once "../../config/db.php";

use Config\Database;

try {

    if (!isset($_SESSION['user_id'])) {
        throw new Exception("User not logged in");
    }

    $db = (new Database())->connect();

    if (
        empty($_POST['name']) ||
        empty($_POST['employeeId']) ||
        empty($_POST['dateLost']) ||
        empty($_POST['location'])
    ) {
        throw new Exception("Missing required fields");
    }

    $userId = $_SESSION['user_id'];

    $name = trim($_POST['name']);
    $employeeId = trim($_POST['employeeId']);
    $dateLost = $_POST['dateLost'];
    $location = trim($_POST['location']);
    $notes = $_POST['notes'] ?? null;

    /* ================= CHECK ID CARD ================= */

    $cardStmt = $db->prepare("
        SELECT id
        FROM id_cards
        WHERE user_id = ?
        AND card_number = ?
        LIMIT 1
    ");

    $cardStmt->execute([$userId, $employeeId]);
    $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

    /* If card doesn't exist → create it (safe for testing) */
    if (!$card) {

        $insertCard = $db->prepare("
            INSERT INTO id_cards
            (user_id, card_number, status, issued_date, created_at)
            VALUES (?, ?, 'Lost', NOW(), NOW())
        ");

        $insertCard->execute([$userId, $employeeId]);
        $cardId = $db->lastInsertId();

    } else {
        $cardId = $card['id'];
    }

    /* ================= FILE UPLOAD ================= */

    $filePath = null;

    if (!empty($_FILES['evidence']['name'])) {

        $uploadDir = "../../uploads/lostid/";

        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = time() . "_" . preg_replace("/[^a-zA-Z0-9.]/", "", $_FILES['evidence']['name']);

        $target = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['evidence']['tmp_name'], $target)) {
            $filePath = "uploads/lostid/" . $filename;
        }
    }

    /* ================= INSERT REPORT ================= */

    $stmt = $db->prepare("
        INSERT INTO lost_id_reports
        (user_id, name, employee_id, date_lost, location, notes, evidence_file, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
    ");

    $stmt->execute([
        $userId,
        $name,
        $employeeId,
        $dateLost,
        $location,
        $notes,
        $filePath
    ]);

    /* ================= UPDATE CARD STATUS ================= */

    $db->prepare("
        UPDATE id_cards
        SET status = 'Lost'
        WHERE id = ?
    ")->execute([$cardId]);

    echo json_encode([
        "success" => true,
        "message" => "Lost ID report submitted"
    ]);

} catch(Exception $e){

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}