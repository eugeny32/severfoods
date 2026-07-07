<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

$user_role      = $_SESSION['role']             ?? 'admin';
$user_name      = $_SESSION['user_name']        ?? 'Пользователь';
$is_admin       = true; // доступ на страницу уже проверен выше
$is_super_admin = $user_role === 'super_admin';
$assigned_pid   = $_SESSION['assigned_point_id'] ?? null;

$start_date  = preg_replace('/[^0-9\-]/', '', $_GET['start_date'] ?? date('Y-m-d', strtotime(localToday() . ' -30 days')));
$end_date    = preg_replace('/[^0-9\-]/', '', $_GET['end_date']   ?? localToday());
$meal_type   = in_array($_GET['meal_type'] ?? '', ['breakfast','lunch','dinner','night','all']) ? $_GET['meal_type'] : 'all';
$report_type = in_array($_GET['report_type'] ?? '', ['meals','dry_rations']) ? $_GET['report_type'] : 'meals';
$export      = $_GET['export']      ?? null;
$point_id    = isset($_GET['point_id']) && $_GET['point_id'] !== '' ? (int)$_GET['point_id'] : null;
$unassigned_only = !empty($_GET['unassigned_only']);

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
// Местное время считаем по часовому поясу КОНКРЕТНОЙ точки питания (meal_points.tz_offset),
// если он настроен; иначе — по глобальному часовому поясу браузера (APP_TZ_OFFSET).
$scannedLocal = "CONVERT_TZ(ml.scanned_at, '+00:00', COALESCE(mpt.tz_offset, '" . APP_TZ_OFFSET . "'))";
$sql = "SELECT ml.id, ml.scanned_at, $scannedLocal AS scanned_local, e.full_name, e.organization, e.department,
               e.vjg_type, e.price, ml.meal_type, ml.scanner_ip, ml.meal_point_id,
               ml.operator_name, ml.meal_point_name
        FROM meal_logs ml
        JOIN employees e ON ml.employee_id = e.id
        LEFT JOIN meal_points mpt ON mpt.id = ml.meal_point_id
        WHERE DATE($scannedLocal) BETWEEN :start AND :end
          AND ml.access_granted = 1";
$params = [':start' => $start_date, ':end' => $end_date];

if ($meal_type !== 'all') { $sql .= " AND ml.meal_type = :mt"; $params[':mt'] = $meal_type; }
if ($filter_point_id)     { $sql .= " AND ml.meal_point_id = :pid"; $params[':pid'] = $filter_point_id; }
if ($unassigned_only)     { $sql .= " AND ml.meal_point_id IS NULL"; }
$sql .= " ORDER BY ml.scanned_at DESC";

$stmt = $pdo->prepare($sql); $stmt->execute($params);
$logs = $stmt->fetchAll();

// CSV убран — используем Excel (export_excel.php)

// Сводный отчёт по сотрудникам: кол-во приёмов + уникальных дней, сгруппировано по организациям
$sqlEmp = "SELECT e.id, e.full_name, e.organization, e.department,
                  COUNT(*) as meals,
                  COUNT(DISTINCT DATE($scannedLocal)) as days
           FROM meal_logs ml
           JOIN employees e ON ml.employee_id = e.id
           LEFT JOIN meal_points mpt ON mpt.id = ml.meal_point_id
           WHERE DATE($scannedLocal) BETWEEN :start AND :end
             AND ml.access_granted = 1";
$paramsEmp = [':start' => $start_date, ':end' => $end_date];
if ($meal_type !== 'all')  { $sqlEmp .= " AND ml.meal_type = :mt";        $paramsEmp[':mt']  = $meal_type; }
if ($filter_point_id)      { $sqlEmp .= " AND ml.meal_point_id = :pid";   $paramsEmp[':pid'] = $filter_point_id; }
$sqlEmp .= " GROUP BY e.id, e.full_name, e.organization, e.department
             ORDER BY e.organization, e.full_name";
$stmtEmp = $pdo->prepare($sqlEmp);
$stmtEmp->execute($paramsEmp);
$empStats = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

// Группируем по организациям
$empByOrg = [];
foreach ($empStats as $row) {
    $empByOrg[$row['organization']][] = $row;
}
ksort($empByOrg);

