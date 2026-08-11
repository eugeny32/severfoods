const router = require('express').Router();
const tailscale = require('../tailscale');

// GET /api/tailscale/status
router.get('/status', async (req, res) => {
    try {
        res.json({ ok: true, ...(await tailscale.getStatus()) });
    } catch (e) {
        res.status(500).json({ ok: false, error: e.message });
    }
});

// POST /api/tailscale/install { auth_key, hostname }
// Права доступа (только супер-админ) проверяются на клиенте (см. app.js,
// карточка видна только isSA) — как и у остальных настроек в этом файле
// (config.js: sync_url/sync_token тоже без серверной ролевой проверки).
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
