<?php
/**
 * API: список активных сотрудников (без QR-кодов).
 * Требует авторизации.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

// qr_code намеренно исключён — это чувствительные данные для доступа.
// Дата рождения и цена питания — тоже персональные данные и оператору для
// работы не нужны, поэтому отдаются только администраторам.
$isAdmin = !empty($_SESSION['is_admin']);
$isSuper = ($_SESSION['role'] ?? '') === 'super_admin';

$fields = $isAdmin
    ? "id, full_name, birth_date, organization, department, position,
       vjg_type, price, qr_status, qr_expires_at, is_active, role, assigned_point_id"
    : "id, full_name, organization, department, position,
       qr_status, qr_expires_at, is_active, role, assigned_point_id";

$sql    = "SELECT {$fields} FROM employees WHERE is_active = 1";
$params = [];

// Ограничения по организации здесь нет намеренно: администраторы обслуживают
// несколько организаций сразу, и фильтр по своей лишал их работы. Защита
// карточек супер-администраторов действует отдельно, при открытии карточки
// (canAccessEmployeeCard в src/functions.php).

$sql .= " ORDER BY full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
