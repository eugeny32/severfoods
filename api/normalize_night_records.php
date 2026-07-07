<?php
/**
 * Разовая миграция: переклассифицирует исторические записи meal_logs
 * с типом 'night' в 'breakfast'/'dinner' по местному времени точки
 * (до полудня — завтрак, после — ужин), см. normalizeMealType().
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

try {
    $stmt = $pdo->query(
        "SELECT ml.id, ml.scanned_at, ml.meal_point_id, mpt.tz_offset
         FROM meal_logs ml
         LEFT JOIN meal_points mpt ON mpt.id = ml.meal_point_id
         WHERE ml.meal_type = 'night'"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("UPDATE meal_logs SET meal_type = ? WHERE id = ?");
    $toBreakfast = 0;
    $toDinner    = 0;

    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $tz = (!empty($r['tz_offset']) && preg_match('/^[+-]\d{2}:\d{2}$/', $r['tz_offset'])) ? $r['tz_offset'] : APP_TZ_OFFSET;
        $ts = strtotime($r['scanned_at'] . ' UTC');
        $localTime = gmdate('H:i:s', $ts + offsetToMinutes($tz) * 60);
        $newType = normalizeMealType('night', $localTime);
        $upd->execute([$newType, $r['id']]);
        if ($newType === 'breakfast') $toBreakfast++; else $toDinner++;
    }
    $pdo->commit();

    logAction('normalize_night_records', "Переклассифицировано ночных записей: {$toBreakfast} → завтрак, {$toDinner} → ужин");

    echo json_encode([
        'success'      => true,
        'total'        => count($rows),
        'to_breakfast' => $toBreakfast,
        'to_dinner'    => $toDinner,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
}
