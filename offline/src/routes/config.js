const router  = require('express').Router();
const fs      = require('fs');
const path    = require('path');
const { execSync } = require('child_process');
const db      = require('../db');
const tz      = require('../tz');

const ENV_PATH = path.join(require('electron').app.getPath('userData'), '../../../.env');
// Fallback for dev: look next to main.js
const ENV_PATHS = [
    path.join(process.cwd(), '.env'),
    path.join(__dirname, '../../.env'),
];

function findEnvPath() {
    for (const p of ENV_PATHS) { try { if (fs.existsSync(p)) return p; } catch(_){} }
    return ENV_PATHS[0];
}

function readEnv() {
    try {
        const txt = fs.readFileSync(findEnvPath(), 'utf8');
        const obj = {};
        txt.split(/\r?\n/).forEach(line => {
            const m = line.match(/^([A-Z_]+)\s*=\s*(.*)$/);
            if (m) obj[m[1]] = m[2].trim().replace(/^["']|["']$/g,'');
        });
        return obj;
    } catch(_) { return {}; }
}

function writeEnvKey(key, value) {
    const envPath = findEnvPath();
    let txt = '';
    try { txt = fs.readFileSync(envPath, 'utf8'); } catch(_) {}
    const lines = txt.split(/\r?\n/);
    let found = false;
    const updated = lines.map(line => {
        if (line.startsWith(key + '=') || line.startsWith(key + ' =')) {
            found = true;
            return `${key}=${value}`;
        }
        return line;
    });
    if (!found) updated.push(`${key}=${value}`);
    fs.writeFileSync(envPath, updated.filter((l,i,a) => l || i < a.length-1).join('\n'), 'utf8');
}

// GET /api/config — returns current config (super_admin only checked client-side)
router.get('/', (req, res) => {
    const env = readEnv();
    res.json({
        ok: true,
        sync_url:   env.OFFLINE_SYNC_URL   || 'https://www.severfoods.ru/api/offline_sync.php',
        sync_token: env.OFFLINE_SYNC_TOKEN || '',
        tz_offset:  tz.getTzOffset(),
        version:    process.env.npm_package_version || '1.0.0',
        commit_date: (() => { try { return execSync('git log -1 --format=%cd --date=format:%d.%m.%Y', { cwd: path.join(__dirname, '../..'), stdio: ['pipe','pipe','pipe'] }).toString().trim(); } catch(_){ return '—'; } })(),
        db_path:    db.getDbPath ? db.getDbPath() : '—',
        env_path:   findEnvPath(),
    });
});

// POST /api/config — update sync_url and/or sync_token and/or tz_offset
router.post('/', (req, res) => {
    const { sync_url, sync_token, tz_offset } = req.body || {};
    if (sync_url  !== undefined) writeEnvKey('OFFLINE_SYNC_URL',   sync_url);
    if (sync_token !== undefined) writeEnvKey('OFFLINE_SYNC_TOKEN', sync_token);
    if (tz_offset !== undefined) {
        if (!tz.setTzOffset(tz_offset)) {
            return res.status(400).json({ ok: false, error: 'Некорректный часовой пояс (пример: +07:00)' });
        }
    }
    res.json({ ok: true, message: 'Сохранено.' });
});

// GET /api/config/schedules — all points with schedules
router.get('/schedules', (req, res) => {
    const db2 = require('../db');
    res.json({ ok: true, meal_points: db2.getMealPoints() });
});

// PUT /api/config/schedules/:pointId — update schedules for a point
router.put('/schedules/:pointId', (req, res) => {
    const pointId  = parseInt(req.params.pointId);
    const schedules = req.body?.schedules;
    if (!Array.isArray(schedules)) return res.status(400).json({ ok:false, error:'schedules[] required' });
    const db2 = require('../db');
    db2.updateSchedules(pointId, schedules);
    res.json({ ok: true });
});

module.exports = router;
