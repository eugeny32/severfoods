<?php
/** Общая обёртка страниц админки: шапка, меню, подвал. */

declare(strict_types=1);

function adminEsc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function adminHead(string $title, string $active = ''): void
{
    $u = adminCurrentUser();
    $nav = [
        'index'    => ['Обзор',      'index.php'],
        'reports'  => ['Отчёты',     'reports.php'],
        'employees'=> ['Сотрудники', 'employees.php'],
        'points'   => ['Точки',      'points.php'],
        'monitor'  => ['На связи',   'monitor.php'],
        'regions'  => ['Регионы',    'regions.php'],
        'provision'=> ['Новый регион','provision.php'],
        'customers'=> ['Заказчики',  'customers.php'],
        'audit'    => ['Журнал',     'audit.php'],
    ];
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= adminEsc($title) ?> · <?= adminEsc(ADMIN_TITLE) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,'Segoe UI',sans-serif;background:#f1f5f9;color:#0f172a;min-height:100vh}
header{background:#003366;color:#fff;padding:0 24px;display:flex;align-items:center;gap:28px;flex-wrap:wrap}
header .brand{font-size:17px;font-weight:800;padding:16px 0;white-space:nowrap}
header nav{display:flex;gap:4px;flex:1;flex-wrap:wrap}
header nav a{color:rgba(255,255,255,.75);text-decoration:none;font-size:14px;font-weight:600;padding:18px 14px;border-bottom:3px solid transparent}
header nav a:hover{color:#fff}
header nav a.on{color:#fff;border-bottom-color:#4f9cf9}
header .me{font-size:13px;color:rgba(255,255,255,.7);display:flex;align-items:center;gap:12px}
header .me a{color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.25);border-radius:7px;padding:6px 12px;font-weight:600}
main{max-width:1400px;margin:24px auto;padding:0 24px 60px}
h1{font-size:23px;font-weight:700;margin-bottom:18px}
h2{font-size:17px;font-weight:700;margin:24px 0 12px}
.card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.06);padding:20px;margin-bottom:18px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:9px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:700}
tr:last-child td{border-bottom:none}
.muted{color:#64748b;font-size:13px}
.pill{display:inline-block;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700}
.pill-ok{background:#dcfce7;color:#166534}
.pill-off{background:#fee2e2;color:#991b1b}
.pill-reg{background:#e0f2fe;color:#075985}
.btn{display:inline-flex;align-items:center;gap:7px;background:#003366;color:#fff;border:none;border-radius:9px;
     padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
.btn:hover{background:#00438a}
.btn-sec{background:#fff;color:#0f172a;border:1.5px solid #cbd5e1}
.btn-sec:hover{background:#f8fafc}
.btn-danger{background:#dc2626}
.btn-danger:hover{background:#b91c1c}
input[type=text],input[type=password],input[type=date],input[type=number],select,textarea{
  padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;font-family:inherit;background:#fff;color:#0f172a}
label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px;text-transform:uppercase;letter-spacing:.03em}
.field{margin-bottom:14px}
.grid{display:grid;gap:14px}
.msg{border-radius:9px;padding:12px 16px;margin-bottom:16px;font-size:14px}
.msg-err{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b}
.msg-ok{background:#f0fdf4;border:1.5px solid #bbf7d0;color:#166534}
.msg-warn{background:#fff7ed;border:1.5px solid #fed7aa;color:#92400e}
.regbox{display:flex;gap:14px;flex-wrap:wrap;align-items:center}
.regbox label{text-transform:none;letter-spacing:0;font-size:14px;font-weight:600;color:#0f172a;display:flex;align-items:center;gap:6px;margin:0;cursor:pointer}
</style>
</head>
<body>
<header>
    <div class="brand"><?= adminEsc(ADMIN_TITLE) ?></div>
    <nav>
        <?php foreach ($nav as $key => [$label, $href]): ?>
            <a href="<?= $href ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= adminEsc($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="me">
        <span><?= adminEsc($u['full_name'] ?? '') ?><?= ($u['role'] ?? '') === 'viewer' ? ' · только чтение' : '' ?></span>
        <a href="logout.php">Выйти</a>
    </div>
</header>
<main>
<?php
}

function adminFoot(): void
{
    echo "</main>\n</body>\n</html>\n";
}
