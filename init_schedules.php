<?php
/**
 * Скрипт инициализации расписания для всех точек питания.
 * Запустить один раз: https://your-domain.com/init_schedules.php?token=INIT_2024
 * После запуска — УДАЛИТЬ файл.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$token = $_GET['token'] ?? '';
if ($token !== 'INIT_2024') {
    http_response_code(403);
    die('<h2>Forbidden. Укажите ?token=INIT_2024</h2>');
}

$points = getMealPoints($pdo, false); // все точки включая неактивные

$defaultSchedule = [
    ['meal_type'=>'breakfast','meal_name_ru'=>'Завтрак',       'start_time'=>'07:00','end_time'=>'10:00','days_of_week'=>'1,2,3,4,5,6,7','sort_order'=>1],
    ['meal_type'=>'lunch',    'meal_name_ru'=>'Обед',          'start_time'=>'12:00','end_time'=>'15:00','days_of_week'=>'1,2,3,4,5,6,7','sort_order'=>2],
    ['meal_type'=>'dinner',   'meal_name_ru'=>'Ужин',          'start_time'=>'18:00','end_time'=>'21:00','days_of_week'=>'1,2,3,4,5,6,7','sort_order'=>3],
    ['meal_type'=>'night',    'meal_name_ru'=>'Ночное питание','start_time'=>'23:00','end_time'=>'06:00','days_of_week'=>'1,2,3,4,5,6,7','sort_order'=>4],
];

$results = [];
$stmt = $pdo->prepare(
    "INSERT INTO meal_point_schedules
         (meal_point_id, meal_type, meal_name_ru, start_time, end_time, days_of_week, sort_order, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
);

foreach ($points as $pt) {
    // Проверяем есть ли уже расписание
    $check = $pdo->prepare("SELECT COUNT(*) FROM meal_point_schedules WHERE meal_point_id = ?");
    $check->execute([$pt['id']]);
    $existing = (int)$check->fetchColumn();

    if ($existing > 0) {
        $results[] = "⚠️ Точка «{$pt['point_name']}» — уже есть {$existing} записей, пропущено";
        continue;
    }

    foreach ($defaultSchedule as $s) {
        $stmt->execute([
            $pt['id'],
            $s['meal_type'],
            $s['meal_name_ru'],
            $s['start_time'],
            $s['end_time'],
            $s['days_of_week'],
            $s['sort_order'],
        ]);
    }
    $results[] = "✅ Точка «{$pt['point_name']}» — добавлено 4 приёма пищи";
}

logAction('init_schedules', 'Инициализация расписания для всех точек');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Инициализация расписания</title>
<style>
body{font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px}
h2{color:#003366}
li{padding:6px 0;font-size:15px;border-bottom:1px solid #eee}
.done{margin-top:20px;padding:12px 20px;background:#d1fae5;border-radius:8px;color:#065f46;font-weight:600}
a{color:#003366}
</style>
</head>
<body>
<h2>🍽️ Инициализация расписания</h2>
<ul>
<?php foreach ($results as $r): ?>
    <li><?= htmlspecialchars($r) ?></li>
<?php endforeach; ?>
</ul>
<div class="done">
    ✅ Готово! Теперь <strong>удалите</strong> этот файл с сервера.<br><br>
    <a href="index.php">← На главную</a>
</div>
</body>
</html>
