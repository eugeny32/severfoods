<?php
/**
 * API: офлайн-синхронизация записей о питании.
 * Принимает авторизованную сессию ИЛИ API-токен (QR-код оператора).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';

header('Content-Type: application/json');

// ─── Авторизация ──────────────────────────────────────
$apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
$authed   = isset($_SESSION['user_id']);

if (!$authed && $apiToken !== '') {
    $stmt = $pdo->prepare(
        "SELECT id FROM employees
         WHERE qr_code = ? AND is_active = 1
           AND role IN ('operator','admin','super_admin')"
    );
    $stmt->execute([$apiToken]);
    $authed = (bool)$stmt->fetch();
}

if (!$authed) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

// ─── Парсинг тела ─────────────────────────────────────
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// ─── Валидация ────────────────────────────────────────
$employee_id = intval($data['employee_id'] ?? 0);
$meal_type   = $data['meal_type'] ?? '';
$valid_types = ['breakfast', 'lunch', 'dinner', 'night'];

if ($employee_id <= 0 || !in_array($meal_type, $valid_types, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'employee_id and valid meal_type are required']);
    exit;
}

// Проверяем существование сотрудника
$stmt = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND is_active = 1");
$stmt->execute([$employee_id]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Employee not found']);
    exit;
}

// ─── Запись ───────────────────────────────────────────
$scannedAt = isset($data['scanned_at']) ? intval($data['scanned_at']) : (time() * 1000);

// 'night' в базе не хранится — переклассифицируем по местному времени точки.
$pointIdForType  = isset($data['meal_point_id']) ? intval($data['meal_point_id']) : null;
$pointTzForType  = $pointIdForType ? getPointTz($pdo, $pointIdForType) : APP_TZ_OFFSET;
$localTimeAtScan = gmdate('H:i:s', intdiv($scannedAt, 1000) + offsetToMinutes($pointTzForType) * 60);
$meal_type       = normalizeMealType($meal_type, $localTimeAtScan);

$pdo->prepare(
    "INSERT INTO meal_logs
         (employee_id, meal_type, access_granted, denial_reason,
          scanner_ip, operator_id, operator_name,
          meal_point_id, meal_point_name, scanned_at)
     VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?/1000))"
)->execute([
    $employee_id,
    $meal_type,
    $data['denial_reason']   ?? null,
    $data['scanner_ip']      ?? 'offline',
    isset($data['operator_id']) ? intval($data['operator_id']) : null,
    $data['operator_name']   ?? 'Синхронизация',
    isset($data['meal_point_id']) ? intval($data['meal_point_id']) : null,
    $data['meal_point_name'] ?? null,
    $scannedAt,
]);

echo json_encode(['id' => (int)$pdo->lastInsertId(), 'success' => true]);
