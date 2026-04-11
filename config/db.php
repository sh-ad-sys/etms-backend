<?php
namespace Config;

use PDO;
use PDOException;

class Database {
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $port;
    public $conn;

    public function connect() {
        $this->host = getenv('DB_HOST') ?: '127.0.0.1';
        $this->dbname = getenv('DB_NAME') ?: 'etms';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: 'Shadrack2024.';
        $this->port = getenv('DB_PORT') ?: '3306';

        try {
            $ssl = getenv('DB_SSL');
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset=utf8";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ];

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
            error_log('Database Error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error. Please try again later.'
            ]);
            exit;
        }
    }
}
