const fetch = require('node-fetch');
const crypto = require('crypto');
const { EventEmitter } = require('events');
const db    = require('./db');
const APP_VERSION = require('../package.json').version;

// Команды удалённого доступа (см. api/remote_access.php), пришедшие с
// heartbeat, разбираются здесь и пробрасываются наверх событиями —
// main.js подписывается на 'unlock_kiosk'/'restart' (там есть ссылка на
// окно/app), остальное (sync/проверка и установка обновления) выполняется
// прямо тут же, без участия main.js.
const remoteEvents = new EventEmitter();

// ВАЖНО: адрес сервера читается заново при каждом запросе (не кэшируется в
// константе при загрузке модуля) — иначе смена SERVER_URL в настройках (для
// точек другого региона, например nrg.severfoods.ru) не подхватывалась бы
// без перезапуска приложения, и запросы продолжали бы уходить не туда.
function getBaseUrl() {
    return (process.env.SERVER_URL || 'https://www.severfoods.ru').replace(/\/$/, '')
        + '/api/offline_sync.php';
}
const SYNC_INTERVAL_MS = 60 * 60 * 1000; // 1 hour

let _status = {
    online:       false,
    lastSync:     null,
    lastSyncOk:   false,
    syncError:    null,
    inProgress:   false,
    employees:    0,
    mealPoints:   0,
};
let _timer   = null;
let _netCheck = null;

function getStatus() {
    return { ..._status };
}

// Broadcast status to renderer via BrowserWindow if available
function notifyStatus() {
    try {
        const { BrowserWindow } = require('electron');
        BrowserWindow.getAllWindows().forEach(w => {
            w.webContents.send('sync-status-update', getStatus());
        });
    } catch (_) {}
}

// Приложение работает офлайн, синхронизация зачастую идёт по медленному
// каналу — короткий таймаут (было 15с) давал ложные ошибки на слабом интернете.
// ping — короткий (быстро понять, что сети нет), остальное — с большим запасом.
const TIMEOUT_PING    = 15000;
const TIMEOUT_DEFAULT = 45000;
const TIMEOUT_PUSH    = 120000; // отправка накопленных офлайн-записей — самый тяжёлый запрос

async function api(action, opts = {}) {
    const url    = `${getBaseUrl()}?action=${action}`;
    const method = opts.method || 'GET';
    const body   = opts.body ? JSON.stringify(opts.body) : undefined;
    const since  = opts.since ? `&since=${encodeURIComponent(opts.since)}` : '';
    const timeout = opts.timeout
        || (action === 'ping' ? TIMEOUT_PING : action === 'push' ? TIMEOUT_PUSH : TIMEOUT_DEFAULT);

    const res = await fetch(url + since, {
        method,
        headers: {
            'X-Sync-Token':  process.env.OFFLINE_SYNC_TOKEN || '',
            'Content-Type':  'application/json',
        },
        body,
        timeout,
    });

    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Server error');
    return data;
}

async function checkOnline() {
    try {
        await api('ping');
        return true;
    } catch (_) {
        return false;
    }
}

async function pullEmployees() {
    const data = await api('employees');
    for (const e of data.employees) {
        db.upsertEmployee(e);
    }
    return data.employees.length;
}

async function pullMealPoints() {
    const data = await api('meal_points');
    for (const p of data.meal_points) {
        db.upsertMealPoint(p, p.schedules);
    }
    return data.meal_points.length;
}

const PUSH_BATCH_SIZE = 200; // на слабом канале большой единый запрос чаще падает по таймауту

async function pushLogs() {
    const logs = db.getUnsyncedLogs();
    if (!logs.length) return { inserted: 0, skipped: 0, errors: 0 };

    const totals = { inserted: 0, skipped: 0, errors: 0 };
    for (let i = 0; i < logs.length; i += PUSH_BATCH_SIZE) {
        const batch = logs.slice(i, i + PUSH_BATCH_SIZE);
        const data  = await api('push', {
            method: 'POST',
            body: { records: batch },
        });
        db.markLogsSynced(data.results || []);
        totals.inserted += data.inserted || 0;
        totals.skipped  += data.skipped  || 0;
        totals.errors   += data.errors   || 0;
    }
    return totals;
}

