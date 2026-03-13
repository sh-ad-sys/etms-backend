<?php ob_start();
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Content-Type: application/json");
require_once "../../config/db.php";
$output = ob_get_clean();
echo json_encode(["leaked_output" => $output, "length" => strlen($output)]);