/**
 * Синхронизация Android-версии — порт offline/src/sync.js.
 *
 * Отличия от Windows-версии:
 *   - браузерный fetch вместо node-fetch. ВАЖНО: браузер МОЛЧА игнорирует
 *     параметр `timeout`, поэтому везде используется AbortSignal.timeout().
 *     Без этого проверка «питался ли на другой точке» (2 секунды) висела бы
 *     бесконечно и оператор ждал бы у закрытой двери;
 *   - настройки берутся из SFSettings, а не из process.env;
 *   - статус рассылается событием на window вместо IPC Electron;
 *   - удалённые команды ограничены теми, что имеют смысл на планшете
 *     (sync, проверка обновления); перезапуск, разблокировка киоска и
 *     Tailscale — Windows-специфичны и здесь не поддерживаются.
 */
(function (global) {
    'use strict';

    const APP_VERSION      = global.SF_APP_VERSION || '1.0.0';
    const SYNC_INTERVAL_MS = 60 * 60 * 1000;

    const TIMEOUT_PING    = 15000;
    const TIMEOUT_DEFAULT = 45000;
    const TIMEOUT_PUSH    = 120000;
    const CHECK_MEAL_TIMEOUT_MS = 2000; // оператор ждёт этот ответ — держим коротким
    const INSTANT_PUSH_DELAY_MS = 1500;
    const PUSH_BATCH_SIZE       = 200;

    let _status = {
        online: false, lastSync: null, lastSyncOk: false,
        syncError: null, inProgress: false, employees: 0, mealPoints: 0,
    };
    let _timer = null, _netCheck = null, _instantPushTimer = null;

    function getStatus() { return { ..._status }; }

    function notifyStatus() {
        global.dispatchEvent(new CustomEvent('sync-status-update', { detail: getStatus() }));
    }

    async function api(action, opts = {}) {
        const url   = `${SFSettings.syncEndpoint()}?action=${action}`;
        const since = opts.since ? `&since=${encodeURIComponent(opts.since)}` : '';
        const timeout = opts.timeout
            || (action === 'ping' ? TIMEOUT_PING : action === 'push' ? TIMEOUT_PUSH : TIMEOUT_DEFAULT);

        const reqOpts = {
            method:  opts.method || 'GET',
            headers: {
                'X-Sync-Token': SFSettings.syncToken(),
                'Content-Type': 'application/json',
                'Accept':       'application/json',
            },
            body:      opts.body ? JSON.stringify(opts.body) : undefined,
            signal:    AbortSignal.timeout(timeout),
            timeoutMs: timeout,
        };
        // Через нативный мост (core/net-bridge.js), если он есть — на Эвоторе
        // WebView сам по себе не мог достучаться ни до одного HTTPS-хоста,
        // хотя интернет на устройстве был.
        const res = (global.SFNet && global.SFNet.available())
            ? await global.SFNet.nativeHttpFetch(url + since, reqOpts)
            : await fetch(url + since, reqOpts);

        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Server error');
        return data;
    }

    async function checkOnline() {
        try { await api('ping'); return true; } catch (_) { return false; }
    }

    async function pullEmployees() {
        const data = await api('employees');
        SFDb.beginBatch();
        try {
            for (const e of data.employees) SFDb.upsertEmployee(e);
        } finally {
            SFDb.endBatch(); // одна запись в IndexedDB вместо N
        }
        return data.employees.length;
    }

    async function pullMealPoints() {
        const data = await api('meal_points');
        SFDb.beginBatch();
        try {
            for (const p of data.meal_points) SFDb.upsertMealPoint(p, p.schedules);
        } finally {
            SFDb.endBatch();
        }
        return data.meal_points.length;
    }

    async function pushLogs() {
        const logs = SFDb.getUnsyncedLogs();
        if (!logs.length) return { inserted: 0, skipped: 0, errors: 0 };

        const totals = { inserted: 0, skipped: 0, errors: 0 };
        for (let i = 0; i < logs.length; i += PUSH_BATCH_SIZE) {
            const batch = logs.slice(i, i + PUSH_BATCH_SIZE);
            const data  = await api('push', { method: 'POST', body: { records: batch } });
            await SFDb.markLogsSynced(data.results || []);
            totals.inserted += data.inserted || 0;
            totals.skipped  += data.skipped  || 0;
            totals.errors   += data.errors   || 0;
        }
        return totals;
    }

    /** Мгновенная отправка после скана — строго в фоне, оператор её не ждёт. */
    function pushSoon() {
        if (!_status.online) return;
        if (_instantPushTimer) return;
        _instantPushTimer = setTimeout(async () => {
            _instantPushTimer = null;
            if (!_status.online || _status.inProgress) return;
            try {
                const res = await pushLogs();
                if (res.inserted) console.log(`[sync] мгновенно отправлено: ${res.inserted}`);
            } catch (e) {
                console.error('[sync] мгновенная отправка не удалась:', e.message);
            }
        }, INSTANT_PUSH_DELAY_MS);
    }

    /**
     * null — «не знаем» (нет сети, таймаут, старый сервер). В этом случае
     * решение принимается по локальной базе, и питание не блокируется.
     */
    async function checkMealRemotely(employeeId, mealType, mealPointId) {
        if (!_status.online) return null;
        try {
            const data = await api('check_meal', {
                method:  'POST',
                timeout: CHECK_MEAL_TIMEOUT_MS,
                body: { employee_id: employeeId, meal_type: mealType, meal_point_id: mealPointId || null },
            });
            return !!data.exists;
        } catch (_) {
            return null;
        }
    }

    async function runSync() {
        if (_status.inProgress) return;
        _status.inProgress = true;
        notifyStatus();

        try {
            _status.online = await checkOnline();
            if (!_status.online) {
                _status.lastSyncOk = false;
                _status.syncError  = 'Нет подключения к серверу';
                return;
            }

            const empCount = await pullEmployees();
            const ptCount  = await pullMealPoints();
            const pushRes  = await pushLogs();

            _status.employees  = empCount;
            _status.mealPoints = ptCount;
            _status.lastSync   = new Date().toISOString();
            _status.lastSyncOk = true;
            _status.syncError  = null;

            await SFDb.setMeta('last_sync', _status.lastSync);
            await SFDb.setMeta('employees_count', String(empCount));

            console.log(`[sync] OK — emp:${empCount} pts:${ptCount} pushed:${pushRes.inserted} dup:${pushRes.skipped}`);
        } catch (err) {
            _status.lastSyncOk = false;
            _status.syncError  = err.message;
            console.error('[sync] error:', err.message);
        } finally {
            _status.inProgress = false;
            notifyStatus();
        }
    }

    async function networkMonitorLoop() {
        const wasOnline = _status.online;
        _status.online = await checkOnline();
        if (!wasOnline && _status.online) {
            console.log('[sync] сеть восстановлена — синхронизация');
            await runSync();
        }
        if (_status.online) sendHeartbeat().catch(() => {});
    }

    // ── heartbeat и удалённые команды ─────────────────────────

    function getDeviceId() {
        let id = SFDb.getMeta('device_id');
        if (!id) {
            id = (crypto.randomUUID && crypto.randomUUID())
                || 'dev-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
            SFDb.setMeta('device_id', id);
        }
        return id;
    }

    function currentSessionInfo() {
        const raw = SFDb.getMeta('session');
        if (!raw) return {};
        try {
            const emp = (JSON.parse(raw).employee) || {};
            return {
                pointId:      emp.selected_point_id || emp.assigned_point_id || null,
                pointName:    emp.selected_point_name || emp.assigned_point_name || null,
                employeeName: emp.full_name || null,
            };
        } catch (_) { return {}; }
    }

    async function sendHeartbeat() {
        const { pointId, pointName, employeeName } = currentSessionInfo();
        const data = await api('heartbeat', {
            method: 'POST',
            body: {
                device_id:     getDeviceId(),
                point_id:      pointId,
                point_name:    pointName,
                employee_name: employeeName,
                app_version:   APP_VERSION + '-android',
            },
        });
        for (const cmd of (data.commands || [])) handleRemoteCommand(cmd);
    }

    async function ackCommand(id, status, result) {
        try {
            await api('command_ack', { method: 'POST', body: { command_id: id, status, result: result || null } });
        } catch (e) {
            console.error('[remote] ack failed:', e.message);
        }
    }

    /**
     * Удалённый просмотр/управление экраном (только Эвотор) — команда
     * remote_screen от api/remote_access.php. Сам захват, WebRTC и обмен
     * сигналами дальше идут в нативном коде (RemoteScreenService.java),
     * здесь только передаём то, что для этого нужно: session_id,
     * ICE-серверы из payload, и текущие адрес/токен синхронизации — теми же
     * запросами, что и обычная синхронизация (api/offline_sync.php).
     */
    async function handleRemoteScreen(payload) {
        if (!global.SFNative || !global.SFNative.isEvotor || !global.SFNative.isEvotor()) {
            throw new Error('Удалённый экран доступен только на сборке для Эвотора');
        }
        if (!payload.session_id || !payload.ice_servers) {
            throw new Error('Некорректная команда: нет session_id или ice_servers');
        }
        if (!global.SFNative.hasScreenCapturePermission()) {
            // Разрешение выдаётся системным диалогом с участием оператора —
            // само не запросится. RemoteAccess.java на нативной стороне
            // запомнит этот сеанс и запустит его сам, как только оператор
            // подтвердит диалог (кнопка в Настройках, см. app.js).
            global.SFNative.startRemoteScreen(payload.session_id, JSON.stringify(payload.ice_servers),
                SFSettings.syncEndpoint(), SFSettings.syncToken());
            throw new Error('Захват экрана ещё не разрешён — откройте приложение и включите удалённый доступ в Настройках, затем повторите');
        }
        global.SFNative.startRemoteScreen(payload.session_id, JSON.stringify(payload.ice_servers),
            SFSettings.syncEndpoint(), SFSettings.syncToken());
    }

    async function handleRemoteCommand(cmd) {
        try {
            switch (cmd.command) {
                case 'sync':
                    await runSync();
                    break;
                case 'check_update':
                    await SFUpdate.check();
                    break;
                case 'remote_screen':
                    await handleRemoteScreen(cmd.payload || {});
                    break;
                default:
                    // Команды Windows-версии (restart, unlock_kiosk, install_tailscale,
                    // install_update) на планшете неприменимы — отвечаем честно,
                    // чтобы команда не висела в очереди «выполняется» вечно.
                    throw new Error(`Команда «${cmd.command}» не поддерживается на Android`);
            }
            await ackCommand(cmd.id, 'done');
        } catch (e) {
            await ackCommand(cmd.id, 'failed', e.message);
        }
    }

    function init() {
        const last = SFDb.getMeta('last_sync');
        if (last) _status.lastSync = last;

        setTimeout(runSync, 5000);
        _timer    = setInterval(runSync, SYNC_INTERVAL_MS);
        _netCheck = setInterval(networkMonitorLoop, 30_000);

        // Android усыпляет вебвью при выключенном экране, и таймеры в это время
        // не срабатывают. Поэтому при возвращении к приложению синхронизируемся
        // принудительно, если плановая давно не проходила.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible') return;
            const last = _status.lastSync ? Date.parse(_status.lastSync) : 0;
            if (Date.now() - last > SYNC_INTERVAL_MS) runSync();
            else pushSoon();
        });
    }

    global.SFSync = {
        init, runSync, getStatus, pushSoon, checkMealRemotely, getDeviceId,
    };
})(window);
