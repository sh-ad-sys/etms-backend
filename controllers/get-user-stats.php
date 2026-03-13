<?php
session_start();
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["error" => "Not authenticated"]);
    exit;
}

require_once "../config/db.php";
use Config\Database;

try {
    $db = (new Database())->connect();
    $userId = $_SESSION['user']['id'];

    // Total weekly hours (example: sum of hours from attendance)
    $stmt = $db->prepare("SELECT SUM(hours) AS weekHours FROM attendance 
                          WHERE user_id = :userId 
                          AND WEEK(date) = WEEK(NOW())");
    $stmt->bindParam(":userId", $userId);
    $stmt->execute();
    $weekHours = $stmt->fetch(PDO::FETCH_ASSOC)['weekHours'] ?? 0;

    // Tasks
    $stmt = $db->prepare("SELECT COUNT(*) AS totalTasks, SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS tasksCompleted 
                          FROM tasks 
                          WHERE assigned_to = :userId");
    $stmt->bindParam(":userId", $userId);
    $stmt->execute();
    $taskStats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Attendance percentage (example: present / total working days * 100)
    $stmt = $db->prepare("SELECT COUNT(*) AS totalDays, SUM(CASE WHEN status = 'PRESENT' THEN 1 ELSE 0 END) AS presentDays 
                          FROM attendance 
                          WHERE user_id = :userId");
    $stmt->bindParam(":userId", $userId);
    $stmt->execute();
    $att = $stmt->fetch(PDO::FETCH_ASSOC);
    $attendance = $att['totalDays'] > 0 ? round(($att['presentDays'] / $att['totalDays']) * 100) : 0;

    echo json_encode([
        "weekHours" => (float)$weekHours,
        "tasksCompleted" => (int)$taskStats['tasksCompleted'],
        "totalTasks" => (int)$taskStats['totalTasks'],
        "attendance" => $attendance
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to fetch stats"]);
}