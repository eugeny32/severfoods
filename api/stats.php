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

echo json_encode($stats);
