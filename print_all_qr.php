<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/print_card.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php'); exit;
}

$org_filter  = trim($_GET['org']  ?? '');
$dep_filter  = trim($_GET['dep']  ?? '');

$all_employees = getEmployees($pdo);

// Build org list from all employees
$orgList = array_values(array_unique(array_filter(array_column($all_employees, 'organization'))));
sort($orgList);

// Build department list — filtered by selected org if set
$depSource = $org_filter
    ? array_filter($all_employees, fn($e) => trim($e['organization']) === $org_filter)
    : $all_employees;
$depList = array_values(array_unique(array_filter(array_column($depSource, 'department'))));
sort($depList);

// Apply filters
$employees = $all_employees;
if ($org_filter) {
    $employees = array_filter($employees, fn($e) => trim($e['organization']) === $org_filter);
}
if ($dep_filter) {
    $employees = array_filter($employees, fn($e) => trim($e['department']) === $dep_filter);
}
$employees = array_values($employees);
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

/* ── Toolbar ── */
.toolbar {
    display: flex; gap: 10px; align-items: center;
    flex-wrap: wrap; margin-bottom: 14px;
    background: #fff; border-radius: 12px;
    padding: 12px 16px; box-shadow: 0 2px 8px rgba(0,0,0,.07);
}
.toolbar a, .toolbar button, .toolbar select {
    padding: 8px 14px; border-radius: 8px;
    font-family: 'Onest', sans-serif; font-size: 13px; font-weight: 600;
    border: 1.5px solid #e2e8f0; background: #fff;
    cursor: pointer; text-decoration: none; color: #0f172a;
    transition: background .15s;
}
.toolbar a:hover, .toolbar button:hover { background: #f1f5f9; }
.btn-print { background: #003366 !important; color: #fff !important; border-color: #003366 !important; }
.btn-print:hover { background: #00438a !important; }
.btn-print:disabled { background: #94a3b8 !important; border-color: #94a3b8 !important; cursor: default !important; }
.btn-secondary { background: #f8fafc !important; color: #374151 !important; }
.toolbar .count { font-size: 13px; color: #64748b; margin-left: auto; white-space: nowrap; }

/* ── Selection bar ── */
.sel-bar {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
    margin-bottom: 14px; padding: 10px 14px;
    background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 10px;
    font-size: 13px; font-weight: 600; color: #1e40af;
}
.sel-bar button {
    padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600;
    border: 1.5px solid #bfdbfe; background: #fff; color: #1e40af;
    cursor: pointer; transition: background .15s;
}
.sel-bar button:hover { background: #dbeafe; }
.sel-bar .sel-count { margin-right: auto; }

/* ── Legend ── */
.legend {
    display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;
}
.legend-item {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 600; color: #374151;
}
.legend-dot { width: 12px; height: 12px; border-radius: 50%; }

/* ── Grid ── */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
}

/* ── Card wrapper with checkbox ── */
.card-wrap {
    position: relative; cursor: pointer;
}
.card-wrap .card-cb {
    position: absolute; top: 10px; left: 10px; z-index: 10;
    width: 20px; height: 20px; cursor: pointer;
    accent-color: #003366;
}
.card-wrap .qr-card {
    background: #fff;
    transition: outline .1s, opacity .1s;
    outline: 3px solid transparent;
    border-radius: 10px;
}
.card-wrap.selected .qr-card {
    outline: 3px solid #003366;
    opacity: 1;
}
.card-wrap:not(.selected) .qr-card {
    opacity: .55;
}
/* When nothing selected — show all normally */
.grid.none-selected .card-wrap .qr-card {
    opacity: 1;
    outline: 3px solid transparent;
}

@media print {
    @page {
        size: A4 portrait;
        margin: 8mm;
    }
    body { background: white; padding: 0; margin: 0; }
    .toolbar, .legend, .sel-bar { display: none !important; }
    .card-wrap { cursor: default; }
    .card-cb   { display: none !important; }
    .card-wrap:not(.selected) { display: none !important; }
    .grid.none-selected .card-wrap { display: block !important; }

    /* A4 − 16mm margins = 194×281mm
       ISO 7810 ID-1: 85.6×54mm
       2 cols: 2×85.6 + 4mm gap = 175.2mm ✓
       5 rows: 5×54  + 4×4mm gap = 286mm — не влезает, уменьшаем gap до 2.75mm
       5×54 + 4×2.75 = 281mm ✓                                                 */
    .grid {
        display: grid;
        grid-template-columns: 85.6mm 85.6mm;
        gap: 2.75mm;
        width: 175.2mm;
        margin: 0 auto;
    }
    .card-wrap {
        width: 85.6mm;
        height: 54mm;
        overflow: hidden;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .card-wrap .qr-card {
        width: 85.6mm !important;
        height: 54mm !important;
        border-radius: 2.5mm;
        box-shadow: none;
        outline: none !important;
        opacity: 1 !important;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    /* Header — compact, ~12mm tall */
    .card-header {
        padding: 2mm 3mm;
        flex-shrink: 0;
    }
    .card-header img { height: 6mm; max-width: 14mm; }
    .brand-name  { font-size: 8pt; letter-spacing: .2px; }
    .brand-sub   { font-size: 5.5pt; }
    .role-badge  { font-size: 5.5pt; padding: 1px 4px; }

    /* Body — fills remaining ~42mm, QR gets full height */
    .card-body {
        flex: 1;
        padding: 2mm 3mm 2mm 2mm;
        gap: 0;
        align-items: center;
        overflow: hidden;
    }
    /* QR: fill body height (54-12mm header - 4mm padding = ~38mm) */
    .qr-wrap { flex-shrink: 0; }
    .qr-img {
        width: 35mm;
        height: 35mm;
        border-radius: 1.5mm;
        padding: 1mm;
    }
    .emp-info    { padding-left: 2.5mm; }
    .emp-name    { font-size: 8pt; margin-bottom: 1mm; line-height: 1.2; }
    .emp-meta    { font-size: 6.5pt; margin-bottom: 0.5mm; }
    .status-row  { margin-top: 1.5mm; gap: 1.5mm; }
    .status-pill { font-size: 6pt; padding: 0.5mm 2mm; }
    .expires     { font-size: 6pt; }
    .qr-code-txt { font-size: 5.5pt; margin-top: 1mm; }

    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
    <a href="index.php">← Назад</a>

    <form method="GET" id="filterForm" style="display:contents">
        <select name="org" id="orgSel" onchange="this.form.submit()">
            <option value="">Все организации</option>
            <?php foreach ($orgList as $org): ?>
            <option value="<?= htmlspecialchars($org) ?>" <?= $org_filter === $org ? 'selected' : '' ?>>
                <?= htmlspecialchars($org) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <select name="dep" id="depSel" onchange="this.form.submit()">
            <option value="">Все подразделения</option>
            <?php foreach ($depList as $dep): ?>
            <option value="<?= htmlspecialchars($dep) ?>" <?= $dep_filter === $dep ? 'selected' : '' ?>>
                <?= htmlspecialchars($dep) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($org_filter): ?>
        <input type="hidden" name="org" value="<?= htmlspecialchars($org_filter) ?>">
        <?php endif; ?>
    </form>

    <button class="btn-print" id="printBtn" onclick="doPrint()">
        <svg style="display:inline;vertical-align:middle;margin-right:6px" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>
        </svg>
        <span id="printLabel">Печать (<?= count($employees) ?>)</span>
    </button>

    <span class="count" id="totalCount">Найдено: <?= count($employees) ?> сотрудников</span>
</div>

<div class="sel-bar no-print" id="selBar">
    <span class="sel-count" id="selCount">Выбрано: 0 из <?= count($employees) ?></span>
    <button onclick="selectAll()">Выбрать все</button>
    <button onclick="selectNone()">Снять выбор</button>
    <button onclick="invertSelection()">Инвертировать</button>
</div>

<div class="legend no-print">
    <div class="legend-item"><div class="legend-dot" style="background:#003366"></div> Сотрудник</div>
    <div class="legend-item"><div class="legend-dot" style="background:#c2410c"></div> Оператор</div>
    <div class="legend-item"><div class="legend-dot" style="background:#9b1c1c"></div> Администратор</div>
    <div class="legend-item"><div class="legend-dot" style="background:#166534"></div> Супер-администратор</div>
</div>

<div class="grid none-selected" id="grid">
<?php foreach ($employees as $emp): ?>
    <div class="card-wrap" onclick="toggleCard(this)">
        <input type="checkbox" class="card-cb" onclick="event.stopPropagation(); toggleCard(this.closest('.card-wrap'))">
        <?= renderCard($emp, 220) ?>
    </div>
<?php endforeach; ?>
</div>

<script>
const grid = document.getElementById('grid');
const selCountEl = document.getElementById('selCount');
const printLabel = document.getElementById('printLabel');
const total = <?= count($employees) ?>;

function updateUI() {
    const selected = grid.querySelectorAll('.card-wrap.selected');
    const n = selected.length;
    selCountEl.textContent = `Выбрано: ${n} из ${total}`;
    printLabel.textContent = `Печать (${n > 0 ? n : total})`;
    // sync checkboxes
    grid.querySelectorAll('.card-wrap').forEach(w => {
        w.querySelector('.card-cb').checked = w.classList.contains('selected');
    });
    grid.classList.toggle('none-selected', n === 0);
}

function toggleCard(wrap) {
    wrap.classList.toggle('selected');
    updateUI();
}

function selectAll() {
    grid.querySelectorAll('.card-wrap').forEach(w => w.classList.add('selected'));
    updateUI();
}

function selectNone() {
    grid.querySelectorAll('.card-wrap').forEach(w => w.classList.remove('selected'));
    updateUI();
}

function invertSelection() {
    grid.querySelectorAll('.card-wrap').forEach(w => w.classList.toggle('selected'));
    updateUI();
}

function doPrint() {
    window.print();
}

updateUI();
</script>
</body>
</html>
