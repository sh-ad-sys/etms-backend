<?php
/**
 * CORS Configuration
 * 
 * This file provides dynamic CORS headers based on environment variables.
 * Include this at the top of any PHP file that needs CORS support.
 * 
 * Usage:
 *   require_once "../config/cors.php";
 * 
 * Environment Variables:
 *   CORS_ORIGIN - The allowed origin (e.g., http://localhost:3000, https://your-vercel-app.vercel.app)
 * 
 * Default: http://localhost:3000 (for local development)
 */

// Get allowed origin from environment or use default
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';

// Set CORS headers
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