async function runSync() {
    if (_status.inProgress) return;
    _status.inProgress = true;
    notifyStatus();

    try {
        const online = await checkOnline();
        _status.online = online;

        if (!online) {
            _status.lastSyncOk = false;
            _status.syncError  = 'Нет подключения к серверу';
            return;
        }

        const empCount = await pullEmployees();
        const ptCount  = await pullMealPoints();
        const pushRes  = await pushLogs();

        _status.employees   = empCount;
        _status.mealPoints  = ptCount;
        _status.lastSync    = new Date().toISOString();
        _status.lastSyncOk  = true;
        _status.syncError   = null;

        db.setMeta('last_sync', _status.lastSync);
        db.setMeta('employees_count', String(empCount));

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
    let wasOnline = _status.online;
    const isNow   = await checkOnline();
    _status.online = isNow;

    if (!wasOnline && isNow) {
        console.log('[sync] Network restored — triggering sync');
        await runSync();
    }

    if (isNow) sendHeartbeat().catch(() => {}); // тихо — heartbeat не критичен
}

// ── Удалённый доступ супер-администратора (см. api/remote_access.php) ──

/** Устойчивый ID этого конкретного компьютера/установки — генерируется один
 *  раз и хранится локально, переживает перезапуски приложения. */
function getDeviceId() {
    let id = db.getMeta('device_id');
    if (!id) {
        id = crypto.randomUUID();
        db.setMeta('device_id', id);
    }
    return id;
}

function currentSessionInfo() {
    const raw = db.getMeta('session');
    if (!raw) return {};
    try {
        const sess = JSON.parse(raw);
        const emp  = sess.employee || {};
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
            app_version:   APP_VERSION,
        },
    });
    for (const cmd of (data.commands || [])) {
        handleRemoteCommand(cmd); // не ждём — команды выполняются параллельно/в фоне
    }
}

async function ackCommand(id, status, result) {
    try {
        await api('command_ack', { method: 'POST', body: { command_id: id, status, result: result || null } });
    } catch (e) {
        console.error('[remote] ack failed:', e.message);
    }
}

async function handleRemoteCommand(cmd) {
    console.log(`[remote] command: ${cmd.command} (id ${cmd.id})`);
    try {
        switch (cmd.command) {
            case 'sync':
                await runSync();
                break;
            case 'check_update':
                await require('./updater').checkNow();
                break;
            case 'install_update':
                require('./updater').installNow();
                break;
            case 'unlock_kiosk':
                remoteEvents.emit('unlock_kiosk');
                break;
            case 'restart':
                // Подтверждаем ДО перезапуска и с небольшой задержкой — иначе
                // процесс может завершиться раньше, чем успеет уйти ack-запрос.
                await ackCommand(cmd.id, 'done');
                setTimeout(() => remoteEvents.emit('restart'), 500);
                return;
            default:
                throw new Error(`Неизвестная команда: ${cmd.command}`);
        }
        await ackCommand(cmd.id, 'done');
    } catch (e) {
        await ackCommand(cmd.id, 'failed', e.message);
    }
}

function init() {
    // restore last sync time from db
    const last = db.getMeta('last_sync');
    if (last) _status.lastSync = last;

    // initial sync
    setTimeout(runSync, 5000);

    // hourly timer
    _timer = setInterval(runSync, SYNC_INTERVAL_MS);

    // network monitor every 30s
    _netCheck = setInterval(networkMonitorLoop, 30_000);
}

function destroy() {
    if (_timer)    clearInterval(_timer);
    if (_netCheck) clearInterval(_netCheck);
}

function reloadConfig() {
    // OFFLINE_SYNC_TOKEN / SERVER_URL уже обновлены в process.env к этому моменту
    // (см. main.js writeEnvFile) — getBaseUrl()/api() подхватят их на следующий же
    // запрос сами, здесь просто триггерим свежую синхронизацию.
    setTimeout(runSync, 500);
}

module.exports = { init, destroy, runSync, getStatus, reloadConfig, remoteEvents, getDeviceId };
