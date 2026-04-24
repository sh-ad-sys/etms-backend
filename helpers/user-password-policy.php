<?php

if (!function_exists('ensureMustChangePasswordColumn')) {
    function ensureMustChangePasswordColumn(PDO $db): void {
<<<<<<< HEAD
        try {
            $stmt = $db->query("DESCRIBE users must_change_password", PDO::FETCH_ASSOC);
            $result = $stmt->fetch();
            
            // Column doesn't exist, try to add it
            if (!$result) {
                try {
                    $db->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
                } catch (Exception $e) {
                    // Column might already exist or error occurred, continue anyway
                    error_log("Could not add must_change_password column: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            // If schema check fails, continue anyway - column might not be needed for this request
            error_log("Schema check failed: " . $e->getMessage());
=======
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
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8
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
