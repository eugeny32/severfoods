<?php
/**
 * API: журнал питания за текущий день.
 * Требует авторизации.
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}

// Каждая точка считает "сегодня" по своему часовому поясу (meal_points.tz_offset),
// с запасом 2 часа с хвоста предыдущих местных суток — см. pointTodayWindow().
$rows = [];
try {
    $points   = $pdo->query("SELECT id, tz_offset FROM meal_points WHERE is_active = 1")->fetchAll();
    $byOffset = [];
    foreach ($points as $p) {
        $tz = (!empty($p['tz_offset']) && preg_match('/^[+-]\d{2}:\d{2}$/', $p['tz_offset'])) ? $p['tz_offset'] : APP_TZ_OFFSET;
        $byOffset[$tz][] = (int)$p['id'];
    }
    if (!$byOffset) $byOffset[APP_TZ_OFFSET] = [];

    foreach ($byOffset as $tz => $pointIds) {
        [$start, $end] = pointTodayWindow($tz);
        if ($pointIds) {
            $ph   = implode(',', array_fill(0, count($pointIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT employee_id, meal_type, scanned_at, meal_point_id
                 FROM meal_logs
                 WHERE meal_point_id IN ($ph) AND scanned_at BETWEEN ? AND ? AND access_granted = 1"
            );
            $stmt->execute([...$pointIds, $start, $end]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT employee_id, meal_type, scanned_at, meal_point_id
                 FROM meal_logs
                 WHERE meal_point_id IS NULL AND scanned_at BETWEEN ? AND ? AND access_granted = 1"
            );
            $stmt->execute([$start, $end]);
        }
        $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    usort($rows, fn($a, $b) => strcmp($b['scanned_at'], $a['scanned_at']));
} catch (PDOException $e) {}

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
