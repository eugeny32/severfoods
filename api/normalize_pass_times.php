<?php
/**
 * Разовая миграция: приводит время (scanned_at) ВСЕХ исторических записей,
 * сделанных через «Массовую проводку» (scanner_ip = 'bulk') или «Ручной
 * пропуск» (scanner_ip = 'manual'), к началу периода расписания точки для
 * их типа питания — на ту же местную дату, что и была у записи. Исключает
 * ситуации вида «завтрак 08.07.2026 в 19:00» → становится «завтрак
 * 08.07.2026 в 07:00» (или иное время начала завтрака на конкретной точке).
 *
 * Тип 'night' сначала переклассифицируется в breakfast/dinner по местному
 * времени (см. normalizeMealType()), как и в normalize_night_records.php,
 * а затем тоже получает время начала соответствующего периода.
 *
 * Записи реального сканирования и синхронизации с оффлайн-приложением
 * НЕ трогаются — ни время, ни тип питания.
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

// dry_run=1 — только посчитать, что изменилось бы, без записи в БД
// (кнопка предпросмотра рядом с действием в «Обслуживание БД»).
$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$dryRun = !empty($data['dry_run']);

try {
    $stmt = $pdo->query(
        "SELECT ml.id, ml.scanned_at, ml.meal_type, ml.meal_point_id, ml.scanner_ip, mpt.tz_offset
         FROM meal_logs ml
         LEFT JOIN meal_points mpt ON mpt.id = ml.meal_point_id
         WHERE ml.scanner_ip IN ('bulk', 'manual')"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $upd       = $pdo->prepare("UPDATE meal_logs SET meal_type = ?, scanned_at = ? WHERE id = ?");
    $schedStmt = $pdo->prepare(
        "SELECT start_time FROM meal_point_schedules
         WHERE meal_point_id = ? AND meal_type = ? AND is_active = 1
         ORDER BY sort_order LIMIT 1"
    );
    $defaultStart = ['breakfast' => '07:00:00', 'lunch' => '12:00:00', 'dinner' => '18:00:00'];

    $retimed  = 0;
    $unchanged = 0;
    $byType   = ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0];

    if (!$dryRun) $pdo->beginTransaction();
    foreach ($rows as $r) {
        $tz = (!empty($r['tz_offset']) && preg_match('/^[+-]\d{2}:\d{2}$/', $r['tz_offset'])) ? $r['tz_offset'] : APP_TZ_OFFSET;
        $ts = strtotime($r['scanned_at'] . ' UTC');
        $localTime = gmdate('H:i:s', $ts + offsetToMinutes($tz) * 60);
        $localDate = gmdate('Y-m-d', $ts + offsetToMinutes($tz) * 60);

        $newType = $r['meal_type'] === 'night' ? normalizeMealType('night', $localTime) : $r['meal_type'];
        if (!isset($defaultStart[$newType])) continue; // на случай неизвестного типа — не трогаем

        $startTime = $defaultStart[$newType];
        if ($r['meal_point_id']) {
            $schedStmt->execute([$r['meal_point_id'], $newType]);
            $sched = $schedStmt->fetchColumn();
            if ($sched) $startTime = $sched;
        }
        $localTs   = strtotime("$localDate $startTime UTC") - offsetToMinutes($tz) * 60;
        $scannedAt = gmdate('Y-m-d H:i:s', $localTs);

        if ($scannedAt === $r['scanned_at'] && $newType === $r['meal_type']) {
            $unchanged++;
            continue;
        }

        if (!$dryRun) $upd->execute([$newType, $scannedAt, $r['id']]);
        $retimed++;
        $byType[$newType]++;
    }
    if (!$dryRun) $pdo->commit();

    if (!$dryRun) {
        logAction('normalize_pass_times', "Нормализовано время массовых/ручных записей: {$retimed} изменено (завтрак {$byType['breakfast']}, обед {$byType['lunch']}, ужин {$byType['dinner']}), {$unchanged} уже были корректны");
    }

    echo json_encode([
        'success'   => true,
        'dry_run'   => $dryRun,
        'total'     => count($rows),
        'retimed'   => $retimed,
        'unchanged' => $unchanged,
        'by_type'   => $byType,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
}
