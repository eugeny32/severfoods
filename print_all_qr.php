<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/print_card.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php'); exit;
}

$org_filter = trim($_GET['org'] ?? '');
$employees  = getEmployees($pdo);
if ($org_filter) {
    $employees = array_filter($employees, fn($e) => trim($e['organization']) === $org_filter);
}
$orgList = array_values(array_unique(array_filter(array_column($employees, 'organization'))));
sort($orgList);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Печать всех QR — <?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
<?php include __DIR__ . '/print_card_css.php'; ?>

body { background: #e8edf2; padding: 20px; }

/* ── Screen toolbar ── */
.toolbar {
    display: flex; gap: 10px; align-items: center;
    flex-wrap: wrap; margin-bottom: 20px;
    background: #fff; border-radius: 12px;
    padding: 12px 16px; box-shadow: 0 2px 8px rgba(0,0,0,.07);
}
.toolbar a, .toolbar button, .toolbar select {
    padding: 8px 16px; border-radius: 8px;
    font-family: 'Onest', sans-serif; font-size: 13px; font-weight: 600;
    border: 1.5px solid #e2e8f0; background: #fff;
    cursor: pointer; text-decoration: none; color: #0f172a;
    transition: background .15s;
}
.toolbar a:hover { background: #f1f5f9; }
.btn-print { background: #003366 !important; color: #fff !important; border-color: #003366 !important; }
.btn-print:hover { background: #00438a !important; }
.toolbar .count { font-size: 13px; color: #64748b; margin-left: auto; }

/* ── Legend ── */
.legend {
    display: flex; gap: 12px; flex-wrap: wrap;
    margin-bottom: 16px;
}
.legend-item {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 600; color: #374151;
}
.legend-dot {
    width: 12px; height: 12px; border-radius: 50%;
}

/* ── Grid ── */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
}
.qr-card { background: #fff; }

@media print {
    body { background: white; padding: 6mm; }
    .toolbar, .legend { display: none !important; }
    .grid {
        display: grid;
        grid-template-columns: repeat(3, 85.6mm);
        gap: 4mm;
    }
    .qr-card {
        width: 85.6mm;
        border-radius: 4mm;
        box-shadow: none;
    }
    .qr-img { width: 96px; height: 96px; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
    <a href="index.php">← Назад</a>
    <form method="GET" style="display:contents">
        <select name="org" onchange="this.form.submit()">
            <option value="">Все организации</option>
            <?php foreach ($orgList as $org): ?>
            <option value="<?= htmlspecialchars($org) ?>" <?= $org_filter === $org ? 'selected' : '' ?>>
                <?= htmlspecialchars($org) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <button class="btn-print" onclick="window.print()">
        <svg style="display:inline;vertical-align:middle;margin-right:6px" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>
        </svg>
        Печать (<?= count($employees) ?>)
    </button>
    <span class="count">Найдено: <?= count($employees) ?> сотрудников</span>
</div>

<div class="legend no-print">
    <div class="legend-item"><div class="legend-dot" style="background:#003366"></div> Сотрудник</div>
    <div class="legend-item"><div class="legend-dot" style="background:#c2410c"></div> Оператор</div>
    <div class="legend-item"><div class="legend-dot" style="background:#9b1c1c"></div> Администратор</div>
    <div class="legend-item"><div class="legend-dot" style="background:#166534"></div> Супер-администратор</div>
</div>

<div class="grid">
<?php foreach ($employees as $emp): ?>
    <?= renderCard($emp, 220) ?>
<?php endforeach; ?>
</div>

</body>
</html>
