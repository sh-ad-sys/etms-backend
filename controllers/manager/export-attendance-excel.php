<?php ob_start();

/* CORS first */
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");

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

    $db       = (new Database())->connect();
    $dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo   = $_GET['to']   ?? date('Y-m-d');

    /* Fetch all attendance in date range */
    $stmt = $db->prepare("
        SELECT
            u.employee_code,
            u.full_name,
            COALESCE(d.name, u.department, 'General') AS department,
            DATE(a.check_in)                           AS date,
            DATE_FORMAT(a.check_in,  '%h:%i %p')       AS check_in,
            DATE_FORMAT(a.check_out, '%h:%i %p')       AS check_out,
            a.status,
            CASE WHEN a.status = 'LATE'
                 THEN CONCAT(
                     GREATEST(0, TIMESTAMPDIFF(MINUTE,
                         CONCAT(DATE(a.check_in),' ', IFNULL(s.start_time,'08:00:00')),
                         a.check_in
                     )), ' min')
                 ELSE '' END AS minutes_late,
            a.source
        FROM  v_attendance a
        JOIN  users u ON u.id = a.user_id
        LEFT  JOIN departments d ON d.id = u.department_id
        LEFT  JOIN shifts      s ON s.id = a.shift_id
        WHERE DATE(a.check_in) BETWEEN ? AND ?
        ORDER BY DATE(a.check_in) DESC, u.full_name ASC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ── Build XLS (tab-separated HTML table — opens natively in Excel) ── */
    ob_end_clean();

    $filename = "Royal_Mabati_Attendance_{$dateFrom}_to_{$dateTo}.xls";

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Cache-Control: max-age=0");
    header("Pragma: no-cache");

    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1' cellpadding='6' cellspacing='0'>";

    /* Title row */
    echo "<tr><td colspan='9' style='background:#1a3a6b;color:#fff;font-size:16px;font-weight:bold;text-align:center;'>
            Royal Mabati Factory – Attendance Report ({$dateFrom} to {$dateTo})
          </td></tr>";
    echo "<tr><td colspan='9'></td></tr>";

    /* Header row */
    $headers = ['Emp Code','Full Name','Department','Date','Check In','Check Out','Status','Minutes Late','Source'];
    echo "<tr>";
    foreach ($headers as $h) {
        echo "<th style='background:#1a3a6b;color:#fff;font-weight:bold;padding:8px;'>" . htmlspecialchars($h) . "</th>";
    }
    echo "</tr>";

    /* Data rows */
    $statusColors = [
        'PRESENT' => '#dcfce7',
        'LATE'    => '#fef3c7',
        'ABSENT'  => '#fee2e2',
        'OUTSIDE_GEOFENCE' => '#ede9fe',
    ];

    foreach ($rows as $row) {
        $bg = $statusColors[$row['status']] ?? '#ffffff';
        echo "<tr style='background:{$bg};'>";
        echo "<td>" . htmlspecialchars($row['employee_code']  ?? '—') . "</td>";
        echo "<td>" . htmlspecialchars($row['full_name'])              . "</td>";
        echo "<td>" . htmlspecialchars($row['department'])             . "</td>";
        echo "<td>" . htmlspecialchars($row['date'])                   . "</td>";
        echo "<td>" . htmlspecialchars($row['check_in']   ?? '—')      . "</td>";
        echo "<td>" . htmlspecialchars($row['check_out']  ?? '—')      . "</td>";
        echo "<td style='font-weight:bold;'>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . htmlspecialchars($row['minutes_late'] ?? '')      . "</td>";
        echo "<td style='color:#94a3b8;font-size:11px;'>" . htmlspecialchars($row['source'] ?? '') . "</td>";
        echo "</tr>";
    }

    /* Summary row */
    $total    = count($rows);
    $present  = count(array_filter($rows, fn($r) => $r['status'] === 'PRESENT'));
    $late     = count(array_filter($rows, fn($r) => $r['status'] === 'LATE'));
    $absent   = count(array_filter($rows, fn($r) => $r['status'] === 'ABSENT'));

    echo "<tr><td colspan='9'></td></tr>";
    echo "<tr>
        <td colspan='2' style='font-weight:bold;background:#f8fafc;'>Summary</td>
        <td style='background:#f8fafc;'>Total: <b>{$total}</b></td>
        <td colspan='2' style='background:#dcfce7;'>Present: <b>{$present}</b></td>
        <td style='background:#fef3c7;'>Late: <b>{$late}</b></td>
        <td style='background:#fee2e2;'>Absent: <b>{$absent}</b></td>
        <td colspan='2' style='background:#f8fafc;'>Generated: " . date('d M Y h:i A') . "</td>
    </tr>";

    echo "</table></body></html>";

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}