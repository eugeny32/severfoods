/**
 * Локальный «сервер» Android-версии.
 *
 * В Windows-версии интерфейс ходит на http://127.0.0.1:3847/api/... к Express
 * (offline/src/server.js + routes/). На Android ни Node.js, ни localhost-сервера
 * нет, поэтому вместо переписывания 26 обращений в интерфейсе подменяется сам
 * fetch: адреса, начинающиеся на /api/, обрабатываются здесь, все остальные
 * (синхронизация с severfoods.ru) уходят в сеть как обычно.
 *
 * Интерфейс (public/assets/app.js) при этом НЕ МЕНЯЕТСЯ — тот же код работает
 * и на Windows, и на планшете.
 */
(function (global) {
    'use strict';

    const nativeFetch = global.fetch.bind(global);

    /**
     * fetch() при сетевой ошибке всегда бросает один и тот же «Failed to
     * fetch» — настоящий код (DNS, TLS, обрыв, CORS) виден только в
     * логе Chromium, а до него без ADB на терминале не добраться.
     * <img> идёт в обход fetch/CORS и её onload/onerror отличают «сервер
     * вообще недоступен» от «доступен, но именно fetch к нему блокируется»
     * — этого достаточно, чтобы сузить причину без ADB и без браузера.
     */
    function probeImage(baseUrl, timeoutMs = 8000) {
        return new Promise(resolve => {
            const img = new Image();
            const done = ok => { img.onload = img.onerror = null; resolve(ok); };
            const timer = setTimeout(() => done(false), timeoutMs);
            img.onload  = () => { clearTimeout(timer); done(true); };
            img.onerror = () => { clearTimeout(timer); done(false); };
            img.src = baseUrl.replace(/\/$/, '') + '/favicon.ico?probe=' + Date.now();
        });
    }

    // Внешний хост вне нашей инфраструктуры — отличает «нет интернета на
    // терминале вообще» от «есть интернет, но не резолвится/недоступен именно
    // наш сервер» (например, приватный DNS терминала не знает наш поддомен).
    function probeExternal(timeoutMs = 8000) {
        return probeImage('https://www.google.com', timeoutMs);
    }

    /**
     * Запрос к серверу: через нативный мост (core/net-bridge.js), если он
     * есть, иначе — обычный fetch(). На терминале Эвотор WebView сам по себе
     * не мог достучаться ни до одного HTTPS-хоста, хотя интернет на
     * устройстве был; нативный код идёт другим сетевым путём.
     */
    async function serverFetch(url, opts) {
        if (global.SFNet && global.SFNet.available()) {
            return global.SFNet.nativeHttpFetch(url, {
                method:    opts.method,
                headers:   opts.headers,
                body:      opts.body,
                timeoutMs: opts.timeoutMs,
            });
        }
        return nativeFetch(url, opts);
    }

    // ── ответы ────────────────────────────────────────────────
    function json(body, status = 200) {
        return new Response(JSON.stringify(body), {
            status,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    // ── роль (порт offline/src/auth_guard.js) ─────────────────
    function currentUser() {
        try {
            const raw = SFDb.getMeta('session');
            if (!raw) return null;
            const sess = JSON.parse(raw);
            if (!sess || !sess.employee) return null;
            if (new Date(sess.expires_at) <= new Date()) return null;
            return sess.employee;
        } catch (_) { return null; }
    }
    const roleOf        = () => (currentUser() || {}).role || null;
    const isSuperAdmin  = () => roleOf() === 'super_admin';
    const isAdmin       = () => ['admin', 'super_admin'].includes(roleOf());

    // ── маршруты ──────────────────────────────────────────────

    const routes = [];
    function route(method, pattern, handler) { routes.push({ method, pattern, handler }); }

    // --- auth ---
    route('GET', /^\/api\/auth\/me$/, () => {
        const emp = currentUser();
        return emp ? json({ ok: true, employee: emp }) : json({ ok: false }, 401);
    });

    route('POST', /^\/api\/auth\/login$/, async (m, body) => {
        const { qr_code, role, meal_point_id } = body || {};
        if (!qr_code) return json({ ok: false, error: 'Отсканируйте QR-код' }, 400);

        // Сначала пробуем сервер — он единственный знает актуальные права.
        try {
            const r = await serverFetch(`${SFSettings.syncEndpoint()}?action=auth`, {
                method:  'POST',
                headers: {
                    'X-Sync-Token': SFSettings.syncToken(),
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                },
                body:      JSON.stringify({ qr_code, role: role || 'operator', meal_point_id }),
                signal:    AbortSignal.timeout(12000), // игнорируется нативным мостом — у него свой timeoutMs
                timeoutMs: 12000,
            });
            const data = await r.json();
            if (!data.ok) {
                // data.error иногда оказывается undefined — тогда JSON.stringify
                // молча выбрасывает поле, и на экране видно голое {"ok":false}
                // без единой зацепки. Показываем статус и сырое тело ответа
                // сервера целиком, чтобы понять, что он реально прислал.
                const raw = data.error || `сервер ответил статусом ${r.status}, тело: ${JSON.stringify(data).slice(0, 200)}`;
                return json({ ok: false, error: raw }, 401);
            }
            await SFDb.setMeta('session', JSON.stringify({ employee: data.employee, expires_at: data.expires_at }));
            return json({ ok: true, employee: data.employee });
        } catch (netErr) {
            // Сети нет — вход по локальному справочнику, как в Windows-версии.
            const emp = SFDb.getEmployeeByQr(qr_code);
            if (!emp) {
                // Показываем настоящую причину сетевой ошибки — на терминале без
                // браузера и ADB это единственный способ понять, где рвётся
                // соединение (DNS, таймаут, сертификат и т.д.), не только сам факт,
                // что офлайн-справочник пуст.
                const reason = (netErr && (netErr.message || netErr.name)) || 'неизвестная ошибка сети';
                const imgOk = await probeImage(SFSettings.serverUrl());
                let hint;
                if (imgOk) {
                    hint = ' Сервер отвечает на обычный запрос — блокируется именно fetch (похоже на CORS или прокси).';
                } else {
                    const extOk = await probeExternal();
                    hint = extOk
                        ? ' Интернет на терминале есть (google.com доступен), но именно наш сервер — нет (DNS не резолвит поддомен, сертификат или блокировка по хосту).'
                        : ' На терминале нет интернета вообще: не отвечает ни наш сервер, ни google.com (сеть/DNS/прокси терминала).';
                }
                return json({
                    ok: false,
                    error: `QR-код не найден в офлайн-базе, а сервер недоступен (${reason}).${hint}`,
                }, 401);
            }

            const adminRoles    = ['admin', 'super_admin'];
            const operatorRoles = ['operator', 'admin', 'super_admin'];
            if (role === 'admin' && !adminRoles.includes(emp.role)) {
                return json({ ok: false, error: 'Недостаточно прав для входа как администратор' }, 401);
            }
            if (role === 'operator' && !operatorRoles.includes(emp.role)) {
                return json({ ok: false, error: 'Недостаточно прав для входа как оператор' }, 401);
            }

            if (meal_point_id) {
                emp.selected_point_id = parseInt(meal_point_id);
                const pt = SFDb.getMealPoints().find(p => p.id === emp.selected_point_id);
                emp.selected_point_name = pt ? pt.point_name : null;
            }
            const expires_at = new Date(Date.now() + 30 * 86400000).toISOString();
            await SFDb.setMeta('session', JSON.stringify({ employee: emp, expires_at }));
            return json({ ok: true, employee: emp, offline: true });
        }
    });

    route('POST', /^\/api\/auth\/logout$/, async () => {
        await SFDb.setMeta('session', null);
        return json({ ok: true });
    });

    // --- employees ---
    route('GET', /^\/api\/employees\/scan$/, (m, b, q) => {
        const emp = SFDb.getEmployeeByQr(q.get('qr') || '');
        return emp ? json({ ok: true, employee: emp }) : json({ ok: false, error: 'not_found' }, 404);
    });

    route('GET', /^\/api\/employees$/, (m, b, q) => {
        const term = (q.get('q') || '').toLowerCase();
        let rows = SFDb.getAllEmployees();
        if (term) {
            rows = rows.filter(e =>
                e.full_name.toLowerCase().includes(term) ||
                (e.organization || '').toLowerCase().includes(term) ||
                (e.department   || '').toLowerCase().includes(term));
        }
        return json({ ok: true, employees: rows });
    });

    // --- meal_points ---
    route('GET', /^\/api\/meal_points$/, () => json({ ok: true, meal_points: SFDb.getMealPoints() }));

    // --- meal_logs ---
    const VALID_TYPES = ['breakfast', 'lunch', 'dinner', 'night'];

    route('GET', /^\/api\/meal_logs$/, (m, b, q) => {
        const limit   = Math.min(parseInt(q.get('limit') || '200'), 1000);
        const offset  = parseInt(q.get('offset') || '0');
        const pointId = q.get('point_id') ? parseInt(q.get('point_id')) : null;
        return json({ ok: true, logs: SFDb.getMealLogs(limit, offset, pointId, q.get('since') || null) });
    });

    route('POST', /^\/api\/meal_logs$/, async (m, body) => {
        const { employee_id, meal_type, meal_point_id, meal_point_name, operator_name } = body || {};
        if (!employee_id || !VALID_TYPES.includes(meal_type)) {
            return json({ ok: false, error: 'invalid_params' }, 400);
        }

        // 1. Локальная проверка — быстрая и работает всегда, даже без сети.
        if (SFDb.hasTodayLog(employee_id, meal_type, SFTz.todayWindowUtc())) {
            return json({ ok: false, error: 'duplicate', message: 'Уже зафиксировано сегодня' });
        }

        // 2. Проверка на сервере — видит записи с ДРУГИХ точек. null («не знаем»)
        //    означает, что решаем по локальной базе: питание из-за проблем со
        //    связью не блокируется никогда.
        const remote = await SFSync.checkMealRemotely(employee_id, meal_type, meal_point_id);
        if (remote === true) {
            return json({ ok: false, error: 'duplicate', message: 'Уже питался сегодня на другой точке' });
        }

        const offline_id = (crypto.randomUUID && crypto.randomUUID())
            || 'off-' + Date.now().toString(36) + Math.random().toString(36).slice(2);
        const scanned_at = new Date().toISOString().replace('T', ' ').slice(0, 19);

        await SFDb.insertMealLog({
            offline_id, employee_id, meal_type,
            meal_point_id:   meal_point_id   || null,
            meal_point_name: meal_point_name || 'Офлайн',
            operator_name:   operator_name   || 'Офлайн',
            scanned_at,
        });

        SFSync.pushSoon(); // 3. в фоне, ответ оператору уходит сразу
        return json({ ok: true, offline_id, scanned_at });
    });

    // --- sync ---
    route('GET',  /^\/api\/sync\/status$/, () => json({ ok: true, status: SFSync.getStatus() }));
    route('POST', /^\/api\/sync\/now$/, async () => {
        await SFSync.runSync();
        return json({ ok: true, status: SFSync.getStatus() });
    });

    // --- config ---
    route('GET', /^\/api\/config\/schedules$/, () => json({ ok: true, meal_points: SFDb.getMealPoints() }));

    route('PUT', /^\/api\/config\/schedules\/(\d+)$/, async (m, body) => {
        if (!Array.isArray(body && body.schedules)) return json({ ok: false, error: 'schedules[] required' }, 400);
        await SFDb.updateSchedules(parseInt(m[1]), body.schedules);
        return json({ ok: true });
    });

    route('GET', /^\/api\/config$/, () => {
        const out = {
            ok: true,
            tz_offset: SFTz.getTzOffset(),
            version:   global.SF_APP_VERSION || '1.0.0',
        };
        if (isAdmin()) {
            out.sync_url    = SFSettings.syncEndpoint();
            out.db_path     = 'IndexedDB: ' + SFStorage.KEY;
            out.env_path    = '—'; // на Android настройки лежат в самой базе
            out.commit_date = global.SF_BUILD_DATE || '—';
        }
        // Токен — только супер-администратору (тот же принцип, что и на Windows).
        if (isSuperAdmin()) out.sync_token = SFSettings.syncToken();
        return json(out);
    });

    route('POST', /^\/api\/config$/, (m, body) => {
        const { sync_url, sync_token, tz_offset } = body || {};

        // Первый запуск: сессии ещё нет, вход невозможен без токена — иначе
        // планшет было бы не настроить вообще. Как только токен задан, работает
        // обычное разграничение по ролям.
        const firstRun = !SFSettings.isConfigured();

        if ((sync_url !== undefined || sync_token !== undefined) && !isSuperAdmin() && !firstRun) {
            return json({ ok: false, error: 'Доступно только супер-администратору' }, 403);
        }
        if (tz_offset !== undefined && !isAdmin() && !firstRun) {
            return json({ ok: false, error: 'Доступно только администратору' }, 403);
        }

        if (sync_url   !== undefined) SFSettings.setServerUrl(sync_url);
        if (sync_token !== undefined) SFSettings.setSyncToken(sync_token);
        if (tz_offset  !== undefined && !SFTz.setTzOffset(tz_offset)) {
            return json({ ok: false, error: 'Некорректный часовой пояс (пример: +07:00)' }, 400);
        }
        return json({ ok: true, message: 'Сохранено.' });
    });

    // --- обновление приложения ---
    route('GET',  /^\/api\/update\/status$/,  () => json({ ok: true, status: SFUpdate.getStatus() }));
    route('POST', /^\/api\/update\/check$/,   async () => { await SFUpdate.check(); return json({ ok: true, status: SFUpdate.getStatus() }); });
    route('POST', /^\/api\/update\/install$/, () => { SFUpdate.install(); return json({ ok: true }); });

    // --- Tailscale: Windows-специфичен, на Android ставится отдельным приложением ---
    route('GET',  /^\/api\/tailscale\/status$/,  () => json({ ok: true, available: false, installed: false, running: false, message: 'На Android Tailscale устанавливается отдельным приложением' }));
    route('POST', /^\/api\/tailscale\/install$/, () => json({ ok: false, error: 'На Android недоступно' }, 400));

    // ── подмена fetch ─────────────────────────────────────────

    async function handle(url, init) {
        const method = ((init && init.method) || 'GET').toUpperCase();
        const path   = url.pathname;
        const params = url.searchParams;

        let body = null;
        if (init && init.body) {
            try { body = JSON.parse(init.body); } catch (_) { body = null; }
        }

        for (const r of routes) {
            if (r.method !== method) continue;
            const m = r.pattern.exec(path);
            if (!m) continue;
            try {
                return await r.handler(m, body, params);
            } catch (e) {
                console.error(`[api] ${method} ${path}:`, e);
                return json({ ok: false, error: e.message || 'Внутренняя ошибка' }, 500);
            }
        }
        return json({ ok: false, error: 'not_found' }, 404);
    }

    global.fetch = function (input, init) {
        const raw = (typeof input === 'string') ? input : (input && input.url) || '';
        let url;
        try { url = new URL(raw, global.location.href); } catch (_) { return nativeFetch(input, init); }

        // Перехватываем только собственный API. Всё остальное — в сеть.
        if (url.origin === global.location.origin && url.pathname.startsWith('/api/')) {
            const merged = (typeof input === 'string') ? init : Object.assign({ method: input.method }, init);
            return handle(url, merged || {});
        }
        return nativeFetch(input, init);
    };

    global.SFApi = { currentUser, isAdmin, isSuperAdmin, nativeFetch };
})(window);
