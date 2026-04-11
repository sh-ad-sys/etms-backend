<?php
namespace Middleware;

// Import JWT class
require_once __DIR__ . '/../config/jwt.php';

use Config\JWT;

class JWTAuth {
    /**
     * Validate JWT token from Authorization header
     * Returns decoded token payload or null if invalid
     */
    public static function validateToken() {
        // Get Authorization header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        
        error_log("JWTAuth: Checking auth header - " . ($authHeader ? "Found" : "Not found"));
        
        if (!$authHeader) {
            error_log("JWTAuth: No authorization header provided");
            return null;
        }
        
        // Extract token from "Bearer <token>" format
        if (strpos($authHeader, 'Bearer ') !== 0) {
            error_log("JWTAuth: Invalid bearer format");
            return null;
        }
        
        $token = substr($authHeader, 7);
        error_log("JWTAuth: Token received, length: " . strlen($token));
        
        // Validate and decode JWT
        $decoded = JWT::decode($token);
        if (!$decoded) {
            error_log("JWTAuth: Failed to decode JWT");
        } else {
            error_log("JWTAuth: JWT decoded successfully, user_id: " . ($decoded['user_id'] ?? 'unknown'));
        }
        return $decoded;
    }
    
    /**
     * Middleware to require JWT authentication
     * Returns true if valid token exists, returns 401 error if not
     */
    public static function requireAuth() {
        $token = self::validateToken();
        
        if (!$token) {
            error_log("JWTAuth: Authentication failed - invalid token");
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized - Invalid or missing token']);
            exit;
        }
        
        return $token;
    }
    
    /**
     * Middleware to require specific role
     */
    public static function requireRole($allowedRoles) {
        $token = self::requireAuth();
        
        $roles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
        $userRole = strtolower($token['role'] ?? '');
        
        // Normalize role names for comparison
        $normalizedAllowed = array_map('strtolower', $roles);
        
        if (!in_array($userRole, $normalizedAllowed)) {
            error_log("JWTAuth: Role check failed - user role: $userRole, allowed: " . implode(',', $normalizedAllowed));
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden - Insufficient permissions']);
            exit;
        }
        
        return $token;
    }
}
?>
