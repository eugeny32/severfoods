/**
 * Экранная клавиатура Windows (TabTip) для киоск-режима.
 *
 * Вызывается ТОЛЬКО осознанно — секретным жестом (10 кликов по логотипу),
 * см. public/assets/app.js. Автовызова при фокусе в поле нет: на проходной
 * клавиатура нужна редко, а всплывающая сама по себе она перекрывала бы
 * кнопки на маленьком экране терминала.
 *
 * Главная сложность: киоск-окно держится поверх всех окон и возвращает себе
 * фокус при его потере (см. main.js). Клавиатура при появлении забирает
 * фокус — без взаимодействия с этими механизмами она мгновенно закрывалась
 * бы обратно. Поэтому на время показа клавиатуры окно снимается с
 * "поверх всех", а обработчик blur ставится на паузу (isOpen()).
 */
const { execFile, spawn } = require('child_process');
const fs = require('fs');

// Путь одинаков для Windows 10 и 11; на 11 TabTip может быть уже запущен как
// фоновая служба — тогда повторный запуск просто показывает панель.
const TABTIP = 'C:\\Program Files\\Common Files\\microsoft shared\\ink\\TabTip.exe';

let _open = false;

/** Открыта ли сейчас экранная клавиатура (по нашему учёту). */
function isOpen() {
    return _open;
}

function isAvailable() {
    if (process.platform !== 'win32') return false;
    try { return fs.existsSync(TABTIP); } catch (_) { return false; }
}

function show() {
    return new Promise((resolve) => {
        if (!isAvailable()) return resolve({ ok: false, error: 'Экранная клавиатура не найдена в этой системе' });
        try {
            // detached + unref: клавиатура живёт своей жизнью, не завершается
            // вместе с нашим процессом и не блокирует его.
            const child = spawn(TABTIP, [], { detached: true, stdio: 'ignore' });

            // ОБЯЗАТЕЛЬНО. spawn сообщает о неудаче запуска не исключением, а
            // асинхронным событием 'error' — try/catch вокруг него бесполезен.
            // Без этого обработчика Node роняет весь основной процесс, и
            // оператор на точке видит окно «A JavaScript error occurred in the
            // main process» вместо клавиатуры. Так и происходило: файл TabTip
            // на месте (isAvailable проходит), но запуск может не удаться —
            // например, Windows 11 не даёт запускать его напрямую.
            child.on('error', (e) => {
                _open = false;
                console.error('[osk] не удалось запустить клавиатуру:', e.message);
            });

            child.unref();
            _open = true;
            resolve({ ok: true });
        } catch (e) {
            resolve({ ok: false, error: e.message });
        }
    });
}

function hide() {
    return new Promise((resolve) => {
        _open = false;
        if (process.platform !== 'win32') return resolve({ ok: true });
        // У TabTip нет ключа "закрыться". Штатный способ — закрыть её окно
        // сообщением WM_SYSCOMMAND/SC_CLOSE; проще и надёжнее для киоска —
        // завершить процесс, панель корректно исчезает.
        execFile('taskkill', ['/IM', 'TabTip.exe', '/F'], { timeout: 5000 }, () => {
            resolve({ ok: true }); // не считаем ошибкой, если процесса уже нет
        });
    });
}

/** Переключатель — жест один, поведение зависит от текущего состояния. */
async function toggle() {
    return _open ? hide() : show();
}

module.exports = { show, hide, toggle, isOpen, isAvailable };
