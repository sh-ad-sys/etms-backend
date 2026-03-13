<?php

/**
 * createAlert()
 * Call this from login.php or any controller to log a security event.
 *
 * Usage:
 *   require_once "../helpers/create-alert.php";
 *   createAlert($db, $userId, 'New Device Login', 'A new device logged in from IP ...', 'medium');
 */

function createAlert(
    PDO    $db,
    int    $userId,
    string $title,
    string $description,
    string $severity = 'low'   // 'low' | 'medium' | 'high'
): void {
    $stmt = $db->prepare("
        INSERT INTO security_alerts (user_id, title, description, severity, status, created_at)
        VALUES (:user_id, :title, :description, :severity, 'open', NOW())
    ");

    $stmt->execute([
        ':user_id'     => $userId,
        ':title'       => $title,
        ':description' => $description,
        ':severity'    => in_array($severity, ['low', 'medium', 'high']) ? $severity : 'low',
    ]);
}