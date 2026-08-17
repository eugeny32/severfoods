<?php
/**
 * Единый пользователь для всех площадок.
 *
 * Единой базы сотрудников нет: у каждого региона своя, и идентификаторы в них
 * пересекаются. «Один человек на всех площадках» здесь означает буквально
 * одну и ту же карту: сотрудник заводится в КАЖДОЙ региональной базе с ОДНИМ
 * И ТЕМ ЖЕ значением qr_code. Тогда одна карта открывает вход на любом
 * домене, а оффлайн-приложения на точках получат её обычной синхронизацией
 * своего региона — ничего дорабатывать на точках не нужно.
 *
 * Почему именно так, а не через общую таблицу: вход на площадке — это
 * `SELECT … FROM employees WHERE qr_code = ?` в базе этой площадки
 * (login.php), и то же самое делает оффлайн-приложение по своей локальной
 * копии. Совпадение qr_code — единственное, что делает карту общей, не трогая
 * ни одну строчку кода регионов.
 *
 * QR-код при синхронизации НИКОГДА не меняется: он и есть общий ключ, а карта
 * уже на руках.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/layout.php';
adminRequireLogin();

const GLOBAL_USER_ROLES = ['' => 'без прав (обычный сотрудник)',
                           'operator' => 'оператор',
                           'admin' => 'администратор',
                           'super_admin' => 'супер-администратор'];

$msg = '';
$err = '';
$results = [];

/** Значения формы: из POST, из GET или предустановленные. */
$form = [
    'qr_code'      => trim((string)($_POST['qr_code'] ?? $_GET['qr'] ?? '')),
    'full_name'    => trim((string)($_POST['full_name'] ?? '')),
    'organization' => trim((string)($_POST['organization'] ?? '')),
    'department'   => trim((string)($_POST['department'] ?? '')),
    'position'     => trim((string)($_POST['position'] ?? '')),
    'role'         => (string)($_POST['role'] ?? 'super_admin'),
    'is_active'    => $_SERVER['REQUEST_METHOD'] === 'POST' ? (isset($_POST['is_active']) ? 1 : 0) : 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    adminRequireOwner();
    try {
        if (!preg_match('/^[A-Za-z0-9_\-]{4,100}$/', $form['qr_code'])) {
            throw new RuntimeException('QR-код: латиница, цифры, подчёркивание и дефис, 4–100 символов');
        }
        if ($form['full_name'] === '' || $form['organization'] === '') {
            throw new RuntimeException('Заполните ФИО и организацию');
        }
        if (!array_key_exists($form['role'], GLOBAL_USER_ROLES)) {
            throw new RuntimeException('Неизвестная роль');
        }

        $regions = adminRegions($pdo, true);
        if (!$regions) throw new RuntimeException('В реестре нет активных регионов');

        foreach ($regions as $key => $r) {
            $res = ['region' => $r, 'state' => 'error', 'note' => ''];
            try {
                // Запись всегда идёт отдельным соединением к базе региона.
                // Кросс-базовый UNION годится для чтения, но не для записи:
                // адресат правки должен быть выбран явно.
                $rp = adminRegionPdo($pdo, $r);

                $st = $rp->prepare('SELECT id, full_name, role FROM employees WHERE qr_code = ? LIMIT 1');
                $st->execute([$form['qr_code']]);
                $existing = $st->fetch();

                if ($existing) {
                    $rp->prepare(
                        'UPDATE employees SET full_name = ?, organization = ?, department = ?,
                                position = ?, role = ?, is_active = ?, qr_status = ?
                         WHERE id = ?'
                    )->execute([
                        $form['full_name'], $form['organization'], $form['department'],
                        $form['position'], $form['role'] !== '' ? $form['role'] : null,
                        $form['is_active'], $form['is_active'] ? 'active' : 'blocked',
                        (int)$existing['id'],
                    ]);
                    $res['state'] = 'updated';
                    $res['note']  = 'ID ' . (int)$existing['id'] . ' — обновлён';
                } else {
                    // Дата рождения остаётся NULL: выдуманные персональные
                    // данные хуже отсутствующих. Точка не назначается — эта
                    // карта должна работать на всех точках региона.
                    $rp->prepare(
                        'INSERT INTO employees
                            (full_name, birth_date, organization, department, position,
                             vjg_type, price, qr_code, qr_expires_at, qr_status,
                             is_active, role, assigned_point_id)
                         VALUES (?, NULL, ?, ?, ?, \'\', 0, ?, NULL, ?, ?, ?, NULL)'
                    )->execute([
                        $form['full_name'], $form['organization'], $form['department'],
                        $form['position'], $form['qr_code'],
                        $form['is_active'] ? 'active' : 'blocked',
                        $form['is_active'], $form['role'] !== '' ? $form['role'] : null,
                    ]);
                    $res['state'] = 'created';
                    $res['note']  = 'ID ' . (int)$rp->lastInsertId() . ' — создан';
                }
            } catch (Throwable $e) {
                $res['note'] = $e->getMessage();
            }
            $results[$key] = $res;
        }

        $done = count(array_filter($results, fn($x) => $x['state'] !== 'error'));
        adminAudit($pdo, 'global_user_sync',
            "Единый доступ: {$form['full_name']} ({$form['qr_code']}), регионов: {$done} из " . count($results));
        $msg = "Синхронизировано регионов: {$done} из " . count($results);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// Текущее состояние карты по всем регионам — показывается всегда, когда код
// указан: это же и проверка результата после синхронизации.
$state = [];
if ($form['qr_code'] !== '' && preg_match('/^[A-Za-z0-9_\-]{4,100}$/', $form['qr_code'])) {
    foreach (adminRegions($pdo, false) as $key => $r) {
        $row = ['region' => $r, 'found' => false, 'error' => '', 'emp' => null];
        try {
            $db = adminQuoteDb((string)$r['db_name']);
            $st = $pdo->prepare("SELECT id, full_name, organization, department, position, role, is_active, qr_status
                                 FROM {$db}.employees WHERE qr_code = ? LIMIT 1");
            $st->execute([$form['qr_code']]);
            if ($e = $st->fetch()) { $row['found'] = true; $row['emp'] = $e; }
        } catch (Throwable $e) {
            $row['error'] = adminDbErrorHint($e, (string)$r['db_name'], ADMIN_DB_USER);
        }
        $state[$key] = $row;
    }

    // Форму удобно заполнять по уже существующей записи — но только если
    // человек ничего не ввёл сам, иначе правка затиралась бы при ошибке.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        foreach ($state as $s) {
            if ($s['found']) {
                $form['full_name']    = (string)$s['emp']['full_name'];
                $form['organization'] = (string)$s['emp']['organization'];
                $form['department']   = (string)$s['emp']['department'];
                $form['position']     = (string)$s['emp']['position'];
                $form['role']         = (string)($s['emp']['role'] ?? '');
                $form['is_active']    = (int)$s['emp']['is_active'];
                break;
            }
        }
    }
}

adminHead('Единый доступ', 'global');
?>
<h1>Единый пользователь для всех площадок</h1>

<?php if ($msg): ?><div class="msg msg-ok"><?= adminEsc($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg msg-err"><?= adminEsc($err) ?></div><?php endif; ?>

<div class="card">
    <p class="muted">
        Одна карта на все регионы — это одна и та же запись <code>qr_code</code> в базе каждой
        площадки. Здесь она заводится сразу везде: где такого кода нет — сотрудник создаётся,
        где есть — обновляются данные и роль. <strong>Сам QR-код не меняется никогда</strong>,
        карта на руках продолжает работать.
        <br><br>
        Единого входа между админкой и площадками нет: там вход по QR-коду, здесь по паролю.
        Ссылки на площадки — в синей полосе вверху страницы и в таблице ниже.
    </p>
</div>

<?php if ($results): ?>
<h2>Результат синхронизации</h2>
<div class="card">
<table>
<tr><th>Регион</th><th>Что сделано</th><th>Подробности</th></tr>
<?php foreach ($results as $res): ?>
<tr>
    <td><strong><?= adminEsc($res['region']['label']) ?></strong></td>
    <td>
        <?php if ($res['state'] === 'created'): ?><span class="pill pill-ok">создан</span>
        <?php elseif ($res['state'] === 'updated'): ?><span class="pill pill-reg">обновлён</span>
        <?php else: ?><span class="pill pill-off">ошибка</span><?php endif; ?>
    </td>
    <td class="muted"><?= adminEsc($res['note']) ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<h2>Проверка карты по регионам</h2>
<form method="get" class="card">
    <div class="field" style="max-width:520px">
        <label>QR-код карты</label>
        <input type="text" name="qr" style="width:100%" value="<?= adminEsc($form['qr_code']) ?>"
               placeholder="EMP_1781069014_35643de852d5">
    </div>
    <button class="btn" type="submit">Проверить</button>
</form>

<?php if ($state): ?>
<div class="card">
<table>
<tr><th>Регион</th><th>Карта</th><th>ФИО</th><th>Организация</th><th>Роль</th><th>Площадка</th></tr>
<?php foreach ($state as $key => $s): $r = $s['region']; $url = adminRegionUrl($r); ?>
<tr>
    <td><strong><?= adminEsc($r['label']) ?></strong><br><span class="muted"><?= adminEsc($key) ?></span></td>
    <td>
        <?php if ($s['error']): ?><span class="pill pill-off">база недоступна</span>
        <?php elseif ($s['found']): ?>
            <span class="pill <?= (int)$s['emp']['is_active'] ? 'pill-ok' : 'pill-off' ?>">
                <?= (int)$s['emp']['is_active'] ? 'работает' : 'выключена' ?></span>
        <?php else: ?><span class="pill pill-off">нет</span><?php endif; ?>
    </td>
    <?php if ($s['error']): ?>
        <td colspan="3" class="muted"><?= adminEsc($s['error']) ?></td>
    <?php elseif ($s['found']): ?>
        <td><?= adminEsc($s['emp']['full_name']) ?></td>
        <td class="muted"><?= adminEsc($s['emp']['organization']) ?></td>
        <td class="muted"><?= adminEsc(GLOBAL_USER_ROLES[(string)($s['emp']['role'] ?? '')] ?? (string)$s['emp']['role']) ?></td>
    <?php else: ?>
        <td colspan="3" class="muted">Карта в этом регионе не заведена</td>
    <?php endif; ?>
    <td><?php if ($url): ?><a class="btn btn-sec" href="<?= adminEsc($url) ?>" target="_blank" rel="noopener">Войти ↗</a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php if (adminIsOwner()): ?>
<h2>Завести или обновить во всех регионах</h2>
<form method="post" class="card"
      onsubmit="return confirm('Записать этого сотрудника во все активные регионы?')">
    <input type="hidden" name="action" value="sync">

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
        <div class="field"><label>QR-код (общий ключ)</label>
            <input type="text" name="qr_code" required style="width:100%"
                   value="<?= adminEsc($form['qr_code']) ?>" placeholder="EMP_1781069014_35643de852d5"></div>
        <div class="field"><label>ФИО</label>
            <input type="text" name="full_name" required style="width:100%"
                   value="<?= adminEsc($form['full_name']) ?>"></div>
        <div class="field"><label>Организация</label>
            <input type="text" name="organization" required style="width:100%"
                   value="<?= adminEsc($form['organization']) ?>"></div>
        <div class="field"><label>Подразделение</label>
            <input type="text" name="department" style="width:100%"
                   value="<?= adminEsc($form['department']) ?>"></div>
        <div class="field"><label>Должность</label>
            <input type="text" name="position" style="width:100%"
                   value="<?= adminEsc($form['position']) ?>"></div>
        <div class="field"><label>Роль на площадках</label>
            <select name="role" style="width:100%">
                <?php foreach (GLOBAL_USER_ROLES as $v => $label): ?>
                    <option value="<?= adminEsc($v) ?>" <?= $form['role'] === $v ? 'selected' : '' ?>>
                        <?= adminEsc($label) ?></option>
                <?php endforeach; ?>
            </select></div>
    </div>

    <div class="field">
        <label style="text-transform:none;font-size:14px;display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="is_active" <?= $form['is_active'] ? 'checked' : '' ?>> Карта активна
        </label>
    </div>

    <p class="muted" style="margin-bottom:12px">
        Роль «супер-администратор» даёт полный доступ к площадке, включая карточки других
        супер-администраторов. Дата рождения не заполняется, точка не назначается — карта
        действует на всех точках своего региона.
    </p>

    <button class="btn" type="submit">Записать во все регионы</button>
</form>
<?php else: ?>
<p class="muted">Изменения доступны только владельцу учётной записи.</p>
<?php endif; ?>

<?php adminFoot();
