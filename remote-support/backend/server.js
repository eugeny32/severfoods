/**
 * =====================================================
 *  УДАЛЁННАЯ ПОДДЕРЖКА — сигналинг-сервер
 * =====================================================
 *
 * Отдельный продукт, не привязанный к СеверФудс: просмотр экрана и
 * эмуляция касаний на любых зарегистрированных Эвотор-терминалах.
 *
 * Прямого входящего соединения к терминалу нет и не требуется — вся связь
 * инициируется терминалом (heartbeat + опрос очереди сигналов), поэтому это
 * работает даже если терминал за NAT/файрволом без белого IP. Сама передача
 * видео и данных идёт напрямую между браузером администратора и терминалом
 * через coturn (TURN/STUN на этом же сервере) — сюда попадают только
 * служебные сообщения WebRTC (offer/answer/ice), в открытом виде и ненадолго.
 *
 * Авторизация:
 *   - Устройство — токен, выданный при регистрации (заголовок X-Device-Token).
 *   - Администратор — общий секрет ADMIN_TOKEN из окружения (заголовок
 *     X-Admin-Token). Кто угодно с этим токеном видит список терминалов и
 *     может запускать сеансы — храните его так же бережно, как пароль.
 *   - Зритель (веб-страница viewer.html) — сам session_id, случайная
 *     32-байтная строка, известная только тому, кто начал сеанс, и живущая
 *     ограниченное время (см. store.js → SESSION_MAX_AGE_MS).
 */
const express = require('express');
const path = require('path');
const store = require('./store');
const { mintTurnCredentials } = require('./turn');

const app = express();
app.use(express.json({ limit: '256kb' }));
app.use(express.static(path.join(__dirname, 'public')));

const ADMIN_TOKEN = process.env.ADMIN_TOKEN || '';
if (!ADMIN_TOKEN) {
    console.warn('[remote-support] ADMIN_TOKEN не задан — админские действия будут отклоняться до его настройки в .env');
}

function requireAdmin(req, res, next) {
    const token = req.get('X-Admin-Token') || '';
    if (!ADMIN_TOKEN || token !== ADMIN_TOKEN) {
        return res.status(403).json({ ok: false, error: 'Неверный или отсутствующий админский токен' });
    }
    next();
}

function requireDevice(req, res, next) {
    const id = req.body.device_id || req.query.device_id;
    const token = req.get('X-Device-Token') || '';
    if (!id || !store.checkDeviceToken(id, token)) {
        return res.status(403).json({ ok: false, error: 'Неизвестное устройство или неверный токен' });
    }
    req.deviceId = id;
    next();
}

// ── регистрация устройства (без авторизации — она и выдаётся здесь) ──
// Открытый эндпоинт нарочно: терминал ещё не имеет токена в момент первого
// запуска приложения. Если это станет проблемой (посторонний зарегистрирует
// чужое устройство), сюда стоит добавить общий код активации — сейчас, на
// старте продукта, это осознанно не сделано ради простоты первого запуска.
app.post('/api/register', (req, res) => {
    const name = String(req.body.name || '').slice(0, 100);
    const device = store.registerDevice(name);
    res.json({ ok: true, device_id: device.id, device_token: device.token });
});

// ── устройство: heartbeat + очередь команд ─────────────────
app.post('/api/heartbeat', requireDevice, (req, res) => {
    store.touchDevice(req.deviceId);
    const commands = store.drainCommands(req.deviceId);
    res.json({ ok: true, commands });
});

// ── устройство: сигналы WebRTC (offer/ice) → зрителю ───────
app.post('/api/signal', requireDevice, (req, res) => {
    const { session_id, type, payload } = req.body;
    if (!session_id || !['offer', 'ice', 'bye'].includes(type) || payload === undefined) {
        return res.status(400).json({ ok: false, error: 'Некорректные параметры' });
    }
    if (!store.isSessionActive(session_id)) {
        return res.status(404).json({ ok: false, error: 'Сеанс не найден или завершён' });
    }
    store.addSignal(session_id, 'device', type, payload);
    if (type === 'offer') store.setSessionStatus(session_id, 'connecting');
    res.json({ ok: true });
});

