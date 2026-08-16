<?php
/**
 * Реестр регионов: добавление, правка, проверка доступности базы.
 *
 * Ради этой страницы всё и затевалось: раньше список регионов был захардкожен
 * в getRegions() (src/regions.php основной системы), и новый регион требовал
 * правки PHP и передеплоя всех существующих площадок. Теперь это строка в
 * таблице.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/layout.php';
adminRequireLogin();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminRequireOwner();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $key  = trim((string)($_POST['region_key'] ?? ''));
            $db   = trim((string)($_POST['db_name'] ?? ''));
            $tz   = trim((string)($_POST['tz_offset'] ?? '+03:00'));

            if (!preg_match('/^[a-z0-9_]{2,32}$/', $key)) {
                throw new RuntimeException('Ключ региона: строчная латиница, цифры и подчёркивание, 2–32 символа');
            }
            // Имя базы попадает прямо в текст SQL сводных отчётов — проверяем
            // тем же правилом, что и при сборке запроса.
            adminQuoteDb($db);
            if (!preg_match('/^[+-]\d{2}:\d{2}$/', $tz)) {
                throw new RuntimeException('Часовой пояс в формате +07:00');
            }

            $fields = [
                'region_key'  => $key,
                'label'       => trim((string)($_POST['label'] ?? '')),
                'domain'      => trim((string)($_POST['domain'] ?? '')),
                'db_name'     => $db,
                'db_host'     => trim((string)($_POST['db_host'] ?? '')) ?: null,
                'db_user'     => trim((string)($_POST['db_user'] ?? '')) ?: null,
                'db_pass'     => trim((string)($_POST['db_pass'] ?? '')) ?: null,
                'customer_id' => ((int)($_POST['customer_id'] ?? 0)) ?: null,
                'tz_offset'   => $tz,
                'sort_order'  => (int)($_POST['sort_order'] ?? 0),
                'is_active'   => isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($fields['label'] === '' || $fields['domain'] === '') {
                throw new RuntimeException('Заполните название и домен');
            }

            if ($id) {
                $set = implode(', ', array_map(fn($f) => "$f = ?", array_keys($fields)));
                $st = $pdo->prepare("UPDATE regions SET {$set} WHERE id = ?");
                $st->execute([...array_values($fields), $id]);
                adminAudit($pdo, 'region_update', "Изменён регион {$key}", $key);
                $msg = "Регион «{$fields['label']}» сохранён";
            } else {
                $cols = implode(', ', array_keys($fields));
                $ph   = implode(', ', array_fill(0, count($fields), '?'));
                $pdo->prepare("INSERT INTO regions ({$cols}) VALUES ({$ph})")->execute(array_values($fields));
                adminAudit($pdo, 'region_create', "Добавлен регион {$key}", $key);
                $msg = "Регион «{$fields['label']}» добавлен";
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT region_key, label FROM regions WHERE id = ?');
            $st->execute([$id]);
            if ($r = $st->fetch()) {
                // Удаляется только запись реестра. База региона и всё, что в
                // ней, остаётся нетронутым — иначе одна ошибка стоила бы всех
                // данных площадки.
                $pdo->prepare('DELETE FROM regions WHERE id = ?')->execute([$id]);
                adminAudit($pdo, 'region_delete', "Удалён из реестра: {$r['label']}", $r['region_key']);
                $msg = "Регион «{$r['label']}» убран из реестра. База данных не тронута.";
            }
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$regions   = adminRegions($pdo, false);
$customers = $pdo->query('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name')->fetchAll();

// Проверка доступности: тот же способ, каким будут ходить сводные отчёты.
$health = [];
foreach ($regions as $key => $r) {
    try {
        $pdo->query('SELECT 1 FROM ' . adminQuoteDb($r['db_name']) . '.meal_logs LIMIT 1');
        $health[$key] = '';
    } catch (Throwable $e) {
        $health[$key] = $e->getMessage();
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    foreach ($regions as $r) if ((int)$r['id'] === (int)$_GET['edit']) $edit = $r;
}

adminHead('Регионы', 'regions');
?>
<h1>Регионы</h1>

<?php if ($msg): ?><div class="msg msg-ok"><?= adminEsc($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg msg-err"><?= adminEsc($err) ?></div><?php endif; ?>

<div class="card">
<table>
<tr><th>Регион</th><th>Домен</th><th>База</th><th>Часовой пояс</th><th>Заказчик</th><th>Доступ</th><th></th></tr>
<?php foreach ($regions as $key => $r): ?>
<tr>
    <td><strong><?= adminEsc($r['label']) ?></strong><br><span class="muted"><?= adminEsc($key) ?></span></td>
    <td class="muted"><?= adminEsc($r['domain']) ?></td>
    <td class="muted"><code><?= adminEsc($r['db_name']) ?></code></td>
    <td class="muted"><?= adminEsc($r['tz_offset']) ?></td>
    <td class="muted"><?= adminEsc($r['customer_name'] ?? '—') ?></td>
    <td>
        <?php if ($health[$key] === ''): ?>
            <span class="pill pill-ok">читается</span>
        <?php else: ?>
            <span class="pill pill-off">ошибка</span>
            <div class="muted" style="max-width:320px"><?= adminEsc($health[$key]) ?></div>
        <?php endif; ?>
        <?php if (!(int)$r['is_active']): ?> <span class="pill pill-off">выключен</span><?php endif; ?>
    </td>
    <td>
        <?php if (adminIsOwner()): ?>
        <a class="btn btn-sec" href="?edit=<?= (int)$r['id'] ?>">Править</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Убрать регион из реестра? База данных и все её записи останутся нетронутыми.')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-danger" type="submit">Убрать</button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (!$regions): ?><tr><td colspan="7" class="muted">Реестр пуст.</td></tr><?php endif; ?>
</table>
</div>

<?php if (adminIsOwner()): ?>
<h2><?= $edit ? 'Правка региона' : 'Добавить регион' ?></h2>
<form method="post" class="card">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
        <div class="field"><label>Ключ (латиницей)</label>
            <input type="text" name="region_key" required style="width:100%"
                   value="<?= adminEsc($edit['region_key'] ?? '') ?>" placeholder="tnd"></div>
        <div class="field"><label>Название</label>
            <input type="text" name="label" required style="width:100%"
                   value="<?= adminEsc($edit['label'] ?? '') ?>" placeholder="Тында"></div>
        <div class="field"><label>Домен</label>
            <input type="text" name="domain" required style="width:100%"
                   value="<?= adminEsc($edit['domain'] ?? '') ?>" placeholder="tnd.severfoods.ru"></div>
        <div class="field"><label>Имя базы</label>
            <input type="text" name="db_name" required style="width:100%"
                   value="<?= adminEsc($edit['db_name'] ?? '') ?>" placeholder="u3523560_canteen_tnd"></div>
        <div class="field"><label>Часовой пояс</label>
            <input type="text" name="tz_offset" style="width:100%"
                   value="<?= adminEsc($edit['tz_offset'] ?? '+03:00') ?>" placeholder="+09:00"></div>
        <div class="field"><label>Заказчик</label>
            <select name="customer_id" style="width:100%">
                <option value="">—</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                        <?= (int)($edit['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= adminEsc($c['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Порядок</label>
            <input type="number" name="sort_order" style="width:100%"
                   value="<?= (int)($edit['sort_order'] ?? 0) ?>"></div>
    </div>

    <p class="muted" style="margin-bottom:12px">
        Реквизиты ниже нужны, только если база лежит не на том же сервере, что админка.
        Сейчас все базы на одном MySQL — оставьте пустыми.
    </p>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
        <div class="field"><label>Сервер базы</label>
            <input type="text" name="db_host" style="width:100%" value="<?= adminEsc($edit['db_host'] ?? '') ?>"></div>
        <div class="field"><label>Пользователь</label>
            <input type="text" name="db_user" style="width:100%" value="<?= adminEsc($edit['db_user'] ?? '') ?>"></div>
        <div class="field"><label>Пароль</label>
            <input type="text" name="db_pass" style="width:100%" value="<?= adminEsc($edit['db_pass'] ?? '') ?>"></div>
    </div>

    <div class="field">
        <label style="text-transform:none;font-size:14px;display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="is_active" <?= !$edit || (int)$edit['is_active'] ? 'checked' : '' ?>>
            Регион активен — участвует в сводных отчётах
        </label>
    </div>

    <button class="btn" type="submit"><?= $edit ? 'Сохранить' : 'Добавить' ?></button>
    <?php if ($edit): ?><a class="btn btn-sec" href="regions.php">Отмена</a><?php endif; ?>
</form>
<?php endif; ?>

<?php adminFoot();
