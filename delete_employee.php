<?php
/**
 * Удаление сотрудника — только POST + CSRF + super_admin.
 * Заменяет небезопасный GET /?delete_id=X.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

// Только AJAX-запросы
if (!isAjax()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Только AJAX']);
    exit;
}

// Авторизация: только super_admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

// CSRF-защита
Csrf::guard();

// Получаем и валидируем ID
$data = json_decode(file_get_contents('php://input'), true);
$id   = intval($data['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный ID']);
    exit;
}

$emp = getEmployeeById($pdo, $id);
if (!$emp) {
    echo json_encode(['success' => false, 'message' => 'Сотрудник не найден']);
    exit;
}

// Запрет удалять самого себя
if ($id === (int)$_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Нельзя удалить собственную учётную запись']);
    exit;
}

try {
    $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
    logAction('delete_employee', "Удалён: {$emp['full_name']} (ID:{$id})");
    echo json_encode(['success' => true, 'message' => "Сотрудник «{$emp['full_name']}» удалён"]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
}
