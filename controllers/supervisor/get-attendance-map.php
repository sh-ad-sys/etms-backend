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

    $db    = (new Database())->connect();
    $today = date('Y-m-d');

    /* ── All active users with today's attendance (LEFT JOIN so absent shows too) ── */
    $stmt = $db->prepare("
        SELECT
            u.id,
            u.full_name,
            u.employee_code,
            u.department,
            d.name          AS department_name,
            s.name          AS shift_name,
            a.check_in,
            a.check_out,
            a.status        AS attendance_status,
            a.latitude,
            a.longitude,
            a.distance
        FROM  users u
        LEFT  JOIN departments d  ON d.id  = u.department_id
        LEFT  JOIN v_attendance  a  ON a.user_id = u.id
                                  AND DATE(a.check_in) = :today
        LEFT  JOIN shifts      s  ON s.id  = a.shift_id
        WHERE u.status = 'ACTIVE'
        ORDER BY
            FIELD(a.status, 'PRESENT', 'LATE', 'OUTSIDE_GEOFENCE', 'ABSENT', NULL),
            a.check_in DESC
    ");
    $stmt->execute([':today' => $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $staff = [];

    foreach ($rows as $row) {

        /* Derive frontend status */
        $rawStatus = $row['attendance_status'] ?? null;

        if ($rawStatus === 'PRESENT') {
            $status = 'On Site';
        } elseif ($rawStatus === 'LATE') {
            $status = 'Late';
        } elseif ($rawStatus === 'OUTSIDE_GEOFENCE') {
            $status = 'Outside';
        } else {
            $status = 'Absent';
        }

        /* Location = department name or shift name */
        $location = $row['department_name'] ?? $row['department'] ?? 'Unknown';
        if ($row['shift_name']) {
            $location .= ' · ' . $row['shift_name'];
        }

        $staff[] = [
            'id'           => (string) $row['id'],
            'name'         => $row['full_name'],
            'employeeCode' => $row['employee_code'] ?? '',
            'department'   => $row['department_name'] ?? $row['department'] ?? 'General',
            'location'     => $location,
            'shift'        => $row['shift_name'] ?? '',
            'status'       => $status,
            'lastCheckIn'  => $row['check_in']
                ? date('h:i A', strtotime($row['check_in']))
                : '—',
            'checkOut'     => $row['check_out']
                ? date('h:i A', strtotime($row['check_out']))
                : null,
            'latitude'     => $row['latitude']  ? (float) $row['latitude']  : null,
            'longitude'    => $row['longitude'] ? (float) $row['longitude'] : null,
            'distance'     => $row['distance']  ? round((float) $row['distance'], 1) : null,
        ];
    }

    /* Summary counts */
    $onSite  = count(array_filter($staff, fn($s) => $s['status'] === 'On Site'));
    $late    = count(array_filter($staff, fn($s) => $s['status'] === 'Late'));
    $absent  = count(array_filter($staff, fn($s) => $s['status'] === 'Absent'));
    $outside = count(array_filter($staff, fn($s) => $s['status'] === 'Outside'));

    ob_end_clean();
    echo json_encode([
        "success"     => true,
        "staff"       => $staff,
        "summary"     => [
            "total"   => count($staff),
            "onSite"  => $onSite,
            "late"    => $late,
            "absent"  => $absent,
            "outside" => $outside,
        ],
        "asOf" => date('h:i A'),
        "date" => date('D, d M Y'),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}