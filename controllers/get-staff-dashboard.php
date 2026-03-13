<?php

session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");

require_once "../config/db.php";

use Config\Database;

if (!isset($_SESSION['user_id'])) {

    echo json_encode([
        "success" => false,
        "error" => "Unauthorized"
    ]);
    exit;
}

try {

    $db = (new Database())->connect();

    $userId = $_SESSION['user_id'];

    /* ================= TASKS ================= */

    $taskStmt = $db->prepare("
        SELECT id,title,description,created_at,completed
        FROM tasks
        WHERE assigned_to = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");

    $taskStmt->execute([$userId]);
    $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================= NOTIFICATIONS ================= */

    $notifStmt = $db->prepare("
        SELECT id,title,message,type,priority,is_read,created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");

    $notifStmt->execute([$userId]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================= BASIC STATS ================= */

    $stats = [
        "weekHours" => 40,
        "tasksCompleted" =>
            count(array_filter($tasks, fn($t) => $t['completed'] == 1)),
        "totalTasks" => count($tasks),
        "attendance" => 95,
        "productivity" => 88
    ];

    echo json_encode([
        "success" => true,
        "tasks" => $tasks,
        "notifications" => $notifications,
        "stats" => $stats
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "error" => "Server error"
    ]);
}