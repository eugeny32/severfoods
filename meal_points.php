<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

checkAuth();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    header('Location: index.php'); exit;
}

$msg   = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::guard();
    $action = $_POST['action'];

    if ($action === 'add') {
        $name  = trim($_POST['point_name'] ?? '');
        $code  = strtoupper(trim($_POST['point_code'] ?? ''));
        $city  = trim($_POST['city'] ?? '');
        $addr  = trim($_POST['address'] ?? '');
        $sort  = intval($_POST['sort_order'] ?? 0);

        if (!$name || !$code) { $error = 'Название и код обязательны'; }
        else {
            try {
                $pdo->prepare(
                    "INSERT INTO meal_points (point_name, point_code, city, address, sort_order, is_active)
                     VALUES (?, ?, ?, ?, ?, 1)"
                )->execute([$name, $code, $city, $addr, $sort]);
                logAction('add_point', "Добавлена точка: {$name} ({$code})");
                $msg = "Точка «{$name}» добавлена";
            } catch (PDOException $e) {
                $error = 'Ошибка: точка с таким кодом уже существует';
            }
        }
    } elseif ($action === 'toggle') {
        $pid   = intval($_POST['id']);
        $state = intval($_POST['state']);
        $pdo->prepare("UPDATE meal_points SET is_active=? WHERE id=?")->execute([$state, $pid]);
        logAction('toggle_point', "Точка ID:{$pid} → ".($state?'вкл':'выкл'));
        $msg = "Статус точки обновлён";
    } elseif ($action === 'delete') {
        $pid = intval($_POST['id']);
        $pt  = getMealPointById($pdo, $pid);
        if ($pt) {
            $pdo->prepare("DELETE FROM meal_points WHERE id=?")->execute([$pid]);
            logAction('delete_point', "Удалена точка: {$pt['point_name']}");
            $msg = "Точка «{$pt['point_name']}» удалена";
        }
    }
}

$points = getMealPoints($pdo, false); // все, включая неактивные
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<script src="assets/js/tz-detect.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Управление точками — <?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
<style>
.page-header {
    background: var(--blue-800); color: white;
    padding: 16px 24px;
    display: flex; align-items: center; gap: 16px;
}
.page-header h1 { font-size: 20px; font-weight: 800; }
.points-table-wrap { overflow-x: auto; }
.points-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.points-table th {
    background: var(--bg); padding: 10px 14px;
    text-align: left; font-size: 11px; font-weight: 700;
    color: var(--text-3); text-transform: uppercase;
    letter-spacing: .5px; border-bottom: 1px solid var(--border);
}
.points-table td { padding: 12px 14px; border-bottom: 1px solid var(--bg-deep); vertical-align: middle; }
.points-table tr:last-child td { border-bottom: none; }
.points-table tr:hover td { background: var(--bg); }
.active-dot {
    display: inline-block;
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--success); margin-right: 6px;
}
.inactive-dot { background: var(--text-4); }
.add-form { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 600px) { .add-form { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="page-header">
    <a href="index.php" style="color:rgba(255,255,255,.6);text-decoration:none;font-size:20px"><i class="fas fa-arrow-left"></i></a>
    <h1><i class="fas fa-map-marker-alt"></i> Управление точками питания</h1>
</div>
<div style="max-width:1200px;margin:0 auto;padding:24px 16px">

<?php if ($msg):  ?><div class="notif success" style="margin-bottom:16px"><div class="notif-inner"><div class="notif-icon"><i class="fas fa-check-circle"></i></div><div class="notif-body"><div class="notif-title"><?= htmlspecialchars($msg) ?></div></div></div></div><?php endif; ?>
<?php if ($error): ?><div class="notif error"   style="margin-bottom:16px"><div class="notif-inner"><div class="notif-icon"><i class="fas fa-times-circle"></i></div><div class="notif-body"><div class="notif-title"><?= htmlspecialchars($error) ?></div></div></div></div><?php endif; ?>

<!-- Add form -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header"><div class="card-title"><i class="fas fa-plus"></i> Добавить точку питания</div></div>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="add">
        <div class="add-form">
            <div class="form-group">
                <label>Название точки *</label>
                <input type="text" name="point_name" required placeholder="Столовая №1, Москва">
            </div>
            <div class="form-group">
                <label>Код точки * (уникальный)</label>
                <input type="text" name="point_code" required placeholder="MSK-01" style="text-transform:uppercase">
            </div>
            <div class="form-group">
                <label>Город</label>
                <input type="text" name="city" placeholder="Москва">
            </div>
            <div class="form-group">
                <label>Адрес</label>
                <input type="text" name="address" placeholder="ул. Примерная, 1">
            </div>
            <div class="form-group">
                <label>Порядок сортировки</label>
                <input type="number" name="sort_order" value="0" min="0">
            </div>
        </div>
        <div style="margin-top:16px">
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Добавить точку</button>
        </div>
    </form>
</div>

<!-- Points list -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-clipboard-list"></i> Все точки питания (<?= count($points) ?>)</div>
    </div>
    <?php if (empty($points)): ?>
    <div class="empty"><div class="empty-icon"><i class="fas fa-map-marker-alt"></i></div>Точки питания не добавлены</div>
    <?php else: ?>
    <div class="points-table-wrap">
        <table class="points-table">
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Код</th>
                    <th>Город</th>
                    <th>Адрес</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($points as $pt): ?>
                <tr>
                    <td style="font-weight:700"><?= htmlspecialchars($pt['point_name']) ?></td>
                    <td><code style="background:var(--bg);padding:2px 6px;border-radius:4px;font-size:12px"><?= htmlspecialchars($pt['point_code']) ?></code></td>
                    <td><?= htmlspecialchars($pt['city'] ?? '—') ?></td>
                    <td style="color:var(--text-3);font-size:13px"><?= htmlspecialchars($pt['address'] ?? '—') ?></td>
                    <td>
                        <span>
                            <span class="active-dot <?= $pt['is_active']?'':'inactive-dot' ?>"></span>
                            <?= $pt['is_active'] ? 'Активна' : 'Отключена' ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px">
                            <form method="POST" style="display:inline">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id"     value="<?= $pt['id'] ?>">
                                <input type="hidden" name="state"  value="<?= $pt['is_active']?0:1 ?>">
                                <button type="submit" class="btn-sm" title="<?= $pt['is_active']?'Отключить':'Включить' ?>">
                                    <?= $pt['is_active']?'<i class="fas fa-pause-circle"></i>':'<i class="fas fa-play"></i>' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline"
                                onsubmit="return confirm('Удалить точку «<?= htmlspecialchars(addslashes($pt['point_name'])) ?>»?')">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id"     value="<?= $pt['id'] ?>">
                                <button type="submit" class="btn-sm danger" title="Удалить"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div>
</body>
</html>
