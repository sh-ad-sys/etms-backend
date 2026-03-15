<?php
/**
 * Detect device name from user agent string
 */
function detectDeviceName($userAgent) {
    if (empty($userAgent)) {
        return 'Unknown Device';
    }
    
    $userAgent = strtolower($userAgent);
    
    // Check for mobile devices
    if (preg_match('/mobile|android|iphone|ipad|phone/', $userAgent)) {
        if (strpos($userAgent, 'ipad') !== false) {
            return 'iPad';
        }
        if (strpos($userAgent, 'android') !== false) {
            return 'Android Device';
        }
        if (strpos($userAgent, 'iphone') !== false) {
            return 'iPhone';
        }
        return 'Mobile Device';
    }
    
    // Check for tablets
    if (preg_match('/tablet|ipad|playbook/', $userAgent)) {
        return 'Tablet';
    }
    
    // Check for bots/crawlers
    if (preg_match('/bot|crawler|spider/', $userAgent)) {
        return 'Bot/Crawler';
    }
    
    // Default to desktop
    return 'Desktop Computer';
}
