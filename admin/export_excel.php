<?php
/**
 * Выгрузка сводного отчёта. Запросы берутся из того же модуля, что и на
 * странице отчётов, — цифры в файле и на экране обязаны совпадать.
 *
 * Формат — HTML-таблица с расширением .xls: так же, как в региональных
 * выгрузках. Excel открывает её без вопросов, а библиотек для настоящего xlsx
 * в проекте нет.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/report_query.php';
adminRequireLogin();

$allRegions = adminRegions($pdo);
$selected   = adminSelectedRegions($pdo, $_GET['regions'] ?? null);
$type = in_array($_GET['type'] ?? '', ['meals', 'rations', 'employees'], true)
    ? $_GET['type'] : 'meals';

$filters = [
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to'   => trim((string)($_GET['date_to']   ?? '')),
    'meal_type' => trim((string)($_GET['meal_type'] ?? '')),
    'source'    => trim((string)($_GET['source']    ?? '')),
    'dry_type'  => trim((string)($_GET['dry_type']  ?? '')),
    'search'    => trim((string)($_GET['search']    ?? '')),
];

// Ошибку показываем страницей, а не отдаём испорченный файл: получить в Excel
// пустую таблицу вместо данных хуже, чем не получить ничего.
try {
    if (!$allRegions) throw new RuntimeException('Реестр регионов пуст');

    if ($type === 'meals') {
        [$sql, $params] = adminMealsQuery($selected, $filters);
        $order = 'scanned_at DESC';
    } elseif ($type === 'employees') {
        [$sql, $params] = adminEmployeesQuery($selected, $filters);
        $order = 'organization, full_name';
    } else {
        [$sql, $params] = adminRationsQuery($selected, $filters);
        $order = 'issue_date DESC';
    }
    $st = $pdo->prepare("SELECT * FROM ({$sql}) AS u ORDER BY {$order}");
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    exit('<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Ошибка выгрузки</title></head><body>'
        . '<h1>Не удалось сформировать выгрузку</h1><p>'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>'
        . '<p><a href="javascript:history.back()">Вернуться к отчёту</a></p></body></html>');
}

$regionLabel = fn(string $k): string => $allRegions[$k]['label'] ?? $k;

ob_start();
echo "\xEF\xBB\xBF"; // BOM — иначе Excel открывает кириллицу как мусор
?>
<table border="1">
<?php if ($type === 'meals'): ?>
<tr><th>Регион</th><th>Дата и время</th><th>Сотрудник</th><th>Организация</th><th>Подразделение</th>
    <th>Категория</th><th>Приём пищи</th><th>Точка</th><th>Способ проводки</th><th>Цена</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= htmlspecialchars($regionLabel($r['region_key']), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)$r['local_at'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['full_name'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['organization'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['department'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['vjg_type'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars(adminMealLabel($r['meal_type']), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['point_name'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['operator_name'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= number_format((float)($r['price'] ?? 0), 2, ',', '') ?></td>
</tr>
<?php endforeach; ?>
<?php elseif ($type === 'employees'): ?>
<tr><th>Регион</th><th>Сотрудник</th><th>Организация</th><th>Подразделение</th>
    <th>Завтраки</th><th>Обеды</th><th>Ужины</th><th>Ночное</th>
    <th>Приёмов пищи</th><th>Дней в столовой</th></tr>
<?php
$tot = array_fill_keys(['breakfast','lunch','dinner','night','meals','days'], 0);
foreach ($rows as $r):
    foreach ($tot as $k => $_) $tot[$k] += (int)$r[$k];
?>
<tr>
    <td><?= htmlspecialchars($regionLabel($r['region_key']), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['full_name'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['organization'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['department'] ?? ''), ENT_QUOTES) ?></td>
    <?php foreach (['breakfast','lunch','dinner','night','meals','days'] as $k): ?>
    <td><?= (int)$r[$k] ?></td>
    <?php endforeach; ?>
</tr>
<?php endforeach; ?>
<?php if ($rows): ?>
<tr><td colspan="4"><b>Всего</b></td>
    <?php foreach (['breakfast','lunch','dinner','night','meals','days'] as $k): ?>
    <td><b><?= $tot[$k] ?></b></td>
    <?php endforeach; ?>
</tr>
<?php endif; ?>
<?php else: ?>
<tr><th>Регион</th><th>Дата выдачи</th><th>Сотрудник</th><th>Организация</th>
    <th>Подразделение</th><th>Вид</th><th>Статус</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= htmlspecialchars($regionLabel($r['region_key']), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)$r['issue_date'], ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['full_name'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['organization'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= htmlspecialchars((string)($r['department'] ?? ''), ENT_QUOTES) ?></td>
    <td><?= $r['dry_type'] === 'field' ? 'Выездное' : 'Сухпай' ?></td>
    <td><?= htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES) ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</table>
<?php
$content = ob_get_clean();
while (ob_get_level()) ob_end_clean();

adminAudit($pdo, 'export', sprintf('%s: %d строк, регионы: %s',
    $type, count($rows), implode(',', array_keys($selected))));

$fname = sprintf('severfoods_%s_%s.xls', $type, gmdate('Y-m-d'));
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: max-age=0, no-store');
echo $content;
