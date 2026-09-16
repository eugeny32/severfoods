/**
 * Обновление Android-версии.
 *
 * В Windows-версии этим занимается electron-updater: скачивает установщик и
 * подменяет приложение сам. На Android так нельзя — установку APK всегда
 * подтверждает пользователь системным диалогом. Поэтому здесь: проверяем
 * версию на сервере, а по команде «установить» отдаём ссылку на APK системе,
 * которая скачает файл и предложит установку.
 *
 * Формат latest.yml от electron-updater не подходит (он про .exe и blockmap),
 * рядом лежит отдельный файл latest-android.json:
 *   { "version": "1.7.1", "url": "SeverFoods-1.7.1.apk", "notes": "..." }
 */
(function (global) {
    'use strict';

    const MANIFEST = '/offline/dist/android/latest-android.json';

    let _status = {
        currentVersion: global.SF_APP_VERSION || '1.0.0',
        available:      false,
        newVersion:     null,
        url:            null,
        checking:       false,
        error:          null,
    };

    function getStatus() { return { ..._status }; }

    /** Сравнение версий вида 1.7.10 — почленно, а не строками ("1.10" > "1.9"). */
    function isNewer(a, b) {
        const pa = String(a).split('.').map(n => parseInt(n, 10) || 0);
        const pb = String(b).split('.').map(n => parseInt(n, 10) || 0);
        for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
            const x = pa[i] || 0, y = pb[i] || 0;
            if (x !== y) return x > y;
        }
        return false;
    }

    async function check() {
        // На смарт-терминале Эвотор обновления ставит Эвотор.Маркет. Ходить за
        // своим APK мимо магазина там нельзя, поэтому проверка молча ничего не
        // находит — вместо того чтобы показывать оператору кнопку, которая
        // всё равно не сработает.
        if (global.SF_UPDATES_DISABLED) return getStatus();

        _status.checking = true;
        _status.error    = null;
        try {
            const base = SFSettings.serverUrl();
            const res  = await SFApi.nativeFetch(base + MANIFEST + '?t=' + Date.now(), {
                signal: AbortSignal.timeout(15000),
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const info = await res.json();

            _status.available  = isNewer(info.version, _status.currentVersion);
            _status.newVersion = info.version || null;
            _status.url = info.url
                ? new URL(info.url, base + '/offline/dist/android/').href
                : null;
        } catch (e) {
            _status.available = false;
            _status.error     = e.message;
        } finally {
            _status.checking = false;
        }
        return getStatus();
    }

    /**
     * Передаёт ссылку системе: Android скачает APK и покажет диалог установки.
     * Требуется разрешение «установка из неизвестных источников» — оно даётся
     * один раз при первом обновлении.
     */
    function install() {
        if (global.SF_UPDATES_DISABLED) return false;
        if (!_status.url) return false;
        if (global.SFNative && global.SFNative.openUrl) {
            global.SFNative.openUrl(_status.url);
        } else {
            global.open(_status.url, '_system');
        }
        return true;
    }

    global.SFUpdate = { getStatus, check, install };
})(window);
