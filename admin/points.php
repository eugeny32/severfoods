<?php
/**
 * Точки питания всех регионов.
 *
 * Идентификаторы точек в разных базах совпадают и означают разные точки,
 * поэтому в ссылках и формах регион передаётся всегда явно, рядом с id.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/region_write.php';
require_once __DIR__ . '/src/layout.php';
adminRequireLogin();

$allRegions = adminRegions($pdo);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminRequireOwner();
    try {
        $regionKey = (string)($_POST['region_key'] ?? '');
        $region = adminRegion($pdo, $regionKey);
        if (!$region) throw new RuntimeException('Регион не выбран или отключён');

        $d = [
            'point_name' => trim((string)($_POST['point_name'] ?? '')),
            'point_code' => trim((string)($_POST['point_code'] ?? '')),
            'city'       => trim((string)($_POST['city'] ?? '')),
            'address'    => trim((string)($_POST['address'] ?? '')),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'tz_offset'  => trim((string)($_POST['tz_offset'] ?? '')),
            'is_active'  => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($d['point_name'] === '' || $d['point_code'] === '') {
            throw new RuntimeException('Заполните название и код точки');
        }
        // Часовой пояс точки определяет границы местных суток при дедупликации
        // и в отчётах — мусор здесь исказил бы данные молча.
        if ($d['tz_offset'] !== '' && !preg_match('/^[+-]\d{2}:\d{2}$/', $d['tz_offset'])) {
            throw new RuntimeException('Часовой пояс в формате +07:00');
        }
        if ($d['tz_offset'] === '') $d['tz_offset'] = $region['tz_offset'];

        $rp = adminRegionPdo($pdo, $region);
        $id = (int)($_POST['id'] ?? 0);

        if ($id) {
            adminUpdatePoint($rp, $id, $d);
            adminAudit($pdo, 'point_update', "{$d['point_name']} (id {$id})", $regionKey);
            $msg = "Точка «{$d['point_name']}» сохранена";
        } else {
            $newId = adminCreatePoint($rp, $d);
            adminAudit($pdo, 'point_create', "{$d['point_name']} (id {$newId})", $regionKey);
            $msg = "Точка «{$d['point_name']}» добавлена в регион «{$region['label']}»";
        }
    } catch (Throwable $e) {
        // Код точки уникален внутри базы — самая частая причина отказа.
        $err = str_contains($e->getMessage(), 'Duplicate')
            ? 'Точка с таким кодом в этом регионе уже есть'
            : $e->getMessage();
    }
}

$rows = [];
if ($allRegions) {
    try {
        $part = "SELECT {REGION} AS region_key, mp.id, mp.point_name, mp.point_code,
                        mp.city, mp.address, mp.sort_order, mp.tz_offset, mp.is_active
                 FROM {DB}.meal_points mp";
        [$sql, $p] = adminUnionQuery($allRegions, $part);
        $st = $pdo->prepare("SELECT * FROM ({$sql}) AS u ORDER BY region_key, sort_order, point_name");
        $st->execute($p);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        $err = $err ?: $e->getMessage();
    }
}

$edit = null;
if (isset($_GET['edit'], $_GET['region'])) {
    $r = adminRegion($pdo, (string)$_GET['region']);
    if ($r) {
        try {
            $rp = adminRegionPdo($pdo, $r);
            $st = $rp->prepare('SELECT * FROM meal_points WHERE id = ?');
            $st->execute([(int)$_GET['edit']]);
            if ($p = $st->fetch()) { $edit = $p; $edit['region_key'] = $r['region_key']; }
        } catch (Throwable $e) { $err = $err ?: $e->getMessage(); }
    }
}

adminHead('Точки', 'points');
?>
<h1>Точки питания</h1>

<?php if ($msg): ?><div class="msg msg-ok"><?= adminEsc($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg msg-err"><?= adminEsc($err) ?></div><?php endif; ?>

<?php if (!$allRegions): ?>
    <div class="msg msg-warn">Реестр регионов пуст — добавьте регионы на странице «Регионы».</div>
<?php else: ?>

<div class="card">
<table>
<tr><th>Регион</th><th>Точка</th><th>Код</th><th>Город</th><th>Адрес</th>
    <th>Часовой пояс</th><th>Состояние</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr>
    <td><span class="pill pill-reg"><?= adminEsc($allRegions[$r['region_key']]['label'] ?? $r['region_key']) ?></span></td>
    <td><strong><?= adminEsc($r['point_name']) ?></strong></td>
    <td class="muted"><code><?= adminEsc($r['point_code'] ?? '') ?></code></td>
    <td class="muted"><?= adminEsc($r['city'] ?? '') ?></td>
    <td class="muted"><?= adminEsc($r['address'] ?? '') ?></td>
    <td class="muted"><?= adminEsc($r['tz_offset'] ?? '—') ?></td>
    <td><?= (int)$r['is_active']
            ? '<span class="pill pill-ok">работает</span>'
            : '<span class="pill pill-off">выключена</span>' ?></td>
    <td><?php if (adminIsOwner()): ?>
        <a class="btn btn-sec" href="?edit=<?= (int)$r['id'] ?>&region=<?= adminEsc($r['region_key']) ?>">Править</a>
    <?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows && !$err): ?><tr><td colspan="8" class="muted">Точек нет.</td></tr><?php endif; ?>
</table>
</div>

<?php if (adminIsOwner()): ?>
<h2><?= $edit ? 'Правка точки' : 'Добавить точку' ?></h2>
<form method="post" class="card">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

    <div class="field">
        <label>Регион</label>
        <?php if ($edit): ?>
            <input type="hidden" name="region_key" value="<?= adminEsc($edit['region_key']) ?>">
            <p><strong><?= adminEsc($allRegions[$edit['region_key']]['label'] ?? $edit['region_key']) ?></strong>
               <span class="muted">— регион у существующей точки не меняется</span></p>
        <?php else: ?>
            <select name="region_key" required style="min-width:240px">
                <option value="">— выберите —</option>
                <?php foreach ($allRegions as $key => $r): ?>
                    <option value="<?= adminEsc($key) ?>"><?= adminEsc($r['label']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
        <div class="field"><label>Название</label>
            <input type="text" name="point_name" required style="width:100%"
                   value="<?= adminEsc($edit['point_name'] ?? '') ?>"></div>
        <div class="field"><label>Код (уникальный)</label>
            <input type="text" name="point_code" required style="width:100%"
                   value="<?= adminEsc($edit['point_code'] ?? '') ?>"></div>
        <div class="field"><label>Город</label>
            <input type="text" name="city" style="width:100%" value="<?= adminEsc($edit['city'] ?? '') ?>"></div>
        <div class="field"><label>Адрес</label>
            <input type="text" name="address" style="width:100%" value="<?= adminEsc($edit['address'] ?? '') ?>"></div>
        <div class="field"><label>Часовой пояс</label>
            <input type="text" name="tz_offset" style="width:100%" placeholder="+07:00"
                   value="<?= adminEsc($edit['tz_offset'] ?? '') ?>"></div>
        <div class="field"><label>Порядок</label>
            <input type="number" name="sort_order" style="width:100%"
                   value="<?= (int)($edit['sort_order'] ?? 0) ?>"></div>
    </div>

    <div class="field">
        <label style="text-transform:none;font-size:14px;display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="is_active" <?= !$edit || (int)$edit['is_active'] ? 'checked' : '' ?>>
            Точка работает
        </label>
    </div>

    <p class="muted" style="margin-bottom:12px">
        Часовой пояс определяет границы местных суток: по нему считается, съел ли человек
        обед «сегодня». Пустое значение — берётся пояс региона.
    </p>

    <button class="btn" type="submit"><?= $edit ? 'Сохранить' : 'Добавить' ?></button>
    <?php if ($edit): ?><a class="btn btn-sec" href="points.php">Отмена</a><?php endif; ?>
</form>
<?php endif; ?>

<?php endif; adminFoot();
