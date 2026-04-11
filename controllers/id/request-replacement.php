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

    $notes = $_POST['notes'] ?? null;

    $filePath = null;

    if (!empty($_FILES['file']['name'])) {

        $dir = "../../uploads/replacement/";

        if (!file_exists($dir)) mkdir($dir,0777,true);

        $filename = time()."_".basename($_FILES['file']['name']);

        if (move_uploaded_file($_FILES['file']['tmp_name'], $dir.$filename)) {
            $filePath = "uploads/replacement/".$filename;
        }
    }

    $stmt = $db->prepare("
        INSERT INTO id_replacement_requests
        (user_id, notes, file_path, status, created_at)
        VALUES (?,?,?,'PENDING',NOW())
    ");

    $stmt->execute([
        $_SESSION['user_id'],
        $notes,
        $filePath
    ]);

    /* Update ID status */

    $db->prepare("
        UPDATE id_cards
        SET status='Pending'
        WHERE user_id=?
    ")->execute([$_SESSION['user_id']]);

    echo json_encode([
        "success"=>true
    ]);

} catch(Exception $e){

    http_response_code(500);

    echo json_encode([
        "success"=>false,
        "error"=>$e->getMessage()
    ]);
}
