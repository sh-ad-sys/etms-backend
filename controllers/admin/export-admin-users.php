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
    ob_end_clean(); http_response_code(401); exit;
}

require_once "../../config/db.php";
use Config\Database;

try {
    $db     = (new Database())->connect();
    $role   = trim($_GET['role']   ?? '');
    $status = trim($_GET['status'] ?? '');

    $where  = ["1=1"];
    $params = [];
    if ($role)   { $where[] = "r.name = :role";     $params[':role']   = $role; }
    if ($status) { $where[] = "u.status = :status"; $params[':status'] = strtoupper($status); }

    $stmt = $db->prepare("
        SELECT
            u.employee_code, u.full_name, u.email, u.phone,
            COALESCE(d.name, u.department, 'General') AS department,
            r.name   AS role_name,
            u.status,
            DATE(u.created_at) AS joined_on
        FROM  users u
        LEFT  JOIN roles       r ON r.id = u.role_id
        LEFT  JOIN departments d ON d.id = u.department_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY u.full_name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_end_clean();

    $filename = "Royal_Mabati_Users_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Cache-Control: max-age=0");
    header("Pragma: no-cache");

    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1' cellpadding='6' cellspacing='0'>";
    echo "<tr><td colspan='8' style='background:#1a3a6b;color:#fff;font-size:16px;font-weight:bold;text-align:center;'>
            Royal Mabati Factory – User Management Report (" . date('d M Y') . ")
          </td></tr><tr><td colspan='8'></td></tr>";

    foreach (['Emp Code','Full Name','Email','Phone','Department','Role','Status','Joined On'] as $h) {
        echo "<th style='background:#1a3a6b;color:#fff;font-weight:bold;padding:9px;'>" . htmlspecialchars($h) . "</th>";
    }
    echo "</tr>";

    $statusBg = ['ACTIVE'=>'#dcfce7','SUSPENDED'=>'#fef3c7','EXITED'=>'#fee2e2'];
    foreach ($rows as $r) {
        $bg = $statusBg[$r['status']] ?? '#fff';
        echo "<tr>";
        echo "<td>" . htmlspecialchars($r['employee_code'] ?? '—')           . "</td>";
        echo "<td style='font-weight:bold;'>" . htmlspecialchars($r['full_name'])         . "</td>";
        echo "<td>" . htmlspecialchars($r['email'])                           . "</td>";
        echo "<td>" . htmlspecialchars($r['phone'] ?? '—')                    . "</td>";
        echo "<td>" . htmlspecialchars($r['department'])                      . "</td>";
        echo "<td>" . htmlspecialchars($r['role_name'] ?? 'Staff')            . "</td>";
        echo "<td style='background:{$bg};font-weight:bold;'>"
             . htmlspecialchars(ucfirst(strtolower($r['status'])))            . "</td>";
        echo "<td>" . htmlspecialchars($r['joined_on'] ?? '—')                . "</td>";
        echo "</tr>";
    }

    $total    = count($rows);
    $active   = count(array_filter($rows, fn($r) => $r['status'] === 'ACTIVE'));
    $suspended= count(array_filter($rows, fn($r) => $r['status'] === 'SUSPENDED'));
    $exited   = count(array_filter($rows, fn($r) => $r['status'] === 'EXITED'));

    echo "<tr><td colspan='8'></td></tr>
    <tr>
        <td colspan='2' style='font-weight:bold;background:#f8fafc;'>Total: <b>{$total}</b></td>
        <td colspan='2' style='background:#dcfce7;'>Active: <b>{$active}</b></td>
        <td colspan='2' style='background:#fef3c7;'>Suspended: <b>{$suspended}</b></td>
        <td colspan='2' style='background:#fee2e2;'>Exited: <b>{$exited}</b></td>
    </tr>";

    echo "</table></body></html>";

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo "Export failed: " . $e->getMessage();
}