/**
 * Определяет местный часовой пояс браузера и сохраняет его офсет в cookie
 * app_tz (формат "+03:00"), чтобы сервер мог корректно считать границы
 * отчётных суток по местному времени пользователя, а не по UTC.
 */
(function () {
    try {
        var offsetMin = -new Date().getTimezoneOffset(); // минуты к востоку от UTC
        var sign = offsetMin >= 0 ? '+' : '-';
        var abs  = Math.abs(offsetMin);
        var hh   = String(Math.floor(abs / 60)).padStart(2, '0');
        var mm   = String(abs % 60).padStart(2, '0');
        var tz   = sign + hh + ':' + mm;

        if (document.cookie.indexOf('app_tz=' + tz) === -1) {
            document.cookie = 'app_tz=' + tz + '; path=/; max-age=31536000; SameSite=Lax';
        }
    } catch (e) { /* оставляем серверный часовой пояс по умолчанию */ }
})();
