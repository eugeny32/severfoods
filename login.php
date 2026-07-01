<?php
require_once 'config.php';
require_once 'functions.php';

// Уже авторизован — перенаправляем
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error  = '';
$points = getMealPoints($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role_type     = $_POST['role']          ?? 'operator';
    $login         = trim($_POST['login']    ?? '');
    $password      = trim($_POST['password'] ?? '');
    $meal_point_id = intval($_POST['meal_point_id'] ?? 0);
    $ip            = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // ─── Ограничение попыток входа ─────────────────
    if (!checkLoginRateLimit(10, 10)) {
        $error = 'Слишком много попыток входа. Подождите 10 минут и попробуйте снова.';
    } elseif ($role_type === 'admin') {

        // ─── Вход администратора ───────────────────

        // Опциональный демо-доступ из .env (по умолчанию отключён)
        $demoLogin = env('DEMO_ADMIN_LOGIN');
        $demoPass  = env('DEMO_ADMIN_PASS');

        if ($demoLogin && $demoPass && $login === $demoLogin && $password === $demoPass) {
            $_SESSION['user_id']           = 0;
            $_SESSION['user_name']         = 'Администратор (демо)';
            $_SESSION['role']              = 'admin';
            $_SESSION['is_admin']          = true;
            $_SESSION['assigned_point_id'] = null;
            logAction('admin_login', 'Вход через демо-доступ');
            header('Location: index.php');
            exit;
        }

        // Поиск admin/super_admin в БД по QR-коду
        $stmt = $pdo->prepare(
            "SELECT * FROM employees
             WHERE qr_code = ? AND role IN ('admin','super_admin') AND is_active = 1"
        );
        $stmt->execute([$login]);
        $admin = $stmt->fetch();

        if ($admin) {
            $_SESSION['user_id']           = $admin['id'];
            $_SESSION['user_name']         = $admin['full_name'];
            $_SESSION['role']              = $admin['role'];
            $_SESSION['is_admin']          = true;
            $_SESSION['assigned_point_id'] = $admin['assigned_point_id'] ?? null;
            logAction('admin_login', "Администратор {$admin['full_name']} вошёл");
            header('Location: index.php');
            exit;
        }

        // Неудачная попытка
        logAction('login_failed', "Неверный логин администратора: " . mb_substr($login, 0, 50));
        $error = 'Неверный логин или пароль администратора';

    } else {
        // ─── Вход оператора ────────────────────────

        if ($meal_point_id <= 0) {
            $error = 'Выберите точку питания';
        } else {
            $meal_point = getMealPointById($pdo, $meal_point_id);
            if (!$meal_point) {
                $error = 'Точка питания не найдена';
            } else {
                $stmt = $pdo->prepare(
                    "SELECT * FROM employees
                     WHERE qr_code = ? AND is_active = 1
                       AND role IN ('operator','admin','super_admin')"
                );
                $stmt->execute([$login]);
                $user = $stmt->fetch();

                if ($user) {
                    $is_admin = in_array($user['role'], ['admin', 'super_admin'], true);
                    $_SESSION['user_id']           = $user['id'];
                    $_SESSION['user_name']         = $user['full_name'];
                    $_SESSION['role']              = $user['role'];
                    $_SESSION['is_admin']          = $is_admin;
                    $_SESSION['meal_point_id']     = $meal_point['id'];
                    $_SESSION['meal_point_name']   = $meal_point['point_name'];
                    $_SESSION['assigned_point_id'] = $user['assigned_point_id'] ?? null;
                    logAction('login', "{$user['full_name']} ({$user['role']}) → точка {$meal_point['point_name']}");
                    $pdo->prepare("UPDATE employees SET last_login_point_id = ? WHERE id = ?")
                        ->execute([$meal_point['id'], $user['id']]);
                    header('Location: index.php');
                    exit;
                }

                logAction('login_failed', "QR не найден для точки {$meal_point['point_name']}");
                $error = 'QR-код не найден или недостаточно прав';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<script src="assets/js/tz-detect.js"></script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#002756">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Питание">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/img/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="favicon.ico">
<title>Вход — <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --blue-950: #001a3a; --blue-900: #002756; --blue-800: #003366;
    --blue-700: #00438a; --blue-500: #0055a5; --blue-400: #1a6fc4;
    --accent: #f59e0b; --surface: #ffffff;
    --gray-50: #f8fafc; --gray-100: #f1f5f9; --gray-200: #e2e8f0;
    --gray-500: #64748b; --danger: #ef4444;
    --radius: 16px; --shadow: 0 25px 50px rgba(0,0,0,.35);
}
body {
    font-family: 'Onest', sans-serif; background: var(--blue-900);
    min-height: 100vh; display: flex; align-items: center;
    justify-content: center; padding: 20px; overflow-x: hidden;
}
body::before {
    content: ''; position: fixed; inset: 0;
    background:
        radial-gradient(ellipse 80% 60% at 20% 20%, rgba(0,68,138,.6) 0%, transparent 60%),
        radial-gradient(ellipse 60% 80% at 80% 80%, rgba(0,51,102,.8) 0%, transparent 60%),
        radial-gradient(ellipse 40% 40% at 60% 10%, rgba(245,158,11,.08) 0%, transparent 50%);
    animation: bgShift 12s ease-in-out infinite alternate;
    pointer-events: none; z-index: 0;
}
@keyframes bgShift { 0%{opacity:1} 100%{opacity:.7;filter:hue-rotate(15deg)} }
body::after {
    content: ''; position: fixed; inset: 0;
    background-image: linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),
                      linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size: 40px 40px; pointer-events: none; z-index: 0;
}
.login-wrap { position: relative; z-index: 1; width: 100%; max-width: 460px; }
.brand { text-align: center; margin-bottom: 32px; }
.brand-logo {
    width: 72px; height: 72px; background: var(--blue-800);
    border: 2px solid rgba(255,255,255,.15); border-radius: 20px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 32px; box-shadow: 0 8px 24px rgba(0,0,0,.3),0 0 0 8px rgba(255,255,255,.03);
    margin-bottom: 16px;
}
.brand-logo img { width: 52px; height: 52px; object-fit: contain; border-radius: 8px; }
.brand h1 { font-size: 26px; font-weight: 800; color: #fff; letter-spacing: -.5px; text-shadow: 0 2px 12px rgba(0,0,0,.3); }
.brand p  { font-size: 13px; color: rgba(255,255,255,.5); margin-top: 4px; }
.role-tabs {
    display: flex; gap: 6px; background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.08); border-radius: 14px;
    padding: 5px; margin-bottom: 20px;
}
.role-tab {
    flex: 1; padding: 10px 12px; background: transparent; border: none;
    border-radius: 10px; color: rgba(255,255,255,.55);
    font-family: 'Onest', sans-serif; font-size: 14px; font-weight: 600;
    cursor: pointer; transition: all .25s;
}
.role-tab.active { background: var(--surface); color: var(--blue-800); box-shadow: 0 2px 8px rgba(0,0,0,.2); }
.role-tab:not(.active):hover { color: rgba(255,255,255,.85); }
.card {
    background: rgba(255,255,255,.97); backdrop-filter: blur(20px);
    border-radius: var(--radius); padding: 32px;
    box-shadow: var(--shadow); border: 1px solid rgba(255,255,255,.6);
}
.alert {
    display: flex; align-items: flex-start; gap: 10px;
    background: #fef2f2; border: 1px solid #fecaca;
    border-left: 4px solid var(--danger); border-radius: 10px;
    padding: 12px 14px; margin-bottom: 20px; color: #991b1b;
    font-size: 14px; animation: shake .4s ease;
}
@keyframes shake {
    0%,100%{transform:translateX(0)} 20%{transform:translateX(-6px)}
    40%{transform:translateX(6px)}   60%{transform:translateX(-4px)}
    80%{transform:translateX(4px)}
}
.form { display: none; }
.form.active { display: block; animation: fadeUp .3s ease; }
@keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
.field { margin-bottom: 20px; }
.field label {
    display: block; font-size: 12px; font-weight: 600;
    color: var(--gray-500); text-transform: uppercase;
    letter-spacing: .6px; margin-bottom: 6px;
}
.field input, .field select {
    width: 100%; padding: 13px 16px;
    border: 2px solid var(--gray-200); border-radius: 10px;
    font-family: 'Onest', sans-serif; font-size: 15px; color: #1e293b;
    background: var(--gray-50); transition: all .2s; outline: none;
}
.field input:focus, .field select:focus {
    border-color: var(--blue-500); background: #fff;
    box-shadow: 0 0 0 3px rgba(0,85,165,.12);
}
.field input::placeholder { color: #94a3b8; }
.qr-hint { font-size: 11px; color: var(--gray-500); margin-top: 6px; display: flex; align-items: center; gap: 4px; }
.btn-login {
    width: 100%; padding: 14px;
    background: linear-gradient(135deg, var(--blue-800), var(--blue-500));
    color: white; border: none; border-radius: 10px;
    font-family: 'Onest', sans-serif; font-size: 15px; font-weight: 700;
    cursor: pointer; transition: all .25s;
    box-shadow: 0 4px 16px rgba(0,51,102,.35); position: relative; overflow: hidden;
}
.btn-login::after { content:''; position:absolute; inset:0; background:linear-gradient(transparent,rgba(255,255,255,.08)); }
.btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,51,102,.45); }
.btn-login:active { transform: translateY(0); }
.hint-row { text-align: center; margin-top: 20px; font-size: 12px; color: var(--gray-500); line-height: 1.6; }
.version { text-align: center; margin-top: 20px; font-size: 11px; color: rgba(255,255,255,.25); }

/* ── Camera button ── */
.qr-field-wrap { position: relative; }
.qr-field-wrap input { padding-right: 48px; }
.btn-cam {
    position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
    width: 34px; height: 34px; border-radius: 8px;
    background: var(--blue-800); border: none; color: #fff;
    font-size: 15px; cursor: pointer; display: flex; align-items: center;
    justify-content: center; transition: background .15s;
}
.btn-cam:hover { background: var(--blue-500); }

/* ── Camera overlay ── */
.camera-overlay {
    display: none; position: fixed; inset: 0; z-index: 1000;
    background: rgba(0,0,0,.92); flex-direction: column;
    align-items: center; justify-content: center;
}
.camera-overlay.open { display: flex; }
.camera-frame {
    position: relative; border-radius: 16px; overflow: hidden;
    border: 3px solid rgba(255,255,255,.25);
    box-shadow: 0 0 0 4000px rgba(0,0,0,.5);
}
.camera-frame video { display: block; width: min(90vw, 420px); }
.camera-frame::before, .camera-frame::after,
.camera-corner-bl, .camera-corner-tr {
    content: ''; position: absolute; width: 28px; height: 28px;
    border-color: #f59e0b; border-style: solid;
}
.camera-frame::before  { top: 0; left: 0;  border-width: 3px 0 0 3px; }
.camera-frame::after   { top: 0; right: 0; border-width: 3px 3px 0 0; }
.camera-corner-bl { bottom: 0; left: 0;  border-width: 0 0 3px 3px; }
.camera-corner-tr { bottom: 0; right: 0; border-width: 0 3px 3px 0; }
.camera-scan-line {
    position: absolute; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg,transparent,#f59e0b,transparent);
    animation: scanLine 2s linear infinite;
}
@keyframes scanLine { 0%{top:10%} 100%{top:90%} }
.camera-hint { color: rgba(255,255,255,.7); font-size: 14px; margin-top: 16px; font-weight: 500; }
.camera-close {
    margin-top: 20px; padding: 10px 28px; border-radius: 10px;
    background: rgba(255,255,255,.12); border: 1.5px solid rgba(255,255,255,.2);
    color: #fff; font-family: 'Onest', sans-serif; font-size: 14px;
    font-weight: 600; cursor: pointer; transition: background .15s;
}
.camera-close:hover { background: rgba(255,255,255,.2); }
</style>
</head>
<body>
<div class="login-wrap">
    <div class="brand">
        <div class="brand-logo">
            <img src="logo.png" alt="Логотип" onerror="this.parentElement.innerHTML='<i class=&quot;fas fa-utensils&quot;></i>'">
        </div>
        <h1>СЕВЕР</h1>
        <p>Система контроля питания</p>
    </div>

    <div class="role-tabs">
        <button class="role-tab active" id="tabOperator" onclick="switchTab('operator')">
            <i class="fas fa-user"></i> Оператор
        </button>
        <button class="role-tab" id="tabAdmin" onclick="switchTab('admin')">
            <i class="fas fa-crown"></i> Администратор
        </button>
    </div>

    <div class="card">
        <?php if ($error): ?>
        <div class="alert">
            <span><i class="fas fa-exclamation-triangle"></i></span>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <!-- Форма оператора -->
        <form method="POST" class="form active" id="formOperator">
            <input type="hidden" name="role" value="operator">

            <div class="field">
                <label><i class="fas fa-map-marker-alt"></i> Точка питания</label>
                <select name="meal_point_id" required>
                    <option value="">— Выберите точку —</option>
                    <?php foreach ($points as $pt): ?>
                    <option value="<?= $pt['id'] ?>"
                        <?= (isset($_POST['meal_point_id']) && $_POST['meal_point_id'] == $pt['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pt['point_name']) ?>
                        <?php if (!empty($pt['city'])): ?>— <?= htmlspecialchars($pt['city']) ?><?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label><i class="fas fa-qrcode"></i> QR-код сотрудника</label>
                <div class="qr-field-wrap">
                    <input type="text" name="login" id="loginOp"
                        placeholder="Отсканируйте или введите QR-код"
                        autocomplete="off" autofocus>
                    <button type="button" class="btn-cam" onclick="openLoginCamera('loginOp')" title="Открыть камеру"><i class="fas fa-camera"></i></button>
                </div>
                <div class="qr-hint">
                    <span><i class="fas fa-camera"></i></span> Нажмите камеру для сканирования или введите код вручную
                </div>
            </div>

            <button type="submit" class="btn-login"><i class="fas fa-unlock"></i> Войти как оператор</button>
            <div class="hint-row">Оператор авторизуется по QR-коду сотрудника</div>
        </form>

        <!-- Форма администратора -->
        <form method="POST" class="form" id="formAdmin">
            <input type="hidden" name="role" value="admin">

            <div class="field">
                <label><i class="fas fa-user-shield"></i> QR-код администратора</label>
                <div class="qr-field-wrap">
                    <input type="text" name="login" id="loginAdmin" placeholder="Введите QR-код"
                        autocomplete="off">
                    <button type="button" class="btn-cam" onclick="openLoginCamera('loginAdmin')" title="Открыть камеру"><i class="fas fa-camera"></i></button>
                </div>
                <div class="qr-hint">
                    <span><i class="fas fa-camera"></i></span> Нажмите камеру или введите QR-код администратора
                </div>
            </div>

            <button type="submit" class="btn-login"><i class="fas fa-crown"></i> Войти как администратор</button>
            <div class="hint-row">
                Для первого входа используйте QR-код: <strong>SUPER_ADMIN_QR</strong>
            </div>
        </form>
    </div>

    <div class="version">v<?= APP_VERSION ?> · <?= htmlspecialchars(APP_NAME) ?></div>
    <div style="text-align:center;margin-top:12px">
        <a href="chat_login.php" style="font-size:12px;color:rgba(255,255,255,.45);text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:1px solid rgba(255,255,255,.12);border-radius:8px;transition:all .2s"
           onmouseover="this.style.color='rgba(255,255,255,.75)';this.style.borderColor='rgba(255,255,255,.3)'"
           onmouseout="this.style.color='rgba(255,255,255,.45)';this.style.borderColor='rgba(255,255,255,.12)'">
            <i class="fas fa-comments"></i> Войти в мессенджер
        </a>
    </div>

<!-- Camera overlay -->
<div class="camera-overlay" id="loginCameraOverlay">
    <div style="color:#fff;font-size:18px;font-weight:700;margin-bottom:16px"><i class="fas fa-camera"></i> Сканирование QR-кода</div>
    <div class="camera-frame">
        <video id="loginCameraVideo" playsinline autoplay muted></video>
        <div class="camera-scan-line"></div>
        <div class="camera-corner-bl"></div>
        <div class="camera-corner-tr"></div>
    </div>
    <div class="camera-hint">Наведите камеру на QR-код сотрудника</div>
    <button class="camera-close" onclick="closeLoginCamera()"><i class="fas fa-times"></i> Закрыть</button>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="assets/js/qr-input.js"></script>
<script>
let _camStream = null, _camActive = false, _camTarget = null;
let _camCanvas = null, _camCtx = null, _camFrame = null;

async function openLoginCamera(targetId) {
    _camTarget = document.getElementById(targetId);
    const overlay = document.getElementById('loginCameraOverlay');
    const video   = document.getElementById('loginCameraVideo');
    try {
        _camStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } }
        });
        video.srcObject = _camStream;
        await video.play();
        overlay.classList.add('open');
        _camActive = true;
        _camCanvas = _camCanvas || document.createElement('canvas');
        _camCtx    = _camCtx    || _camCanvas.getContext('2d');
        _scanLoop(video);
    } catch(e) {
        alert('Не удалось открыть камеру: ' + e.message);
    }
}

