<?php
/**
 * API: список активных сотрудников (без QR-кодов).
 * Требует авторизации.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php'; // currentUserOrganization()

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

// qr_code намеренно исключён — это чувствительные данные для доступа.
// Дата рождения и цена питания — тоже персональные данные и оператору для
// работы не нужны, поэтому отдаются только администраторам, и только по
// своей организации (супер-администратору — по всем).
$isAdmin = !empty($_SESSION['is_admin']);
$isSuper = ($_SESSION['role'] ?? '') === 'super_admin';

$fields = $isAdmin
    ? "id, full_name, birth_date, organization, department, position,
       vjg_type, price, qr_status, qr_expires_at, is_active, role, assigned_point_id"
    : "id, full_name, organization, department, position,
       qr_status, qr_expires_at, is_active, role, assigned_point_id";

$sql    = "SELECT {$fields} FROM employees WHERE is_active = 1";
$params = [];

if ($isAdmin && !$isSuper) {
    $myOrg = currentUserOrganization($pdo);
    if ($myOrg === '') { echo json_encode([]); exit; }
    $sql .= " AND TRIM(organization) = ?";
    $params[] = $myOrg;
}

$sql .= " ORDER BY full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
