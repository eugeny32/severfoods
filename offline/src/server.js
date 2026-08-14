const express = require('express');
const path    = require('path');

const authRouter       = require('./routes/auth');
const employeesRouter  = require('./routes/employees');
const mealLogsRouter   = require('./routes/meal_logs');
const mealPointsRouter = require('./routes/meal_points');
const syncRouter       = require('./routes/sync');
const configRouter     = require('./routes/config');
const updateRouter     = require('./routes/update');
const tailscaleRouter  = require('./routes/tailscale');

const app = express();
app.use(express.json());
app.use(express.static(path.join(__dirname, '../public')));

app.use('/api/auth',        authRouter);
app.use('/api/employees',   employeesRouter);
app.use('/api/meal_logs',   mealLogsRouter);
app.use('/api/meal_points', mealPointsRouter);
app.use('/api/sync',        syncRouter);
app.use('/api/config',      configRouter);
app.use('/api/update',      updateRouter);
app.use('/api/tailscale',   tailscaleRouter);

app.get('*', (req, res) => res.sendFile(path.join(__dirname, '../public/index.html')));

let _srv = null;

function start(port) {
    return new Promise((resolve, reject) => {
        const srv = app.listen(port, '127.0.0.1', () => { console.log(`[server] :${port}`); _srv = srv; resolve(srv); });
        srv.on('error', reject);
    });
}

/**
 * Освобождает порт при выходе. Нужно при обновлении: установщик сразу
 * запускает новую копию приложения, и если старый слушающий сокет ещё висит,
 * она не сможет занять 3847 и поднимется без интерфейса.
 */
function stop() {
    if (!_srv) return;
    try { _srv.close(); } catch (_) {}
    _srv = null;
}

module.exports = { start, stop };
