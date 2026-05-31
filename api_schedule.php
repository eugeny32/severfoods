<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'POST' ? 'save' : 'get');

// ── GET: получить расписание точки ──────────────────────
if ($action === 'get' && $method === 'GET') {
    $point_id = intval($_GET['point_id'] ?? 0);
    if (!$point_id) { echo json_encode([]); exit; }

    $stmt = $pdo->prepare(
        "SELECT * FROM meal_point_schedules
         WHERE meal_point_id = ?
         ORDER BY sort_order, start_time"
    );
    $stmt->execute([$point_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── GET: список всех активных точек ─────────────────────
if ($action === 'points' && $method === 'GET') {
    echo json_encode(getMealPoints($pdo, true), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST: сохранить расписание ───────────────────────────
if ($method === 'POST') {
    Csrf::guard();

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        echo json_encode(['success' => false, 'message' => 'Некорректный JSON']); exit;
    }

    $point_id  = intval($body['point_id'] ?? 0);
    $schedules = $body['schedules']        ?? [];

    if (!$point_id) {
        echo json_encode(['success' => false, 'message' => 'Не указана точка питания']); exit;
    }

    $pt = getMealPointById($pdo, $point_id);
    if (!$pt) {
        echo json_encode(['success' => false, 'message' => 'Точка питания не найдена']); exit;
    }

    // Для admin (не super_admin) — проверяем доступ к точке
    $role     = $_SESSION['role'] ?? 'admin';
    $assigned = $_SESSION['assigned_point_id'] ?? null;
    if ($role === 'admin' && $assigned && $assigned != $point_id) {
        echo json_encode(['success' => false, 'message' => 'Нет доступа к этой точке питания']); exit;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM meal_point_schedules WHERE meal_point_id = ?")->execute([$point_id]);

        $stmt = $pdo->prepare(
            "INSERT INTO meal_point_schedules
                 (meal_point_id, meal_type, meal_name_ru, start_time, end_time, days_of_week, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
        );
        $valid_types = ['breakfast','lunch','dinner','night'];
        foreach ($schedules as $i => $s) {
            $mt = $s['meal_type']  ?? '';
            $st = $s['start_time'] ?? '';
            $et = $s['end_time']   ?? '';
            if (!in_array($mt, $valid_types, true) || empty($st) || empty($et)) continue;

            $stmt->execute([
                $point_id, $mt,
                trim($s['meal_name_ru'] ?? '') ?: getMealTypeName($mt),
                $st, $et,
                $s['days_of_week'] ?: '1,2,3,4,5,6,7',
                intval($s['sort_order'] ?? $i),
            ]);
        }
        $pdo->commit();
        logAction('schedule_update', "Расписание точки «{$pt['point_name']}» обновлено");
        echo json_encode(['success' => true, 'message' => "Расписание «{$pt['point_name']}» сохранено"]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Неизвестный запрос']);
