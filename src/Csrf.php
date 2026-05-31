<?php
/**
 * =====================================================
 *  CSRF — защита от межсайтовой подделки запросов
 *
 *  Использование:
 *    В <head> страницы:   <?= Csrf::meta() ?>
 *    В форме:             <?= Csrf::field() ?>
 *    В PHP-обработчике:   Csrf::guard();
 *
 *  JS читает токен из мета-тега и передаёт его
 *  в заголовке X-CSRF-Token для AJAX-запросов.
 * =====================================================
 */
class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    /** Возвращает токен (создаёт если не существует). */
    public static function getToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return '';
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Проверяет переданный токен через timing-safe сравнение. */
    public static function verify(string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        return $expected !== '' && hash_equals($expected, $token);
    }

    /** HTML meta-тег для вставки в <head>. */
    public static function meta(): string
    {
        return '<meta name="csrf-token" content="' . htmlspecialchars(self::getToken(), ENT_QUOTES) . '">';
    }

    /** Скрытое поле для HTML-форм. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(self::getToken(), ENT_QUOTES) . '">';
    }

    /**
     * Прерывает запрос с 403, если CSRF-токен неверный.
     * Ищет токен в заголовке X-CSRF-Token (AJAX) или $_POST['_csrf_token'] (форма).
     */
    public static function guard(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf_token'] ?? '';
        if (self::verify($token)) return;

        if (isAjax()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Недействительный CSRF-токен. Обновите страницу.']);
        } else {
            http_response_code(403);
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title></head>'
               . '<body><h1>403 Forbidden</h1><p>CSRF-токен недействителен. '
               . '<a href="javascript:history.back()">Назад</a></p></body></html>';
        }
        exit;
    }
}
