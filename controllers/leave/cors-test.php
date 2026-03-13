<?php ob_start();
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
ob_end_clean();
echo json_encode(["success" => true, "message" => "CORS works"]);