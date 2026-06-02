<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!isAjax()) {
    http_response_code(400); echo json_encode(['success' => false, 'message' => 'Только AJAX']); exit;
}
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Доступ запрещён']); exit;
}

Csrf::guard();

// Ensure assigned_point_id column exists
try { $pdo->exec("ALTER TABLE employees ADD COLUMN assigned_point_id INT DEFAULT NULL"); } catch(PDOException $e){}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'Нет данных']); exit;
}

$id = intval($data['id']);
$emp = getEmployeeById($pdo, $id);
if (!$emp) { echo json_encode(['success' => false, 'message' => 'Сотрудник не найден']); exit; }

$full_name    = trim($data['full_name']    ?? '');
$birth_date   = $data['birth_date']        ?? null;
$organization = trim($data['organization'] ?? '');
$department   = trim($data['department']   ?? '');
$position     = trim($data['position']     ?? '');
$vjg_type     = trim($data['vjg_type']     ?? '');
$price        = floatval($data['price']    ?? 0);
$qr_expires   = !empty($data['qr_expires_at']) ? $data['qr_expires_at'] : null;
$qr_status    = in_array($data['qr_status'] ?? '', ['active','expired','blocked'])
                ? $data['qr_status'] : 'active';
$is_active    = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
$role         = !empty($data['role']) ? $data['role'] : null;
$regen        = !empty($data['regenerate_qr']);
$assigned_point_id = !empty($data['assigned_point_id']) ? intval($data['assigned_point_id']) : null;

$errors = [];
if (empty($full_name))    $errors[] = 'ФИО обязательно';
if (empty($birth_date))   $errors[] = 'Дата рождения обязательна';
if (empty($organization)) $errors[] = 'Организация обязательна';
if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode('; ', $errors)]); exit;
}

// Только super_admin может менять привилегированные роли
$current_role = $_SESSION['role'] ?? 'admin';
if ($current_role !== 'super_admin' && $role !== $emp['role']) {
    $role = $emp['role'];
}
// Admin can only assign operator to their own point
if ($current_role === 'admin' && $role === 'operator') {
    $assigned_point_id = $_SESSION['assigned_point_id'] ?? null;
}
// Only admin/operator/super_admin get a point
if (!in_array($role, ['admin','operator','super_admin'], true)) {
    $assigned_point_id = null;
}

$qr_code = $regen ? generateUniqueQrCode() : $emp['qr_code'];
if ($regen) logAction('regenerate_qr', "Перегенерирован QR для: {$full_name} (ID:{$id})");

try {
    $pdo->prepare(
        "UPDATE employees SET
             full_name=?, birth_date=?, organization=?, department=?, position=?,
             vjg_type=?, price=?, qr_expires_at=?, qr_status=?, is_active=?, qr_code=?, role=?, assigned_point_id=?
         WHERE id=?"
    )->execute([$full_name, $birth_date, $organization, $department, $position,
                $vjg_type, $price, $qr_expires, $qr_status, $is_active, $qr_code, $role, $assigned_point_id, $id]);
    logAction('update_employee', "Обновлён: {$full_name} (ID:{$id})");
    echo json_encode(['success' => true, 'message' => 'Данные сотрудника обновлены']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
}
