<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
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

require_once "../../config/db.php";
use Config\Database;

/* ── Mail config — swap in real SMTP credentials when ready ── */
define('MAIL_FROM',      'noreply@royalmabati.co.ke');
define('MAIL_FROM_NAME', 'Royal Mabati Factory');
define('APP_URL',        'http://localhost:3000');

function sendInviteEmail(string $toEmail, string $toName, string $tempPassword, string $role): bool {
    $subject  = "Your Royal Mabati Factory Account";
    $loginUrl = APP_URL . "/login";

    $body = "Dear {$toName},\n\n"
          . "You have been invited to the Royal Mabati Factory Employee Tracking Management System.\n\n"
          . "Your login details:\n"
          . "  Email:    {$toEmail}\n"
          . "  Password: {$tempPassword}\n"
          . "  Role:     {$role}\n\n"
          . "Please log in and change your password immediately:\n"
          . "{$loginUrl}\n\n"
          . "This is an automated message. Do not reply.\n\n"
          . "Royal Mabati Factory";

    $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
             . "Reply-To: " . MAIL_FROM . "\r\n"
             . "X-Mailer: PHP/" . phpversion();

    return mail($toEmail, $subject, $body, $headers);
}

function logAudit(PDO $db, int $actorId, string $action, string $entity, ?int $entityId, string $details): void {
    $stmt = $db->prepare("
        INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$actorId, $action, $entity, $entityId, $details]);
}

try {
    $db      = (new Database())->connect();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $action  = $body['action'] ?? '';
    $actorId = (int) $_SESSION['user_id'];

    /* ══════════════════════════════════════════
       TOGGLE STATUS (Active ↔ Suspended)
    ══════════════════════════════════════════ */
    if ($action === 'toggle_status') {
        $userId    = (int) ($body['userId'] ?? 0);
        $newStatus = $body['newStatus'] ?? '';

        if (!in_array($newStatus, ['ACTIVE','SUSPENDED'])) {
            throw new Exception("Invalid status.");
        }

        $stmt = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);

        logAudit($db, $actorId, 'toggle_status', 'users', $userId,
            "Status changed to {$newStatus}");

        ob_end_clean();
        echo json_encode(["success" => true, "message" => "Status updated."]);

    /* ══════════════════════════════════════════
       EDIT USER
    ══════════════════════════════════════════ */
    } elseif ($action === 'edit_user') {
        $userId = (int) ($body['userId'] ?? 0);
        $name   = trim($body['name']   ?? '');
        $email  = trim($body['email']  ?? '');
        $roleId = (int) ($body['roleId'] ?? 0);
        $phone  = trim($body['phone']  ?? '');

        if (!$userId || !$name || !$email) throw new Exception("Missing required fields.");

        /* Check email uniqueness */
        $dup = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $dup->execute([$email, $userId]);
        if ($dup->fetch()) throw new Exception("Email already in use.");

        $stmt = $db->prepare("
            UPDATE users
            SET full_name = ?, email = ?, role_id = ?, phone = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $roleId ?: null, $phone, $userId]);

        logAudit($db, $actorId, 'edit_user', 'users', $userId,
            "Updated name={$name}, email={$email}, role_id={$roleId}");

        ob_end_clean();
        echo json_encode(["success" => true, "message" => "User updated."]);

    /* ══════════════════════════════════════════
       SOFT DELETE (set EXITED)
    ══════════════════════════════════════════ */
    } elseif ($action === 'delete_user') {
        $userId = (int) ($body['userId'] ?? 0);
        if (!$userId) throw new Exception("Invalid user.");

        $stmt = $db->prepare("UPDATE users SET status = 'EXITED', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);

        logAudit($db, $actorId, 'soft_delete', 'users', $userId, "User marked as EXITED");

        ob_end_clean();
        echo json_encode(["success" => true, "message" => "User deactivated."]);

    /* ══════════════════════════════════════════
       RESET PASSWORD
    ══════════════════════════════════════════ */
    } elseif ($action === 'reset_password') {
        $userId = (int) ($body['userId'] ?? 0);
        if (!$userId) throw new Exception("Invalid user.");

        $userRow = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $userRow->execute([$userId]);
        $user = $userRow->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new Exception("User not found.");

        $tempPass = bin2hex(random_bytes(5)); // 10-char temp password
        $hashed   = password_hash($tempPass, PASSWORD_BCRYPT);

        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$hashed, $userId]);

        /* Send email */
        $subject = "Password Reset – Royal Mabati Factory";
        $msgBody = "Dear {$user['full_name']},\n\n"
                 . "Your password has been reset by an administrator.\n\n"
                 . "Temporary Password: {$tempPass}\n\n"
                 . "Please log in and change your password immediately:\n"
                 . APP_URL . "/login\n\n"
                 . "Royal Mabati Factory";

        $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
                 . "Reply-To: " . MAIL_FROM . "\r\n"
                 . "X-Mailer: PHP/" . phpversion();

        mail($user['email'], $subject, $msgBody, $headers);

        logAudit($db, $actorId, 'reset_password', 'users', $userId,
            "Password reset email sent to {$user['email']}");

        ob_end_clean();
        echo json_encode(["success" => true, "message" => "Password reset. Email sent to {$user['email']}."]);

    /* ══════════════════════════════════════════
       INVITE NEW USER
    ══════════════════════════════════════════ */
    } elseif ($action === 'invite_user') {
        $name       = trim($body['name']   ?? '');
        $email      = trim($body['email']  ?? '');
        $roleId     = (int) ($body['roleId'] ?? 0);
        $department = trim($body['department'] ?? '');
        $phone      = trim($body['phone']  ?? '');

        if (!$name || !$email || !$roleId) throw new Exception("Name, email and role are required.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid email address.");

        /* Check duplicate */
        $dup = $db->prepare("SELECT id FROM users WHERE email = ?");
        $dup->execute([$email]);
        if ($dup->fetch()) throw new Exception("A user with this email already exists.");

        /* Generate employee code: RMF-XXXX */
        $lastCode = $db->query("
            SELECT employee_code FROM users
            WHERE employee_code LIKE 'RMF-%'
            ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        $nextNum  = $lastCode ? ((int) substr($lastCode, 4)) + 1 : 1;
        $empCode  = 'RMF-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        /* Temp password */
        $tempPass = ucfirst(strtolower(explode('@', $email)[0])) . '@' . rand(100, 999);
        $hashed   = password_hash($tempPass, PASSWORD_BCRYPT);

        /* Get role name */
        $roleRow = $db->prepare("SELECT name FROM roles WHERE id = ?");
        $roleRow->execute([$roleId]);
        $roleName = $roleRow->fetchColumn() ?: 'Staff';

        /* Insert user */
        $insert = $db->prepare("
            INSERT INTO users (employee_code, full_name, email, password, phone, department, role_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE')
        ");
        $insert->execute([$empCode, $name, $email, $hashed, $phone, $department, $roleId]);
        $newUserId = (int) $db->lastInsertId();

        /* Send invite email */
        $sent = sendInviteEmail($email, $name, $tempPass, $roleName);

        logAudit($db, $actorId, 'invite_user', 'users', $newUserId,
            "Invited {$name} ({$email}) as {$roleName}. Email sent: " . ($sent ? 'yes' : 'no'));

        ob_end_clean();
        echo json_encode([
            "success"     => true,
            "message"     => "User {$name} created with code {$empCode}." . ($sent ? " Invite email sent." : " Email could not be sent — check mail config."),
            "employeeCode"=> $empCode,
        ]);

    } else {
        throw new Exception("Unknown action: {$action}");
    }

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}