<?php ob_start();

/* ================= CORS ================= */

// Get allowed origin from environment or use default
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]); exit;
}

require_once "../config/db.php";
use Config\Database;

try {
    $db = (new Database())->connect();

    /* Try announcements table — fall back gracefully if it doesn't exist yet */
    $announcements = [];

    try {
        $stmt = $db->query("
            SELECT id, title, message, created_at
            FROM   announcements
            ORDER  BY created_at DESC
            LIMIT  40
        ");
        $announcements = array_map(fn($r) => [
            'id'         => (int) $r['id'],
            'title'      => $r['title'],
            'message'    => $r['message'],
            'created_at' => $r['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable) {
        /* Table doesn't exist — return empty array, not an error */
        $announcements = [];
    }

    ob_end_clean();
    echo json_encode(["success" => true, "announcements" => $announcements]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}