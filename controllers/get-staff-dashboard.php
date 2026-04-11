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

$userId = (int) $_SESSION['user_id'];
session_write_close();

try {

    $db = (new Database())->connect();

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

    $taskStatsStmt = $db->prepare("
        SELECT
            COUNT(*) AS total_tasks,
            SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_tasks
        FROM tasks
        WHERE assigned_to = ?
    ");
    $taskStatsStmt->execute([$userId]);
    $taskStats = $taskStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_tasks' => 0,
        'completed_tasks' => 0,
    ];

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

    /* ================= LIVE STATS ================= */

    $hoursStmt = $db->prepare("
        SELECT
            COALESCE(ROUND(SUM(
                CASE
                    WHEN check_out IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, check_in, check_out)
                    ELSE 0
                END
            ) / 60, 1), 0) AS week_hours
        FROM v_attendance
        WHERE user_id = ?
        AND check_in >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $hoursStmt->execute([$userId]);
    $weekHours = (float) $hoursStmt->fetchColumn();

    $workingDaysStmt = $db->query("
        SELECT COUNT(DISTINCT DATE(check_in))
        FROM v_attendance
        WHERE check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $workingDays = (int) $workingDaysStmt->fetchColumn();

    $attendanceStmt = $db->prepare("
        SELECT COUNT(DISTINCT DATE(check_in)) AS days_present
        FROM v_attendance
        WHERE user_id = ?
        AND status IN ('PRESENT', 'LATE')
        AND check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $attendanceStmt->execute([$userId]);
    $daysPresent = (int) $attendanceStmt->fetchColumn();

    $totalTasks = (int) ($taskStats['total_tasks'] ?? 0);
    $completedTasks = (int) ($taskStats['completed_tasks'] ?? 0);

    $attendanceRate = $workingDays > 0
        ? (int) round(($daysPresent / $workingDays) * 100)
        : 0;

    $productivity = $totalTasks > 0
        ? (int) round(($completedTasks / $totalTasks) * 100)
        : 0;

    $stats = [
        "weekHours" => $weekHours,
        "tasksCompleted" => $completedTasks,
        "totalTasks" => $totalTasks,
        "attendance" => $attendanceRate,
        "productivity" => $productivity,
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
