<?php
/**
 * Заказчики: реквизиты, договор, контакты.
 *
 * Отдельно от регионов намеренно: у одного заказчика может быть несколько
 * площадок, а у площадки со временем может смениться заказчик.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/layout.php';
adminRequireLogin();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminRequireOwner();
    try {
        $id = (int)($_POST['id'] ?? 0);
        $f = [
            'name'          => trim((string)($_POST['name'] ?? '')),
            'inn'           => trim((string)($_POST['inn'] ?? '')) ?: null,
            'contact_name'  => trim((string)($_POST['contact_name'] ?? '')) ?: null,
            'contact_phone' => trim((string)($_POST['contact_phone'] ?? '')) ?: null,
            'contact_email' => trim((string)($_POST['contact_email'] ?? '')) ?: null,
            'contract_no'   => trim((string)($_POST['contract_no'] ?? '')) ?: null,
            'contract_date' => trim((string)($_POST['contract_date'] ?? '')) ?: null,
            'notes'         => trim((string)($_POST['notes'] ?? '')) ?: null,
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($f['name'] === '') throw new RuntimeException('Укажите название заказчика');

        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            $pdo->prepare("UPDATE customers SET {$set} WHERE id = ?")->execute([...array_values($f), $id]);
        } else {
            $cols = implode(', ', array_keys($f));
            $ph   = implode(', ', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO customers ({$cols}) VALUES ({$ph})")->execute(array_values($f));
        }
        adminAudit($pdo, 'customer_save', $f['name']);
        $msg = "Заказчик «{$f['name']}» сохранён";
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$customers = $pdo->query(
    'SELECT c.*, (SELECT COUNT(*) FROM regions r WHERE r.customer_id = c.id) AS regions_count
     FROM customers c ORDER BY c.is_active DESC, c.name'
)->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    foreach ($customers as $c) if ((int)$c['id'] === (int)$_GET['edit']) $edit = $c;
}

adminHead('Заказчики', 'customers');
?>
<h1>Заказчики</h1>

<?php if ($msg): ?><div class="msg msg-ok"><?= adminEsc($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg msg-err"><?= adminEsc($err) ?></div><?php endif; ?>

<div class="card">
<table>
<tr><th>Название</th><th>ИНН</th><th>Контакт</th><th>Договор</th><th>Площадок</th><th></th></tr>
<?php foreach ($customers as $c): ?>
<tr>
    <td><strong><?= adminEsc($c['name']) ?></strong>
        <?php if (!(int)$c['is_active']): ?> <span class="pill pill-off">неактивен</span><?php endif; ?>
        <?php if ($c['notes']): ?><div class="muted"><?= adminEsc($c['notes']) ?></div><?php endif; ?></td>
    <td class="muted"><?= adminEsc($c['inn'] ?? '—') ?></td>
    <td class="muted">
        <?= adminEsc($c['contact_name'] ?? '') ?>
        <?php if ($c['contact_phone']): ?><br><?= adminEsc($c['contact_phone']) ?><?php endif; ?>
        <?php if ($c['contact_email']): ?><br><?= adminEsc($c['contact_email']) ?><?php endif; ?>
    </td>
    <td class="muted"><?= adminEsc($c['contract_no'] ?? '—') ?>
        <?php if ($c['contract_date']): ?><br><?= adminEsc($c['contract_date']) ?><?php endif; ?></td>
    <td><?= (int)$c['regions_count'] ?></td>
    <td><?php if (adminIsOwner()): ?><a class="btn btn-sec" href="?edit=<?= (int)$c['id'] ?>">Править</a><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$customers): ?><tr><td colspan="6" class="muted">Заказчиков пока нет.</td></tr><?php endif; ?>
</table>
</div>

<?php if (adminIsOwner()): ?>
<h2><?= $edit ? 'Правка заказчика' : 'Добавить заказчика' ?></h2>
<form method="post" class="card">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
        <div class="field"><label>Название</label>
            <input type="text" name="name" required style="width:100%" value="<?= adminEsc($edit['name'] ?? '') ?>"></div>
        <div class="field"><label>ИНН</label>
            <input type="text" name="inn" style="width:100%" value="<?= adminEsc($edit['inn'] ?? '') ?>"></div>
        <div class="field"><label>Контактное лицо</label>
            <input type="text" name="contact_name" style="width:100%" value="<?= adminEsc($edit['contact_name'] ?? '') ?>"></div>
        <div class="field"><label>Телефон</label>
            <input type="text" name="contact_phone" style="width:100%" value="<?= adminEsc($edit['contact_phone'] ?? '') ?>"></div>
        <div class="field"><label>Почта</label>
            <input type="text" name="contact_email" style="width:100%" value="<?= adminEsc($edit['contact_email'] ?? '') ?>"></div>
        <div class="field"><label>Номер договора</label>
            <input type="text" name="contract_no" style="width:100%" value="<?= adminEsc($edit['contract_no'] ?? '') ?>"></div>
        <div class="field"><label>Дата договора</label>
            <input type="date" name="contract_date" style="width:100%" value="<?= adminEsc($edit['contract_date'] ?? '') ?>"></div>
    </div>
    <div class="field"><label>Примечание</label>
        <textarea name="notes" rows="2" style="width:100%"><?= adminEsc($edit['notes'] ?? '') ?></textarea></div>
    <div class="field">
        <label style="text-transform:none;font-size:14px;display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="is_active" <?= !$edit || (int)$edit['is_active'] ? 'checked' : '' ?>> Активен
        </label>
    </div>
    <button class="btn" type="submit"><?= $edit ? 'Сохранить' : 'Добавить' ?></button>
    <?php if ($edit): ?><a class="btn btn-sec" href="customers.php">Отмена</a><?php endif; ?>
</form>
<?php endif; ?>

<?php adminFoot();
