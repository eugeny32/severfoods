/**
 * Смарт-терминал Эвотор: приём штрихкодов и правила площадки.
 *
 * На планшете внешний сканер притворяется клавиатурой, и код ловит сам
 * интерфейс (initGlobalScanCapture в app.js). На Эвоторе сканер принадлежит
 * системе: она рассылает код широковещательным сообщением, нативная часть его
 * принимает (EvotorIntegration.java) и передаёт сюда.
 *
 * Обработчик один и тот же — handleQrScan: логика проверки сотрудника,
 * дедупликации и записи прохода не должна раздваиваться из-за того, откуда
 * пришли символы.
 */
(function (global) {
    'use strict';

    // На каком устройстве работаем. Нативная часть отвечает честно: флаг
    // берётся из BuildConfig сборки, а не угадывается по строке браузера.
    const isEvotor = !!(global.SFNative && global.SFNative.isEvotor && global.SFNative.isEvotor());
    global.SF_EVOTOR = isEvotor;

    if (!isEvotor) return;

    // Обновления на Эвоторе приходят только из Эвотор.Маркета. Приложение,
    // которое обновляет себя мимо магазина, там недопустимо — и карточку
    // «Обновления» в настройках показывать нечего.
    global.SF_UPDATES_DISABLED = true;

    // Экранного закрепления на терминале нет: кассир обязан в любой момент
    // вернуться в меню Эвотора. Секретные жесты выхода из киоска тоже ни к
    // чему — им просто нечего разблокировать.
    global.SF_NO_KIOSK = true;

    /**
     * Точка входа для нативной части. Имя должно совпадать с тем, что
     * вызывает EvotorIntegration.deliver().
     */
    global.onEvotorBarcode = function (code) {
        const value = String(code || '').trim();
        if (!value) return;

        // До входа оператора в приложение сканировать нечего: скан на экране
        // входа — это вход по карте, там своё поле.
        const loginScreen = document.getElementById('loginScreen');
        const atLogin = loginScreen && loginScreen.style.display !== 'none';

        if (atLogin) {
            const input = document.getElementById('opQrInput');
            if (input) {
                input.value = value;
                if (typeof doLogin === 'function') doLogin('operator');
            }
            return;
        }

        if (typeof handleQrScan === 'function') {
            handleQrScan(value);
        } else {
            console.warn('[evotor] интерфейс ещё не готов, код пропущен:', value);
        }
    };
})(window);
