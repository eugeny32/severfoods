<?php
/**
 * Общая обёртка страниц админки: шапка, меню, подвал.
 *
 * Оформление вынесено в assets/admin.css и повторяет порталы: тот же синий
 * #003366, янтарный акцент, шрифт Onest и иконки Font Awesome. Раньше стили
 * лежали прямо здесь одним куском — редактировать их было неудобно, а
 * браузер не мог их закэшировать.
 */

declare(strict_types=1);

function adminEsc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function adminHead(string $title, string $active = ''): void
{
    $u = adminCurrentUser();
    // Иконка рядом с каждым пунктом: на узком экране меню разворачивается в
    // столбец, и значок помогает найти нужное быстрее, чем чтение подписей.
    $nav = [
        'index'    => ['Обзор',        'index.php',       'fa-gauge-high'],
        'reports'  => ['Отчёты',       'reports.php',     'fa-chart-column'],
        'employees'=> ['Сотрудники',   'employees.php',   'fa-users'],
        'global'   => ['Единый доступ','global_user.php', 'fa-id-card'],
        'points'   => ['Точки',        'points.php',      'fa-utensils'],
        'monitor'  => ['На связи',     'monitor.php',     'fa-signal'],
        'regions'  => ['Регионы',      'regions.php',     'fa-map-location-dot'],
        'provision'=> ['Новый регион', 'provision.php',   'fa-circle-plus'],
        'customers'=> ['Заказчики',    'customers.php',   'fa-building'],
        'audit'    => ['Журнал',       'audit.php',       'fa-clock-rotate-left'],
    ];

    // Ссылки на площадки. Реестр читается прямо здесь: панель со ссылками
    // нужна на каждой странице, а тащить список через все вызовы adminHead()
    // означало бы править каждую страницу ради одного и того же.
    // Недоступный реестр не должен ронять страницу — тогда просто нет панели.
    $siteLinks = [];
    try {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            foreach (adminRegions($pdo, true) as $r) {
                $url = adminRegionUrl($r);
                if ($url !== '') $siteLinks[] = ['label' => (string)$r['label'], 'url' => $url];
            }
        }
    } catch (Throwable $e) { /* панель со ссылками необязательна */ }
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#003366">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= adminEsc($title) ?> · <?= adminEsc(ADMIN_TITLE) ?></title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="apple-touch-icon" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=2">
</head>
<body>
<header class="hdr">
    <div class="hdr-top">
        <button class="hdr-burger" type="button" id="navToggle"
                aria-label="Меню" aria-expanded="false" aria-controls="mainNav">
            <i class="fas fa-bars"></i>
        </button>
        <img src="logo.png" alt="" class="hdr-logo" onerror="this.style.display='none'">
        <div class="hdr-name">
            <?= adminEsc(ADMIN_TITLE) ?>
            <small>Центр управления регионами</small>
        </div>
        <div class="hdr-spacer"></div>
        <div class="hdr-me">
            <span class="who">
                <i class="fas fa-user-shield"></i>
                <?= adminEsc($u['full_name'] ?? '') ?><?= ($u['role'] ?? '') === 'viewer' ? ' · только чтение' : '' ?>
            </span>
            <a class="hdr-out" href="logout.php"><i class="fas fa-right-from-bracket"></i> Выйти</a>
        </div>
    </div>
    <nav class="nav" id="mainNav">
        <div class="nav-inner">
            <?php foreach ($nav as $key => [$label, $href, $icon]): ?>
                <a href="<?= $href ?>" class="<?= $key === $active ? 'on' : '' ?>">
                    <i class="fas <?= $icon ?>"></i><?= adminEsc($label) ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
</header>
<?php if ($siteLinks): ?>
<div class="sites">
    <div class="sites-inner">
        <span class="cap"><i class="fas fa-location-dot"></i> Площадки</span>
        <?php foreach ($siteLinks as $s): ?>
            <a href="<?= adminEsc($s['url']) ?>" target="_blank" rel="noopener">
                <?= adminEsc($s['label']) ?> <i class="fas fa-arrow-up-right-from-square"></i></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<main>
<?php
}

function adminFoot(): void
{
    ?>
</main>
<script>
// Меню на узком экране
(function () {
    var btn = document.getElementById('navToggle');
    var nav = document.getElementById('mainNav');
    if (!btn || !nav) return;
    btn.addEventListener('click', function () {
        var open = nav.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.querySelector('i').className = open ? 'fas fa-xmark' : 'fas fa-bars';
    });
})();

// Таблицы для телефона.
//
// На узком экране широкая таблица либо уезжает за край, либо сжимается до
// нечитаемого. Здесь каждая ячейка получает подпись своего столбца, и CSS
// (@media max-width:860px) раскладывает строку карточкой «подпись — значение».
// Делается это одним местом для всех страниц: иначе пришлось бы дописывать
// data-label в каждую ячейку каждой таблицы и не забывать про новые.
(function () {
    document.querySelectorAll('table').forEach(function (t) {
        var headRow = t.querySelector('tr');
        if (!headRow) return;
        var heads = [].map.call(headRow.querySelectorAll('th'), function (th) {
            return th.textContent.trim();
        });
        if (!heads.length) return;          // таблица без шапки — оставляем как есть

        t.classList.add('stack');
        // Строку заголовков прячем целиком: сами th скрыты стилем, но пустая
        // строка иначе осталась бы пустой карточкой сверху таблицы.
        headRow.classList.add('hdrow');
        [].forEach.call(t.rows, function (row) {
            if (row === headRow) return;
            var i = 0;
            [].forEach.call(row.cells, function (cell) {
                if (!cell.hasAttribute('data-label')) {
                    // Ячейка, растянутая на несколько столбцов, не относится ни
                    // к одному из них (обычно это сообщение об ошибке на всю
                    // строку) — подпись столбца ввела бы в заблуждение.
                    cell.setAttribute('data-label', cell.colSpan > 1 ? '' : (heads[i] || ''));
                }
                i += cell.colSpan || 1;
            });
        });

        if (!t.parentElement.classList.contains('table-wrap')) {
            var wrap = document.createElement('div');
            wrap.className = 'table-wrap';
            t.parentNode.insertBefore(wrap, t);
            wrap.appendChild(t);
        }
    });
})();
</script>
</body>
</html>
<?php
}
