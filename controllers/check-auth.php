<?php
session_start();
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Check if user session exists
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["authenticated" => false]);
    exit;
}

// Session exists → user is authenticated
echo json_encode([
    "authenticated" => true,
    "user" => $_SESSION['user']
]);