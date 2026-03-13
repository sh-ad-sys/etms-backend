<?php

session_start();
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");

require_once "../../config/db.php";

use Config\Database;

try {

    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Unauthorized");
    }

    $db = (new Database())->connect();

    $userId = $_SESSION['user_id'];

    /* ===== CURRENT CARD ===== */

    $cardStmt = $db->prepare("
        SELECT *
        FROM id_cards
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");

    $cardStmt->execute([$userId]);
    $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

    /* ===== HISTORY ===== */

    $historyStmt = $db->prepare("
        SELECT status, notes AS note, created_at
        FROM lost_id_reports
        WHERE user_id = ?

        UNION

        SELECT status, note, created_at
        FROM id_status_history
        WHERE user_id = ?

        ORDER BY created_at DESC
        LIMIT 30
    ");

    $historyStmt->execute([$userId, $userId]);

    echo json_encode([
        "current" => $card,
        "history" => $historyStmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch(Exception $e){

    http_response_code(500);

    echo json_encode([
        "error" => $e->getMessage()
    ]);
}