/**
 * Сетевой мост в нативный код — только для Эвотора (window.SFNative.httpRequest).
 *
 * Подозрение подтверждено на живом терминале: обычный интернет на устройстве
 * есть (работает удалённый доступ, штатные функции терминала), а из WebView
 * не проходит вообще ни один HTTPS-запрос — ни к нашему серверу, ни к
 * google.com. Проксирование в кабинете Эвотора (вкладка «Интеграция») на это
 * не повлияло. Значит дело в сетевом стеке самого WebView (Chromium/Cronet) —
 * запрос из нативного Java-кода (java.net.HttpURLConnection) идёт другим
 * путём и не должен зависеть от того, что бы ни блокировало WebView.
 *
 * На планшете и Windows этот файл подключается тоже (общий список ядра в
 * build-www.sh), но window.SFNative.httpRequest там не появится — MainActivity
 * общий для обеих Android-сборок, метод есть у обеих, а вызывающий код сам
 * ничего не меняет: SFNet.available() просто вернёт true и там тоже, что
 * не страшно — мост работает одинаково на планшете и на Эвоторе.
 */
(function (global) {
    'use strict';

    const pending = new Map();
    let reqCounter = 0;

    global.__evotorNetCallback = function (reqId, result) {
        const cb = pending.get(reqId);
        if (!cb) return;
        pending.delete(reqId);
        cb(result);
    };

    function available() {
        return !!(global.SFNative && typeof global.SFNative.httpRequest === 'function');
    }

    /**
     * Минимальная замена fetch() поверх нативного моста — ровно то, что
     * использует вызывающий код здесь (ok/status/json()/text()), без
     * претензии на полную совместимость с Response.
     */
    function nativeHttpFetch(url, opts = {}) {
        return new Promise((resolve, reject) => {
            const reqId = 'r' + (++reqCounter) + '_' + Date.now();
            const timeoutMs = opts.timeoutMs || 15000;

            pending.set(reqId, raw => {
                let data;
                try { data = JSON.parse(raw); }
                catch (e) { reject(new Error('Некорректный ответ нативного моста: ' + e.message)); return; }

                if (!data.ok) { reject(new Error(data.error || 'Сетевая ошибка (нативный мост)')); return; }

                resolve({
                    ok:     data.status >= 200 && data.status < 300,
                    status: data.status,
                    json:   async () => JSON.parse(data.body || 'null'),
                    text:   async () => data.body || '',
                });
            });

            try {
                global.SFNative.httpRequest(
                    reqId,
                    opts.method || 'GET',
                    url,
                    JSON.stringify(opts.headers || {}),
                    opts.body || '',
                    timeoutMs
                );
            } catch (e) {
                pending.delete(reqId);
                reject(e);
            }
        });
    }

    global.SFNet = { available, nativeHttpFetch };
})(window);
