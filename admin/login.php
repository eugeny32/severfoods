<?php
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/layout.php';

if (adminIsLoggedIn()) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $error] = adminAttemptLogin(
        $pdo,
        trim((string)($_POST['login'] ?? '')),
        (string)($_POST['password'] ?? '')
    );
    if ($ok) { header('Location: index.php'); exit; }
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#002756">
<title>Вход · <?= adminEsc(ADMIN_TITLE) ?></title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="apple-touch-icon" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=2">
<style>
/* Экран входа оформлен как на порталах: тёмно-синий фон с подсветкой,
   сетка поверх, карточка по центру. Отличие одно и намеренное — здесь
   логин и пароль, а не QR-код. */
body {
    background: var(--blue-900);
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 24px calc(20px + env(safe-area-inset-left, 0px));
    overflow-x: hidden;
}
body::before {
    content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 0;
    background:
        radial-gradient(ellipse 80% 60% at 20% 20%, rgba(0,68,138,.6) 0%, transparent 60%),
        radial-gradient(ellipse 60% 80% at 80% 80%, rgba(0,51,102,.8) 0%, transparent 60%),
        radial-gradient(ellipse 40% 40% at 60% 10%, rgba(245,158,11,.08) 0%, transparent 50%);
}
body::after {
    content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 0;
    background-image: linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
    background-size: 40px 40px;
}
.wrap { position: relative; z-index: 1; width: 100%; max-width: 420px; }
.brand { text-align: center; margin-bottom: 28px; }
.brand-logo {
    width: 76px; height: 76px; margin: 0 auto 16px;
    background: var(--blue-800); border: 2px solid rgba(255,255,255,.15);
    border-radius: 22px; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 8px 24px rgba(0,0,0,.3), 0 0 0 8px rgba(255,255,255,.03);
}
.brand-logo img { width: 54px; height: 54px; object-fit: contain; border-radius: 8px; }
.brand-logo i { font-size: 30px; color: var(--accent); }
.brand h1 {
    font-size: 25px; font-weight: 800; color: #fff; letter-spacing: -.5px;
    justify-content: center; text-shadow: 0 2px 12px rgba(0,0,0,.3);
}
.brand p { font-size: 13px; color: rgba(255,255,255,.5); margin-top: 5px; }
.box {
    background: rgba(255,255,255,.97);
    border: 1px solid rgba(255,255,255,.6);
    border-radius: var(--radius-lg); padding: 28px;
    box-shadow: 0 25px 50px rgba(0,0,0,.35);
}
.box .field:last-of-type { margin-bottom: 22px; }
.box input { width: 100%; }
.box .btn { width: 100%; min-height: 48px; font-size: 15px; }
.err {
    display: flex; align-items: flex-start; gap: 10px;
    background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid var(--danger);
    border-radius: 10px; padding: 12px 14px; margin-bottom: 18px;
    color: #991b1b; font-size: 14px; animation: shake .4s ease;
}
@keyframes shake {
    0%,100% { transform: translateX(0) }
    20% { transform: translateX(-6px) } 40% { transform: translateX(6px) }
    60% { transform: translateX(-4px) } 80% { transform: translateX(4px) }
}
.note { text-align: center; margin-top: 18px; font-size: 12px; color: rgba(255,255,255,.35); line-height: 1.6; }
@media (max-width: 420px) {
    .box { padding: 22px 18px; }
    .brand-logo { width: 64px; height: 64px; border-radius: 18px; }
    .brand-logo img { width: 44px; height: 44px; }
}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="brand-logo">
            <img src="logo.png" alt="Логотип"
                 onerror="this.parentElement.innerHTML='<i class=&quot;fas fa-shield-halved&quot;></i>'">
        </div>
        <h1><?= adminEsc(ADMIN_TITLE) ?></h1>
        <p>Центр управления регионами</p>
    </div>

    <form class="box" method="post" autocomplete="off">
        <?php if ($error): ?>
            <div class="err"><i class="fas fa-circle-exclamation"></i><span><?= adminEsc($error) ?></span></div>
        <?php endif; ?>

        <div class="field">
            <label for="login">Логин</label>
            <input id="login" name="login" type="text" autocapitalize="off" spellcheck="false" autofocus
                   value="<?= adminEsc((string)($_POST['login'] ?? '')) ?>">
        </div>

        <div class="field">
            <label for="password">Пароль</label>
            <input id="password" name="password" type="password">
        </div>

        <button class="btn" type="submit"><i class="fas fa-right-to-bracket"></i> Войти</button>
    </form>

    <p class="note">
        Доступ ко всем регионам сразу.<br>
        Вход на площадки — по карте, на их собственных адресах.
    </p>
</div>
</body>
</html>
