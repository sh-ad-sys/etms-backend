<?php
namespace Config;

use PDO;
use PDOException;

class Database {
    // Use environment variables for deployment
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $port;
    private $ssl;
    public $conn;

    public function __construct() {
        // Get from environment variables (production) or use Aiven credentials
        $this->host = getenv('DB_HOST') ?: 'etms-shadrackmutua081-64f3.f.aivencloud.com';
        $this->dbname = getenv('DB_NAME') ?: 'etms';
        $this->username = getenv('DB_USER') ?: 'avnadmin';
       $this->password = getenv('DB_PASS');

        $this->port = getenv('DB_PORT') ?: '27258';
        $this->ssl = getenv('DB_SSL') ?: 'required';
    }

    public function connect() {
        try {
            // Build DSN with SSL for Aiven
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            
            // Enable SSL for Aiven
            if ($this->ssl === 'required') {
                $options[PDO::MYSQL_ATTR_SSL_CA] = null; // Use default CA bundle
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false; // Optional: disable cert verification
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
