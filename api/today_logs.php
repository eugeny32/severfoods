<?php
/**
 * API: журнал питания за текущий день.
 * Требует авторизации.
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

$today = date('Y-m-d');
$stmt  = $pdo->prepare(
    "SELECT employee_id, meal_type, scanned_at, meal_point_id
     FROM meal_logs
     WHERE DATE(scanned_at) = ? AND access_granted = 1
     ORDER BY scanned_at DESC"
);
$stmt->execute([$today]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
