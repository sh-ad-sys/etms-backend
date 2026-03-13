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
    echo json_encode(["error" => "Not authenticated"]); exit;
}

require_once "../config/db.php";
use Config\Database;

try {

    $db     = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];

    /* ── Fetch all records from v_attendance (covers both tables) ── */
    $stmt = $db->prepare("
        SELECT
            DATE(check_in)   AS work_date,
            MIN(check_in)    AS checkIn,
            MAX(check_out)   AS checkOut,
            MAX(status)      AS att_status,
            MAX(source)      AS source
        FROM  v_attendance
        WHERE user_id = ?
        AND   check_in IS NOT NULL
        GROUP BY DATE(check_in)
        ORDER BY work_date DESC
        LIMIT 365
    ");
    $stmt->execute([$userId]);
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

        /* Derive status — prefer stored value, fall back to time comparison */
        $stored = strtoupper(trim($row['att_status'] ?? ''));

        if ($stored === 'LATE') {
            $status = 'Late'; $late++;
        } elseif ($stored === 'PRESENT') {
            $status = 'Present'; $present++;
        } elseif (!$checkIn) {
            $status = 'Absent'; $absent++;
        } else {
            $arrival    = new DateTime($checkIn);
            $shiftStart = new DateTime($row['work_date'] . ' 08:00:00');
            if ($arrival > $shiftStart) {
                $status = 'Late'; $late++;
            } else {
                $status = 'Present'; $present++;
            }
        }

        /* Format times for display */
        $fmtIn  = $checkIn  ? date('h:i A', strtotime($checkIn))  : null;
        $fmtOut = $checkOut ? date('h:i A', strtotime($checkOut)) : null;

        $records[] = [
            'id'          => uniqid(),
            'date'        => $row['work_date'],
            'checkIn'     => $fmtIn,
            'checkOut'    => $fmtOut,
            'method'      => strtoupper($row['source'] ?? 'QR'),
            'location'    => 'Office',
            'status'      => $status,
            'hoursWorked' => round($hoursWorked, 2),
        ];
    }

    ob_end_clean();
    echo json_encode([
        'records' => $records,
        /* Keys match exactly what the frontend SummaryStats interface expects */
        'summary' => [
            'totalRecords' => count($records),
            'totalHours'   => round($totalHours, 2),
            'lateCount'    => $late,
            'absentCount'  => $absent,
        ],
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}