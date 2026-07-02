const router  = require('express').Router();
const updater = require('../updater');

router.get('/status', (req, res) => {
    res.json({ ok: true, status: updater.getStatus() });
});

router.post('/check', async (req, res) => {
    await updater.checkNow();
    res.json({ ok: true, status: updater.getStatus() });
});

router.post('/install', (req, res) => {
    res.json({ ok: true });
    updater.installNow(); // перезапускает приложение — ответ отправляем заранее
});

module.exports = router;
