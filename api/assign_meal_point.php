<?php
/**
 * Массовое назначение точки питания (и опционально типа приёма) выбранным
 * записям meal_logs, у которых точка ещё не привязана (meal_point_id IS NULL).
 * Обычно это записи, внесённые администратором вручную (массовая проводка,
 * ручной пропуск без указания точки).
 *
 * Ради безопасности обновляются ТОЛЬКО записи без точки — реальные сканы
 * с уже известной точкой этим инструментом не трогаются.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');

checkAuth();
if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступно только администраторам']);
    exit;
}
if (!isAjax()) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Только AJAX']); exit; }
Csrf::guard();

$data     = json_decode(file_get_contents('php://input'), true) ?? [];
$ids      = array_values(array_unique(array_filter(array_map('intval', $data['ids'] ?? []))));
$pointId  = (int)($data['point_id'] ?? 0);
$mealType = $data['meal_type'] ?? null;
if ($mealType !== null && !in_array($mealType, ['breakfast', 'lunch', 'dinner'], true)) {
    $mealType = null;
}

if (!$ids || !$pointId) {
    echo json_encode(['success' => false, 'message' => 'Не выбраны записи или точка']);
    exit;
}

// Не-super_admin может назначать только на свою точку
$userRole    = $_SESSION['role'] ?? '';
$assignedPid = $_SESSION['assigned_point_id'] ?? null;
if ($userRole !== 'super_admin' && $pointId !== (int)$assignedPid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Можно назначать только свою точку']);
    exit;
}

$point = getMealPointById($pdo, $pointId);
if (!$point) {
    echo json_encode(['success' => false, 'message' => 'Точка не найдена']);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE meal_logs SET meal_point_id = ?, meal_point_name = ?"
         . ($mealType !== null ? ", meal_type = ?" : "")
         . " WHERE id IN ($placeholders) AND meal_point_id IS NULL";

    $params = [$pointId, $point['point_name']];
    if ($mealType !== null) $params[] = $mealType;
    $params = array_merge($params, $ids);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $updated = $stmt->rowCount();

    logAction('assign_meal_point', "Назначена точка «{$point['point_name']}» для {$updated} записей" . ($mealType ? " (тип: {$mealType})" : ''));

    echo json_encode(['success' => true, 'updated' => $updated]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
}
