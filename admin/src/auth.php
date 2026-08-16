<?php
/**
 * Вход в центральную админку — по логину и паролю.
 *
 * В региональных системах вход администратора устроен иначе: там ищут
 * сотрудника по qr_code, а пароль не проверяется вовсе. Тот QR-код лежит в
 * базе открытым текстом и физически носится в кармане. Для площадки, которая
 * видит персональные данные всех регионов сразу, это неприемлемо, поэтому
 * здесь заведён свой список учётных записей. Региональный вход не меняется.
 */

declare(strict_types=1);

const ADMIN_LOGIN_MAX_ATTEMPTS = 8;
const ADMIN_LOGIN_WINDOW_MIN   = 15;

function adminCurrentUser(): ?array
{
    return $_SESSION['admin_user'] ?? null;
}

function adminIsLoggedIn(): bool
{
    return adminCurrentUser() !== null;
}

/** Владелец может менять данные; наблюдатель — только смотреть. */
function adminIsOwner(): bool
{
    return (adminCurrentUser()['role'] ?? '') === 'owner';
}

function adminRequireLogin(): void
{
    if (adminIsLoggedIn()) return;
    header('Location: login.php');
    exit;
}

function adminRequireOwner(): void
{
    adminRequireLogin();
    if (adminIsOwner()) return;
    http_response_code(403);
    exit('<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>403</title></head>'
       . '<body><h1>Недостаточно прав</h1><p>Это действие доступно только владельцу.</p>'
       . '<p><a href="index.php">На главную</a></p></body></html>');
}

function adminClientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

/**
 * Запись в журнал действий. Логин дублируется строкой: учётную запись могут
 * удалить, а след действия должен остаться читаемым.
 */
function adminAudit(PDO $pdo, string $action, ?string $details = null, ?string $regionKey = null): void
{
    $u = adminCurrentUser();
    try {
        $pdo->prepare(
            'INSERT INTO admin_audit (user_id, user_login, action, region_key, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $u['id'] ?? null,
            $u['login'] ?? null,
            $action,
            $regionKey,
            $details !== null ? mb_substr($details, 0, 2000) : null,
            adminClientIp(),
        ]);
    } catch (PDOException $e) {
        // Журнал не должен ронять действие, ради которого он ведётся.
        error_log('[admin_audit] ' . $e->getMessage());
    }
}

/**
 * Не превышен ли лимит неудачных попыток с этого адреса.
 *
 * При ошибке базы возвращает false — то есть БЛОКИРУЕТ вход. В региональной
 * системе аналогичная проверка при сбое пропускала пользователя дальше; для
 * площадки с доступом ко всем данным правильнее наоборот.
 */
function adminLoginAllowed(PDO $pdo): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE ip_address = ? AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)'
        );
        $st->execute([adminClientIp(), ADMIN_LOGIN_WINDOW_MIN]);
        return (int)$st->fetchColumn() < ADMIN_LOGIN_MAX_ATTEMPTS;
    } catch (PDOException $e) {
        error_log('[admin_login_rate] ' . $e->getMessage());
        return false;
    }
}

function adminRecordFailedLogin(PDO $pdo, string $login): void
{
    try {
        // Сам введённый пароль не пишем никуда и никогда: опечатка в одном
        // символе оставила бы почти верный пароль в журнале навсегда.
        $pdo->prepare('INSERT INTO admin_login_attempts (ip_address, login) VALUES (?, ?)')
            ->execute([adminClientIp(), mb_substr($login, 0, 64)]);
    } catch (PDOException $e) {
        error_log('[admin_login_attempt] ' . $e->getMessage());
    }
}

/**
 * Проверка пары логин/пароль.
 *
 * @return array{0:bool,1:string} успех и текст ошибки для показа
 */
function adminAttemptLogin(PDO $pdo, string $login, string $password): array
{
    if (!adminLoginAllowed($pdo)) {
        return [false, 'Слишком много неудачных попыток. Подождите ' . ADMIN_LOGIN_WINDOW_MIN . ' минут.'];
    }
    if ($login === '' || $password === '') {
        return [false, 'Введите логин и пароль'];
    }

    $st = $pdo->prepare('SELECT * FROM admin_users WHERE login = ? AND is_active = 1');
    $st->execute([$login]);
    $user = $st->fetch();

    // Пароль проверяем всегда, даже когда учётной записи нет: иначе по времени
    // ответа можно перебрать существующие логины.
    $hash = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
    $ok   = password_verify($password, $hash);

    if (!$user || !$ok) {
        adminRecordFailedLogin($pdo, $login);
        return [false, 'Неверный логин или пароль'];
    }

    session_regenerate_id(true);
    $_SESSION['admin_user'] = [
        'id'        => (int)$user['id'],
        'login'     => $user['login'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
    ];
    $_SESSION['last_seen'] = time();

    $pdo->prepare('UPDATE admin_users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([$user['id']]);
    adminAudit($pdo, 'login', 'Вход в админку');

    return [true, ''];
}

function adminLogout(PDO $pdo): void
{
    if (adminIsLoggedIn()) adminAudit($pdo, 'logout', 'Выход из админки');
    $_SESSION = [];
    session_destroy();
}
