<?php
/**
 * =====================================================
 *  ЦЕНТРАЛЬНАЯ АДМИНКА — общий фундамент
 *
 *  Отдельное приложение со своей базой, своим .env и своим входом.
 *  Базы регионов оно только читает (и, начиная со второго этапа, пишет в
 *  явно выбранный регион) — но никогда не подменяет их «по умолчанию».
 * =====================================================
 */

declare(strict_types=1);

// ─── .env ────────────────────────────────────────────────────
// Свой файл, отдельно от региональных площадок: у админки другая база и
// другие секреты. Формат тот же, что в основной системе.
(function (): void {
    $path = dirname(__DIR__) . '/.env';
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        [$k, $v] = [trim($parts[0]), trim($parts[1])];
        $_ENV[$k] = $v;
        putenv("$k=$v");
    }
})();

function adminEnv(string $key, mixed $default = null): mixed
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

define('ADMIN_DB_HOST', adminEnv('ADMIN_DB_HOST', 'localhost'));
define('ADMIN_DB_NAME', adminEnv('ADMIN_DB_NAME', ''));
define('ADMIN_DB_USER', adminEnv('ADMIN_DB_USER', ''));
define('ADMIN_DB_PASS', adminEnv('ADMIN_DB_PASS', ''));
define('ADMIN_TITLE',   adminEnv('ADMIN_TITLE', 'СеверФудс · Центр'));

// ─── Подключение к центральной базе ──────────────────────────
/** @var PDO $pdo */
try {
    $pdo = new PDO(
        'mysql:host=' . ADMIN_DB_HOST . ';dbname=' . ADMIN_DB_NAME . ';charset=utf8mb4',
        ADMIN_DB_USER,
        ADMIN_DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Все отметки времени в базах хранятся в UTC — как и в региональных
    // системах. Местное время считается при выводе, по часовому поясу точки.
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    http_response_code(503);
    exit('<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>503</title></head>'
       . '<body><h1>База админки недоступна</h1>'
       . '<p>Проверьте настройки подключения в файле <code>admin/.env</code>.</p></body></html>');
}

// ─── Сессия ──────────────────────────────────────────────────
// Короче, чем в региональных системах (там 30 дней): у этой площадки доступ к
// персональным данным всех регионов сразу, и брошенная открытой вкладка —
// реальный риск. Восемь часов покрывают рабочий день.
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    session_set_cookie_params([
        'lifetime' => 0,          // до закрытия браузера
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SFADMINSESS');
    session_start();

    // Таймаут простоя — то, чего нет в региональных системах.
    $idleLimit = 8 * 3600;
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > $idleLimit) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_seen'] = time();
}

require_once __DIR__ . '/regions.php';
require_once __DIR__ . '/auth.php';
