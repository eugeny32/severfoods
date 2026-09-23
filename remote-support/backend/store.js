// Минимальное хранилище на файле — на ntrip.host кроме coturn ничего нет,
// поэтому без внешней БД и без зависимостей, требующих компиляции нативных
// модулей (та же причина, по которой не взят better-sqlite3). Данные не
// критичные и недолговечные (присутствие устройств, сигналы WebRTC на
// время одного сеанса) — простого JSON-файла с синхронной записью более
// чем достаточно, нагрузка тут не производственная база данных, а горстка
// терминалов поддержки.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// Путь можно вынести из каталога кода (DATA_FILE) — например в /var/lib/remote-support под
// systemd StateDirectory, когда сам каталог кода смонтирован только на чтение.
const DB_PATH = process.env.DATA_FILE || path.join(__dirname, 'data.json');

function load() {
    try {
        return JSON.parse(fs.readFileSync(DB_PATH, 'utf8'));
    } catch (e) {
        return { devices: {}, commands: {}, sessions: {}, signals: {} };
    }
}

let db = load();
let saveScheduled = false;

function save() {
    if (saveScheduled) return;
    saveScheduled = true;
    setImmediate(() => {
        saveScheduled = false;
        try {
            fs.writeFileSync(DB_PATH, JSON.stringify(db));
        } catch (e) {
            // Необработанное исключение внутри setImmediate раньше валило
            // весь процесс (нет try/catch выше по стеку, кто бы его поймал) —
            // и это объясняло перемежающиеся 502: systemd перезапускал
            // упавший Node на любой ошибке записи (например, файл data.json
            // однажды создался от другого пользователя, чем тот, под которым
            // работает сервис, и запись каждый раз падала с EACCES). Теперь
            // сбой записи только логируется — сама запись пропускается, но
            // процесс живёт, а данные в памяти не теряются.
            console.error('[store] не удалось сохранить data.json:', e.message);
        }
    });
}

function randomId(bytes = 16) {
    return crypto.randomBytes(bytes).toString('hex');
}

// ── устройства ──────────────────────────────────────────────

function registerDevice(name) {
    const id = randomId(8);
    const token = randomId(24);
    db.devices[id] = { id, token, name: name || id, last_seen: null, created_at: Date.now() };
    save();
    return db.devices[id];
}

function getDevice(id) {
    return db.devices[id] || null;
}

function checkDeviceToken(id, token) {
    const d = getDevice(id);
    return !!d && d.token === token;
}

function touchDevice(id) {
    if (db.devices[id]) { db.devices[id].last_seen = Date.now(); save(); }
}

function listDevices() {
    return Object.values(db.devices).sort((a, b) => (b.last_seen || 0) - (a.last_seen || 0));
}

function renameDevice(id, name) {
    if (!db.devices[id]) return false;
    db.devices[id].name = name;
    save();
    return true;
}

function deleteDevice(id) {
    delete db.devices[id];
    delete db.commands[id];
    save();
}

// ── очередь команд устройству (пока только «начать сеанс») ────

function queueCommand(deviceId, command, payload) {
    const id = randomId(8);
    if (!db.commands[deviceId]) db.commands[deviceId] = [];
    db.commands[deviceId].push({ id, command, payload, created_at: Date.now() });
    save();
    return id;
}

function drainCommands(deviceId) {
    const cmds = db.commands[deviceId] || [];
    db.commands[deviceId] = [];
    if (cmds.length) save();
    return cmds;
}

// ── сеансы удалённого просмотра ─────────────────────────────

function createSession(deviceId) {
    const id = randomId(16);
    db.sessions[id] = { id, device_id: deviceId, status: 'pending', created_at: Date.now() };
    db.signals[id] = [];
    save();
    return db.sessions[id];
}

function getSession(id) {
    return db.sessions[id] || null;
}

function setSessionStatus(id, status) {
    if (db.sessions[id]) { db.sessions[id].status = status; save(); }
}

function endSession(id) {
    setSessionStatus(id, 'ended');
    addSignal(id, 'viewer', 'bye', {});
}

// Сеансы устарели через 2 часа — не оставляем висящие записи и открытые
// на этот момент разрешения (терминал завершит по таймауту при опросе).
const SESSION_MAX_AGE_MS = 2 * 3600 * 1000;

function isSessionActive(id) {
    const s = getSession(id);
    return !!s && s.status !== 'ended' && (Date.now() - s.created_at) < SESSION_MAX_AGE_MS;
}

// ── сигналы WebRTC (offer/answer/ice) — та же очередь на оба направления,
// различаются полем sender ──────────────────────────────────

function addSignal(sessionId, sender, type, payload) {
    if (!db.signals[sessionId]) db.signals[sessionId] = [];
    const seq = db.signals[sessionId].length
        ? db.signals[sessionId][db.signals[sessionId].length - 1].seq + 1
        : 1;
    db.signals[sessionId].push({ seq, sender, type, payload });
    save();
    return seq;
}

function pollSignals(sessionId, forSender, after) {
    const all = db.signals[sessionId] || [];
    return all.filter(s => s.sender !== forSender && s.seq > after);
}

module.exports = {
    randomId,
    registerDevice, getDevice, checkDeviceToken, touchDevice, listDevices, renameDevice, deleteDevice,
    queueCommand, drainCommands,
    createSession, getSession, setSessionStatus, endSession, isSessionActive,
    addSignal, pollSignals,
};
