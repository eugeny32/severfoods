const router = require('express').Router();
const tailscale = require('../tailscale');
const db = require('../db');

// Удалённый доступ (Tailscale) — только для супер-администратора. UI и так
// прячет карточку от остальных ролей, но это можно обойти прямым запросом
// к локальному серверу — поэтому проверяем роль и здесь, по активной
// сессии (см. db.getMeta('session'), тот же механизм, что и в routes/auth.js).
function requireSuperAdmin(req, res, next) {
    try {
        const raw = db.getMeta('session');
        const sess = raw ? JSON.parse(raw) : null;
        if (sess?.employee?.role === 'super_admin' && new Date(sess.expires_at) > new Date()) {
            return next();
        }
    } catch (_) {}
    res.status(403).json({ ok: false, error: 'Доступно только супер-администратору' });
}

router.use(requireSuperAdmin);

// GET /api/tailscale/status
router.get('/status', async (req, res) => {
    try {
        res.json({ ok: true, ...(await tailscale.getStatus()) });
    } catch (e) {
        res.status(500).json({ ok: false, error: e.message });
    }
});

// POST /api/tailscale/install { auth_key, hostname }
router.post('/install', async (req, res) => {
    const { auth_key, hostname } = req.body || {};
    if (!auth_key) return res.status(400).json({ ok: false, error: 'Не указан auth key' });
    try {
        await tailscale.installAndJoin(auth_key, hostname);
        res.json({ ok: true });
    } catch (e) {
        res.status(500).json({ ok: false, error: e.message });
    }
});

module.exports = router;
