<?php
/**
 * Разовая миграция: переклассифицирует исторические записи meal_logs
 * с типом 'night' в 'breakfast'/'dinner' по местному времени точки
 * (до полудня — завтрак, после — ужин), см. normalizeMealType().
 * Доступно только супер-администратору.
 *
 * Дополнительно: для записей, сделанных через «Массовую проводку»
 * (scanner_ip = 'bulk') и только для них, время записи (scanned_at)
 * дополнительно переносится на начало расписания точки для нового
 * типа питания на ту же местную дату — так же, как это теперь делает
 * сама массовая проводка (см. api/bulk_pass.php), чтобы старые «ночные»
 * записи от неё не путались со сканером и не давали ложных срабатываний
 * дедупликации. Записи от ручного пропуска, оффлайн-приложения и
 * реального сканирования по времени НЕ трогаем — только их meal_type.
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

try {
    $stmt = $pdo->query(
        "SELECT ml.id, ml.scanned_at, ml.meal_point_id, ml.scanner_ip, mpt.tz_offset
         FROM meal_logs ml
         LEFT JOIN meal_points mpt ON mpt.id = ml.meal_point_id
         WHERE ml.meal_type = 'night'"
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
    $toBreakfast  = 0;
    $toDinner     = 0;
    $retimed      = 0;

    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $tz = (!empty($r['tz_offset']) && preg_match('/^[+-]\d{2}:\d{2}$/', $r['tz_offset'])) ? $r['tz_offset'] : APP_TZ_OFFSET;
        $ts = strtotime($r['scanned_at'] . ' UTC');
        $localTime = gmdate('H:i:s', $ts + offsetToMinutes($tz) * 60);
        $newType = normalizeMealType('night', $localTime);

        if ($r['scanner_ip'] === 'bulk') {
            $localDate = gmdate('Y-m-d', $ts + offsetToMinutes($tz) * 60);
            $startTime = $defaultStart[$newType];
            if ($r['meal_point_id']) {
                $schedStmt->execute([$r['meal_point_id'], $newType]);
                $sched = $schedStmt->fetchColumn();
                if ($sched) $startTime = $sched;
            }
            $localTs   = strtotime("$localDate $startTime UTC") - offsetToMinutes($tz) * 60;
            $scannedAt = gmdate('Y-m-d H:i:s', $localTs);
            $updWithTime->execute([$newType, $scannedAt, $r['id']]);
            $retimed++;
        } else {
            $upd->execute([$newType, $r['id']]);
        }
        if ($newType === 'breakfast') $toBreakfast++; else $toDinner++;
    }
    $pdo->commit();

    logAction('normalize_night_records', "Переклассифицировано ночных записей: {$toBreakfast} → завтрак, {$toDinner} → ужин, из них с переносом времени (массовая проводка): {$retimed}");

    echo json_encode([
        'success'      => true,
        'total'        => count($rows),
        'to_breakfast' => $toBreakfast,
        'to_dinner'    => $toDinner,
        'retimed'      => $retimed,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
}
