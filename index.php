<?php
/**
 * ETMS API - Root Entry Point
 * 
 * This is the root entry point for the ETMS Backend API.
 * The frontend is hosted separately on Vercel.
 * 
 * @version 1.0.0
 */

// Set CORS headers
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// API Information
$apiInfo = [
    'name' => 'ETMS API',
    'version' => '1.0.0',
    'description' => 'Employee Tracking Management System Backend API',
    'status' => 'running',
    'endpoints' => [
        'login' => '/controllers/login.php',
        'logout' => '/controllers/logout.php',
        'profile' => '/controllers/get-profile.php',
        'notifications' => '/controllers/notifications.php',
        'messages' => '/controllers/messages.php',
        'announcements' => '/controllers/announcements.php',
        'leave' => [
            'apply' => '/controllers/leave/apply-leave.php',
            'balance' => '/controllers/leave/get-leave-balance.php',
            'status' => '/controllers/leave/get-leave-status.php'
        ],
        'attendance' => [
            'check-in' => '/controllers/check-in.php',
            'check-out' => '/controllers/check-out.php',
            'history' => '/controllers/get-attendance-history.php'
        ]
    ],
    'frontend_url' => getenv('FRONTEND_URL') ?: 'http://localhost:3000'
];

http_response_code(200);
echo json_encode($apiInfo, JSON_PRETTY_PRINT);
