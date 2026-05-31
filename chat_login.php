<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['chat_uid'], $_SESSION['chat_uname'], $_SESSION['chat_urole'], $_SESSION['chat_is_admin']);
    header('Location: chat_login.php');
    exit;
}

// Already logged in?
if (!empty($_SESSION['user_id']) && !empty($_SESSION['is_admin'])) {
    header('Location: chat.php'); exit;
}
if (!empty($_SESSION['chat_uid'])) {
    header('Location: chat.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['login_type'] ?? 'qr';
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($type === 'qr') {
        $qr = trim($_POST['qr_code'] ?? '');
        if ($qr) {
            $stmt = $pdo->prepare(
                "SELECT * FROM employees
                 WHERE qr_code = ? AND is_active = 1
                   AND (chat_access = 1 OR role IN ('admin','super_admin'))"
            );
            $stmt->execute([$qr]);
            $user = $stmt->fetch();
            if ($user) {
                $_SESSION['chat_uid']      = (int)$user['id'];
                $_SESSION['chat_uname']    = $user['full_name'];
                $_SESSION['chat_urole']    = $user['role'];
                $_SESSION['chat_is_admin'] = in_array($user['role'], ['admin','super_admin'], true);
                header('Location: chat.php'); exit;
            } else {
                $error = 'QR-код не найден или доступ в чат не разрешён';
            }
        } else {
            $error = 'Введите QR-код';
        }
    } else {
        $login    = trim($_POST['login']    ?? '');
        $password = $_POST['password'] ?? '';
        if ($login && $password) {
            $stmt = $pdo->prepare(
                "SELECT * FROM employees
                 WHERE chat_username = ? AND chat_password IS NOT NULL AND is_active = 1"
            );
            $stmt->execute([$login]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['chat_password'])) {
                $_SESSION['chat_uid']      = (int)$user['id'];
                $_SESSION['chat_uname']    = $user['full_name'];
                $_SESSION['chat_urole']    = $user['role'];
                $_SESSION['chat_is_admin'] = in_array($user['role'], ['admin','super_admin'], true);
                header('Location: chat.php'); exit;
            } else {
                $error = 'Неверный логин или пароль';
            }
        } else {
            $error = 'Заполните все поля';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход в мессенджер — <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
    --blue-900:#002756;--blue-800:#003366;--blue-700:#00438a;--blue-500:#0055a5;
    --accent:#f59e0b;--surface:#fff;--gray-50:#f8fafc;--gray-100:#f1f5f9;
    --gray-200:#e2e8f0;--gray-500:#64748b;--danger:#ef4444;--radius:16px;
}
body{font-family:'Onest',sans-serif;background:var(--blue-900);min-height:100vh;
    display:flex;align-items:center;justify-content:center;padding:20px;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;
    background:radial-gradient(ellipse 80% 60% at 20% 20%,rgba(0,68,138,.6) 0%,transparent 60%),
               radial-gradient(ellipse 60% 80% at 80% 80%,rgba(0,51,102,.8) 0%,transparent 60%);
    animation:bgShift 12s ease-in-out infinite alternate;pointer-events:none;z-index:0}
@keyframes bgShift{0%{opacity:1}100%{opacity:.7;filter:hue-rotate(15deg)}}
body::after{content:'';position:fixed;inset:0;
    background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size:40px 40px;pointer-events:none;z-index:0}
.wrap{position:relative;z-index:1;width:100%;max-width:440px}
.brand{text-align:center;margin-bottom:28px}
.brand-logo{width:68px;height:68px;background:var(--blue-800);border:2px solid rgba(255,255,255,.15);
    border-radius:18px;display:inline-flex;align-items:center;justify-content:center;
    font-size:28px;color:#fff;margin-bottom:14px;box-shadow:0 8px 24px rgba(0,0,0,.3)}
.brand h1{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.5px}
.brand p{font-size:13px;color:rgba(255,255,255,.5);margin-top:4px}
.tabs{display:flex;gap:5px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);
    border-radius:12px;padding:4px;margin-bottom:18px}
.tab-btn{flex:1;padding:9px 10px;background:transparent;border:none;border-radius:9px;
    color:rgba(255,255,255,.55);font-family:'Onest',sans-serif;font-size:14px;font-weight:600;
    cursor:pointer;transition:all .25s;display:flex;align-items:center;justify-content:center;gap:6px}
.tab-btn.active{background:var(--surface);color:var(--blue-800);box-shadow:0 2px 8px rgba(0,0,0,.2)}
.tab-btn:not(.active):hover{color:rgba(255,255,255,.85)}
.card{background:rgba(255,255,255,.97);backdrop-filter:blur(20px);border-radius:var(--radius);
    padding:28px;box-shadow:0 25px 50px rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.6)}
.alert{display:flex;align-items:flex-start;gap:10px;background:#fef2f2;border:1px solid #fecaca;
    border-left:4px solid var(--danger);border-radius:10px;padding:12px 14px;margin-bottom:18px;
    color:#991b1b;font-size:14px;animation:shake .4s ease}
@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-6px)}
    40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}}
