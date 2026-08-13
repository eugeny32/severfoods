const router = require('express').Router();
const db     = require('../db');
const tz     = require('../tz');
const sync   = require('../sync');
const crypto = require('crypto');

const VALID_TYPES = ['breakfast', 'lunch', 'dinner', 'night'];

// GET /api/meal_logs?limit=200&offset=0&point_id=N&since=ISO
router.get('/', (req, res) => {
    const limit   = Math.min(parseInt(req.query.limit   || '200'), 1000);
    const offset  = parseInt(req.query.offset  || '0');
    const pointId = req.query.point_id ? parseInt(req.query.point_id) : null;
    const since   = req.query.since || null;
    res.json({ ok: true, logs: db.getMealLogs(limit, offset, pointId, since) });
});

// POST /api/meal_logs — register a meal scan
router.post('/', async (req, res) => {
    const { employee_id, meal_type, meal_point_id, meal_point_name, operator_name } = req.body || {};

    if (!employee_id || !VALID_TYPES.includes(meal_type)) {
        return res.status(400).json({ ok: false, error: 'invalid_params' });
    }

    // 1. Локальная проверка — быстрая и работает всегда, даже без сети.
    if (db.hasTodayLog(employee_id, meal_type, tz.todayWindowUtc())) {
        return res.json({ ok: false, error: 'duplicate', message: 'Уже зафиксировано сегодня' });
    }

    // 2. Проверка на сервере — видит записи с ДРУГИХ точек, о которых локальная
    //    база ещё не знает (важно, когда точки раздачи стоят рядом). Если сети
    //    нет или сервер не ответил за 2 сек — возвращается null, и решение
    //    принимается только по локальной базе, как раньше. Питание из-за
    //    проблем со связью никогда не блокируется.
    const remote = await sync.checkMealRemotely(employee_id, meal_type, meal_point_id);
    if (remote === true) {
        return res.json({ ok: false, error: 'duplicate', message: 'Уже питался сегодня на другой точке' });
    }

    const offline_id  = crypto.randomUUID();
    const scanned_at  = new Date().toISOString().replace('T', ' ').slice(0, 19);

    db.insertMealLog({
        offline_id,
        employee_id,
        meal_type,
        meal_point_id:   meal_point_id   || null,
        meal_point_name: meal_point_name || 'Офлайн',
        operator_name:   operator_name   || 'Офлайн',
        scanned_at,
    });

    // 3. Отправляем на сервер сразу, не дожидаясь часовой синхронизации —
    //    чтобы соседняя точка увидела запись в ближайшие секунды. Строго в
    //    фоне: ответ оператору уходит немедленно, ниже.
    sync.pushSoon();

    res.json({ ok: true, offline_id, scanned_at });
});

module.exports = router;
