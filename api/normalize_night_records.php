<?php
/**
 * Разовая миграция: приводит meal_type исторических записей meal_logs в
 * соответствие с фактическим местным временем записи и расписанием точки
 * — не только для 'night' (как раньше), но и для breakfast/lunch/dinner:
 * если запись типа «Обед» по факту попадает в окно расписания «Ужин» на
 * своей точке (или наоборот) — тип переклассифицируется.
 *
 * Определение корректного типа:
 *  - если у записи есть привязанная точка — ищем среди её активных
 *    расписаний (meal_point_schedules) окно, в которое попадает местное
 *    время записи (с учётом окон, переходящих через полночь);
 *  - если точки нет — используем те же дефолтные окна, что и
 *    getCurrentMealType() без точки (завтрак 07–11, обед 12–15,
 *    ужин 18–21, ночь 23–06);
 *  - если найденное окно само имеет тип 'night' (устаревшее расписание) —
 *    доводим до breakfast/dinner через normalizeMealType() (до полудня —
 *    завтрak, после — ужин), как и раньше;
 *  - если ни одно окно не подошло (запись реально «вне графика») —
 *    тип НЕ трогаем, чтобы не гадать: такие записи видно в отчётах по
 *    бейджу «вне графика» и фильтру «Только вне графика столовой».
 *
 * Время записи (scanned_at) дополнительно переносится на начало периода
 * нового типа ТОЛЬКО для записей «Массовой проводки» (scanner_ip = 'bulk')
 * — как и раньше. Ручной пропуск, оффлайн-приложение и реальное
 * сканирование сохраняют своё время без изменений — меняется только
 * meal_type.
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

// Дефолтные окна для записей без привязанной точки — те же, что в
// getCurrentMealType() без $meal_point_id.
function defaultScheduleType(string $localTime): ?string
{
    if ($localTime >= '07:00:00' && $localTime < '11:00:00') return 'breakfast';
    if ($localTime >= '12:00:00' && $localTime < '15:00:00') return 'lunch';
    if ($localTime >= '18:00:00' && $localTime < '21:00:00') return 'dinner';
    if ($localTime >= '23:00:00' || $localTime < '06:00:00') return 'night';
    return null;
}

try {
    $stmt = $pdo->query(
        "SELECT ml.id, ml.scanned_at, ml.meal_type, ml.meal_point_id, ml.scanner_ip, mpt.tz_offset
         FROM meal_logs ml
         LEFT JOIN meal_points mpt ON mpt.id = ml.meal_point_id
         WHERE ml.access_granted = 1
           AND ml.meal_type IN ('night', 'breakfast', 'lunch', 'dinner')"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $upd         = $pdo->prepare("UPDATE meal_logs SET meal_type = ? WHERE id = ?");
    $updWithTime = $pdo->prepare("UPDATE meal_logs SET meal_type = ?, scanned_at = ? WHERE id = ?");
    $schedStmt   = $pdo->prepare(
        "SELECT start_time FROM meal_point_schedules
         WHERE meal_point_id = ? AND meal_type = ? AND is_active = 1
         ORDER BY sort_order LIMIT 1"
    );
    $defaultStart = ['breakfast' => '07:00:00', 'lunch' => '12:00:00', 'dinner' => '18:00:00'];

    $pointSchedules = []; // meal_point_id => [ [start_time,end_time,days_of_week,meal_type], ... ]

    $changed = ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0];
    $retimed = 0;
    $skipped = 0; // вне графика — тип не определён однозначно

    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $tz        = (!empty($r['tz_offset']) && preg_match('/^[+-]\d{2}:\d{2}$/', $r['tz_offset'])) ? $r['tz_offset'] : APP_TZ_OFFSET;
        $ts        = strtotime($r['scanned_at'] . ' UTC');
        $localTime = gmdate('H:i:s', $ts + offsetToMinutes($tz) * 60);
        $localDate = gmdate('Y-m-d', $ts + offsetToMinutes($tz) * 60);
        $weekday   = gmdate('N', $ts + offsetToMinutes($tz) * 60);

        $scheduleType = null;
        if ($r['meal_point_id']) {
            if (!isset($pointSchedules[$r['meal_point_id']])) {
                $ss = $pdo->prepare(
                    "SELECT start_time, end_time, days_of_week, meal_type FROM meal_point_schedules
                     WHERE meal_point_id = ? AND is_active = 1"
                );
                $ss->execute([$r['meal_point_id']]);
                $pointSchedules[$r['meal_point_id']] = $ss->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($pointSchedules[$r['meal_point_id']] as $s) {
                if (strpos(',' . $s['days_of_week'] . ',', ',' . $weekday . ',') === false) continue;
                $start = $s['start_time']; $end = $s['end_time'];
                $inWindow = ($end < $start)
                    ? ($localTime >= $start || $localTime < $end)
                    : ($localTime >= $start && $localTime < $end);
                if ($inWindow) { $scheduleType = $s['meal_type']; break; }
            }
        } else {
            $scheduleType = defaultScheduleType($localTime);
        }

        if ($scheduleType === null) { $skipped++; continue; } // вне графика — не гадаем

        $newType = $scheduleType === 'night' ? normalizeMealType('night', $localTime) : $scheduleType;
        if (!isset($defaultStart[$newType])) { $skipped++; continue; }

        if ($newType === $r['meal_type']) continue; // уже верный тип — трогать нечего

        if ($r['scanner_ip'] === 'bulk') {
            $startTime = $defaultStart[$newType];
            $schedStmt->execute([$r['meal_point_id'], $newType]);
            $sched = $schedStmt->fetchColumn();
            if ($sched) $startTime = $sched;
            $localTs   = strtotime("$localDate $startTime UTC") - offsetToMinutes($tz) * 60;
            $scannedAt = gmdate('Y-m-d H:i:s', $localTs);
            $updWithTime->execute([$newType, $scannedAt, $r['id']]);
            $retimed++;
        } else {
            $upd->execute([$newType, $r['id']]);
        }
        $changed[$newType]++;
    }
    $pdo->commit();

    $total = $changed['breakfast'] + $changed['lunch'] + $changed['dinner'];
    logAction('normalize_night_records', "Переклассифицировано записей по расписанию: {$total} (завтрак {$changed['breakfast']}, обед {$changed['lunch']}, ужин {$changed['dinner']}), из них с переносом времени (массовая проводка): {$retimed}, пропущено (вне графика): {$skipped}");

    echo json_encode([
        'success'  => true,
        'total'    => count($rows),
        'changed'  => $total,
        'by_type'  => $changed,
        'retimed'  => $retimed,
        'skipped'  => $skipped,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
}