.form{display:none}.form.active{display:block;animation:fadeUp .3s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.field{margin-bottom:18px}
.field label{display:block;font-size:11px;font-weight:700;color:var(--gray-500);
    text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
.field input{width:100%;padding:12px 14px;border:2px solid var(--gray-200);border-radius:10px;
    font-family:'Onest',sans-serif;font-size:15px;color:#1e293b;background:var(--gray-50);
    transition:all .2s;outline:none}
.field input:focus{border-color:var(--blue-500);background:#fff;box-shadow:0 0 0 3px rgba(0,85,165,.12)}
.field input::placeholder{color:#94a3b8}
.hint{font-size:11px;color:var(--gray-500);margin-top:5px;display:flex;align-items:center;gap:4px}
.btn-submit{width:100%;padding:13px;background:linear-gradient(135deg,var(--blue-800),var(--blue-500));
    color:#fff;border:none;border-radius:10px;font-family:'Onest',sans-serif;font-size:15px;font-weight:700;
    cursor:pointer;transition:all .25s;box-shadow:0 4px 16px rgba(0,51,102,.35)}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,51,102,.45)}
.version{text-align:center;margin-top:18px;font-size:11px;color:rgba(255,255,255,.25)}
.back-link{display:block;text-align:center;margin-top:14px;font-size:12px;color:rgba(255,255,255,.4);text-decoration:none}
.back-link:hover{color:rgba(255,255,255,.7)}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="brand-logo"><i class="fas fa-comments"></i></div>
        <h1>Мессенджер</h1>
        <p><?= htmlspecialchars(APP_NAME) ?></p>
    </div>

    <div class="tabs">
        <button class="tab-btn active" id="tabQr" onclick="switchTab('qr')">
            <i class="fas fa-qrcode"></i> QR-код
        </button>
        <button class="tab-btn" id="tabPass" onclick="switchTab('pass')">
            <i class="fas fa-key"></i> Логин / Пароль
        </button>
    </div>

    <div class="card">
        <?php if ($error): ?>
        <div class="alert">
            <i class="fas fa-exclamation-triangle"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <!-- QR form -->
        <form method="POST" class="form active" id="formQr">
            <input type="hidden" name="login_type" value="qr">
            <div class="field">
                <label><i class="fas fa-qrcode"></i> QR-код сотрудника</label>
                <input type="text" name="qr_code" placeholder="Отсканируйте или введите QR-код"
                    autocomplete="off" autofocus>
                <div class="hint"><i class="fas fa-info-circle"></i> Используйте сканер или введите код вручную</div>
            </div>
            <button type="submit" class="btn-submit">
                <i class="fas fa-sign-in-alt"></i> Войти по QR
            </button>
        </form>

        <!-- Password form -->
        <form method="POST" class="form" id="formPass">
            <input type="hidden" name="login_type" value="password">
            <div class="field">
                <label><i class="fas fa-user"></i> Логин</label>
                <input type="text" name="login" placeholder="Имя пользователя" autocomplete="username">
            </div>
            <div class="field">
                <label><i class="fas fa-lock"></i> Пароль</label>
                <input type="password" name="password" placeholder="••••••••" autocomplete="current-password">
            </div>
            <button type="submit" class="btn-submit">
                <i class="fas fa-sign-in-alt"></i> Войти
            </button>
        </form>
    </div>

    <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Вернуться в основную систему</a>
    <div class="version">v<?= APP_VERSION ?> · <?= htmlspecialchars(APP_NAME) ?></div>
</div>

<script src="assets/js/qr-input.js"></script>
<script>
function switchTab(tab){
    const isQr = tab==='qr';
    document.getElementById('formQr').classList.toggle('active',isQr);
    document.getElementById('formPass').classList.toggle('active',!isQr);
    document.getElementById('tabQr').classList.toggle('active',isQr);
    document.getElementById('tabPass').classList.toggle('active',!isQr);
    const inp = isQr
        ? document.querySelector('#formQr input[name=qr_code]')
        : document.querySelector('#formPass input[name=login]');
    setTimeout(()=>inp&&inp.focus(),50);
}
</script>
</body>
</html>
