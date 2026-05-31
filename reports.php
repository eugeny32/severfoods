<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

$user_role      = $_SESSION['role']             ?? 'admin';
$user_name      = $_SESSION['user_name']        ?? 'Пользователь';
$is_super_admin = $user_role === 'super_admin';
$assigned_pid   = $_SESSION['assigned_point_id'] ?? null;

$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$meal_type  = $_GET['meal_type']  ?? 'all';
$export     = $_GET['export']     ?? null;
$point_id   = isset($_GET['point_id']) && $_GET['point_id'] !== '' ? (int)$_GET['point_id'] : null;

// Доступные точки
$points = [];
if ($is_super_admin) {
    $points = getMealPoints($pdo);
    $filter_point_id = $point_id;
} elseif ($assigned_pid) {
    $pt = getMealPointById($pdo, $assigned_pid);
    if ($pt) $points = [$pt];
    $filter_point_id = $assigned_pid;
} else {
    $filter_point_id = null;
}

// Запрос
$sql = "SELECT ml.id, ml.scanned_at, e.full_name, e.organization, e.department,
               e.vjg_type, e.price, ml.meal_type, ml.scanner_ip,
               ml.operator_name, ml.meal_point_name
        FROM meal_logs ml
        JOIN employees e ON ml.employee_id = e.id
        WHERE DATE(ml.scanned_at) BETWEEN :start AND :end
          AND ml.access_granted = 1";
$params = [':start' => $start_date, ':end' => $end_date];

if ($meal_type !== 'all') { $sql .= " AND ml.meal_type = :mt"; $params[':mt'] = $meal_type; }
if ($filter_point_id)     { $sql .= " AND ml.meal_point_id = :pid"; $params[':pid'] = $filter_point_id; }
$sql .= " ORDER BY ml.scanned_at DESC";

$stmt = $pdo->prepare($sql); $stmt->execute($params);
$logs = $stmt->fetchAll();

// CSV убран — используем Excel (export_excel.php)

// Статистика
$by_type  = ['breakfast'=>0,'lunch'=>0,'dinner'=>0,'night'=>0,'total'=>0];
$by_point = [];
$by_org   = [];
$by_date  = [];

foreach ($logs as $log) {
    $by_type[$log['meal_type']] = ($by_type[$log['meal_type']] ?? 0) + 1;
    $by_type['total']++;
    $pt = $log['meal_point_name'] ?? 'Не указана';
    $by_point[$pt] = ($by_point[$pt] ?? 0) + 1;
    $org = $log['organization'];
    $by_org[$org] = ($by_org[$org] ?? 0) + 1;
    $d = date('d.m', strtotime($log['scanned_at']));
    $by_date[$d] = ($by_date[$d] ?? 0) + 1;
}
arsort($by_point); arsort($by_org);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Отчёты — <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
<style>
.report-header {
    background: var(--blue-800);
    color: white;
    padding: 16px 24px;
    display: flex; align-items: center; gap: 16px;
    flex-wrap: wrap;
}
.report-header h1 { font-size: 20px; font-weight: 800; }
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px; margin-bottom: 24px;
}
.summary-card {
    background: white; border-radius: var(--radius);
    padding: 16px; border: 1px solid var(--border);
    text-align: center;
}
.summary-card .num { font-size: 32px; font-weight: 800; color: var(--blue-800); font-variant-numeric: tabular-nums; }
.summary-card .lbl { font-size: 11px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }
.summary-card.total .num { color: var(--blue-600); }
.filter-bar {
    background: white; border-radius: var(--radius-lg);
    padding: 20px; margin-bottom: 20px;
    border: 1px solid var(--border); box-shadow: var(--shadow);
    display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;
}
.filter-bar .form-group { flex: 1; min-width: 130px; }
.split2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
@media (max-width: 700px) { .split2 { grid-template-columns: 1fr; } }
.by-list { display: flex; flex-direction: column; gap: 6px; }
.by-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: var(--bg); border-radius: 8px; }
.by-name { flex: 1; font-size: 13px; font-weight: 600; color: var(--text-2); }
.by-bar-wrap { flex: 2; height: 14px; background: var(--bg-deep); border-radius: 4px; overflow: hidden; }
.by-bar { height: 100%; border-radius: 4px; background: linear-gradient(90deg, var(--blue-700), var(--blue-400)); min-width: 2px; }
.by-cnt { font-size: 13px; font-weight: 700; color: var(--blue-700); width: 36px; text-align: right; }
</style>
</head>
<body style="background:var(--bg)">

<div class="report-header">
    <a href="index.php" style="color:rgba(255,255,255,.6);text-decoration:none;font-size:20px"><i class="fas fa-arrow-left"></i></a>
    <h1><i class="fas fa-chart-bar"></i> Отчёты по питанию</h1>
    <span style="font-size:13px;opacity:.6;margin-left:auto"><?= htmlspecialchars($user_name) ?></span>
</div>

<div style="max-width:1300px;margin:0 auto;padding:20px 16px">

