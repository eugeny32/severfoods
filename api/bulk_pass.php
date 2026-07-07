<?php
/**
 * Массовая проводка сотрудников на указанную дату (без сканирования QR).
 * Используется, когда нужно задним числом или пакетно отметить приём пищи
 * группе сотрудников — например, при сбое сканера или для целой бригады.
 *
 * Запись создаётся БЕЗ привязки к точке питания (meal_point_id = NULL) —
 * назначить точку и, при необходимости, поменять тип питания можно позже
 * в разделе «Отчёты» (см. api/assign_meal_point.php).
 *
 * Если на указанную дату для сотрудника уже есть активная запись с этим
 * типом питания — новая запись не создаётся, сотрудник попадает в список
 * "уже отмечены ранее".
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

$data        = json_decode(file_get_contents('php://input'), true) ?? [];
$date        = preg_replace('/[^0-9\-]/', '', $data['date'] ?? '');
$mealType    = $data['meal_type'] ?? '';
$employeeIds = array_values(array_unique(array_filter(array_map('intval', $data['employee_ids'] ?? []))));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Некорректная дата']);
    exit;
}
if (!in_array($mealType, ['breakfast', 'lunch', 'dinner'], true)) {
    echo json_encode(['success' => false, 'message' => 'Некорректный тип питания']);
    exit;
}
if (!$employeeIds) {
    echo json_encode(['success' => false, 'message' => 'Не выбраны сотрудники']);
    exit;
}
if ($date > localToday()) {
    echo json_encode(['success' => false, 'message' => 'Нельзя проводить будущей датой']);
    exit;
}

$operatorId   = $_SESSION['user_id']   ?? null;
$operatorName = $_SESSION['user_name'] ?? 'Администратор';

// Уже существующие активные записи этого типа на эту дату
$placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
$stmt = $pdo->prepare(
    "SELECT employee_id FROM meal_logs
     WHERE meal_type = ? AND DATE(scanned_at) = ? AND access_granted = 1
       AND employee_id IN ($placeholders)"
);
$stmt->execute(array_merge([$mealType, $date], $employeeIds));
$already = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
$alreadySet = array_flip($already);

$toInsert = array_values(array_diff($employeeIds, $already));

// Имена сотрудников для ответа
$namesStmt = $pdo->prepare("SELECT id, full_name FROM employees WHERE id IN ($placeholders)");
$namesStmt->execute($employeeIds);
$names = [];
foreach ($namesStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['id']] = $r['full_name'];

$inserted = [];
if ($toInsert) {
    // Фиксированное время в середине суток — запись административная, без
    // привязки к реальному времени скана и часовому поясу конкретной точки.
    $scannedAt = $date . ' 12:00:00';
    $ins = $pdo->prepare(
        "INSERT INTO meal_logs
             (employee_id, meal_type, access_granted, scanner_ip,
              operator_id, operator_name, meal_point_id, meal_point_name, scanned_at)
         VALUES (?, ?, 1, 'bulk', ?, ?, NULL, NULL, ?)"
    );
    foreach ($toInsert as $empId) {
        $ins->execute([$empId, $mealType, $operatorId, $operatorName, $scannedAt]);
        $inserted[] = ['id' => $empId, 'name' => $names[$empId] ?? "#{$empId}"];
    }
}

$alreadyList = array_map(fn($id) => ['id' => $id, 'name' => $names[$id] ?? "#{$id}"], $already);

logAction('bulk_pass', "Массовая проводка ({$mealType}, {$date}): проведено " . count($inserted) . ", уже было " . count($alreadyList));

echo json_encode([
    'success'  => true,
    'inserted' => array_values($inserted),
    'already'  => array_values($alreadyList),
], JSON_UNESCAPED_UNICODE);