// Dry rations report
$dryLogs = [];
if ($report_type === 'dry_rations') {
    try {
        $sqlDry = "SELECT dr.ration_date, dr.ration_type, dr.status, dr.created_at,
                          e.full_name, e.organization, e.department, e.vjg_type,
                          op.full_name as created_by_name
                   FROM dry_rations dr
                   JOIN employees e ON dr.employee_id = e.id
                   LEFT JOIN employees op ON dr.created_by = op.id
                   WHERE dr.ration_date BETWEEN :start AND :end
                   ORDER BY dr.ration_date DESC, e.full_name";
        $stmtDry = $pdo->prepare($sqlDry);
        $stmtDry->execute([':start' => $start_date, ':end' => $end_date]);
        $dryLogs = $stmtDry->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

$by_type  = ['total' => 0, 'breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'night' => 0];
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
    $d = date('d.m', strtotime($log['scanned_local']));
    $by_date[$d] = ($by_date[$d] ?? 0) + 1;
}
arsort($by_point); arsort($by_org);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<script src="assets/js/tz-detect.js"></script>
<?= Csrf::meta() ?>
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
th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
th.sortable:hover { background:#c7d9f0; }
th.sortable .sort-icon { display:inline-block; margin-left:4px; opacity:.4; font-size:10px; }
th.sortable.asc  .sort-icon::after { content:'▲'; opacity:1; }
th.sortable.desc .sort-icon::after { content:'▼'; opacity:1; }
th.sortable:not(.asc):not(.desc) .sort-icon::after { content:'⇅'; }
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
        <label><i class="fas fa-list"></i> Раздел</label>
        <select name="report_type" onchange="this.form.submit()">
            <option value="meals"       <?= $report_type==='meals'?'selected':''       ?>>Столовая</option>
            <option value="dry_rations" <?= $report_type==='dry_rations'?'selected':'' ?>>Сухой паёк</option>
        </select>
    </div>
    <?php if ($report_type === 'meals'): ?>
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
    <?php endif; ?>
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
    <?php if ($report_type === 'meals'): ?>
    <div class="form-group" style="min-width:auto;flex-direction:row;align-items:center;gap:6px;padding-top:20px">
        <input type="checkbox" id="unassignedOnly" name="unassigned_only" value="1" <?= $unassigned_only?'checked':'' ?> onchange="this.form.submit()">
        <label for="unassignedOnly" style="margin:0;white-space:nowrap">Только не привязанные к точке</label>
    </div>
    <?php endif; ?>
    <div class="form-group" style="min-width:auto">
        <label>&nbsp;</label>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Применить</button>
            <a href="export_excel.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&report_type=<?= $report_type ?>&meal_type=<?= $meal_type ?><?= $filter_point_id?'&point_id='.$filter_point_id:'' ?>"
               class="btn btn-success" style="background:#1d6f42"><i class="fas fa-table"></i> Excel (детали)</a>
            <?php if ($report_type !== 'dry_rations'): ?>
            <a href="export_excel_employees.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&meal_type=<?= $meal_type ?><?= $filter_point_id?'&point_id='.$filter_point_id:'' ?>"
               class="btn btn-success" style="background:#15803d"><i class="fas fa-users"></i> Excel (сотрудники)</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if ($is_super_admin): ?>
<div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span style="font-size:13px;color:#92400e"><i class="fas fa-tools"></i> Обслуживание базы: привести старые «ночные» записи к завтраку/ужину по местному времени точки.</span>
    <button type="button" class="btn" onclick="normalizeNightRecords()" style="background:#f59e0b;color:#fff">
        <i class="fas fa-broom"></i> Нормализовать ночные записи
    </button>
    <span id="normalizeResult" style="font-size:13px;color:#92400e"></span>
</div>
<?php endif; ?>

<?php if ($report_type === 'dry_rations'): ?>
<!-- Dry rations report -->
<?php
$dryActive    = count(array_filter($dryLogs, fn($r) => $r['status'] === 'active'));
$dryCancelled = count(array_filter($dryLogs, fn($r) => $r['status'] === 'cancelled'));
$dryDryRation = count(array_filter($dryLogs, fn($r) => $r['ration_type'] === 'dry_ration'));
$dryField     = count(array_filter($dryLogs, fn($r) => $r['ration_type'] === 'field'));
?>
<div class="summary-grid">
    <div class="summary-card total">
        <div class="num"><?= count($dryLogs) ?></div>
        <div class="lbl">Всего записей</div>
    </div>
    <div class="summary-card">
        <div class="num" style="color:#15803d"><?= $dryActive ?></div>
        <div class="lbl">Активных</div>
    </div>
    <div class="summary-card">
        <div class="num" style="color:#dc2626"><?= $dryCancelled ?></div>
        <div class="lbl">Аннулировано</div>
    </div>
    <div class="summary-card">
        <div class="num" style="color:#92400e"><?= $dryDryRation ?></div>
        <div class="lbl">Сухой паёк</div>
    </div>
    <div class="summary-card">
        <div class="num" style="color:#1e3a8a"><?= $dryField ?></div>
        <div class="lbl">Выездное питание</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-box"></i> Выданные сухие пайки / выездное питание (<?= count($dryLogs) ?> записей)</div>
    </div>
    <?php if (empty($dryLogs)): ?>
    <div class="empty"><div class="empty-icon"><i class="fas fa-box"></i></div>Нет данных за выбранный период</div>
    <?php else: ?>
    <div class="report-table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th class="sortable">Дата<span class="sort-icon"></span></th>
                    <th class="sortable">ФИО<span class="sort-icon"></span></th>
                    <th class="sortable">Организация<span class="sort-icon"></span></th>
                    <th class="sortable">Отдел<span class="sort-icon"></span></th>
                    <th class="sortable">Вахтовый жилой городок<span class="sort-icon"></span></th>
                    <th class="sortable">Тип<span class="sort-icon"></span></th>
                    <th class="sortable">Статус<span class="sort-icon"></span></th>
                    <th class="sortable">Создал<span class="sort-icon"></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dryLogs as $dr): ?>
                <tr>
                    <td style="white-space:nowrap;font-variant-numeric:tabular-nums;font-weight:600">
                        <?= date('d.m.Y', strtotime($dr['ration_date'])) ?>
                    </td>
                    <td style="font-weight:600"><?= htmlspecialchars($dr['full_name']) ?></td>
                    <td><?= htmlspecialchars($dr['organization']) ?></td>
                    <td style="color:var(--text-3)"><?= htmlspecialchars($dr['department'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($dr['vjg_type'] ?? '—') ?></td>
                    <td>
                        <?php if ($dr['ration_type'] === 'dry_ration'): ?>
                            <span style="background:#fef9c3;color:#92400e;border-radius:5px;padding:2px 8px;font-size:12px;font-weight:700">Сухой паёк</span>
                        <?php else: ?>
                            <span style="background:#dbeafe;color:#1e40af;border-radius:5px;padding:2px 8px;font-size:12px;font-weight:700">Выездное</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($dr['status'] === 'active'): ?>
                            <span style="background:#dcfce7;color:#15803d;border-radius:5px;padding:2px 8px;font-size:12px;font-weight:700">Активен</span>
                        <?php else: ?>
                            <span style="background:#fee2e2;color:#dc2626;border-radius:5px;padding:2px 8px;font-size:12px;font-weight:700">Аннулирован</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--text-3)"><?= htmlspecialchars($dr['created_by_name'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
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

    <?php $assignablePoints = $is_super_admin ? getMealPoints($pdo) : $points; ?>
    <?php if ($is_admin && !empty($logs)): ?>
    <div id="assignPanel" style="display:none;background:#eff6ff;border-bottom:1.5px solid #dbeafe;padding:12px 16px;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:13px;color:#1e40af;font-weight:600">Выбрано: <span id="assignCount">0</span></span>
        <select id="assignPointId" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px;max-width:220px">
            <option value="">— выберите точку —</option>
            <?php foreach ($assignablePoints as $pt): ?>
            <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['point_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="assignMealType" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:13px">
            <option value="">— не менять тип —</option>
            <option value="breakfast">Завтрак</option>
            <option value="lunch">Обед</option>
            <option value="dinner">Ужин</option>
        </select>
        <button type="button" class="btn btn-primary" onclick="assignSelectedToPoint()">
            <i class="fas fa-map-marker-alt"></i> Назначить выбранным
        </button>
        <span id="assignResult" style="font-size:13px"></span>
    </div>
    <?php endif; ?>

    <?php if (empty($logs)): ?>
    <div class="empty"><div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>Нет данных за выбранный период</div>
    <?php else: ?>
    <div class="report-table-wrap">
        <table class="report-table report-table-wide">
            <thead>
                <tr>
                    <?php if ($is_admin): ?>
                    <th style="width:30px"><input type="checkbox" id="checkAll" onchange="toggleAllRows(this)"></th>
                    <?php endif; ?>
                    <th class="sortable">Дата / Время<span class="sort-icon"></span></th>
                    <th class="sortable">ФИО<span class="sort-icon"></span></th>
                    <th class="sortable">Организация<span class="sort-icon"></span></th>
                    <th class="sortable">Отдел<span class="sort-icon"></span></th>
                    <th class="sortable">Вахтовый жилой городок<span class="sort-icon"></span></th>
                    <th class="sortable">Тип питания<span class="sort-icon"></span></th>
                    <th class="sortable">Точка<span class="sort-icon"></span></th>
                    <th class="sortable">Оператор<span class="sort-icon"></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <?php if ($is_admin): ?>
                    <td>
                        <?php if (empty($log['meal_point_id'])): ?>
                        <input type="checkbox" class="row-check" value="<?= $log['id'] ?>" onchange="updateAssignPanel()">
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td style="white-space:nowrap;font-variant-numeric:tabular-nums">
                        <?= date('d.m.Y', strtotime($log['scanned_local'])) ?><br>
                        <span style="color:var(--text-3);font-size:11px"><?= date('H:i:s', strtotime($log['scanned_local'])) ?></span>
                    </td>
                    <td style="font-weight:600"><?= htmlspecialchars($log['full_name']) ?></td>
                    <td><?= htmlspecialchars($log['organization']) ?></td>
                    <td style="color:var(--text-3)"><?= htmlspecialchars($log['department'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($log['vjg_type'] ?? '—') ?></td>
                    <td><span class="meal-badge <?= $log['meal_type'] ?>"><?= getMealTypeName($log['meal_type']) ?></span></td>
                    <td style="font-size:12px">
                        <?php if (empty($log['meal_point_id'])): ?>
                        <div style="display:flex;align-items:center;gap:4px;min-width:150px">
                            <select class="row-point-select" data-id="<?= $log['id'] ?>" title="Выберите точку питания"
                                style="padding:4px 6px;border-radius:6px;border:1px solid #fed7aa;font-size:12px;background:#fff7ed;color:#92400e;max-width:130px;flex:1">
                                <option value="">Не привязано…</option>
                                <?php foreach ($assignablePoints as $pt): ?>
                                <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['point_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" title="Назначить" onclick="assignSingleRow(<?= $log['id'] ?>, this)"
                                style="border:none;background:var(--blue-800);color:#fff;border-radius:6px;width:24px;height:24px;cursor:pointer;flex-shrink:0">
                                <i class="fas fa-check" style="font-size:11px"></i>
                            </button>
                        </div>
                        <?php else: ?>
                        <?= htmlspecialchars($log['meal_point_name'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--text-3)"><?= htmlspecialchars($log['operator_name'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Сводный отчёт по сотрудникам -->
<div class="card" style="margin-top:20px">
    <div class="card-header">
        <div class="card-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            Сводный отчёт по сотрудникам (<?= count($empStats) ?> чел.)
        </div>
        <a href="export_excel_employees.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&meal_type=<?= $meal_type ?><?= $filter_point_id?'&point_id='.$filter_point_id:'' ?>"
           class="btn btn-success" style="background:#15803d;padding:6px 14px;font-size:12px;text-decoration:none;border-radius:7px;color:#fff;font-weight:600">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
            Excel
        </a>
    </div>

    <?php if (empty($empStats)): ?>
    <div class="empty"><div class="empty-icon">👤</div>Нет данных за выбранный период</div>
    <?php else: ?>
    <?php foreach ($empByOrg as $org => $rows):
        $orgMeals = array_sum(array_column($rows, 'meals'));
        $orgDays  = array_sum(array_column($rows, 'days'));
    ?>
    <div style="margin-bottom:24px">
        <div style="background:var(--bg-input,#f1f5f9);padding:8px 16px;border-radius:8px;margin-bottom:6px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span style="font-size:13px;font-weight:700;color:var(--text-main)"><?= htmlspecialchars($org ?: '—') ?></span>
            <span style="font-size:11px;color:var(--text-3);margin-left:auto"><?= count($rows) ?> сотр. · <?= $orgMeals ?> приёмов · <?= $orgDays ?> дней</span>
        </div>
        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th class="sortable">ФИО<span class="sort-icon"></span></th>
                        <th class="sortable">Подразделение<span class="sort-icon"></span></th>
                        <th class="sortable" style="text-align:center">Приёмов пищи<span class="sort-icon"></span></th>
                        <th class="sortable" style="text-align:center">Дней в столовой<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td style="color:var(--text-3);font-size:12px"><?= $i+1 ?></td>
                        <td style="font-weight:600"><?= htmlspecialchars($r['full_name']) ?></td>
                        <td style="color:var(--text-3)"><?= htmlspecialchars($r['department'] ?: '—') ?></td>
                        <td style="text-align:center;font-weight:700;font-variant-numeric:tabular-nums"><?= $r['meals'] ?></td>
                        <td style="text-align:center;font-weight:700;font-variant-numeric:tabular-nums;color:var(--blue-700,#003366)"><?= $r['days'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-input,#f8fafc)">
                        <td colspan="3" style="font-weight:700;font-size:12px;padding:8px 12px">Итого по организации</td>
                        <td style="text-align:center;font-weight:800"><?= $orgMeals ?></td>
                        <td style="text-align:center;font-weight:800;color:var(--blue-700,#003366)"><?= $orgDays ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php endif; // report_type ?>

</div><!-- /page -->

<script src="assets/js/app.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('th.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const tbody = th.closest('table').querySelector('tbody');
            if (!tbody) return;
            const col = Array.from(th.parentElement.children).indexOf(th);
            const asc = !th.classList.contains('asc');
            // Reset siblings
            th.closest('tr').querySelectorAll('th.sortable').forEach(t => t.classList.remove('asc','desc'));
            th.classList.add(asc ? 'asc' : 'desc');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((a, b) => {
                const ca = a.children[col]?.textContent.trim() ?? '';
                const cb = b.children[col]?.textContent.trim() ?? '';
                const na = parseFloat(ca.replace(/\s/g,'')), nb = parseFloat(cb.replace(/\s/g,''));
                const cmp = (!isNaN(na) && !isNaN(nb)) ? na - nb : ca.localeCompare(cb, 'ru');
                return asc ? cmp : -cmp;
            });
            rows.forEach(r => tbody.appendChild(r));
        });
    });
});

// ── Массовое назначение точки/типа для не привязанных записей ──
function toggleAllRows(checkAll) {
    document.querySelectorAll('.row-check').forEach(cb => { cb.checked = checkAll.checked; });
    updateAssignPanel();
}

function updateAssignPanel() {
    const checked = document.querySelectorAll('.row-check:checked');
    const panel = document.getElementById('assignPanel');
    if (!panel) return;
    panel.style.display = checked.length ? 'flex' : 'none';
    const countEl = document.getElementById('assignCount');
    if (countEl) countEl.textContent = checked.length;
}

async function assignSelectedToPoint() {
    const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(cb => parseInt(cb.value, 10));
    const pointId  = document.getElementById('assignPointId').value;
    const mealType = document.getElementById('assignMealType').value;
    const resultEl = document.getElementById('assignResult');

    if (!ids.length) return;
    if (!pointId) { alert('Выберите точку питания'); return; }

    resultEl.textContent = 'Сохранение…';
    resultEl.style.color = '#64748b';
    try {
        const res = await fetch('api/assign_meal_point.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
            body: JSON.stringify({ ids, point_id: pointId, meal_type: mealType || null }),
        });
        const data = await res.json();
        if (data.success) {
            resultEl.style.color = '#15803d';
            resultEl.textContent = `Обновлено записей: ${data.updated}`;
            setTimeout(() => location.reload(), 900);
        } else {
            resultEl.style.color = '#dc2626';
            resultEl.textContent = data.message || 'Ошибка';
        }
    } catch (e) {
        resultEl.style.color = '#dc2626';
        resultEl.textContent = 'Ошибка сети';
    }
}

// Быстрое назначение точки прямо из строки таблицы
async function assignSingleRow(id, btnEl) {
    const select  = btnEl.closest('td').querySelector('.row-point-select');
    const pointId = select.value;
    if (!pointId) { alert('Выберите точку в списке'); return; }

    btnEl.disabled = true;
    select.disabled = true;
    try {
        const res = await fetch('api/assign_meal_point.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
            body: JSON.stringify({ ids: [id], point_id: pointId, meal_type: null }),
        });
        const data = await res.json();
        if (data.success && data.updated > 0) {
            const cell = btnEl.closest('td');
            cell.textContent = select.options[select.selectedIndex].textContent;
        } else {
            alert(data.message || 'Не удалось назначить точку');
            btnEl.disabled = false;
            select.disabled = false;
        }
    } catch (e) {
        alert('Ошибка сети');
        btnEl.disabled = false;
        select.disabled = false;
    }
}

async function normalizeNightRecords() {
    if (!confirm('Переклассифицировать все исторические записи с типом «Ночное» в «Завтрак»/«Ужин» по местному времени точки? Действие необратимо.')) return;
    const resultEl = document.getElementById('normalizeResult');
    resultEl.textContent = 'Выполняется…';
    try {
        const res = await fetch('api/normalize_night_records.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': getCsrfToken() },
        });
        const data = await res.json();
        if (data.success) {
            resultEl.textContent = `Готово: всего ${data.total}, в завтрак — ${data.to_breakfast}, в ужин — ${data.to_dinner}`;
        } else {
            resultEl.textContent = data.message || 'Ошибка';
        }
    } catch (e) {
        resultEl.textContent = 'Ошибка сети';
    }
}
</script>
</body>
</html>
