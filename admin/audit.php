<?php
/**
 * Журнал действий админки.
 *
 * Площадка видит персональные данные всех регионов, поэтому след каждого
 * действия — обязательная часть, а не украшение.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/layout.php';
adminRequireLogin();

const AUDIT_PER_PAGE = 100;

$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * AUDIT_PER_PAGE;

$total = (int)$pdo->query('SELECT COUNT(*) FROM admin_audit')->fetchColumn();
$st = $pdo->prepare('SELECT * FROM admin_audit ORDER BY created_at DESC LIMIT ? OFFSET ?');
$st->bindValue(1, AUDIT_PER_PAGE, PDO::PARAM_INT);
$st->bindValue(2, $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

$pages   = max(1, (int)ceil($total / AUDIT_PER_PAGE));
$regions = adminRegions($pdo, false);

$actionLabels = [
    'login'         => 'Вход',
    'logout'        => 'Выход',
    'export'        => 'Выгрузка',
    'region_create' => 'Регион добавлен',
    'region_update' => 'Регион изменён',
    'region_delete' => 'Регион убран',
    'customer_save' => 'Заказчик сохранён',
];

adminHead('Журнал', 'audit');
?>
<h1><i class="fas fa-clock-rotate-left"></i> Журнал действий <span class="muted">(<?= $total ?>)</span></h1>

<div class="card">
<table>
<tr><th>Когда (UTC)</th><th>Кто</th><th>Действие</th><th>Регион</th><th>Подробности</th><th>Адрес</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
    <td class="muted"><?= adminEsc((string)$r['created_at']) ?></td>
    <td><?= adminEsc($r['user_login'] ?? '—') ?></td>
    <td><?= adminEsc($actionLabels[$r['action']] ?? $r['action']) ?></td>
    <td class="muted"><?= $r['region_key'] ? adminEsc($regions[$r['region_key']]['label'] ?? $r['region_key']) : '—' ?></td>
    <td class="muted"><?= adminEsc($r['details'] ?? '') ?></td>
    <td class="muted"><?= adminEsc($r['ip_address'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">Записей пока нет.</td></tr><?php endif; ?>
</table>
</div>

<?php if ($pages > 1): ?>
<div class="card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if ($page > 1): ?><a class="btn btn-sec" href="?page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i> Назад</a><?php endif; ?>
    <span class="muted">Страница <?= $page ?> из <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-sec" href="?page=<?= $page + 1 ?>">Вперёд <i class="fas fa-chevron-right"></i></a><?php endif; ?>
</div>
<?php endif; ?>

<?php adminFoot();
