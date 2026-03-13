<?php
// ============================
// CHECK-AUTH.PHP – VERIFY SESSION
// ============================

// Allow cross-origin requests and cookies
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Configure session cookie for cross-origin
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => 'localhost',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'None'
]);
session_start();

// Check if user session exists
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["authenticated" => false]);
    exit;
}

// ✅ Session exists → user is authenticated
echo json_encode([
    "authenticated" => true,
    "user" => $_SESSION['user']
]);