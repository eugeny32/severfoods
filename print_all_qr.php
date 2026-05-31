<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php'); exit;
}

$org_filter = trim($_GET['org'] ?? '');
$employees  = getEmployees($pdo);
if ($org_filter) {
    $employees = array_filter($employees, fn($e) => $e['organization'] === $org_filter);
}
$orgList = array_unique(array_column($employees, 'organization'));
sort($orgList);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Печать всех QR — <?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Onest',sans-serif; background:#f0f4f8; padding:20px; }
.no-print { margin-bottom:24px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
.no-print select, .no-print button, .no-print a {
    padding:10px 18px; border-radius:10px;
    font-family:'Onest',sans-serif; font-size:14px; font-weight:600;
    border:1.5px solid #e2e8f0; background:white; cursor:pointer; text-decoration:none; color:#0f172a;
}
.no-print .btn-print { background:#003366; color:white; border-color:#003366; }
.grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap:16px; }
.qr-card {
    background:white; border-radius:16px; overflow:hidden;
    border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,.08);
    page-break-inside:avoid;
}
.qr-card-head {
    background:#003366; padding:12px 16px;
    display:flex; align-items:center; gap:10px;
}
.qr-card-head img { height:32px; object-fit:contain; }
.qr-card-head span { color:white; font-weight:800; font-size:14px; }
.qr-img-wrap { padding:16px; text-align:center; }
.qr-img-wrap img { width:200px; height:200px; border-radius:8px; border:1px solid #e2e8f0; padding:6px; }
.qr-info { padding:0 16px 16px; }
.qr-name { font-weight:800; font-size:14px; color:#0f172a; margin-bottom:4px; }
.qr-org  { font-size:12px; color:#64748b; margin-bottom:2px; }
.qr-code-text { font-size:10px; color:#94a3b8; font-family:monospace; margin-top:6px; }
.status-pill {
    display:inline-flex; align-items:center; gap:4px;
    padding:2px 8px; border-radius:100px;
    font-size:10px; font-weight:700; margin-top:4px;
}
.valid   { background:#d1fae5; color:#065f46; }
.invalid { background:#fee2e2; color:#991b1b; }
@media print {
    body { background:white; padding:10px; }
    .no-print { display:none !important; }
    .grid { grid-template-columns: repeat(3, 1fr); gap:8px; }
    .qr-card { box-shadow:none; border:1px solid #ccc; }
    * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>
</head>
<body>
<div class="no-print">
    <a href="index.php">← Назад</a>
    <form method="GET" style="display:flex;gap:8px">
        <select name="org" onchange="this.form.submit()">
            <option value="">Все организации</option>
            <?php foreach ($orgList as $org): ?>
            <option value="<?= htmlspecialchars($org) ?>" <?= $org_filter===$org?'selected':'' ?>>
                <?= htmlspecialchars($org) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <button class="btn-print" onclick="window.print()">🖨️ Печать всех (<?= count($employees) ?>)</button>
</div>

<div class="grid">
<?php foreach ($employees as $emp):
    $qrUrl = generateQRCode($emp['qr_code'], 220);
    $valid  = isQrCodeValid($emp);
?>
<div class="qr-card">
    <div class="qr-card-head">
        <img src="logo.png" alt="" onerror="this.style.display='none'">
        <span>СЕВЕР</span>
    </div>
    <div class="qr-img-wrap">
        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR">
    </div>
    <div class="qr-info">
        <div class="qr-name"><?= htmlspecialchars($emp['full_name']) ?></div>
        <div class="qr-org"><?= htmlspecialchars($emp['organization']) ?></div>
        <?php if ($emp['department']): ?>
        <div class="qr-org"><?= htmlspecialchars($emp['department']) ?></div>
        <?php endif; ?>
        <span class="status-pill <?= $valid?'valid':'invalid' ?>">
            <?= $valid?'✅ Действителен':'❌ Недействителен' ?>
        </span>
        <?php if ($emp['qr_expires_at']): ?>
        <div class="qr-code-text">до <?= date('d.m.Y', strtotime($emp['qr_expires_at'])) ?></div>
        <?php endif; ?>
        <div class="qr-code-text"><?= htmlspecialchars($emp['qr_code']) ?></div>
    </div>
</div>
<?php endforeach; ?>
</div>
</body>
</html>
