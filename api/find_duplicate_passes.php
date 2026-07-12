<?php
/**
 * Поиск дублирующихся записей приёма пищи: один и тот же сотрудник,
 * один и тот же тип питания, одна и та же МЕСТНАЯ дата (по часовому
 * поясу точки записи) — но несколько записей meal_logs.
 * Типичный случай: сотрудника провели вручную/массово, хотя он уже
 * реально прошёл через сканер в этот же приём пищи (или наоборот).
 *
 * Возвращает список групп дублей с признаком recommended_delete —
 * true для записи, сделанной вручную/массово (scanner_ip = 'manual'
 * или 'bulk'), если в той же группе есть хотя бы одна запись
 * реального сканирования (scanner_ip NULL/IP, не bulk/manual/offline).
 * Оффлайн-записи (scanner_ip = 'offline') в авторекомендацию не
 * помечаются — только показываются в группе для ручной проверки.
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

$scannedLocal = "CONVERT_TZ(ml.scanned_at, '+00:00', COALESCE(mpt.tz_offset, '" . APP_TZ_OFFSET . "'))";

$keysStmt = $pdo->query(
    "SELECT ml.employee_id, ml.meal_type, DATE($scannedLocal) AS local_date, COUNT(*) AS cnt
     FROM meal_logs ml
     LEFT JOIN meal_points mpt ON mpt.id = ml.meal_point_id
     WHERE ml.access_granted = 1
     GROUP BY ml.employee_id, ml.meal_type, local_date
     HAVING cnt > 1"
);
$keys = $keysStmt->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
if ($keys) {
    $rowsStmt = $pdo->prepare(
        "SELECT ml.id, ml.scanned_at, $scannedLocal AS scanned_local, ml.meal_type, ml.scanner_ip,
                ml.operator_name, ml.meal_point_name, ml.employee_id, e.full_name
         FROM meal_logs ml
         JOIN employees e ON e.id = ml.employee_id
         LEFT JOIN meal_points mpt ON mpt.id = ml.meal_point_id
         WHERE ml.access_granted = 1
           AND ml.employee_id = ? AND ml.meal_type = ? AND DATE($scannedLocal) = ?
         ORDER BY ml.scanned_at"
    );
    foreach ($keys as $k) {
        $rowsStmt->execute([$k['employee_id'], $k['meal_type'], $k['local_date']]);
        $members = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasRealScan = false;
        foreach ($members as $m) {
            if (!in_array($m['scanner_ip'], ['bulk', 'manual', 'offline'], true)) { $hasRealScan = true; break; }
        }
        foreach ($members as &$m) {
            $m['recommended_delete'] = $hasRealScan && $m['scanner_ip'] === 'manual';
        }
        unset($m);

        $groups[] = [
            'employee_id' => (int)$k['employee_id'],
            'full_name'   => $members[0]['full_name'],
            'meal_type'   => $k['meal_type'],
            'local_date'  => $k['local_date'],
            'has_real_scan' => $hasRealScan,
            'members'     => $members,
        ];
    }
}

echo json_encode(['success' => true, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
