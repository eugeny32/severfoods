<?php
/**
 * Удаление выбранных дублирующихся записей meal_logs (см.
 * find_duplicate_passes.php). Физически строки не удаляются — ставится
 * access_granted = 0, что делает их невидимыми во всех отчётах и
 * подсчётах (везде используется фильтр access_granted = 1), но
 * оставляет след в базе на случай ошибки/проверки.
 *
 * Доступно только супер-администратору.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');

checkAuth();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступно только супер-администратору']);
    exit;
}
if (!isAjax()) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Только AJAX']); exit; }
Csrf::guard();

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$ids  = array_values(array_unique(array_filter(array_map('intval', $data['ids'] ?? []))));

if (!$ids) {
    echo json_encode(['success' => false, 'message' => 'Не выбраны записи']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$upd = $pdo->prepare("UPDATE meal_logs SET access_granted = 0 WHERE id IN ($placeholders) AND access_granted = 1");
$upd->execute($ids);
$affected = $upd->rowCount();

logAction('delete_duplicate_passes', "Помечено как удалённые дублирующихся записей: {$affected} (id: " . implode(',', $ids) . ")");

echo json_encode(['success' => true, 'deleted' => $affected]);
