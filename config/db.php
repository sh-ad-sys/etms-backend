<?php
namespace Config;

use PDO;
use PDOException;

class Database {
    private $host = "localhost";
    private $dbname = "etms";
    private $username = "root";
    private $password = "Shadrack2024.";
    public $conn;

    public function connect() {
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8",
                $this->username,
                $this->password
            );

            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $this->conn;

        } catch (PDOException $e) {
            // Log the error on the server
            error_log("Database Error: " . $e->getMessage());

            // Send JSON to frontend (no plain text)
            http_response_code(500);
            echo json_encode([
                "error" => "Internal server error. Please try again later."
            ]);
            exit;
        }
    }
}