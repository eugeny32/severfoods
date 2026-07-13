<?php
/**
 * Массовая проводка сухого пайка / выездного питания сразу нескольким
 * сотрудникам на указанную дату — аналог api/bulk_pass.php, но пишет в
 * таблицу dry_rations, а не meal_logs (точка питания тут не участвует).
 *
 * Разрешена проводка задним числом (и вперёд — выездное питание часто
 * планируется заранее) — дата сама по себе не ограничена ни в прошлое,
 * ни в будущее (лимит на количество будущих записей — см. ниже).
 *
 * Если на указанную дату у сотрудника УЖЕ отмечены все 3 приёма пищи в
 * столовой (завтрак+обед+ужин, реальные активные записи meal_logs) —
 * сухой паёк/выездное питание для него НЕ проводится (человек и так
 * питался в столовой весь день) — такие сотрудники попадают в отдельный
 * список "конфликт" с пояснением.
 *
 * Если запись на эту дату у сотрудника уже есть (независимо от типа —
 * dry_rations.ration_date уникален на сотрудника, см. uq_emp_date) —
 * попадает в список "уже отмечены ранее", как и в bulk_pass.php.
 *
 * Лимит "не более 4 записей в скользящем окне ±30 дней" (тот же, что и в
 * api/dry_rations.php) применяется ТОЛЬКО когда указанная дата ещё не
 * наступила (в будущем) — задним числом и на сегодня лимита нет.
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
$rationType  = ($data['ration_type'] ?? '') === 'dry_ration' ? 'dry_ration' : (($data['ration_type'] ?? '') === 'field' ? 'field' : '');
$employeeIds = array_values(array_unique(array_filter(array_map('intval', $data['employee_ids'] ?? []))));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Некорректная дата']);
    exit;
}
if (!$rationType) {
    echo json_encode(['success' => false, 'message' => 'Некорректный тип (ожидается сухой паёк или выездное питание)']);
    exit;
}
if (!$employeeIds) {
    echo json_encode(['success' => false, 'message' => 'Не выбраны сотрудники']);
    exit;
}

// Таблица создаётся при необходимости — та же миграция, что и в dry_rations.php.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dry_rations (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        ration_date DATE NOT NULL,
        ration_type VARCHAR(20) NOT NULL DEFAULT 'field',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        cancelled_at DATETIME DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_emp_date (employee_id, ration_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

$namesStmt = $pdo->prepare(
    "SELECT id, full_name FROM employees WHERE id IN (" . implode(',', array_fill(0, count($employeeIds), '?')) . ")"
);
$namesStmt->execute($employeeIds);
$names = [];
foreach ($namesStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['id']] = $r['full_name'];

// Все 3 приёма пищи на указанную местную дату — по фиксированному
// серверному часовому поясу (SERVER_TZ_OFFSET), как и в bulk_pass.php:
// сухой паёк/выездное питание не привязаны к конкретной точке, поэтому
// сравнивать не с чем, кроме стабильного серверного значения.
$mealsCountStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT meal_type) FROM meal_logs
     WHERE employee_id = ? AND access_granted = 1
       AND meal_type IN ('breakfast','lunch','dinner')
       AND DATE(CONVERT_TZ(scanned_at, '+00:00', ?)) = ?"
);

$ins = $pdo->prepare(
    "INSERT IGNORE INTO dry_rations (employee_id, ration_date, ration_type, status, created_by)
     VALUES (?, ?, ?, 'active', ?)"
);

// Лимит на будущие даты — см. комментарий в шапке файла.
$today      = localToday();
$isFuture   = $date > $today;
$statsFrom  = date('Y-m-d', strtotime($today . ' -30 days'));
$statsTo    = date('Y-m-d', strtotime($today . ' +30 days'));
$futureCntStmt = $isFuture ? $pdo->prepare(
    "SELECT COUNT(*) FROM dry_rations
     WHERE employee_id=? AND ration_date BETWEEN ? AND ? AND ration_date > ? AND status='active'"
) : null;

$inserted   = [];
$already    = [];
$conflicted = [];
$limited    = [];

foreach ($employeeIds as $empId) {
    $mealsCountStmt->execute([$empId, SERVER_TZ_OFFSET, $date]);
    if ((int)$mealsCountStmt->fetchColumn() >= 3) {
        $conflicted[] = ['id' => $empId, 'name' => $names[$empId] ?? "#{$empId}"];
        continue;
    }

    if ($isFuture) {
        $futureCntStmt->execute([$empId, $statsFrom, $statsTo, $today]);
        if ((int)$futureCntStmt->fetchColumn() >= 4) {
            $limited[] = ['id' => $empId, 'name' => $names[$empId] ?? "#{$empId}"];
            continue;
        }
    }

    $ins->execute([$empId, $date, $rationType, $_SESSION['user_id'] ?? null]);
    if ($ins->rowCount() > 0) {
        $inserted[] = ['id' => $empId, 'name' => $names[$empId] ?? "#{$empId}"];
    } else {
        $already[] = ['id' => $empId, 'name' => $names[$empId] ?? "#{$empId}"];
    }
}

$typeName = $rationType === 'dry_ration' ? 'Сухой паёк' : 'Выездное питание';
logAction('bulk_dry_ration', "Массовая проводка ({$typeName}, {$date}): проведено " . count($inserted) . ", уже было " . count($already) . ", конфликт (все 3 приёма пищи) " . count($conflicted) . ", лимит будущих дней " . count($limited));

echo json_encode([
    'success'    => true,
    'inserted'   => $inserted,
    'already'    => $already,
    'conflicted' => $conflicted,
    'limited'    => $limited,
], JSON_UNESCAPED_UNICODE);
