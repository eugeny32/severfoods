<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$emp = getEmployeeById($pdo, $id);
if (!$emp) { http_response_code(404); die('<p>Сотрудник не найден</p>'); }

$qrUrl = generateQRCode($emp['qr_code'], 320);
$valid = isQrCodeValid($emp);
$expires = $emp['qr_expires_at'] ? date('d.m.Y', strtotime($emp['qr_expires_at'])) : 'Бессрочно';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR — <?= htmlspecialchars($emp['full_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: 'Onest', sans-serif;
    background: white;
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh; padding: 20px;
}
.card {
    width: 340px;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,.12);
}
.card-header {
    background: #003366;
    padding: 20px;
    text-align: center;
}
.card-header img { height: 50px; width: auto; max-width: 160px; object-fit: contain; }
.card-header .brand { color: white; font-size: 18px; font-weight: 800; margin-top: 10px; }
.card-header .sub   { color: rgba(255,255,255,.6); font-size: 12px; margin-top: 4px; }
.qr-wrap {
    padding: 20px;
    text-align: center;
    background: white;
}
.qr-wrap img {
    width: 260px; height: 260px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 8px;
}
.status-row {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 100px;
    font-size: 11px; font-weight: 700; margin-top: 10px;
}
.status-row.valid   { background: #d1fae5; color: #065f46; }
.status-row.invalid { background: #fee2e2; color: #991b1b; }
.info {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 16px 20px;
}
.info-row {
    display: flex; justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
}
.info-row:last-child { border-bottom: none; }
.info-row .lbl { color: #64748b; font-weight: 500; }
.info-row .val { font-weight: 700; color: #0f172a; text-align: right; max-width: 60%; }
.print-btn {
    display: block; width: 100%;
    padding: 14px;
    background: #003366; color: white;
    border: none; border-radius: 0 0 20px 20px;
    font-family: 'Onest', sans-serif;
    font-size: 15px; font-weight: 700;
    cursor: pointer; transition: background .2s;
}
.print-btn:hover { background: #00438a; }
@media print {
    body { padding: 0; }
    .print-btn { display: none; }
    .card { box-shadow: none; border: none; border-radius: 0; width: 100%; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <img src="logo.png" alt="Логотип" onerror="this.style.display='none'">
        <div class="brand">СЕВЕР</div>
        <div class="sub">Система контроля питания</div>
    </div>
    <div class="qr-wrap">
        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR-код" id="qrImg">
        <br>
        <span class="status-row <?= $valid ? 'valid' : 'invalid' ?>">
            <?= $valid ? '✅ Действителен' : '❌ Недействителен' ?>
        </span>
    </div>
    <div class="info">
        <div class="info-row">
            <span class="lbl">ФИО</span>
            <span class="val"><?= htmlspecialchars($emp['full_name']) ?></span>
        </div>
        <?php if ($emp['organization']): ?>
        <div class="info-row">
            <span class="lbl">Организация</span>
            <span class="val"><?= htmlspecialchars($emp['organization']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($emp['department']): ?>
        <div class="info-row">
            <span class="lbl">Отдел</span>
            <span class="val"><?= htmlspecialchars($emp['department']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($emp['vjg_type']): ?>
        <div class="info-row">
            <span class="lbl">ВЖГ</span>
            <span class="val"><?= htmlspecialchars($emp['vjg_type']) ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="lbl">Действует до</span>
            <span class="val"><?= $expires ?></span>
        </div>
        <div class="info-row" style="font-size:10px">
            <span class="lbl">Код</span>
            <span class="val" style="font-family:monospace;font-size:10px"><?= htmlspecialchars($emp['qr_code']) ?></span>
        </div>
    </div>
    <button class="print-btn" onclick="window.print()">🖨️ Распечатать QR-карточку</button>
</div>
</body>
</html>
