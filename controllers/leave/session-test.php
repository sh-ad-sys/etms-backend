<?php ob_start();
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
$started = session_start();
$output = ob_get_clean();
echo json_encode([
    "session_started" => $started,
    "session_id"      => session_id(),
    "user_id"         => $_SESSION['user_id'] ?? "NOT SET",
    "leaked_output"   => $output,
    "length"          => strlen($output)
]);