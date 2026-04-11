<?php

if (!function_exists('ensureMustChangePasswordColumn')) {
    function ensureMustChangePasswordColumn(PDO $db): void {
        $stmt = $db->query("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'must_change_password'
        ");

        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("
                ALTER TABLE users
                ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0
            ");
        }
    }
}

if (!function_exists('isStrongPassword')) {
    function isStrongPassword(string $password): bool {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/\d/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);
    }
}
