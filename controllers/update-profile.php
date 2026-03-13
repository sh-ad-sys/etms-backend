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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean(); http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]); exit;
}

if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]); exit;
}

require_once "../config/db.php";
use Config\Database;

try {

    $userId = (int) $_SESSION['user_id'];

    /* Supports both JSON and multipart (when avatar is uploaded) */
    $isMultipart = !empty($_FILES['avatar']);

    if ($isMultipart) {
        $fullName   = trim($_POST['full_name']  ?? '');
        $email      = trim($_POST['email']      ?? '');
        $phone      = trim($_POST['phone']      ?? '');
        $department = trim($_POST['department'] ?? '');
    } else {
        $body       = json_decode(file_get_contents("php://input"), true) ?? [];
        $fullName   = trim($body['full_name']  ?? '');
        $email      = trim($body['email']      ?? '');
        $phone      = trim($body['phone']      ?? '');
        $department = trim($body['department'] ?? '');
    }

    /* ── Validate ── */
    if (!$fullName || !$email) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Full name and email are required"]); exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid email address"]); exit;
    }

    $db = (new Database())->connect();

    /* ── Email uniqueness check ── */
    $emailCheck = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $emailCheck->execute([$email, $userId]);
    if ($emailCheck->fetch()) {
        ob_end_clean(); http_response_code(409);
        echo json_encode(["success" => false, "error" => "Email already in use by another account"]); exit;
    }

    /* ── Optional avatar upload ── */
    $avatarPath = null;

    if ($isMultipart && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {

        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext     = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            ob_end_clean(); http_response_code(400);
            echo json_encode(["success" => false, "error" => "Only JPG, PNG and WEBP allowed"]); exit;
        }
        if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            ob_end_clean(); http_response_code(400);
            echo json_encode(["success" => false, "error" => "Avatar must be under 2MB"]); exit;
        }

        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        /* Delete old avatar file */
        $old = $db->prepare("SELECT avatar FROM users WHERE id = ?");
        $old->execute([$userId]);
        $oldAvatar = $old->fetchColumn();
        if ($oldAvatar && file_exists(__DIR__ . '/../../' . $oldAvatar)) {
            unlink(__DIR__ . '/../../' . $oldAvatar);
        }

        $fileName   = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        $avatarPath = 'uploads/avatars/' . $fileName;

        if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $fileName)) {
            ob_end_clean(); http_response_code(500);
            echo json_encode(["success" => false, "error" => "Failed to save avatar"]); exit;
        }
    }

    /* ── Update users table ── */
    if ($avatarPath !== null) {
        $stmt = $db->prepare("
            UPDATE users
            SET full_name = ?, email = ?, phone = ?, department = ?, avatar = ?
            WHERE id = ?
        ");
        $stmt->execute([$fullName, $email, $phone ?: null, $department ?: null, $avatarPath, $userId]);
    } else {
        $stmt = $db->prepare("
            UPDATE users
            SET full_name = ?, email = ?, phone = ?, department = ?
            WHERE id = ?
        ");
        $stmt->execute([$fullName, $email, $phone ?: null, $department ?: null, $userId]);
    }

    /* Keep session in sync */
    $_SESSION['user_name']  = $fullName;
    $_SESSION['user_email'] = $email;

    /* Return updated row */
    $fetch = $db->prepare("
        SELECT full_name, email, phone, department, avatar
        FROM   users WHERE id = ?
    ");
    $fetch->execute([$userId]);
    $updated = $fetch->fetch(PDO::FETCH_ASSOC);

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "message" => "Profile updated successfully.",
        "user"    => [
            "full_name"  => $updated['full_name'],
            "email"      => $updated['email'],
            "phone"      => $updated['phone']      ?? '',
            "department" => $updated['department']  ?? '',
            "avatar"     => $updated['avatar']      ?? '',
            "role"       => $_SESSION['user_role']  ?? 'Staff',
        ],
    ]);

} catch (Exception $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}