function closeLoginCamera() {
    _camActive = false;
    cancelAnimationFrame(_camFrame);
    if (_camStream) { _camStream.getTracks().forEach(t => t.stop()); _camStream = null; }
    document.getElementById('loginCameraOverlay').classList.remove('open');
}

function _scanLoop(video) {
    if (!_camActive || !window.jsQR) return;
    _camFrame = requestAnimationFrame(() => {
        if (!_camActive) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            _camCanvas.width  = video.videoWidth;
            _camCanvas.height = video.videoHeight;
            _camCtx.drawImage(video, 0, 0);
            const img  = _camCtx.getImageData(0, 0, _camCanvas.width, _camCanvas.height);
            const code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
            if (code && code.data) {
                closeLoginCamera();
                if (_camTarget) {
                    _camTarget.value = code.data;
                    _camTarget.dispatchEvent(new Event('input', { bubbles: true }));
                    _camTarget.closest('form').submit();
                }
                return;
            }
        }
        _scanLoop(video);
    });
}

function switchTab(tab) {
    const isOp = tab === 'operator';
    document.getElementById('formOperator').classList.toggle('active', isOp);
    document.getElementById('formAdmin').classList.toggle('active', !isOp);
    document.getElementById('tabOperator').classList.toggle('active', isOp);
    document.getElementById('tabAdmin').classList.toggle('active', !isOp);
    const inp = isOp
        ? document.querySelector('#formOperator input[name=login]')
        : document.querySelector('#formAdmin input[name=login]');
    setTimeout(() => inp && inp.focus(), 50);
}
<?php if ($error && ($_POST['role'] ?? '') === 'admin'): ?>
switchTab('admin');
<?php endif; ?>
</script>
</body>
</html>
