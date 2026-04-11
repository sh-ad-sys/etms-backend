<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['user']['role'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]); exit;
}

require_once "../../config/db.php";
require_once "../../helpers/communication-policy.php";

use Config\Database;

try {
    $db = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];
    $role = normalizeCommunicationRole($_SESSION['user']['role'] ?? '');
    $allowedRoles = communicationAllowedRoles($role);

    $stmt = $db->query("
        SELECT
            u.id,
            u.full_name,
            u.email,
            COALESCE(d.name, u.department, '') AS department_name,
            r.name AS role_name
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.status = 'ACTIVE'
        ORDER BY u.full_name ASC
    ");

    $contacts = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $contactId = (int) ($row['id'] ?? 0);
        $contactRole = normalizeCommunicationRole($row['role_name'] ?? '');

        if ($contactId === $userId) {
            continue;
        }

        if (!canDirectlyMessage($role, $contactRole)) {
            continue;
        }

        $contacts[] = [
            'id' => (string) $contactId,
            'name' => $row['full_name'] ?? 'Unknown',
            'email' => $row['email'] ?? '',
            'department' => $row['department_name'] ?? '',
            'role' => ucfirst($contactRole),
        ];
    }

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "role" => ucfirst($role),
        "allowedRoles" => array_map('ucfirst', $allowedRoles),
        "contacts" => $contacts,
        "notificationMeta" => communicationNotificationMeta($role),
        "architecture" => communicationArchitecture($role),
    ]);
} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
