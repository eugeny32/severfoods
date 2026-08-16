<?php
/**
 * Наблюдение за точками всех регионов: кто на связи, какие версии стоят,
 * постановка удалённых команд.
 *
 * В отличие от отчётов, здесь опрос идёт по регионам ПООТДЕЛЬНОСТИ, а не
 * сводным запросом. Причина: таблицы offline_presence и offline_commands
 * создаются на площадке лениво — при первом обращении точки. В регионе, где
 * удалённый доступ ещё не использовали, их просто нет, и общий UNION упал бы
 * целиком из-за одного такого региона. Данных тут немного, цена опроса низкая.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/layout.php';
adminRequireLogin();

const ONLINE_THRESHOLD_SEC = 90;   // heartbeat раз в 30 с, запас на пару пропусков

/** Команды, которые понимает оффлайн-приложение (см. offline/src/sync.js). */
const REMOTE_COMMANDS = [
    'sync'           => 'Синхронизировать',
    'check_update'   => 'Проверить обновление',
    'install_update' => 'Установить обновление',
    'unlock_kiosk'   => 'Снять киоск',
    'restart'        => 'Перезапустить',
];

$allRegions = adminRegions($pdo);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminRequireOwner();
    try {
        $regionKey = (string)($_POST['region_key'] ?? '');
        $region = adminRegion($pdo, $regionKey);
        if (!$region) throw new RuntimeException('Регион не найден');
        $deviceId = trim((string)($_POST['device_id'] ?? ''));
        if ($deviceId === '') throw new RuntimeException('Не указано устройство');

        $rp = adminRegionPdo($pdo, $region);

        if (($_POST['action'] ?? '') === 'forget') {
            // Убираем только строку наблюдения вместе с её очередью команд.
            // Ни записей о питании, ни настроек точки это не касается.
            $rp->prepare('DELETE FROM offline_commands WHERE device_id = ?')->execute([$deviceId]);
            $rp->prepare('DELETE FROM offline_presence WHERE device_id = ?')->execute([$deviceId]);
            adminAudit($pdo, 'device_forget', "Убрано устройство {$deviceId}", $regionKey);
            $msg = 'Устройство убрано из списка. Если приложение на нём ещё работает, оно вернётся само.';
        } else {
            $cmd = (string)($_POST['command'] ?? '');
            if (!isset(REMOTE_COMMANDS[$cmd])) throw new RuntimeException('Неизвестная команда');

            $rp->prepare(
                'INSERT INTO offline_commands (device_id, command, created_by) VALUES (?, ?, ?)'
            )->execute([$deviceId, $cmd, adminCurrentUser()['login'] ?? 'admin']);

            adminAudit($pdo, 'remote_command', REMOTE_COMMANDS[$cmd] . " → {$deviceId}", $regionKey);
            $msg = 'Команда «' . REMOTE_COMMANDS[$cmd] . '» поставлена в очередь. '
                 . 'Точка заберёт её при следующем сеансе связи, обычно в течение полуминуты.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// ── Опрос регионов ───────────────────────────────────────────
$devices = [];
$regionErrors = [];
foreach ($allRegions as $key => $r) {
    try {
        $rp = adminRegionPdo($pdo, $r);
        $st = $rp->query(
            'SELECT device_id, point_id, point_name, employee_name, app_version, ip_address,
                    last_seen_at,
                    TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP()) AS seconds_ago
             FROM offline_presence ORDER BY last_seen_at DESC'
        );
        foreach ($st as $row) {
            $row['region_key'] = $key;
            $row['online'] = $row['seconds_ago'] !== null && (int)$row['seconds_ago'] <= ONLINE_THRESHOLD_SEC;
            // Ожидающие команды показываем рядом: иначе непонятно, почему
            // точка «не реагирует» — она может просто ещё не выходила на связь.
            try {
                $pc = $rp->prepare("SELECT COUNT(*) FROM offline_commands
                                    WHERE device_id = ? AND status IN ('pending','sent')");
                $pc->execute([$row['device_id']]);
                $row['pending'] = (int)$pc->fetchColumn();
            } catch (Throwable $e) { $row['pending'] = 0; }
            $devices[] = $row;
        }
    } catch (Throwable $e) {
        // Нет таблицы — значит на этой площадке удалённый доступ ещё ни разу
        // не использовали. Это не ошибка региона, отмечаем отдельно.
        $regionErrors[$key] = str_contains($e->getMessage(), 'offline_presence')
            ? 'точки ещё не выходили на связь'
            : $e->getMessage();
    }
}

usort($devices, fn($a, $b) => [$b['online'], $a['region_key']] <=> [$a['online'], $b['region_key']]);

function agoText(?int $sec): string
{
    if ($sec === null) return '—';
    if ($sec < 60)   return $sec . ' с назад';
    if ($sec < 3600) return intdiv($sec, 60) . ' мин назад';
    if ($sec < 86400) return intdiv($sec, 3600) . ' ч назад';
    return intdiv($sec, 86400) . ' сут назад';
}

// Сводка по версиям — сразу видно, где отстали с обновлением.
$versions = [];
foreach ($devices as $d) {
    $v = $d['app_version'] ?: '—';
    $versions[$v] = ($versions[$v] ?? 0) + 1;
}
krsort($versions);

adminHead('Точки на связи', 'monitor');
?>
<h1>Наблюдение за точками</h1>

<?php if ($msg): ?><div class="msg msg-ok"><?= adminEsc($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg msg-err"><?= adminEsc($err) ?></div><?php endif; ?>

<?php if ($regionErrors): ?>
<div class="msg msg-warn">
    <?php foreach ($regionErrors as $k => $e): ?>
        <div><strong><?= adminEsc($allRegions[$k]['label'] ?? $k) ?>:</strong> <?= adminEsc($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($versions): ?>
<div class="card">
    <strong>Версии приложения:</strong>
    <?php foreach ($versions as $v => $n): ?>
        <span class="pill pill-reg" style="margin-left:6px"><?= adminEsc($v) ?> — <?= $n ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
<table>
<tr><th>Регион</th><th>Точка</th><th>Оператор</th><th>Версия</th><th>Адрес</th>
    <th>Связь</th><th>В очереди</th><th>Команды</th></tr>
<?php foreach ($devices as $d): ?>
<tr>
    <td><span class="pill pill-reg"><?= adminEsc($allRegions[$d['region_key']]['label'] ?? $d['region_key']) ?></span></td>
    <td><strong><?= adminEsc($d['point_name'] ?? 'Точка не выбрана') ?></strong>
        <div class="muted" style="font-size:11px"><?= adminEsc($d['device_id']) ?></div></td>
    <td class="muted"><?= adminEsc($d['employee_name'] ?? '—') ?></td>
    <td class="muted"><?= adminEsc($d['app_version'] ?? '—') ?></td>
    <td class="muted"><?= adminEsc($d['ip_address'] ?? '—') ?></td>
    <td>
        <?php if ($d['online']): ?>
            <span class="pill pill-ok">на связи</span>
        <?php else: ?>
            <span class="pill pill-off">нет связи</span>
            <div class="muted"><?= adminEsc(agoText($d['seconds_ago'] === null ? null : (int)$d['seconds_ago'])) ?></div>
        <?php endif; ?>
    </td>
    <td class="muted"><?= $d['pending'] ?: '—' ?></td>
    <td>
    <?php if (adminIsOwner()): ?>
        <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="region_key" value="<?= adminEsc($d['region_key']) ?>">
            <input type="hidden" name="device_id" value="<?= adminEsc($d['device_id']) ?>">
            <select name="command" <?= $d['online'] ? '' : 'disabled' ?>>
                <?php foreach (REMOTE_COMMANDS as $k => $v): ?>
                    <option value="<?= $k ?>"><?= adminEsc($v) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sec" type="submit" <?= $d['online'] ? '' : 'disabled' ?>>Отправить</button>
        </form>
        <?php if (!$d['online']): ?>
        <form method="post" style="margin-top:6px"
              onsubmit="return confirm('Убрать устройство из списка? Записи о питании и настройки точки не пострадают.')">
            <input type="hidden" name="action" value="forget">
            <input type="hidden" name="region_key" value="<?= adminEsc($d['region_key']) ?>">
            <input type="hidden" name="device_id" value="<?= adminEsc($d['device_id']) ?>">
            <button class="btn btn-danger" type="submit">Убрать</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (!$devices): ?>
    <tr><td colspan="8" class="muted">Ни одна точка ещё не выходила на связь.</td></tr>
<?php endif; ?>
</table>
</div>

<p class="muted">
    Команды выполняет только работающее приложение: они кладутся в очередь, и точка забирает
    их при очередном сеансе связи. Отправка на точку без связи ничего не даст, поэтому у таких
    строк выбор команд отключён — вместо этого их можно убрать из списка, если точка выведена
    из работы. Удаление касается только строки наблюдения: записи о питании и настройки точки
    остаются.
</p>

<?php adminFoot();
