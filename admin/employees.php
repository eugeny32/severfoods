<?php
/**
 * Сотрудники всех регионов.
 *
 * Чтение — сводным запросом через границы баз. Запись — отдельным соединением
 * к базе явно выбранного региона (src/region_write.php).
 *
 * QR-коды здесь не показываются вообще. В этой системе QR-код администратора
 * работает как пароль, а у обычного сотрудника — как пропуск; выводить их
 * списком на странице, доступной сразу по всем регионам, незачем. Печать
 * карточек остаётся в региональной системе.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/region_write.php';
require_once __DIR__ . '/src/layout.php';
adminRequireLogin();

const EMP_LIMIT = 500;

$allRegions = adminRegions($pdo);
$msg = '';
$err = '';

// ── Сохранение ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminRequireOwner();
    try {
        $regionKey = (string)($_POST['region_key'] ?? '');
        $region = adminRegion($pdo, $regionKey);
        if (!$region) throw new RuntimeException('Регион не выбран или отключён');

        $d = [
            'full_name'    => trim((string)($_POST['full_name'] ?? '')),
            'birth_date'   => trim((string)($_POST['birth_date'] ?? '')),
            'organization' => trim((string)($_POST['organization'] ?? '')),
            'department'   => trim((string)($_POST['department'] ?? '')),
            'position'     => trim((string)($_POST['position'] ?? '')),
            'vjg_type'     => trim((string)($_POST['vjg_type'] ?? '')),
            'price'        => (float)($_POST['price'] ?? 0),
            'qr_status'    => in_array($_POST['qr_status'] ?? '', ['active','expired','blocked'], true)
                              ? $_POST['qr_status'] : 'active',
            'is_active'    => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($d['full_name'] === '')    throw new RuntimeException('Укажите ФИО');
        if ($d['organization'] === '') throw new RuntimeException('Укажите организацию');

        $rp = adminRegionPdo($pdo, $region);
        $id = (int)($_POST['id'] ?? 0);

        if ($id) {
            adminUpdateEmployee($rp, $id, $d);
            adminAudit($pdo, 'employee_update', "{$d['full_name']} (id {$id})", $regionKey);
            $msg = "Сотрудник «{$d['full_name']}» сохранён в регионе «{$region['label']}»";
        } else {
            $newId = adminCreateEmployee($rp, $d);
            adminAudit($pdo, 'employee_create', "{$d['full_name']} (id {$newId})", $regionKey);
            $msg = "Сотрудник «{$d['full_name']}» добавлен в регион «{$region['label']}». "
                 . 'QR-карту напечатайте в системе этого региона.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// ── Список ───────────────────────────────────────────────────
$selected = adminSelectedRegions($pdo, $_GET['regions'] ?? null);
$search   = trim((string)($_GET['search'] ?? ''));
$onlyActive = !isset($_GET['show_all']);

$rows = [];
$truncated = false;
if ($allRegions) {
    try {
        $where  = ['1=1'];
        $params = [];
        if ($onlyActive) $where[] = 'e.is_active = 1';
        if ($search !== '') {
            $where[] = '(e.full_name LIKE ? OR e.organization LIKE ? OR e.department LIKE ?)';
            array_push($params, "%$search%", "%$search%", "%$search%");
        }
        $part = "SELECT {REGION} AS region_key, e.id, e.full_name, e.birth_date, e.organization,
                        e.department, e.position, e.vjg_type, e.price, e.qr_status, e.is_active, e.role
                 FROM {DB}.employees e
                 WHERE " . implode(' AND ', $where);
        [$sql, $p] = adminUnionQuery($selected, $part, $params);
        $st = $pdo->prepare("SELECT * FROM ({$sql}) AS u ORDER BY full_name LIMIT " . (EMP_LIMIT + 1));
        $st->execute($p);
        $rows = $st->fetchAll();
        if (count($rows) > EMP_LIMIT) { $truncated = true; array_pop($rows); }
    } catch (Throwable $e) {
        $err = $err ?: $e->getMessage();
    }
}

// ── Карточка для правки ──────────────────────────────────────
$edit = null;
if (isset($_GET['edit'], $_GET['region'])) {
    $r = adminRegion($pdo, (string)$_GET['region']);
    if ($r) {
        try {
            $rp = adminRegionPdo($pdo, $r);
            $st = $rp->prepare('SELECT * FROM employees WHERE id = ?');
            $st->execute([(int)$_GET['edit']]);
            if ($e = $st->fetch()) { $edit = $e; $edit['region_key'] = $r['region_key']; }
        } catch (Throwable $e) { $err = $err ?: $e->getMessage(); }
    }
}

adminHead('Сотрудники', 'employees');
?>
<h1>Сотрудники</h1>

<?php if ($msg): ?><div class="msg msg-ok"><?= adminEsc($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg msg-err"><?= adminEsc($err) ?></div><?php endif; ?>

<?php if (!$allRegions): ?>
    <div class="msg msg-warn">Реестр регионов пуст — добавьте регионы на странице «Регионы».</div>
<?php else: ?>

<form method="get" class="card">
    <div class="field">
        <label>Регионы <span class="muted">(ничего не отмечено — значит все)</span></label>
        <div class="regbox">
        <?php foreach ($allRegions as $key => $r): ?>
            <label><input type="checkbox" name="regions[]" value="<?= adminEsc($key) ?>"
                <?= count($selected) !== count($allRegions) && isset($selected[$key]) ? 'checked' : '' ?>>
                <?= adminEsc($r['label']) ?></label>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
        <div class="field"><label>Поиск</label>
            <input type="text" name="search" style="width:100%" placeholder="ФИО, организация, подразделение"
                   value="<?= adminEsc($search) ?>"></div>
        <div class="field"><label>&nbsp;</label>
            <label style="text-transform:none;font-size:14px;display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="show_all" value="1" <?= $onlyActive ? '' : 'checked' ?>>
                Показывать и уволенных
            </label></div>
    </div>
    <button class="btn" type="submit">Показать</button>
</form>

<?php if ($truncated): ?>
<div class="msg msg-warn">Показаны первые <?= EMP_LIMIT ?> — уточните поиск.</div>
<?php endif; ?>

<div class="card">
<table>
<tr><th>Регион</th><th>ФИО</th><th>Организация</th><th>Подразделение</th><th>Должность</th>
    <th>Категория</th><th>Цена</th><th>Состояние</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr>
    <td><span class="pill pill-reg"><?= adminEsc($allRegions[$r['region_key']]['label'] ?? $r['region_key']) ?></span></td>
    <td><strong><?= adminEsc($r['full_name']) ?></strong>
        <?php if ($r['role']): ?><br><span class="muted"><?= adminEsc($r['role']) ?></span><?php endif; ?></td>
    <td class="muted"><?= adminEsc($r['organization'] ?? '') ?></td>
    <td class="muted"><?= adminEsc($r['department'] ?? '') ?></td>
    <td class="muted"><?= adminEsc($r['position'] ?? '') ?></td>
    <td class="muted"><?= adminEsc($r['vjg_type'] ?? '') ?></td>
    <td class="muted"><?= number_format((float)$r['price'], 2, ',', ' ') ?></td>
    <td><?= (int)$r['is_active']
            ? '<span class="pill pill-ok">работает</span>'
            : '<span class="pill pill-off">уволен</span>' ?></td>
    <td><?php if (adminIsOwner()): ?>
        <a class="btn btn-sec" href="?edit=<?= (int)$r['id'] ?>&region=<?= adminEsc($r['region_key']) ?>">Править</a>
    <?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows && !$err): ?><tr><td colspan="9" class="muted">Никого не найдено.</td></tr><?php endif; ?>
</table>
</div>

<?php if (adminIsOwner()): ?>
<h2><?= $edit ? 'Правка сотрудника' : 'Добавить сотрудника' ?></h2>
<form method="post" class="card">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

    <div class="field">
        <label>Регион</label>
        <?php if ($edit): ?>
            <input type="hidden" name="region_key" value="<?= adminEsc($edit['region_key']) ?>">
            <p><strong><?= adminEsc($allRegions[$edit['region_key']]['label'] ?? $edit['region_key']) ?></strong>
               <span class="muted">— регион у существующей записи не меняется</span></p>
        <?php else: ?>
            <select name="region_key" required style="min-width:240px">
                <option value="">— выберите —</option>
                <?php foreach ($allRegions as $key => $r): ?>
                    <option value="<?= adminEsc($key) ?>"><?= adminEsc($r['label']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
        <div class="field"><label>ФИО</label>
            <input type="text" name="full_name" required style="width:100%"
                   value="<?= adminEsc($edit['full_name'] ?? '') ?>"></div>
        <div class="field"><label>Дата рождения</label>
            <input type="date" name="birth_date" style="width:100%"
                   value="<?= adminEsc($edit['birth_date'] ?? '') ?>"></div>
        <div class="field"><label>Организация</label>
            <input type="text" name="organization" required style="width:100%"
                   value="<?= adminEsc($edit['organization'] ?? '') ?>"></div>
        <div class="field"><label>Подразделение</label>
            <input type="text" name="department" style="width:100%"
                   value="<?= adminEsc($edit['department'] ?? '') ?>"></div>
        <div class="field"><label>Должность</label>
            <input type="text" name="position" style="width:100%"
                   value="<?= adminEsc($edit['position'] ?? '') ?>"></div>
        <div class="field"><label>Категория (ВЖГ)</label>
            <input type="text" name="vjg_type" style="width:100%"
                   value="<?= adminEsc($edit['vjg_type'] ?? '') ?>"></div>
        <div class="field"><label>Цена питания</label>
            <input type="number" step="0.01" name="price" style="width:100%"
                   value="<?= adminEsc((string)($edit['price'] ?? '0')) ?>"></div>
        <div class="field"><label>Состояние карты</label>
            <select name="qr_status" style="width:100%">
                <?php foreach (['active'=>'Действует','expired'=>'Истекла','blocked'=>'Заблокирована'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= ($edit['qr_status'] ?? 'active') === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select></div>
    </div>

    <div class="field">
        <label style="text-transform:none;font-size:14px;display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="is_active" <?= !$edit || (int)$edit['is_active'] ? 'checked' : '' ?>>
            Работает
        </label>
    </div>

    <p class="muted" style="margin-bottom:12px">
        QR-код при правке не меняется — карта на руках продолжает работать.
        Роль и привязка к точке задаются в системе самого региона.
    </p>

    <button class="btn" type="submit"><?= $edit ? 'Сохранить' : 'Добавить' ?></button>
    <?php if ($edit): ?><a class="btn btn-sec" href="employees.php">Отмена</a><?php endif; ?>
</form>
<?php endif; ?>

<?php endif; adminFoot();
