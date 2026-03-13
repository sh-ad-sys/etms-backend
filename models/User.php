<?php
namespace Models;

use PDO;

class User {
    private $conn;

    public function __construct(PDO $db) {
        $this->conn = $db;
    }

    public function findByEmail($email) {
        $stmt = $this->conn->prepare("
            SELECT users.*, roles.name as role_name
            FROM users
            JOIN roles ON users.role_id = roles.id
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute(['email' => $email]);
        return $stmt->fetch();
    }
}