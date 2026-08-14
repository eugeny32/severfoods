<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Раньше эта страница была доступна без авторизации вообще — любой, кто
// знал ID сотрудника, мог получить его QR-код. Плюс отдельная защита от
// компрометации QR супер-администратора: обычный админ не должен вообще
// видеть/печатать карточку супер-админа (даже своей организации), а
// карточки чужих организаций — не видит никто, кроме супер-админа.
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403); die('<p>Доступ запрещён</p>');
}

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$emp = getEmployeeById($pdo, $id);
if (!$emp) { http_response_code(404); die('<p>Сотрудник не найден</p>'); }

$is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';
if (!$is_super_admin) {
    if (($emp['role'] ?? '') === 'super_admin') {
        http_response_code(403); die('<p>Доступ запрещён</p>');
    }
    $me = getEmployeeById($pdo, (int)($_SESSION['user_id'] ?? 0));
    $myOrg = trim($me['organization'] ?? '');
    if ($myOrg === '' || trim($emp['organization'] ?? '') !== $myOrg) {
        http_response_code(403); die('<p>Доступ запрещён</p>');
    }
}

require_once __DIR__ . '/print_card.php';
$cardHtml = renderCard($emp, 260);
$role     = $emp['role'] ?? '';
$c        = cardColors($role);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR — <?= htmlspecialchars($emp['full_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
<?php include __DIR__ . '/print_card_css.php'; ?>
body {
    background: #e8edf2;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    min-height: 100vh; padding: 24px;
    gap: 20px;
}
.qr-card { width: 340px; }
.print-btn {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 28px; border-radius: 12px;
    background: <?= $c['header'] ?>; color: #fff;
    border: none; font-family: 'Onest', sans-serif;
    font-size: 15px; font-weight: 700; cursor: pointer;
    transition: opacity .2s;
}
.print-btn:hover { opacity: .85; }
@media print {
    body { background: white; padding: 0; justify-content: flex-start; }
    .print-btn { display: none; }
    .qr-card {
        width: 85.6mm; box-shadow: none;
        border-radius: 4mm;
    }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<?= $cardHtml ?>
<button class="print-btn" onclick="window.print()">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
        <rect x="6" y="14" width="12" height="8"/>
    </svg>
    Распечатать карточку
</button>
<button class="print-btn" onclick="QrPdf.downloadCardPdf(document.querySelector('.qr-card'))"
        style="background:#166534" title="Файл для отправки сотруднику — открывается на телефоне">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <rect x="5" y="2" width="14" height="20" rx="2"/><line x1="10" y1="18" x2="14" y2="18"/>
    </svg>
    Скачать PDF для телефона
</button>
<script src="assets/js/qrious.min.js"></script>
<script src="assets/js/jspdf.umd.min.js"></script>
<script src="assets/js/qr-pdf.js"></script>
<script>
document.querySelectorAll('canvas[data-qr]').forEach(function(c) {
    var size = Math.round(c.offsetWidth) || 220;
    new QRious({ element: c, value: c.dataset.qr, size: size * 2,
        foreground: c.dataset.qrFg || '#003366', background: '#ffffff', padding: 6 });
    c.style.width = c.style.height = '';
});
</script>
</body>
</html>