// ── устройство: опрос сигналов от зрителя (answer/ice/bye) + статус ──
app.get('/api/signal/poll', requireDevice, (req, res) => {
    const sessionId = req.query.session_id;
    const after = parseInt(req.query.after || '0', 10);
    const session = store.getSession(sessionId);
    if (!session) return res.status(404).json({ ok: false, error: 'not_found' });

    const signals = store.pollSignals(sessionId, 'device', after);
    res.json({ ok: true, status: session.status, signals });
});

// ── админ: список устройств ─────────────────────────────────
app.get('/api/devices', requireAdmin, (req, res) => {
    const ONLINE_THRESHOLD_MS = 90 * 1000; // heartbeat раз в ~30с
    const now = Date.now();
    const devices = store.listDevices().map(d => ({
        ...d,
        token: undefined, // не отдаём токен устройства администратору без нужды
        online: !!d.last_seen && (now - d.last_seen) < ONLINE_THRESHOLD_MS,
    }));
    res.json({ ok: true, devices });
});

app.post('/api/devices/:id/rename', requireAdmin, (req, res) => {
    const ok = store.renameDevice(req.params.id, String(req.body.name || '').slice(0, 100));
    res.json({ ok });
});

app.delete('/api/devices/:id', requireAdmin, (req, res) => {
    store.deleteDevice(req.params.id);
    res.json({ ok: true });
});

// ── админ: запуск сеанса просмотра ──────────────────────────
app.post('/api/session/start', requireAdmin, (req, res) => {
    const deviceId = req.body.device_id;
    const device = store.getDevice(deviceId);
    if (!device) return res.status(404).json({ ok: false, error: 'Устройство не найдено' });

    const iceServers = mintTurnCredentials(2 * 3600);
    if (!iceServers) {
        return res.status(500).json({ ok: false, error: 'TURN не настроен на сервере (TURN_SHARED_SECRET)' });
    }

    const session = store.createSession(deviceId);
    store.queueCommand(deviceId, 'start_session', { session_id: session.id, ice_servers: iceServers });

    res.json({ ok: true, session_id: session.id, ice_servers: iceServers });
});

app.post('/api/session/:id/end', requireAdmin, (req, res) => {
    store.endSession(req.params.id);
    res.json({ ok: true });
});

// Зритель тоже может завершить свой сеанс (например, при закрытии вкладки
// через sendBeacon, у которого нет доступа к заголовкам — знания самого
// session_id для этого достаточно, как и для остальных действий зрителя).
app.post('/api/session/:id/viewer_end', (req, res) => {
    if (store.getSession(req.params.id)) store.endSession(req.params.id);
    res.json({ ok: true });
});

// ── зритель: сигналы (answer/ice) → устройству ──────────────
// Авторизация — знание самого session_id (см. пояснение в шапке файла),
// отдельного токена зрителя нет.
app.post('/api/session/:id/signal', (req, res) => {
    const sessionId = req.params.id;
    const { type, payload } = req.body;
    if (!['answer', 'ice'].includes(type) || payload === undefined) {
        return res.status(400).json({ ok: false, error: 'Некорректные параметры' });
    }
    if (!store.isSessionActive(sessionId)) {
        return res.status(404).json({ ok: false, error: 'Сеанс не найден или завершён' });
    }
    store.addSignal(sessionId, 'viewer', type, payload);
    if (type === 'answer') store.setSessionStatus(sessionId, 'active');
    res.json({ ok: true });
});

app.get('/api/session/:id/poll', (req, res) => {
    const sessionId = req.params.id;
    const after = parseInt(req.query.after || '0', 10);
    const session = store.getSession(sessionId);
    if (!session) return res.status(404).json({ ok: false, error: 'not_found' });

    const signals = store.pollSignals(sessionId, 'viewer', after);
    res.json({ ok: true, status: session.status, signals });
});

const PORT = process.env.PORT || 8787;
app.listen(PORT, () => {
    console.log(`[remote-support] слушаю порт ${PORT}`);
});
