<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!isAjax()) {
    http_response_code(400); echo json_encode(['success' => false, 'message' => 'Только AJAX']); exit;
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); echo json_encode(['success' => false, 'message' => 'Не авторизован']); exit;
}

Csrf::guard();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { echo json_encode(['success' => false, 'message' => 'Нет данных']); exit; }

$employee_id = intval($data['employee_id'] ?? 0);
$meal_type   = $data['meal_type'] ?? '';
$reason      = trim($data['reason'] ?? '');

if (!$employee_id || !in_array($meal_type, ['breakfast','lunch','dinner'], true)) {
    echo json_encode(['success' => false, 'message' => 'Некорректные данные']); exit;
}

$emp = getEmployeeById($pdo, $employee_id);
if (!$emp) { echo json_encode(['success' => false, 'message' => 'Сотрудник не найден']); exit; }

$meal_point_id   = $_SESSION['meal_point_id']   ?? null;
$pointTz = getPointTz($pdo, $meal_point_id);
$today   = gmdate('Y-m-d', time() + offsetToMinutes($pointTz) * 60);
$stmt  = $pdo->prepare(
    "SELECT COUNT(*) FROM meal_logs
     WHERE employee_id=? AND meal_type=? AND DATE(CONVERT_TZ(scanned_at, '+00:00', ?))=? AND access_granted=1"
);
$stmt->execute([$employee_id, $meal_type, $pointTz, $today]);
if ($stmt->fetchColumn() > 0) {
    echo json_encode(['success' => false,
        'message' => "{$emp['full_name']} уже питался(ась) сегодня — " . getMealTypeName($meal_type)]);
    exit;
}

$operator_id     = $_SESSION['user_id'];
$operator_name   = $_SESSION['user_name'];
$meal_point_name = $_SESSION['meal_point_name'] ?? null;

try {
    $pdo->prepare(
        "INSERT INTO meal_logs
             (employee_id, meal_type, access_granted, scanner_ip,
              operator_id, operator_name, meal_point_id, meal_point_name, denial_reason)
         VALUES (?, ?, 1, 'manual', ?, ?, ?, ?, ?)"
    )->execute([$employee_id, $meal_type, $operator_id, $operator_name,
                $meal_point_id, $meal_point_name, $reason ?: null]);

    logAction('manual_pass',
        "Ручной пропуск: {$emp['full_name']} — " . getMealTypeName($meal_type) . ($reason ? " ($reason)" : ''));

    // Аннулировать выездное питание на сегодня (отметить красным, не удалять)
    try {
        $pdo->prepare("UPDATE dry_rations SET status='cancelled', cancelled_at=NOW() WHERE employee_id=? AND ration_date=? AND ration_type='field' AND status='active'")
            ->execute([$employee_id, $today]);
    } catch (PDOException $e) {}

    echo json_encode(['success' => true,
        'message'   => "Пропущен вручную: {$emp['full_name']}",
        'employee'  => $emp,
        'meal_type' => $meal_type]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
}
