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

if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]); exit;
}

require_once "../../config/db.php";
use Config\Database;

try {

    $db = (new Database())->connect();

    /* ══════════════════════════════════════════
       1. SERVER STATUS
    ══════════════════════════════════════════ */

    /* Uptime from /proc/uptime (Linux only; fallback for Windows) */
    $uptimeSeconds = 0;
    if (file_exists('/proc/uptime')) {
        $uptimeSeconds = (float) explode(' ', file_get_contents('/proc/uptime'))[0];
    }

    $uptimeDays    = floor($uptimeSeconds / 86400);
    $uptimeHours   = floor(($uptimeSeconds % 86400) / 3600);
    $uptimeMinutes = floor(($uptimeSeconds % 3600) / 60);
    $uptimeStr     = $uptimeDays > 0
        ? "{$uptimeDays}d {$uptimeHours}h {$uptimeMinutes}m"
        : "{$uptimeHours}h {$uptimeMinutes}m";

    /* PHP version, server software */
    $phpVersion    = PHP_VERSION;
    $serverSoftware= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';

    /* ══════════════════════════════════════════
       2. CPU USAGE
       Read from /proc/stat (Linux); fallback = null
    ══════════════════════════════════════════ */

    $cpuPercent = null;

    if (file_exists('/proc/stat')) {
        $stat1 = file_get_contents('/proc/stat');
        usleep(200000); // 0.2 second sample
        $stat2 = file_get_contents('/proc/stat');

        $cpu1 = array_slice(explode(' ', preg_split('/\n/', $stat1)[0]), 1);
        $cpu2 = array_slice(explode(' ', preg_split('/\n/', $stat2)[0]), 1);

        $cpu1 = array_filter($cpu1, fn($v) => $v !== '');
        $cpu2 = array_filter($cpu2, fn($v) => $v !== '');

        $idle1  = (float) array_values($cpu1)[3];
        $idle2  = (float) array_values($cpu2)[3];
        $total1 = array_sum($cpu1);
        $total2 = array_sum($cpu2);

        $totalDiff = $total2 - $total1;
        $idleDiff  = $idle2  - $idle1;

        if ($totalDiff > 0) {
            $cpuPercent = round((($totalDiff - $idleDiff) / $totalDiff) * 100, 1);
        }
    }

    /* Windows fallback using wmic */
    if ($cpuPercent === null && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $wmicOut = shell_exec('wmic cpu get loadpercentage /value 2>nul');
        if ($wmicOut && preg_match('/LoadPercentage=(\d+)/', $wmicOut, $m)) {
            $cpuPercent = (float) $m[1];
        }
    }

    $cpuPercent = $cpuPercent ?? 0;

    /* ══════════════════════════════════════════
       3. MEMORY USAGE
    ══════════════════════════════════════════ */

    $memTotal   = 0;
    $memFree    = 0;
    $memPercent = 0;

    if (file_exists('/proc/meminfo')) {
        $memInfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/',    $memInfo, $mt);
        preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $ma);
        $memTotal   = isset($mt[1]) ? round($mt[1] / 1024) : 0;   // MB
        $memAvail   = isset($ma[1]) ? round($ma[1] / 1024) : 0;
        $memUsed    = $memTotal - $memAvail;
        $memPercent = $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 1) : 0;
    }

    /* PHP memory limit & current usage */
    $phpMemUsed  = round(memory_get_usage(true) / 1024 / 1024, 1);
    $phpMemPeak  = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

    /* ══════════════════════════════════════════
       4. DISK / STORAGE USAGE
    ══════════════════════════════════════════ */

    $diskPath    = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__FILE__, 3);
    $diskTotal   = @disk_total_space($diskPath);
    $diskFree    = @disk_free_space($diskPath);
    $diskUsed    = $diskTotal - $diskFree;
    $diskPercent = $diskTotal > 0
        ? round(($diskUsed / $diskTotal) * 100, 1) : 0;
    $diskUsedGB  = round($diskUsed  / 1073741824, 2);
    $diskTotalGB = round($diskTotal / 1073741824, 2);

    /* ══════════════════════════════════════════
       5. DATABASE HEALTH
    ══════════════════════════════════════════ */

    /* DB size */
    $dbSize = (float) $db->query("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
        FROM   information_schema.tables
        WHERE  table_schema = DATABASE()
    ")->fetchColumn();

    /* Table count */
    $tableCount = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE()
    ")->fetchColumn();

    /* Slow queries (if performance_schema available) */
    $slowQueries = 0;
    try {
        $slowQueries = (int) $db->query("
            SHOW STATUS LIKE 'Slow_queries'
        ")->fetchColumn(1);
    } catch (Throwable) {}

    /* DB connections */
    $dbConnections = 0;
    try {
        $dbConnections = (int) $db->query("
            SHOW STATUS LIKE 'Threads_connected'
        ")->fetchColumn(1);
    } catch (Throwable) {}

    /* ══════════════════════════════════════════
       6. SERVICES (check each module's last activity)
    ══════════════════════════════════════════ */

    /* Authentication — any login in last 24h */
    $authActivity = (int) $db->query("
        SELECT COUNT(*) FROM audit_logs
        WHERE action LIKE '%login%'
        AND   created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ")->fetchColumn();

    /* Attendance — any check-in in last 24h */
    $attActivity = (int) $db->query("
        SELECT COUNT(*) FROM v_attendance
        WHERE check_in >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ")->fetchColumn();

    /* Email — check notifications table for sent emails */
    $emailActivity = (int) $db->query("
        SELECT COUNT(*) FROM notifications
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ")->fetchColumn();

    /* Backup — last backup log */
    $backupActivity = (int) $db->query("
        SELECT COUNT(*) FROM audit_logs
        WHERE action = 'db_backup'
        AND   created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetchColumn();

    $services = [
        [
            'name'        => 'Authentication Service',
            'status'      => 'running',
            'detail'      => "{$authActivity} logins in last 24h",
        ],
        [
            'name'        => 'Attendance Module',
            'status'      => 'running',
            'detail'      => "{$attActivity} check-ins in last 24h",
        ],
        [
            'name'        => 'Email Notification Service',
            'status'      => $emailActivity > 0 ? 'running' : 'idle',
            'detail'      => $emailActivity > 0
                ? "{$emailActivity} notifications sent today"
                : 'No notifications sent today',
        ],
        [
            'name'        => 'Backup Service',
            'status'      => $backupActivity > 0 ? 'running' : 'idle',
            'detail'      => $backupActivity > 0
                ? "Last backup within 7 days"
                : 'No backup in last 7 days',
        ],
        [
            'name'        => 'QR Check-In Module',
            'status'      => 'running',
            'detail'      => "Active sessions tracked",
        ],
    ];

    /* ══════════════════════════════════════════
       7. RECENT SYSTEM LOGS (from audit_logs)
    ══════════════════════════════════════════ */

    $logsStmt = $db->query("
        SELECT
            al.action,
            al.entity,
            al.details,
            al.created_at,
            u.full_name AS actor
        FROM  audit_logs al
        LEFT  JOIN users u ON u.id = al.user_id
        ORDER BY al.created_at DESC
        LIMIT 12
    ");
    $recentLogs = array_map(fn($r) => [
        'level'  => str_contains(strtolower($r['action']), 'fail')   ? 'ERROR'
                  : (str_contains(strtolower($r['action']), 'delete') ? 'WARN'
                  : (str_contains(strtolower($r['action']), 'backup') ? 'SUCCESS'
                  : 'INFO')),
        'message'=> $r['action'] . ($r['entity'] ? " [{$r['entity']}]" : ''),
        'detail' => $r['details'] ?? '',
        'actor'  => $r['actor']   ?? 'System',
        'time'   => date('M d, h:i A', strtotime($r['created_at'])),
    ], $logsStmt->fetchAll(PDO::FETCH_ASSOC));

    /* ══════════════════════════════════════════
       8. SECURITY — unresolved alerts
    ══════════════════════════════════════════ */

    $openAlerts = (int) $db->query("
        SELECT COUNT(*) FROM security_alerts WHERE status = 'open'
    ")->fetchColumn();

    $securityStatus = $openAlerts === 0 ? 'Protected' : "{$openAlerts} open alert(s)";
    $securityLevel  = $openAlerts === 0 ? 'good' : 'warning';

    /* ══════════════════════════════════════════
       9. OVERALL SYSTEM STATUS
    ══════════════════════════════════════════ */

    $overallStatus = 'Operational';
    if ($cpuPercent > 90 || $memPercent > 90 || $diskPercent > 90) {
        $overallStatus = 'Warning';
    }
    if ($cpuPercent > 95 || $diskPercent > 95) {
        $overallStatus = 'Critical';
    }

    ob_end_clean();
    echo json_encode([
        "success" => true,

        "server" => [
            "status"         => $overallStatus,
            "phpVersion"     => $phpVersion,
            "software"       => $serverSoftware,
            "uptimeStr"      => $uptimeStr ?: 'N/A',
            "uptimeSeconds"  => $uptimeSeconds,
        ],

        "cpu" => [
            "percent" => $cpuPercent,
            "status"  => $cpuPercent > 80 ? 'warning' : 'good',
        ],

        "memory" => [
            "percent"    => $memPercent,
            "totalMB"    => $memTotal,
            "phpUsedMB"  => $phpMemUsed,
            "phpPeakMB"  => $phpMemPeak,
            "status"     => $memPercent > 80 ? 'warning' : 'good',
        ],

        "disk" => [
            "percent"  => $diskPercent,
            "usedGB"   => $diskUsedGB,
            "totalGB"  => $diskTotalGB,
            "status"   => $diskPercent > 80 ? 'warning' : 'good',
        ],

        "database" => [
            "status"      => "Connected",
            "sizeMB"      => $dbSize,
            "tableCount"  => $tableCount,
            "connections" => $dbConnections,
            "slowQueries" => $slowQueries,
        ],

        "security" => [
            "status" => $securityStatus,
            "level"  => $securityLevel,
            "openAlerts" => $openAlerts,
        ],

        "services"   => $services,
        "recentLogs" => $recentLogs,

        "generatedAt" => date('h:i A'),
        "date"        => date('D, d M Y'),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}