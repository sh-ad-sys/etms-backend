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

    $db         = (new Database())->connect();
    $department = trim($_GET['department'] ?? '');
    $status     = trim($_GET['status']     ?? '');

    $where  = ["1=1"];
    $params = [];

    if ($department) {
        $where[]         = "(d.name = :dept OR u.department = :dept)";
        $params[':dept'] = $department;
    }
    if ($status) {
        $where[]           = "u.status = :status";
        $params[':status'] = strtoupper($status);
    }

    $whereSQL = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT
            u.employee_code,
            u.full_name,
            u.email,
            u.phone,
            COALESCE(d.name, u.department, 'General') AS department_name,
            r.name   AS role_name,
            u.status,
            DATE(u.created_at) AS joined_on,
            ROUND(
                COUNT(DISTINCT DATE(a.check_in)) /
                NULLIF((
                    SELECT COUNT(DISTINCT DATE(check_in))
                    FROM   v_attendance
                    WHERE  check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ), 0) * 100
            ) AS attendance_rate,
            MAX(a.check_in) AS last_check_in
        FROM  users u
        LEFT  JOIN departments  d ON d.id  = u.department_id
        LEFT  JOIN roles        r ON r.id  = u.role_id
        LEFT  JOIN v_attendance a ON a.user_id = u.id
                                  AND a.check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        WHERE {$whereSQL}
        GROUP BY u.id, u.employee_code, u.full_name, u.email, u.phone,
                 department_name, role_name, u.status, joined_on
        ORDER BY u.full_name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_end_clean();

    $filename = "Royal_Mabati_Staff_Profiles_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Cache-Control: max-age=0");
    header("Pragma: no-cache");

    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1' cellpadding='6' cellspacing='0'>";

    echo "<tr>
        <td colspan='10' style='background:#1a3a6b;color:#fff;font-size:16px;
             font-weight:bold;text-align:center;'>
            Royal Mabati Factory – Staff Profiles Report (Generated: " . date('d M Y h:i A') . ")
        </td>
    </tr><tr><td colspan='10'></td></tr>";

    $headers = ['Emp Code','Full Name','Email','Phone','Department','Role','Status','Joined On','Attendance Rate (30d)','Last Check-In'];
    echo "<tr>";
    foreach ($headers as $h) {
        echo "<th style='background:#1a3a6b;color:#fff;font-weight:bold;padding:9px;'>"
             . htmlspecialchars($h) . "</th>";
    }
    echo "</tr>";

    $statusBg = ['ACTIVE' => '#dcfce7', 'SUSPENDED' => '#fef3c7', 'EXITED' => '#fee2e2'];

    foreach ($rows as $row) {
        $bg   = $statusBg[$row['status']] ?? '#fff';
        $rate = (int)($row['attendance_rate'] ?? 0);
        $rateBg = $rate >= 80 ? '#dcfce7' : ($rate >= 60 ? '#fef3c7' : '#fee2e2');

        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['employee_code'] ?? '—')          . "</td>";
        echo "<td style='font-weight:bold;'>" . htmlspecialchars($row['full_name'])        . "</td>";
        echo "<td>" . htmlspecialchars($row['email'])                          . "</td>";
        echo "<td>" . htmlspecialchars($row['phone'] ?? '—')                   . "</td>";
        echo "<td>" . htmlspecialchars($row['department_name'])                . "</td>";
        echo "<td>" . htmlspecialchars($row['role_name'] ?? 'Staff')           . "</td>";
        echo "<td style='background:{$bg};font-weight:bold;'>"
             . htmlspecialchars(ucfirst(strtolower($row['status'])))           . "</td>";
        echo "<td>" . htmlspecialchars($row['joined_on'] ?? '—')               . "</td>";
        echo "<td style='background:{$rateBg};font-weight:bold;text-align:center;'>{$rate}%</td>";
        echo "<td>" . htmlspecialchars(
            $row['last_check_in'] ? date('d M Y h:i A', strtotime($row['last_check_in'])) : 'Never'
        ) . "</td>";
        echo "</tr>";
    }

    /* Summary */
    $total    = count($rows);
    $active   = count(array_filter($rows, fn($r) => $r['status'] === 'ACTIVE'));
    $inactive = $total - $active;
    echo "<tr><td colspan='10'></td></tr>";
    echo "<tr>
        <td colspan='3' style='font-weight:bold;background:#f8fafc;'>Total Staff: <b>{$total}</b></td>
        <td colspan='3' style='background:#dcfce7;'>Active: <b>{$active}</b></td>
        <td colspan='4' style='background:#fee2e2;'>Inactive/Exited: <b>{$inactive}</b></td>
    </tr>";

    echo "</table></body></html>";

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo "Export failed: " . $e->getMessage();
}