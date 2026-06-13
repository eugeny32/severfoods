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
if (!$data) { echo json_encode(['success' => false, 'message' => 'Нет данных']); exit; }

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
$assigned_point_id = !empty($data['assigned_point_id']) ? intval($data['assigned_point_id']) : null;

// Генерируем случайную дату рождения если не указана
if (empty($birth_date)) {
    $birth_date = sprintf('%04d-%02d-%02d', rand(1960, 1990), rand(1, 12), rand(1, 28));
}

$errors = [];
if (empty($full_name))    $errors[] = 'ФИО обязательно';
if (empty($organization)) $errors[] = 'Организация обязательна';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode('; ', $errors)]); exit;
}

// Только super_admin может назначать привилегированные роли
$current_role = $_SESSION['role'] ?? 'admin';
if ($current_role !== 'super_admin' && in_array($role, ['admin','super_admin'], true)) {
    $role = null;
}
// Admin can only assign operator to their own point
if ($current_role === 'admin' && $role === 'operator') {
    $assigned_point_id = $_SESSION['assigned_point_id'] ?? null;
}
// Only admin/operator/super_admin get a point
if (!in_array($role, ['admin','operator','super_admin'], true)) {
    $assigned_point_id = null;
}

$qr_code = generateUniqueQrCode();

try {
    $pdo->prepare(
        "INSERT INTO employees
             (full_name, birth_date, organization, department, position,
              vjg_type, price, qr_code, qr_expires_at, qr_status, is_active, role, assigned_point_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$full_name, $birth_date, $organization, $department, $position,
                $vjg_type, $price, $qr_code, $qr_expires, $qr_status, $is_active, $role, $assigned_point_id]);
    $new_id = (int)$pdo->lastInsertId();
    logAction('add_employee', "Добавлен: {$full_name} (ID:{$new_id})");
    echo json_encode(['success' => true, 'message' => "Сотрудник «{$full_name}» добавлен", 'id' => $new_id]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
}
