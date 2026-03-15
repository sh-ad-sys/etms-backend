<?php
namespace Config;

use PDO;
use PDOException;

class Database {
    // Use environment variables for deployment (Render/Aiven)
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $port;
    public $conn;

    public function connect() {
        // Get from environment variables (Render/Aiven) or fallback to local XAMPP
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->dbname = getenv('DB_NAME') ?: 'etms';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: 'Shadrack2024.';
        $this->port = getenv('DB_PORT') ?: '3306';

        try {
            // Check if SSL is required (for Aiven)
            $ssl = getenv('DB_SSL');
            
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            
            // Enable SSL for Aiven if required
            if ($ssl === 'true' || $ssl === '1') {
                $options[PDO::MYSQL_ATTR_SSL_CA] = null;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }

            $this->conn = new PDO(
                $dsn,
                $this->username,
                $this->password,
                $options
            );

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
