<?php
/**
 * API: список активных точек питания.
 * Требует авторизации.
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

$stmt = $pdo->query(
    "SELECT id, point_name, point_code, address, city, is_active, sort_order
     FROM meal_points
     WHERE is_active = 1
     ORDER BY sort_order, point_name"
);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
