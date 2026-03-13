<?php

ob_start();
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

require_once "../config/db.php";
use Config\Database;

try {

    if (!isset($_SESSION['user_id'])) {
        ob_end_clean(); http_response_code(401);
        echo json_encode(["error" => "User not authenticated"]); exit;
    }

    $db         = (new Database())->connect();
    $userId     = (int) $_SESSION['user_id'];
    $dateFilter = $_GET['date'] ?? null;

    /* ── Query v_attendance (covers qr_attendance_logs + attendance table) ── */

    $params = [$userId];
    $where  = "user_id = ?";

    if ($dateFilter) {
        $where   .= " AND DATE(check_in) = ?";
        $params[] = $dateFilter;
    }

    $stmt = $db->prepare("
        SELECT
            DATE(check_in)   AS work_date,
            MIN(check_in)    AS checkIn,
            MAX(check_out)   AS checkOut,
            MAX(status)      AS att_status,
            MAX(source)      AS source
        FROM  v_attendance
        WHERE {$where}
        GROUP BY DATE(check_in)
        ORDER BY work_date DESC
        LIMIT 60
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $records    = [];
    $present    = 0;
    $late       = 0;
    $absent     = 0;
    $totalHours = 0;

    foreach ($rows as $row) {

        $checkIn     = $row['checkIn'];
        $checkOut    = $row['checkOut'];
        $hoursWorked = 0;

        if ($checkIn && $checkOut) {
            $diff        = (new DateTime($checkIn))->diff(new DateTime($checkOut));
            $hoursWorked = $diff->h + ($diff->i / 60) + ($diff->s / 3600);
            $totalHours += $hoursWorked;
        }

        /* ── Derive status:
           1. Use stored status from v_attendance when available
           2. Fall back to comparing arrival vs 08:00 shift start        ── */
        $storedStatus = strtoupper(trim($row['att_status'] ?? ''));

        if ($storedStatus === 'LATE') {
            $status = 'Late';
            $late++;
        } elseif ($storedStatus === 'PRESENT') {
            $status = 'Present';
            $present++;
        } elseif (!$checkIn) {
            $status = 'Absent';
            $absent++;
        } else {
            /* Fallback: calculate from check-in time */
            $arrival    = new DateTime($checkIn);
            $shiftStart = new DateTime($row['work_date'] . ' 08:00:00');
            if ($arrival > $shiftStart) {
                $status = 'Late';
                $late++;
            } else {
                $status = 'Present';
                $present++;
            }
        }

        $records[] = [
            'id'          => uniqid(),
            'date'        => $row['work_date'],
            'checkIn'     => $checkIn,
            'checkOut'    => $checkOut,
            'method'      => strtoupper($row['source'] ?? 'QR'),
            'location'    => 'Office',
            'status'      => $status,
            'hoursWorked' => round($hoursWorked, 2),
        ];
    }

    ob_end_clean();
    echo json_encode([
        'records' => $records,
        'summary' => [
            'totalDays'  => count($records), /* key the frontend expects */
            'present'    => $present,         /* key the frontend expects */
            'late'       => $late,            /* key the frontend expects */
            'absent'     => $absent,          /* key the frontend expects */
            'totalHours' => round($totalHours, 2),
        ],
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}