<?php
/**
 * Мастер добавления региона.
 *
 * Структура новой базы создаётся КОПИРОВАНИЕМ с региона-образца
 * (`CREATE TABLE … LIKE`), а не по эталонному дампу. Причина: канонической
 * схемы в проекте не существует — таблицы в региональных системах создаются
 * лениво, прямо в рантайме, а database.sql из README отсутствует. Копирование
 * даёт точное совпадение по определению и исключает расхождение схем, из-за
 * которого потом падали бы сводные отчёты.
 *
 * Поддомен и саму базу создаёт человек в панели хостинга: для автоматизации
 * нужен её API, которого у нас нет. Мастер берёт на себя всё остальное и
 * выдаёт пошаговую инструкцию.
 */
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/region_write.php';
require_once __DIR__ . '/src/layout.php';
adminRequireOwner();

/** Таблицы, которые должны быть в базе региона. */
const REGION_TABLES = [
    'employees', 'meal_points', 'meal_point_schedules', 'meal_logs',
    'dry_rations', 'admin_logs', 'sync_meta', 'vjg_prices',
    'offline_presence', 'offline_commands',
];

$allRegions = adminRegions($pdo, false);
$steps = [];
$err   = '';
$done  = false;
$envText = '';
$firstQr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $key    = trim((string)($_POST['region_key'] ?? ''));
        $label  = trim((string)($_POST['label'] ?? ''));
        $domain = trim((string)($_POST['domain'] ?? ''));
        $dbName = trim((string)($_POST['db_name'] ?? ''));
        $tz     = trim((string)($_POST['tz_offset'] ?? '+03:00'));
        $sample = trim((string)($_POST['sample'] ?? ''));
        $adminName = trim((string)($_POST['admin_name'] ?? ''));

        if (!preg_match('/^[a-z0-9_]{2,32}$/', $key)) throw new RuntimeException('Ключ: строчная латиница, цифры, подчёркивание');
        if (isset($allRegions[$key])) throw new RuntimeException("Регион «{$key}» уже есть в реестре");
        if ($label === '' || $domain === '') throw new RuntimeException('Заполните название и домен');
        if (!preg_match('/^[+-]\d{2}:\d{2}$/', $tz)) throw new RuntimeException('Часовой пояс в формате +07:00');
        if ($adminName === '') throw new RuntimeException('Укажите имя первого администратора');
        adminQuoteDb($dbName);

        $src = adminRegion($pdo, $sample, false);
        if (!$src) throw new RuntimeException('Не выбран регион-образец');
        $srcDb = adminQuoteDb($src['db_name']);
        $dstDb = adminQuoteDb($dbName);

        // 1. База должна существовать и быть пустой: копировать структуру
        //    поверх работающей площадки нельзя ни при каких обстоятельствах.
        $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$dbName]);
        if (!(int)$exists->fetchColumn()) {
            throw new RuntimeException("База «{$dbName}» не найдена. Создайте её в панели хостинга и повторите.");
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $cnt->execute([$dbName]);
        if ((int)$cnt->fetchColumn() > 0) {
            throw new RuntimeException("В базе «{$dbName}» уже есть таблицы. Мастер работает только с пустой базой.");
        }
        $steps[] = "База «{$dbName}» найдена и пуста";

        // 2. Копируем структуру таблиц образца.
        $srcTables = [];
        $ts = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $ts->execute([$src['db_name']]);
        foreach ($ts as $row) $srcTables[] = $row['TABLE_NAME'];

        $copied = [];
        foreach (REGION_TABLES as $t) {
            if (!in_array($t, $srcTables, true)) continue; // в образце её нет — пропускаем
            $pdo->exec("CREATE TABLE {$dstDb}.`{$t}` LIKE {$srcDb}.`{$t}`");
            $copied[] = $t;
        }
        $steps[] = 'Созданы таблицы: ' . implode(', ', $copied);

        $missing = array_diff(REGION_TABLES, $copied);
        if ($missing) {
            $steps[] = 'Не найдены в образце и не созданы: ' . implode(', ', $missing)
                     . ' — они появятся сами при первом обращении';
        }

        // 3. Справочник цен переносим вместе с данными: это настройка, а не
        //    накопленные записи, и без него новая площадка считает питание по нулю.
        if (in_array('vjg_prices', $copied, true)) {
            $pdo->exec("INSERT INTO {$dstDb}.vjg_prices SELECT * FROM {$srcDb}.vjg_prices");
            $steps[] = 'Перенесён справочник цен ВЖГ';
        }

        // 4. Первый администратор. Вход в региональную систему идёт по
        //    QR-коду, поэтому код нужно показать — другого способа войти нет.
        $rp = new PDO(
            'mysql:host=' . ADMIN_DB_HOST . ";dbname={$dbName};charset=utf8mb4",
            ADMIN_DB_USER, ADMIN_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $firstQr = adminNewQrCode($rp);
        $rp->prepare(
            "INSERT INTO employees (full_name, organization, qr_code, qr_status, is_active, role)
             VALUES (?, ?, ?, 'active', 1, 'super_admin')"
        )->execute([$adminName, $label, $firstQr]);
        $steps[] = "Создан супер-администратор «{$adminName}»";

        // 5. Запись в реестр.
        $pdo->prepare(
            'INSERT INTO regions (region_key, label, domain, db_name, tz_offset, customer_id, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $key, $label, $domain, $dbName, $tz,
            ((int)($_POST['customer_id'] ?? 0)) ?: null,
            count($allRegions) + 1,
        ]);
        $steps[] = 'Регион добавлен в реестр';

        adminAudit($pdo, 'region_provision', "Развёрнут регион {$label} ({$dbName})", $key);

        $token = bin2hex(random_bytes(24));
        $envText = "DB_HOST=" . ADMIN_DB_HOST . "\n"
                 . "DB_NAME={$dbName}\n"
                 . "DB_USER=" . ADMIN_DB_USER . "\n"
                 . "DB_PASS=пароль_пользователя_базы\n\n"
                 . "APP_NAME=Система питания\n"
                 . "SITE_URL=https://{$domain}/\n"
                 . "REGION_KEY={$key}\n"
                 . "SERVER_TZ_OFFSET={$tz}\n\n"
                 . "OFFLINE_SYNC_TOKEN={$token}\n";
        $done = true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$customers = $pdo->query('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name')->fetchAll();

adminHead('Новый регион', 'provision');
?>
<h1><i class="fas fa-circle-plus"></i> Добавление региона</h1>

<?php if ($err): ?><div class="msg msg-err"><?= adminEsc($err) ?></div><?php endif; ?>

<?php if ($done): ?>
    <?php foreach ($steps as $s): ?><div class="msg msg-ok"><?= adminEsc($s) ?></div><?php endforeach; ?>

    <h2>Что сделать дальше вручную</h2>
    <div class="card">
        <ol style="margin-left:20px;line-height:1.9">
            <li>Создайте поддомен в панели хостинга с отдельной папкой.</li>
            <li>Залейте в него файлы системы — те же, что на действующих площадках.</li>
            <li>Создайте в корне файл <code>.env</code> с содержимым ниже.</li>
            <li>Откройте сайт и войдите по QR-коду администратора.</li>
            <li>Заведите точки питания и сотрудников — или добавьте их отсюда,
                на страницах «Точки» и «Сотрудники».</li>
        </ol>
    </div>

    <h2>Файл .env для новой площадки</h2>
    <div class="card">
        <p class="muted" style="margin-bottom:10px">
            Пароль пользователя базы подставьте сами — здесь он не показывается намеренно.
            Токен синхронизации сгенерирован новый: он нужен оффлайн-приложениям этой площадки.
        </p>
        <textarea readonly rows="11" style="width:100%;font-family:monospace;font-size:13px"><?= adminEsc($envText) ?></textarea>
    </div>

    <h2>QR-код первого администратора</h2>
    <div class="card">
        <div class="msg msg-warn">
            <strong>Запишите его сейчас — больше он нигде не покажется.</strong>
            В региональной системе вход администратора выполняется по этому коду,
            он же является паролем. Храните его как пароль.
        </div>
        <p style="font-family:monospace;font-size:18px;font-weight:700"><?= adminEsc($firstQr) ?></p>
    </div>

    <p><a class="btn" href="regions.php"><i class="fas fa-arrow-left"></i> К списку регионов</a></p>

<?php else: ?>

<div class="card">
    <p class="muted" style="line-height:1.6">
        Мастер создаёт структуру таблиц в <strong>уже созданной пустой базе</strong>, копируя её с
        региона-образца, заводит первого администратора и вносит регион в реестр.
        Поддомен и саму базу нужно создать заранее в панели хостинга — это требует её API,
        которого у нас нет.
    </p>
</div>

<form method="post" class="card">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
        <div class="field"><label>Ключ региона</label>
            <input type="text" name="region_key" required style="width:100%" placeholder="tnd"></div>
        <div class="field"><label>Название</label>
            <input type="text" name="label" required style="width:100%" placeholder="Тында"></div>
        <div class="field"><label>Домен</label>
            <input type="text" name="domain" required style="width:100%" placeholder="tnd.severfoods.ru"></div>
        <div class="field"><label>Имя пустой базы</label>
            <input type="text" name="db_name" required style="width:100%" placeholder="u3523560_canteen_tnd"></div>
        <div class="field"><label>Часовой пояс</label>
            <input type="text" name="tz_offset" style="width:100%" value="+03:00" placeholder="+09:00"></div>
        <div class="field"><label>Регион-образец</label>
            <select name="sample" required style="width:100%">
                <option value="">— выберите —</option>
                <?php foreach ($allRegions as $k => $r): ?>
                    <option value="<?= adminEsc($k) ?>"><?= adminEsc($r['label']) ?> (<?= adminEsc($r['db_name']) ?>)</option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Заказчик</label>
            <select name="customer_id" style="width:100%">
                <option value="">—</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= adminEsc($c['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Имя первого администратора</label>
            <input type="text" name="admin_name" required style="width:100%" placeholder="Иванов Иван"></div>
    </div>

    <button class="btn" type="submit"><i class="fas fa-circle-plus"></i> Развернуть регион</button>
</form>

<?php endif; adminFoot();
