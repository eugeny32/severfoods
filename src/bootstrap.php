<?php
/**
 * =====================================================
 *  BOOTSTRAP — инициализация приложения
 *  Загружает .env, PDO, сессию, хелперы авторизации
 * =====================================================
 */

if (ob_get_level() === 0) ob_start();

// ─── Загрузка .env ────────────────────────────────────
(static function () {
    $file = dirname(__DIR__) . '/.env';
    if (!file_exists($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if ($key === '') continue;
        $_ENV[$key] = $val;
        putenv("{$key}={$val}");
    }
})();

/**
 * Читает переменную окружения; возвращает $default если не задана или пустая строка.
 */
function env(string $key, mixed $default = null): mixed
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

// ─── Константы приложения ─────────────────────────────
define('DB_HOST',     env('DB_HOST',     'localhost'));
define('DB_NAME',     env('DB_NAME',     ''));
define('DB_USER',     env('DB_USER',     ''));
define('DB_PASS',     env('DB_PASS',     ''));
define('APP_NAME',    env('APP_NAME',    'Система питания'));
define('APP_VERSION', env('APP_VERSION', '2.1.0'));
define('SITE_URL',    env('SITE_URL',    '/'));
define('TIMEZONE',    env('TIMEZONE',    'Europe/Moscow'));

define('TEMP_QR_PATH', dirname(__DIR__) . '/temp_qr/');
define('LOGS_PATH',    dirname(__DIR__) . '/logs/');

foreach ([TEMP_QR_PATH, LOGS_PATH] as $_dir) {
    if (!is_dir($_dir)) @mkdir($_dir, 0755, true);
}
unset($_dir);

date_default_timezone_set(TIMEZONE);

// ─── Подключение к БД ────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(503);
    // Не раскрываем детали БД клиенту
    $msg = isAjax() ? json_encode(['error' => 'Service unavailable']) : '<h1>503 Service Unavailable</h1>';
    die($msg);
}

// ─── Сессия ──────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // Автоопределение HTTPS
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    $sessionLifetime = 86400 * 30; // 30 дней — максимально удерживаем сессию

    // session.gc_maxlifetime по умолчанию ~24 мин — без этого сервер удалит
    // файл сессии задолго до истечения куки, и пользователь всё равно
    // окажется разлогинен. Синхронизируем оба значения.
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'secure'   => $isHttps,    // только HTTPS при продакшне
        'httponly' => true,        // недоступно JS
        'samesite' => 'Lax',
    ]);
    session_start();

    // Продлеваем срок действия куки при каждом запросе ("скользящая" сессия) —
    // активный пользователь не будет разлогинен, даже если открыл вкладку
    // позже истечения исходных 30 дней с момента входа.
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $sessionLifetime,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

// ─── Хелперы авторизации ─────────────────────────────

function isAjax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function checkAuth(): void
{
    if (!isset($_SESSION['user_id'])) {
        if (isAjax()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Не авторизован']);
            exit;
        }
        header('Location: login.php');
        exit;
    }
}

function checkAdmin(): void
{
    checkAuth();
    if (!($_SESSION['is_admin'] ?? false)) {
        if (isAjax()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
            exit;
        }
        header('Location: index.php?error=access_denied');
        exit;
    }
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

// ─── Ограничение попыток входа ────────────────────────

/**
 * Проверяет, не превышен ли лимит неудачных попыток входа с данного IP.
 * Использует таблицу admin_logs (action = 'login_failed').
 *
 * @param  int $maxAttempts Максимум попыток за окно времени
 * @param  int $windowMin   Окно в минутах
 * @return bool true — вход разрешён, false — заблокирован
 */
function checkLoginRateLimit(int $maxAttempts = 10, int $windowMin = 10): bool
{
    global $pdo;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM admin_logs
             WHERE ip_address = ? AND action = 'login_failed'
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$ip, $windowMin]);
        return (int)$stmt->fetchColumn() < $maxAttempts;
    } catch (PDOException $e) {
        return true; // при ошибке не блокируем
    }
}
