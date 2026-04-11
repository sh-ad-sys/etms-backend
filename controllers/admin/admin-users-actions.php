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
require_once "../../helpers/smtp-mail.php";
require_once "../../helpers/user-password-policy.php";

use Config\Database;

function sendInviteEmail(string $toEmail, string $toName, string $tempPassword, string $role): bool {
    $subject  = "Your Royal Mabati Factory Account";
    $loginUrl = rtrim(getenv('FRONTEND_URL') ?: 'http://localhost:3000', '/') . "/";

    $textBody = "Dear {$toName},\n\n"
          . "You have been invited to the Royal Mabati Factory Employee Tracking Management System.\n\n"
          . "Your login details:\n"
          . "  Email:    {$toEmail}\n"
          . "  Password: {$tempPassword}\n"
          . "  Role:     {$role}\n\n"
          . "On your first login, you will be required to set a strong password immediately:\n"
          . "{$loginUrl}\n\n"
          . "This is an automated message. Do not reply.\n\n"
          . "Royal Mabati Factory";

    $htmlBody = '<p>Dear ' . htmlspecialchars($toName) . ',</p>'
          . '<p>You have been invited to the Royal Mabati Factory Employee Tracking Management System.</p>'
          . '<p><strong>Email:</strong> ' . htmlspecialchars($toEmail) . '<br>'
          . '<strong>Temporary Password:</strong> ' . htmlspecialchars($tempPassword) . '<br>'
          . '<strong>Role:</strong> ' . htmlspecialchars($role) . '</p>'
          . '<p>On your first login, you will be required to set a strong password immediately.</p>'
          . '<p><a href="' . htmlspecialchars($loginUrl) . '">Open ETMS Login</a></p>'
          . '<p>Royal Mabati Factory</p>';

    return smtpSendMail($toEmail, $toName, $subject, $htmlBody, $textBody);
}

