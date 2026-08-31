<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode([]); exit; }

$role     = $_SESSION['role']              ?? 'operator';
$is_super = $role === 'super_admin';
$is_admin = $_SESSION['is_admin']          ?? false;
$mp_id    = $_SESSION['meal_point_id']     ?? null;
$ap_id    = $_SESSION['assigned_point_id'] ?? null;

if ($is_super) {
    $stats = getTodayStats($pdo);
} elseif ($is_admin && $ap_id) {
    $stats = getPointTodayStats($pdo, $ap_id);
} else {
    $stats = $mp_id ? getPointTodayStats($pdo, $mp_id) : getTodayStats($pdo);
}

// Убедимся что все ключи присутствуют
$stats = array_merge(['total'=>0,'breakfast'=>0,'lunch'=>0,'dinner'=>0,'night'=>0], $stats);

// Текущий приём пищи считаем ЗДЕСЬ, по расписанию и часовому поясу точки, и
// отдаём в шапку страницы: иначе тип оставался бы тем, каким был в момент
// открытия страницы, и смена завтрака на обед была бы видна только после
// перезагрузки. Правило одно на всю систему — getCurrentMealType().
$point_id   = $mp_id ?: $ap_id;
$meal_type  = getCurrentMealType($pdo, $point_id);
$stats['meal_type'] = $meal_type;
$stats['meal_name'] = getMealTypeName($meal_type);
$stats['meal_icon'] = getMealTypeIcon($meal_type);
$stats['tz_offset'] = $point_id ? getPointTz($pdo, $point_id) : SERVER_TZ_OFFSET;

// Часы и тип питания по каждой точке — тот же набор, что страница получила при
// открытии (см. $clock_points в index.php). Пояс тоже отдаём: его могли
// поправить в карточке точки, и часы должны подхватить это без перезагрузки.
$stats['clocks'] = [];
foreach ($point_id
            ? array_filter([getMealPointById($pdo, (int)$point_id)])
            : getMealPoints($pdo, true) as $cp) {
    $mt = getCurrentMealType($pdo, (int)$cp['id']);
    $stats['clocks'][] = [
        'id'        => (int)$cp['id'],
        'tz'        => getPointTz($pdo, (int)$cp['id']),
        'meal_type' => $mt,
        'meal_name' => getMealTypeName($mt),
        'meal_icon' => getMealTypeIcon($mt),
    ];
}

echo json_encode($stats);
