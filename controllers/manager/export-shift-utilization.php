<?php ob_start();

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

    /* Fetch shift-level daily utilization */
    $shifts = $db->query("
        SELECT id, name, start_time, end_time, grace_period FROM shifts ORDER BY start_time
    ")->fetchAll(PDO::FETCH_ASSOC);

    $totalWorkers = (int) $db->query("
        SELECT COUNT(*) FROM users WHERE status = 'ACTIVE'
    ")->fetchColumn();

    /* Fetch all attendance in range grouped by date + shift window */
    $stmt = $db->prepare("
        SELECT
            DATE(a.check_in)                           AS att_date,
            COALESCE(d.name, u.department,'General')   AS department,
            u.full_name,
            u.employee_code,
            DATE_FORMAT(a.check_in,  '%h:%i %p')       AS check_in,
            DATE_FORMAT(a.check_out, '%h:%i %p')       AS check_out,
            a.status,
            TIME(a.check_in)                           AS check_in_time
        FROM  v_attendance a
        JOIN  users        u ON u.id  = a.user_id
        LEFT  JOIN departments d ON d.id = u.department_id
        WHERE DATE(a.check_in) BETWEEN ? AND ?
        AND   a.status IN ('PRESENT','LATE')
        ORDER BY att_date DESC, check_in_time ASC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Match each row to a shift */
    $shiftMap = [];
    foreach ($shifts as $s) {
        $shiftMap[$s['name']] = [
            'start' => $s['start_time'],
            'end'   => $s['end_time'],
            'grace' => (int)($s['grace_period'] ?? 0),
        ];
    }

    function getShiftName(string $checkInTime, array $shiftMap): string {
        foreach ($shiftMap as $name => $s) {
            $windowStart = date('H:i:s', strtotime($s['start']) - ($s['grace'] * 60));
            if ($checkInTime >= $windowStart && $checkInTime <= $s['end']) return $name;
        }
        return 'Unassigned';
    }

    ob_end_clean();

    $filename = "Royal_Mabati_Shift_Utilization_{$dateFrom}_to_{$dateTo}.xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Cache-Control: max-age=0");
    header("Pragma: no-cache");

    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1' cellpadding='6' cellspacing='0'>";

    echo "<tr><td colspan='8' style='background:#1a3a6b;color:#fff;font-size:16px;font-weight:bold;text-align:center;'>
            Royal Mabati Factory – Shift Utilization Report ({$dateFrom} to {$dateTo})
          </td></tr><tr><td colspan='8'></td></tr>";

    $headers = ['Date','Employee Code','Full Name','Department','Shift','Check In','Check Out','Status'];
    echo "<tr>";
    foreach ($headers as $h) {
        echo "<th style='background:#1a3a6b;color:#fff;font-weight:bold;padding:8px;'>" . htmlspecialchars($h) . "</th>";
    }
    echo "</tr>";

    $statusColors = ['PRESENT' => '#dcfce7', 'LATE' => '#fef3c7'];

    foreach ($rows as $row) {
        $shiftName = getShiftName($row['check_in_time'], $shiftMap);
        $bg        = $statusColors[$row['status']] ?? '#fff';
        echo "<tr style='background:{$bg};'>";
        echo "<td>" . htmlspecialchars($row['att_date'])                 . "</td>";
        echo "<td>" . htmlspecialchars($row['employee_code'] ?? '—')     . "</td>";
        echo "<td>" . htmlspecialchars($row['full_name'])                . "</td>";
        echo "<td>" . htmlspecialchars($row['department'])               . "</td>";
        echo "<td style='font-weight:bold;'>" . htmlspecialchars($shiftName) . "</td>";
        echo "<td>" . htmlspecialchars($row['check_in']  ?? '—')         . "</td>";
        echo "<td>" . htmlspecialchars($row['check_out'] ?? '—')         . "</td>";
        echo "<td style='font-weight:bold;'>" . htmlspecialchars($row['status']) . "</td>";
        echo "</tr>";
    }

    /* Summary */
    $total   = count($rows);
    $present = count(array_filter($rows, fn($r) => $r['status'] === 'PRESENT'));
    $late    = count(array_filter($rows, fn($r) => $r['status'] === 'LATE'));

    echo "<tr><td colspan='8'></td></tr>";
    echo "<tr>
        <td colspan='3' style='font-weight:bold;background:#f8fafc;'>Total Records: <b>{$total}</b></td>
        <td colspan='2' style='background:#dcfce7;'>On Time: <b>{$present}</b></td>
        <td colspan='2' style='background:#fef3c7;'>Late: <b>{$late}</b></td>
        <td style='background:#f8fafc;'>Generated: " . date('d M Y h:i A') . "</td>
    </tr>";

    echo "</table></body></html>";

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}