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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход · <?= htmlspecialchars(ADMIN_TITLE, ENT_QUOTES) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,'Segoe UI',sans-serif;background:#17212b;color:#e8ecf1;
     min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.box{width:100%;max-width:400px;background:#1e2a35;border:1px solid #2d3f50;border-radius:16px;padding:32px}
h1{font-size:21px;font-weight:800;margin-bottom:6px}
p.sub{font-size:13px;color:#8096a7;margin-bottom:26px}
label{display:block;font-size:12px;color:#8096a7;margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
input{width:100%;height:46px;padding:0 14px;font-size:15px;color:#e8ecf1;background:#17212b;
      border:1.5px solid #2d3f50;border-radius:9px;margin-bottom:16px;font-family:inherit}
input:focus{outline:none;border-color:#4f9cf9}
button{width:100%;height:46px;background:#2f7dd1;color:#fff;border:none;border-radius:9px;
       font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}
button:hover{background:#276cb8}
.err{background:rgba(220,38,38,.15);border:1px solid #7f1d1d;color:#fca5a5;
     border-radius:8px;padding:11px 14px;font-size:13px;margin-bottom:18px}
</style>
</head>
<body>
<form class="box" method="post" autocomplete="off">
    <h1><?= htmlspecialchars(ADMIN_TITLE, ENT_QUOTES) ?></h1>
    <p class="sub">Центральная админка. Доступ ко всем регионам.</p>

    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>

    <label for="login">Логин</label>
    <input id="login" name="login" type="text" autocapitalize="off" spellcheck="false" autofocus
           value="<?= htmlspecialchars((string)($_POST['login'] ?? ''), ENT_QUOTES) ?>">

    <label for="password">Пароль</label>
    <input id="password" name="password" type="password">

    <button type="submit">Войти</button>
</form>
</body>
</html>
