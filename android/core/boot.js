/**
 * Запуск Android-версии.
 *
 * Порядок важен: база → часовой пояс → API-переходник → синхронизация →
 * и только потом интерфейс (public/assets/app.js). Поэтому app.js подключается
 * отсюда динамически, а не тегом <script> — иначе он начал бы дёргать /api/
 * раньше, чем переходник встанет на место.
 */
(function (global) {
    'use strict';

    // AbortSignal.timeout появился в Chrome 103. На планшетах со старым
    // системным вебвью его нет, и тогда КАЖДЫЙ запрос упал бы с ошибкой ещё до
    // отправки — приложение молча не смогло бы ни войти, ни синхронизироваться.
    // Автофокус в поле ввода выключен: на планшете он поднимает системную
    // клавиатуру на пол-экрана каждый раз, когда открывается вход, а код и так
    // приходит с камеры или внешнего сканера. Поле остаётся доступным —
    // клавиатура появится, если оператор сам коснётся поля.
    global.SF_NO_AUTOFOCUS = true;

    if (typeof AbortSignal !== 'undefined' && !AbortSignal.timeout) {
        AbortSignal.timeout = function (ms) {
            const c = new AbortController();
            setTimeout(() => c.abort(new DOMException('TimeoutError', 'TimeoutError')), ms);
            return c.signal;
        };
    }

    /**
     * Мостик к Electron-функциям, которых на Android нет.
     * Интерфейс обращается к window.electron всего в трёх местах и каждый раз
     * проверяет наличие метода — поэтому достаточно объявить только то, что
     * реально работает. Экранную клавиатуру не объявляем сознательно: на
     * Android она появляется сама при фокусе в поле, секретный жест не нужен.
     */
    function installElectronShim() {
        global.electron = {
            syncNow:    async () => { await SFSync.runSync(); return SFSync.getStatus(); },
            syncStatus: async () => SFSync.getStatus(),
            // Выход из экранного закрепления — тем же жестом, что и выход из
            // киоска на Windows (долгое нажатие по логотипу).
            kioskUnlock: () => {
                if (global.SFNative && global.SFNative.unpin) return global.SFNative.unpin();
                console.log('[kiosk] экранное закрепление недоступно в этой сборке');
            },
        };
    }

    // ── экран первичной настройки ─────────────────────────────
    // Без токена синхронизации планшет не может ни войти, ни скачать
    // справочник — а обычный экран настроек доступен только после входа.
    // Замкнутый круг размыкается здесь: до первой настройки показываем
    // отдельный экран, дальше он больше не появляется.
    function showSetup() {
        return new Promise(resolve => {
            const wrap = document.createElement('div');
            wrap.className = 'sf-setup';
            wrap.innerHTML = `
                <div class="sf-setup-card">
                    <h1>Первичная настройка</h1>
                    <p>Укажите адрес сервера и токен синхронизации. Их выдаёт супер-администратор.</p>
                    <label>Адрес сервера</label>
                    <input id="sfSetupUrl" type="url" inputmode="url" autocapitalize="off"
                           autocomplete="off" spellcheck="false" value="${SFSettings.DEFAULT_SERVER}">
                    <label>Токен синхронизации</label>
                    <input id="sfSetupToken" type="text" autocapitalize="off"
                           autocomplete="off" spellcheck="false" placeholder="OFFLINE_SYNC_TOKEN">
                    <label>Часовой пояс точки</label>
                    <input id="sfSetupTz" type="text" inputmode="text" value="${SFTz.getTzOffset()}"
                           placeholder="+07:00">
                    <div class="sf-setup-err" id="sfSetupErr"></div>
                    <button id="sfSetupSave" type="button">Сохранить и продолжить</button>
                </div>`;
            document.body.appendChild(wrap);

            const err = wrap.querySelector('#sfSetupErr');
            wrap.querySelector('#sfSetupSave').addEventListener('click', async () => {
                const url   = wrap.querySelector('#sfSetupUrl').value.trim();
                const token = wrap.querySelector('#sfSetupToken').value.trim();
                const tz    = wrap.querySelector('#sfSetupTz').value.trim();

                if (!token) { err.textContent = 'Введите токен синхронизации.'; return; }
                if (tz && !SFTz.setTzOffset(tz)) { err.textContent = 'Часовой пояс в формате +07:00'; return; }

                SFSettings.setServerUrl(url);
                SFSettings.setSyncToken(token);
                await SFDb.saveNow();
                wrap.remove();
                resolve();
            });
        });
    }

    async function boot() {
        // Локальная база — это SQLite, собранный в WebAssembly. Смарт-терминалы
        // и старые планшеты обновляют системный вебвью не всегда, а без
        // WebAssembly приложение не заработает вообще. Проверяем это первым
        // делом: понятное сообщение лучше белого экрана, по которому не
        // догадаться, что дело в устаревшем вебвью.
        if (typeof WebAssembly === 'undefined') {
            document.body.innerHTML =
                `<div class="sf-setup"><div class="sf-setup-card">
                    <h1>Устаревший системный компонент</h1>
                    <p>На устройстве старая версия Android System WebView — приложение
                       не может открыть локальную базу данных.</p>
                    <p>Обновите системный вебвью или операционную систему устройства
                       и запустите приложение снова.</p>
                 </div></div>`;
            return;
        }

        try {
            await SFDb.init();
        } catch (e) {
            document.body.innerHTML =
                `<div class="sf-setup"><div class="sf-setup-card">
                    <h1>Не удалось открыть базу</h1><p>${e.message}</p>
                 </div></div>`;
            return;
        }

        installElectronShim();

        if (!SFSettings.isConfigured()) await showSetup();

        SFSync.init();

        // Интерфейс — последним, когда /api/ уже отвечает.
        // Версия в query-строке — та же причина, что и у остальных
        // скриптов в build-www.sh: WebView кэширует локальные файлы по URL
        // между обновлениями приложения, без этого могла бы выполняться
        // старая копия app.js даже после установки новой версии APK.
        const s = document.createElement('script');
        s.src = 'assets/app.js?v=' + (global.SF_APP_VERSION || Date.now());
        document.body.appendChild(s);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