function sendResetPasswordEmail(string $toEmail, string $toName, string $tempPassword): bool {
    $subject  = "Password Reset - Royal Mabati Factory";
    $loginUrl = rtrim(getenv('FRONTEND_URL') ?: 'http://localhost:3000', '/') . "/";

    $textBody = "Dear {$toName},\n\n"
          . "Your password has been reset by an administrator.\n\n"
          . "Temporary Password: {$tempPassword}\n\n"
          . "On your next login, you will be required to set a strong password immediately:\n"
          . "{$loginUrl}\n\n"
          . "Royal Mabati Factory";

    $htmlBody = '<p>Dear ' . htmlspecialchars($toName) . ',</p>'
          . '<p>Your password has been reset by an administrator.</p>'
          . '<p><strong>Temporary Password:</strong> ' . htmlspecialchars($tempPassword) . '</p>'
          . '<p>On your next login, you will be required to set a strong password immediately.</p>'
          . '<p><a href="' . htmlspecialchars($loginUrl) . '">Open ETMS Login</a></p>'
          . '<p>Royal Mabati Factory</p>';

    return smtpSendMail($toEmail, $toName, $subject, $htmlBody, $textBody);
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
    ensureMustChangePasswordColumn($db);
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $action  = $body['action'] ?? '';
    $actorId = (int) $_SESSION['user_id'];

    if ($action === 'toggle_status') {
        $userId    = (int) ($body['userId'] ?? 0);
        $newStatus = $body['newStatus'] ?? '';

        if (!in_array($newStatus, ['ACTIVE','SUSPENDED'], true)) {
            throw new Exception("Invalid status.");
        }

        $stmt = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);

        logAudit($db, $actorId, 'toggle_status', 'users', $userId, "Status changed to {$newStatus}");

        ob_end_clean();
        echo json_encode(["success" => true, "message" => "Status updated."]);

    } elseif ($action === 'edit_user') {
        $userId = (int) ($body['userId'] ?? 0);
        $name   = trim($body['name']   ?? '');
        $email  = trim($body['email']  ?? '');
        $roleId = (int) ($body['roleId'] ?? 0);
        $phone  = trim($body['phone']  ?? '');

        if (!$userId || !$name || !$email) throw new Exception("Missing required fields.");

        $dup = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $dup->execute([$email, $userId]);
        if ($dup->fetch()) throw new Exception("Email already in use.");

        $stmt = $db->prepare("
            UPDATE users
            SET full_name = ?, email = ?, role_id = ?, phone = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $roleId ?: null, $phone, $userId]);

        logAudit($db, $actorId, 'edit_user', 'users', $userId, "Updated name={$name}, email={$email}, role_id={$roleId}");

        ob_end_clean();
        echo json_encode(["success" => true, "message" => "User updated."]);

    } elseif ($action === 'delete_user') {
        $userId = (int) ($body['userId'] ?? 0);
        if (!$userId) throw new Exception("Invalid user.");

        $stmt = $db->prepare("UPDATE users SET status = 'EXITED', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);

        logAudit($db, $actorId, 'soft_delete', 'users', $userId, "User marked as EXITED");

        ob_end_clean();
        echo json_encode(["success" => true, "message" => "User deactivated."]);

    } elseif ($action === 'reset_password') {
        $userId = (int) ($body['userId'] ?? 0);
        if (!$userId) throw new Exception("Invalid user.");

        $userRow = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $userRow->execute([$userId]);
        $user = $userRow->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new Exception("User not found.");

        $tempPass = bin2hex(random_bytes(5));
        $hashed   = password_hash($tempPass, PASSWORD_BCRYPT);

        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                UPDATE users
                SET password = ?, must_change_password = 1, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$hashed, $userId]);

            sendResetPasswordEmail($user['email'], $user['full_name'], $tempPass);
            $db->commit();
        } catch (Throwable $mailError) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new Exception("Password reset email could not be sent. No changes were saved.");
        }

        logAudit($db, $actorId, 'reset_password', 'users', $userId,
            "Password reset email sent to {$user['email']}; strong password required on next login");

        ob_end_clean();
        echo json_encode(["success" => true, "message" => "Password reset. Email sent to {$user['email']}."]);

    } elseif ($action === 'invite_user') {
        $name       = trim($body['name']   ?? '');
        $email      = trim($body['email']  ?? '');
        $roleId     = (int) ($body['roleId'] ?? 0);
        $department = trim($body['department'] ?? '');
        $phone      = trim($body['phone']  ?? '');

        if (!$name || !$email || !$roleId) throw new Exception("Name, email and role are required.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid email address.");

        $dup = $db->prepare("SELECT id FROM users WHERE email = ?");
        $dup->execute([$email]);
        if ($dup->fetch()) throw new Exception("A user with this email already exists.");

        $lastCode = $db->query("
            SELECT employee_code FROM users
            WHERE employee_code LIKE 'RMF-%'
            ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        $nextNum  = $lastCode ? ((int) substr($lastCode, 4)) + 1 : 1;
        $empCode  = 'RMF-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        $tempPass = ucfirst(strtolower(explode('@', $email)[0])) . '@' . rand(100, 999);
        $hashed   = password_hash($tempPass, PASSWORD_BCRYPT);

        $roleRow = $db->prepare("SELECT name FROM roles WHERE id = ?");
        $roleRow->execute([$roleId]);
        $roleName = $roleRow->fetchColumn() ?: 'Staff';

        $db->beginTransaction();

        try {
            $insert = $db->prepare("
                INSERT INTO users (employee_code, full_name, email, password, phone, department, role_id, status, must_change_password)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE', 1)
            ");
            $insert->execute([$empCode, $name, $email, $hashed, $phone, $department, $roleId]);
            $newUserId = (int) $db->lastInsertId();

            sendInviteEmail($email, $name, $tempPass, $roleName);
            $db->commit();
        } catch (Throwable $mailError) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new Exception("Invite email could not be sent. The user was not created.");
        }

        logAudit($db, $actorId, 'invite_user', 'users', $newUserId,
            "Invited {$name} ({$email}) as {$roleName}. Email sent: yes. Strong password required on first login.");

        ob_end_clean();
        echo json_encode([
            "success"      => true,
            "message"      => "User {$name} created with code {$empCode}. Invite email sent with a temporary password. Strong password required on first login.",
            "employeeCode" => $empCode,
        ]);

    } else {
        throw new Exception("Unknown action: {$action}");
    }

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