<!-- Filter -->
<form method="GET" class="filter-bar">
    <div class="form-group">
        <label><i class="fas fa-calendar-alt"></i> Дата от</label>
        <input type="date" name="start_date" value="<?= $start_date ?>">
    </div>
    <div class="form-group">
        <label><i class="fas fa-calendar-alt"></i> Дата до</label>
        <input type="date" name="end_date" value="<?= $end_date ?>">
    </div>
    <div class="form-group">
        <label><i class="fas fa-utensils"></i> Тип питания</label>
        <select name="meal_type">
            <option value="all" <?= $meal_type==='all'?'selected':'' ?>>Все</option>
            <option value="breakfast" <?= $meal_type==='breakfast'?'selected':'' ?>>Завтрак</option>
            <option value="lunch"     <?= $meal_type==='lunch'?'selected':''     ?>>Обед</option>
            <option value="dinner"    <?= $meal_type==='dinner'?'selected':''    ?>>Ужин</option>
            <option value="night"     <?= $meal_type==='night'?'selected':''     ?>>Ночное</option>
        </select>
    </div>
    <?php if ($is_super_admin && !empty($points)): ?>
    <div class="form-group">
        <label><i class="fas fa-map-marker-alt"></i> Точка</label>
        <select name="point_id">
            <option value="">Все точки</option>
            <?php foreach ($points as $pt): ?>
            <option value="<?= $pt['id'] ?>" <?= $filter_point_id==$pt['id']?'selected':'' ?>>
                <?= htmlspecialchars($pt['point_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php elseif ($assigned_pid): ?>
    <input type="hidden" name="point_id" value="<?= $assigned_pid ?>">
    <?php endif; ?>
    <div class="form-group" style="min-width:auto">
        <label>&nbsp;</label>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Применить</button>
            <a href="export_excel.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&meal_type=<?= $meal_type ?><?= $filter_point_id?'&point_id='.$filter_point_id:'' ?>"
               class="btn btn-success" style="background:#1d6f42"><i class="fas fa-table"></i> Excel</a>
        </div>
    </div>
</form>

<!-- Summary -->
<div class="summary-grid">
    <div class="summary-card total">
        <div class="num"><?= $by_type['total'] ?></div>
        <div class="lbl">Всего питаний</div>
    </div>
    <div class="summary-card">
        <div class="num" style="color:#d97706"><?= $by_type['breakfast'] ?></div>
        <div class="lbl"><i class="fas fa-cloud-sun"></i> Завтрак</div>
    </div>
    <div class="summary-card">
        <div class="num" style="color:#059669"><?= $by_type['lunch'] ?></div>
        <div class="lbl"><i class="fas fa-sun"></i> Обед</div>
    </div>
    <div class="summary-card">
        <div class="num" style="color:#6d28d9"><?= $by_type['dinner'] ?></div>
        <div class="lbl"><i class="fas fa-moon"></i> Ужин</div>
    </div>
    <div class="summary-card">
        <div class="num" style="color:#1e3a8a"><?= $by_type['night'] ?></div>
        <div class="lbl"><i class="fas fa-star"></i> Ночное</div>
    </div>
</div>

<!-- Charts -->
<div class="split2">
    <?php if (!empty($by_point)): ?>
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-map-marker-alt"></i> По точкам</div></div>
        <?php $maxP = max(array_values($by_point)); ?>
        <div class="by-list">
            <?php foreach ($by_point as $name => $cnt): ?>
            <div class="by-row">
                <span class="by-name"><?= htmlspecialchars($name) ?></span>
                <div class="by-bar-wrap"><div class="by-bar" style="width:<?= round($cnt/$maxP*100) ?>%"></div></div>
                <span class="by-cnt"><?= $cnt ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($by_org)): ?>
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-building"></i> По организациям</div></div>
        <?php $maxO = max(array_values($by_org)); ?>
        <div class="by-list">
            <?php foreach (array_slice($by_org, 0, 10, true) as $org => $cnt): ?>
            <div class="by-row">
                <span class="by-name"><?= htmlspecialchars($org) ?></span>
                <div class="by-bar-wrap"><div class="by-bar" style="width:<?= round($cnt/$maxO*100) ?>%"></div></div>
                <span class="by-cnt"><?= $cnt ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-clipboard-list"></i> Детализация (<?= count($logs) ?> записей)</div>
        <?php if (count($logs) > 100): ?>
        <span style="font-size:12px;color:var(--text-3)">Показаны все <?= count($logs) ?> записей</span>
        <?php endif; ?>
    </div>

    <?php if (empty($logs)): ?>
    <div class="empty"><div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>Нет данных за выбранный период</div>
    <?php else: ?>
    <div class="report-table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Дата / Время</th>
                    <th>ФИО</th>
                    <th>Организация</th>
                    <th>Отдел</th>
                    <th>ВЖГ</th>
                    <th>Цена</th>
                    <th>Тип питания</th>
                    <th>Точка</th>
                    <th>Оператор</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="white-space:nowrap;font-variant-numeric:tabular-nums">
                        <?= date('d.m.Y', strtotime($log['scanned_at'])) ?><br>
                        <span style="color:var(--text-3);font-size:11px"><?= date('H:i:s', strtotime($log['scanned_at'])) ?></span>
                    </td>
                    <td style="font-weight:600"><?= htmlspecialchars($log['full_name']) ?></td>
                    <td><?= htmlspecialchars($log['organization']) ?></td>
                    <td style="color:var(--text-3)"><?= htmlspecialchars($log['department'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($log['vjg_type'] ?? '—') ?></td>
                    <td style="font-variant-numeric:tabular-nums">
                        <?= $log['price'] ? number_format($log['price'],0,'.',' ').' ₽' : '—' ?>
                    </td>
                    <td><span class="meal-badge <?= $log['meal_type'] ?>"><?= getMealTypeName($log['meal_type']) ?></span></td>
                    <td style="font-size:12px"><?= htmlspecialchars($log['meal_point_name'] ?? '—') ?></td>
                    <td style="font-size:12px;color:var(--text-3)"><?= htmlspecialchars($log['operator_name'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /page -->

<script src="assets/js/app.js" defer></script>
</body>
</html>
