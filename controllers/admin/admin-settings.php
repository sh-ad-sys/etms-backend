<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

function logAudit(PDO $db, int $actorId, string $action, string $details): void {
    $stmt = $db->prepare("
        INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
        VALUES (?, ?, 'system_settings', NULL, ?)
    ");
    $stmt->execute([$actorId, $action, $details]);
}

try {
    $db      = (new Database())->connect();
    $actorId = (int) $_SESSION['user_id'];
    $method  = $_SERVER['REQUEST_METHOD'];

    /* ══════════════════════════════════════════
       GET — Load all settings
    ══════════════════════════════════════════ */
    if ($method === 'GET') {

        $rows = $db->query("
            SELECT setting_key, value FROM system_settings
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        /* DB stats for the database tab */
        $dbSize = $db->query("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
            FROM   information_schema.tables
            WHERE  table_schema = DATABASE()
        ")->fetchColumn();

        $tableCount = $db->query("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
        ")->fetchColumn();

        $lastBackupRow = $db->query("
            SELECT details, created_at FROM audit_logs
            WHERE  action = 'db_backup'
            ORDER  BY created_at DESC LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        ob_end_clean();
        echo json_encode([
            "success"  => true,
            "settings" => $rows,
            "dbStats"  => [
                "sizeMB"     => (float) ($dbSize    ?? 0),
                "tableCount" => (int)   ($tableCount ?? 0),
                "lastBackup" => $lastBackupRow
                    ? date('M d Y, h:i A', strtotime($lastBackupRow['created_at']))
                    : 'Never',
            ],
        ]);

    /* ══════════════════════════════════════════
       POST — Save settings or run DB action
    ══════════════════════════════════════════ */
    } elseif ($method === 'POST') {

        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? 'save_settings';

        /* ── Save settings ── */
        if ($action === 'save_settings') {

            $allowed = [
                'org_name', 'timezone', 'two_factor_auth',
                'password_min_length', 'session_timeout',
                'maintenance_mode', 'audit_logs_enabled',
                'notifications_email', 'notifications_sms', 'notifications_push',
                'db_auto_backup', 'backup_frequency',
            ];

            $saved = [];
            $stmt  = $db->prepare("
                INSERT INTO system_settings (setting_key, value, updated_by)
                VALUES (:key, :val, :uid)
                ON DUPLICATE KEY UPDATE value = :val2, updated_by = :uid2, updated_at = NOW()
            ");

            foreach ($allowed as $key) {
                if (!array_key_exists($key, $body)) continue;
                $val = is_bool($body[$key]) ? ($body[$key] ? '1' : '0') : (string) $body[$key];
                $stmt->execute([
                    ':key'  => $key,
                    ':val'  => $val,
                    ':uid'  => $actorId,
                    ':val2' => $val,
                    ':uid2' => $actorId,
                ]);
                $saved[] = $key;
            }

            logAudit($db, $actorId, 'save_settings',
                "Updated: " . implode(', ', $saved));

            ob_end_clean();
            echo json_encode(["success" => true, "message" => "Settings saved successfully."]);

        /* ── Create DB backup (export SQL via mysqldump or log entry) ── */
        } elseif ($action === 'db_backup') {

            /* Log the backup event — actual mysqldump requires shell_exec
               which may be disabled on shared hosting.
               If shell_exec is available, swap the comment below.            */

            // $backupFile = '/path/to/backups/etms_' . date('Ymd_His') . '.sql';
            // shell_exec("mysqldump -u DB_USER -p'DB_PASS' etms > {$backupFile}");

            logAudit($db, $actorId, 'db_backup', "Manual backup triggered.");

            ob_end_clean();
            echo json_encode([
                "success" => true,
                "message" => "Backup event logged. Configure shell_exec in the PHP file to enable actual SQL dumps.",
            ]);

        /* ── Reset system (danger zone) — logs + clears transient tables ── */
        } elseif ($action === 'reset_system') {

            $confirm = $body['confirm'] ?? '';
            if ($confirm !== 'RESET') {
                ob_end_clean();
                echo json_encode(["success" => false, "error" => "Type RESET to confirm."]);
                exit;
            }

            /* Clear non-critical transient data only */
            $db->exec("TRUNCATE TABLE audit_logs");
            $db->exec("TRUNCATE TABLE notifications");
            $db->exec("DELETE FROM missing_checkins WHERE status = 'Resolved'");

            logAudit($db, $actorId, 'system_reset', "System reset by admin.");

            ob_end_clean();
            echo json_encode(["success" => true, "message" => "System data cleared."]);

        } else {
            throw new Exception("Unknown action: {$action}");
        }

    } else {
        throw new Exception("Method not allowed.");
    }

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}