<?php
/**
 * Обзор: состояние всех регионов на одной странице.
 *
 * Каждый регион проверяется отдельно и в try/catch: одна недоступная база не
 * должна ронять всю страницу — наоборот, именно это и надо показать.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/layout.php';
adminRequireLogin();

$regions = adminRegions($pdo, false);
$today   = gmdate('Y-m-d');

$stats = [];
foreach ($regions as $key => $r) {
    $row = ['region' => $r, 'ok' => false, 'error' => '', 'employees' => 0, 'points' => 0,
            'meals_today' => 0, 'online' => 0, 'versions' => []];
    try {
        $db = adminQuoteDb($r['db_name']);
        $row['employees'] = (int)$pdo->query("SELECT COUNT(*) FROM {$db}.employees WHERE is_active = 1")->fetchColumn();
        $row['points']    = (int)$pdo->query("SELECT COUNT(*) FROM {$db}.meal_points WHERE is_active = 1")->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM {$db}.meal_logs
                             WHERE access_granted = 1 AND DATE(scanned_at) = ?");
        $st->execute([$today]);
        $row['meals_today'] = (int)$st->fetchColumn();

        // Таблицы присутствия может не быть, если удалённый доступ на этой
        // площадке ещё ни разу не использовался — это не ошибка региона.
        try {
            $row['online'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM {$db}.offline_presence
                 WHERE last_seen_at > (UTC_TIMESTAMP() - INTERVAL 90 SECOND)")->fetchColumn();
            foreach ($pdo->query("SELECT DISTINCT app_version FROM {$db}.offline_presence
                                  WHERE app_version IS NOT NULL ORDER BY app_version DESC") as $v) {
                $row['versions'][] = $v['app_version'];
            }
        } catch (PDOException $e) { /* точки ещё не выходили на связь */ }

        $row['ok'] = true;
    } catch (Throwable $e) {
        $row['error'] = $e->getMessage();
    }
    $stats[$key] = $row;
}

$broken = array_filter($stats, fn($s) => !$s['ok']);

adminHead('Обзор', 'index');
?>
<h1>Обзор регионов</h1>

<?php if ($broken): ?>
<div class="msg msg-err">
    <strong>Недоступны базы: <?= count($broken) ?>.</strong>
    Сводные отчёты по этим регионам строиться не будут. Проверьте имя базы в реестре
    и права пользователя MySQL.
</div>
<?php endif; ?>

<div class="card">
<table>
<tr>
    <th>Регион</th><th>Домен</th><th>Заказчик</th>
    <th>Сотрудников</th><th>Точек</th><th>Питание сегодня</th>
    <th>На связи</th><th>Версии</th><th>Состояние</th>
</tr>
<?php foreach ($stats as $key => $s): $r = $s['region']; ?>
<tr>
    <td><strong><?= adminEsc($r['label']) ?></strong><br><span class="muted"><?= adminEsc($key) ?></span></td>
    <td class="muted"><?= adminEsc($r['domain']) ?></td>
    <td class="muted"><?= adminEsc($r['customer_name'] ?? '—') ?></td>
    <?php if ($s['ok']): ?>
        <td><?= $s['employees'] ?></td>
        <td><?= $s['points'] ?></td>
        <td><?= $s['meals_today'] ?></td>
        <td><?= $s['online'] ?: '—' ?></td>
        <td class="muted"><?= $s['versions'] ? adminEsc(implode(', ', $s['versions'])) : '—' ?></td>
        <td>
            <?php if (!(int)$r['is_active']): ?>
                <span class="pill pill-off">выключен</span>
            <?php else: ?>
                <span class="pill pill-ok">работает</span>
            <?php endif; ?>
        </td>
    <?php else: ?>
        <td colspan="5" class="muted">База <code><?= adminEsc($r['db_name']) ?></code> недоступна:
            <?= adminEsc($s['error']) ?></td>
        <td><span class="pill pill-off">ошибка</span></td>
    <?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (!$stats): ?>
<tr><td colspan="9" class="muted">Реестр пуст. Добавьте регионы на странице «Регионы».</td></tr>
<?php endif; ?>
</table>
</div>

<p class="muted">
    «Питание сегодня» считается по календарной дате UTC и может отличаться от местных
    суток точки на несколько часов. Точные отчёты — на странице «Отчёты», там используется
    часовой пояс точки.
</p>

<?php adminFoot();
