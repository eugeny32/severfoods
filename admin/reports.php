<?php
/**
 * Сводные отчёты по всем регионам сразу или по выбранным.
 *
 * Запросы собираются в src/report_query.php — тем же кодом, что и выгрузка в
 * Excel, чтобы цифры на экране и в файле не могли разойтись.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/report_query.php';
require_once __DIR__ . '/src/layout.php';
adminRequireLogin();

const REPORT_LIMIT = 1000;

$allRegions = adminRegions($pdo);
$selected   = adminSelectedRegions($pdo, $_GET['regions'] ?? null);
$type       = ($_GET['type'] ?? 'meals') === 'rations' ? 'rations' : 'meals';

$filters = [
    'date_from' => trim((string)($_GET['date_from'] ?? gmdate('Y-m-01'))),
    'date_to'   => trim((string)($_GET['date_to']   ?? gmdate('Y-m-d'))),
    'meal_type' => trim((string)($_GET['meal_type'] ?? '')),
    'source'    => trim((string)($_GET['source']    ?? '')),
    'dry_type'  => trim((string)($_GET['dry_type']  ?? '')),
    'search'    => trim((string)($_GET['search']    ?? '')),
];

$rows = [];
$summary = [];
$error = '';
$truncated = false;

if ($allRegions) {
    try {
        if ($type === 'meals') {
            [$sql, $params] = adminMealsQuery($selected, $filters);
            $st = $pdo->prepare("SELECT * FROM ({$sql}) AS u ORDER BY scanned_at DESC LIMIT " . (REPORT_LIMIT + 1));
            $st->execute($params);
            $rows = $st->fetchAll();

            [$sSql, $sParams] = adminMealsSummaryQuery($selected, $filters);
            $ss = $pdo->prepare($sSql);
            $ss->execute($sParams);
            $summary = $ss->fetchAll();
        } else {
            [$sql, $params] = adminRationsQuery($selected, $filters);
            $st = $pdo->prepare("SELECT * FROM ({$sql}) AS u ORDER BY issue_date DESC LIMIT " . (REPORT_LIMIT + 1));
            $st->execute($params);
            $rows = $st->fetchAll();
        }

        if (count($rows) > REPORT_LIMIT) {
            $truncated = true;
            array_pop($rows);
        }
    } catch (Throwable $e) {
        // Показываем причину, а не пустую страницу: чаще всего это недоступная
        // база или расхождение схем между регионами.
        $error = $e->getMessage();
    }
}

$qs = fn(array $over = []) => http_build_query(array_merge([
    'type' => $type, 'regions' => array_keys($selected),
], $filters, $over));

adminHead('Отчёты', 'reports');
?>
<h1>Сводные отчёты</h1>

<?php if (!$allRegions): ?>
    <div class="msg msg-warn">Реестр регионов пуст — добавьте регионы на странице «Регионы».</div>
<?php else: ?>

<form method="get" class="card">
    <input type="hidden" name="type" value="<?= adminEsc($type) ?>">

    <div class="field">
        <label>Регионы <span class="muted">(ничего не отмечено — значит все)</span></label>
        <div class="regbox">
        <?php foreach ($allRegions as $key => $r): ?>
            <label>
                <input type="checkbox" name="regions[]" value="<?= adminEsc($key) ?>"
                    <?= count($selected) !== count($allRegions) && isset($selected[$key]) ? 'checked' : '' ?>>
                <?= adminEsc($r['label']) ?>
            </label>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
        <div class="field"><label>С даты</label>
            <input type="date" name="date_from" value="<?= adminEsc($filters['date_from']) ?>"></div>
        <div class="field"><label>По дату</label>
            <input type="date" name="date_to" value="<?= adminEsc($filters['date_to']) ?>"></div>

        <?php if ($type === 'meals'): ?>
        <div class="field"><label>Приём пищи</label>
            <select name="meal_type">
                <option value="">Все</option>
                <?php foreach (['breakfast','lunch','dinner','night'] as $mt): ?>
                    <option value="<?= $mt ?>" <?= $filters['meal_type'] === $mt ? 'selected' : '' ?>>
                        <?= adminEsc(adminMealLabel($mt)) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Способ проводки</label>
            <select name="source">
                <option value="">Любой</option>
                <?php foreach (['scanner'=>'Сканер','bulk'=>'Массовая','manual'=>'Ручная','offline'=>'Оффлайн'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $filters['source'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select></div>
        <?php else: ?>
        <div class="field"><label>Вид</label>
            <select name="dry_type">
                <option value="">Все</option>
                <option value="dry_ration" <?= $filters['dry_type']==='dry_ration'?'selected':'' ?>>Сухпай</option>
                <option value="field"      <?= $filters['dry_type']==='field'?'selected':'' ?>>Выездное</option>
            </select></div>
        <?php endif; ?>

        <div class="field"><label>Поиск</label>
            <input type="text" name="search" placeholder="ФИО или организация"
                   value="<?= adminEsc($filters['search']) ?>"></div>
    </div>

    <button class="btn" type="submit">Показать</button>
    <a class="btn btn-sec" href="?<?= adminEsc($qs(['type' => $type === 'meals' ? 'rations' : 'meals'])) ?>">
        <?= $type === 'meals' ? 'Сухпаи и выездное' : 'Журнал питания' ?>
    </a>
    <a class="btn btn-sec" href="export_excel.php?<?= adminEsc($qs()) ?>">Выгрузить в Excel</a>
</form>

<?php if ($error): ?>
    <div class="msg msg-err">
        <strong>Не удалось построить отчёт.</strong><br><?= adminEsc($error) ?><br>
        <span class="muted">Чаще всего причина в недоступной базе региона или в том, что в одной
        из баз не хватает колонки. Состояние баз видно на странице «Обзор».</span>
    </div>
<?php endif; ?>

<?php if ($summary): ?>
    <h2>Итоги</h2>
    <div class="card">
    <table>
        <tr><th>Регион</th><th>Приём пищи</th><th>Порций</th><th>Сумма</th></tr>
        <?php $tc = 0; $ts = 0.0; foreach ($summary as $s):
            $tc += (int)$s['cnt']; $ts += (float)$s['total']; ?>
        <tr>
            <td><span class="pill pill-reg"><?= adminEsc($allRegions[$s['region_key']]['label'] ?? $s['region_key']) ?></span></td>
            <td><?= adminEsc(adminMealLabel($s['meal_type'])) ?></td>
            <td><?= (int)$s['cnt'] ?></td>
            <td><?= number_format((float)$s['total'], 2, ',', ' ') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr><td colspan="2"><strong>Всего</strong></td>
            <td><strong><?= $tc ?></strong></td>
            <td><strong><?= number_format($ts, 2, ',', ' ') ?></strong></td></tr>
    </table>
    </div>
<?php endif; ?>

<?php if ($truncated): ?>
    <div class="msg msg-warn">
        Показаны первые <?= REPORT_LIMIT ?> записей — сузьте период или фильтры.
        В выгрузке в Excel ограничения нет.
    </div>
<?php endif; ?>

<h2><?= $type === 'meals' ? 'Журнал питания' : 'Сухпаи и выездное питание' ?>
    <span class="muted">(<?= count($rows) ?>)</span></h2>
<div class="card">
<table>
<?php if ($type === 'meals'): ?>
    <tr><th>Регион</th><th>Дата и время</th><th>Сотрудник</th><th>Организация</th>
        <th>Подразделение</th><th>Приём пищи</th><th>Точка</th><th>Проводка</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><span class="pill pill-reg"><?= adminEsc($allRegions[$r['region_key']]['label'] ?? $r['region_key']) ?></span></td>
        <td><?= adminEsc((string)$r['local_at']) ?></td>
        <td><?= adminEsc($r['full_name'] ?? '—') ?></td>
        <td class="muted"><?= adminEsc($r['organization'] ?? '') ?></td>
        <td class="muted"><?= adminEsc($r['department'] ?? '') ?></td>
        <td><?= adminEsc(adminMealLabel($r['meal_type'])) ?></td>
        <td class="muted"><?= adminEsc($r['point_name'] ?? '—') ?></td>
        <td class="muted"><?= adminEsc($r['operator_name'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr><th>Регион</th><th>Дата выдачи</th><th>Сотрудник</th><th>Организация</th>
        <th>Подразделение</th><th>Вид</th><th>Статус</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><span class="pill pill-reg"><?= adminEsc($allRegions[$r['region_key']]['label'] ?? $r['region_key']) ?></span></td>
        <td><?= adminEsc((string)$r['issue_date']) ?></td>
        <td><?= adminEsc($r['full_name'] ?? '—') ?></td>
        <td class="muted"><?= adminEsc($r['organization'] ?? '') ?></td>
        <td class="muted"><?= adminEsc($r['department'] ?? '') ?></td>
        <td><?= $r['dry_type'] === 'field' ? 'Выездное' : 'Сухпай' ?></td>
        <td class="muted"><?= adminEsc($r['status'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
<?php endif; ?>
<?php if (!$rows && !$error): ?>
    <tr><td colspan="8" class="muted">За выбранный период записей нет.</td></tr>
<?php endif; ?>
</table>
</div>

<?php endif; adminFoot();
