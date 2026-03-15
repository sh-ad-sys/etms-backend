<?php
namespace Config;

/**
 * Simple JWT implementation for PHP
 * Uses HMAC-SHA256 for signing
 */
class JWT {
    private static $secret_key = 'your-super-secret-key-change-this-in-production';
    private static $algorithm = 'HS256';
    private static $expire_time = 86400; // 24 hours in seconds

    /**
     * Generate a JWT token
     */
    public static function encode($payload) {
        // Add issued time
        $payload['iat'] = time();
        // Add expiration time
        $payload['exp'] = time() + self::$expire_time;

        // Encode header
        $header = self::base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => self::$algorithm
        ]));

        // Encode payload
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        // Create signature
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payloadEncoded", self::$secret_key, true)
        );

        return "$header.$payloadEncoded.$signature";
    }

    /**
     * Decode and validate a JWT token
     */
    public static function decode($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return null;
        }

        list($header, $payload, $signature) = $parts;

        // Verify signature
        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", self::$secret_key, true)
        );

        if ($signature !== $expectedSignature) {
            return null;
        }

        // Decode payload
        $payloadData = json_decode(self::base64UrlDecode($payload), true);

        // Check expiration
        if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
            return null;
        }

        return $payloadData;
    }

    /**
     * Get token from Authorization header
     */
    public static function getBearerToken() {
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s+(.+)/i', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }

    /**
     * Validate token and return user data
     */
    public static function validateRequest() {
        $token = self::getBearerToken();
        
        if (!$token) {
            http_response_code(401);
            echo json_encode(['error' => 'No token provided']);
            exit;
        }

        $payload = self::decode($token);
        
        if (!$payload) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            exit;
        }

        return $payload;
    }

    /**
     * Base64 URL safe encoding
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL safe decoding
     */
    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Set custom secret key (call this before encode/decode)
     */
    public static function setSecretKey($key) {
        self::$secret_key = $key;
    }
}
