<?php
/**
 * Предварительная проверка перед массовой проводкой: для указанной даты,
 * типа питания и списка сотрудников возвращает тех, у кого уже ЕСТЬ
 * активная запись этого типа на эту местную дату (по часовому поясу
 * точки) — ничего не создаёт и не меняет. Используется UI для
 * предупреждения администратора до нажатия «Провести выбранных».
 *
 * Если у сотрудника уже несколько дублирующихся записей на этот приём
 * пищи (например, скан + ручная проводка) — SELECT DISTINCT считает их
 * одной записью, а не несколькими.
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

$data        = json_decode(file_get_contents('php://input'), true) ?? [];
$date        = preg_replace('/[^0-9\-]/', '', $data['date'] ?? '');
$mealType    = $data['meal_type'] ?? '';
$employeeIds = array_values(array_unique(array_filter(array_map('intval', $data['employee_ids'] ?? []))));
$pointId     = !empty($data['point_id']) ? (int)$data['point_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($mealType, ['breakfast', 'lunch', 'dinner'], true) || !$employeeIds) {
    echo json_encode(['success' => true, 'already' => []]);
    exit;
}

$pointTz = SERVER_TZ_OFFSET;
if ($pointId) {
    $point = getMealPointById($pdo, $pointId);
    if ($point) $pointTz = getPointTz($pdo, $pointId);
}

$placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
$stmt = $pdo->prepare(
    "SELECT DISTINCT employee_id FROM meal_logs
     WHERE meal_type = ? AND DATE(CONVERT_TZ(scanned_at, '+00:00', ?)) = ? AND access_granted = 1
       AND employee_id IN ($placeholders)"
);
$stmt->execute(array_merge([$mealType, $pointTz, $date], $employeeIds));
$already = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$names = [];
if ($already) {
    $ph2 = implode(',', array_fill(0, count($already), '?'));
    $namesStmt = $pdo->prepare("SELECT id, full_name FROM employees WHERE id IN ($ph2)");
    $namesStmt->execute($already);
    foreach ($namesStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['id']] = $r['full_name'];
}

$alreadyList = array_map(fn($id) => ['id' => $id, 'name' => $names[$id] ?? "#{$id}"], $already);

echo json_encode(['success' => true, 'already' => array_values($alreadyList)], JSON_UNESCAPED_UNICODE);
