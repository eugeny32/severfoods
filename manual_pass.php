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

$operator_id     = $_SESSION['user_id'];
$operator_name   = $_SESSION['user_name'];
$meal_point_name = $_SESSION['meal_point_name'] ?? null;

// Лок на время проверки+вставки — исключает дубль, если сотрудника проводят
// вручную одновременно с реальным сканированием или другим админом.
$locked = acquireMealLock($pdo, $employee_id);
if (!$locked) {
    echo json_encode(['success' => false, 'message' => 'Система обрабатывает предыдущий запрос, повторите']);
    exit;
}

// exit() не запускает finally в PHP — поэтому внутри блокировки не выходим
// напрямую, а копим результат в $response и делаем echo+exit уже после
// гарантированного releaseMealLock().
$response = null;
try {
    if (hasExistingMealLog($pdo, $employee_id, $meal_type, $meal_point_id, $today)) {
        $response = ['success' => false,
            'message' => "{$emp['full_name']} уже питался(ась) сегодня — " . getMealTypeName($meal_type)];
    } else {
        // Время записи назначается на НАЧАЛО расписания точки для этого типа
        // питания (местное время точки → UTC) — как и в массовой проводке
        // (см. api/bulk_pass.php), а не текущий момент вставки. Исключает
        // попадание записи вне окна приёма пищи при ручном пропуске.
        $defaultStart = ['breakfast' => '07:00:00', 'lunch' => '12:00:00', 'dinner' => '18:00:00'][$meal_type];
        $startTime = $defaultStart;
        if ($meal_point_id) {
            $schedStmt = $pdo->prepare(
                "SELECT start_time FROM meal_point_schedules
                 WHERE meal_point_id = ? AND meal_type = ? AND is_active = 1
                 ORDER BY sort_order LIMIT 1"
            );
            $schedStmt->execute([$meal_point_id, $meal_type]);
            $sched = $schedStmt->fetchColumn();
            if ($sched) $startTime = $sched;
        }
        $localTs   = strtotime("$today $startTime UTC") - offsetToMinutes($pointTz) * 60;
        $scannedAt = gmdate('Y-m-d H:i:s', $localTs);

        $pdo->prepare(
            "INSERT INTO meal_logs
                 (employee_id, meal_type, access_granted, scanner_ip,
                  operator_id, operator_name, meal_point_id, meal_point_name, denial_reason, scanned_at)
             VALUES (?, ?, 1, 'manual', ?, ?, ?, ?, ?, ?)"
        )->execute([$employee_id, $meal_type, $operator_id, $operator_name,
                    $meal_point_id, $meal_point_name, $reason ?: null, $scannedAt]);

        logAction('manual_pass',
            "Ручной пропуск: {$emp['full_name']} — " . getMealTypeName($meal_type) . ($reason ? " ($reason)" : ''));

        // Аннулировать выездное питание на сегодня (отметить красным, не удалять)
        try {
            $pdo->prepare("UPDATE dry_rations SET status='cancelled', cancelled_at=NOW() WHERE employee_id=? AND ration_date=? AND ration_type='field' AND status='active'")
                ->execute([$employee_id, $today]);
        } catch (PDOException $e) {}

        $response = ['success' => true,
            'message'   => "Пропущен вручную: {$emp['full_name']}",
            'employee'  => $emp,
            'meal_type' => $meal_type];
    }
} catch (PDOException $e) {
    $response = ['success' => false, 'message' => 'Ошибка базы данных'];
} finally {
    releaseMealLock($pdo, $employee_id);
}

echo json_encode($response);
