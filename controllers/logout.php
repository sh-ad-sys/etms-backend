<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

/* ================= CORS ================= */

// Get allowed origin from environment or use default
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

/* ================= PRE-FLIGHT ================= */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ================= JWT VALIDATION ================= */

require_once "../config/jwt.php";

use Config\JWT;

// Validate JWT token (this will exit if invalid)
$payload = JWT::validateRequest();

/* ================= LOGOUT SUCCESS ================= */

// In JWT, we don't need to do anything server-side
// The client removes the token from localStorage
// For blacklist functionality, you would need to store invalidated tokens

echo json_encode([
    "success" => true,
    "message" => "Logged out successfully"
]);
