<?php
/**
 * API: список активных сотрудников (без QR-кодов).
 * Требует авторизации.
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

// qr_code намеренно исключён — это чувствительные данные для доступа
$stmt = $pdo->query(
    "SELECT id, full_name, birth_date, organization, department, position,
            vjg_type, price, qr_status, qr_expires_at, is_active, role, assigned_point_id
     FROM employees
     WHERE is_active = 1
     ORDER BY full_name"
);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
