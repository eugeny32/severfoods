const router  = require('express').Router();
const fs      = require('fs');
const path    = require('path');
const { execSync } = require('child_process');
const db      = require('../db');
const tz      = require('../tz');
const guard   = require('../auth_guard');

/**
 * Настройки лежат там же, где локальная база — в userData (см. main.js).
 *
 * Раньше здесь был собственный перебор каталогов, начинавшийся с
 * process.cwd(). При запуске из ярлыка или из автозапуска это НЕ каталог
 * приложения, поэтому сохранение токена могло записать файл совсем не туда,
 * откуда его читает main.js: настройка «сохранялась», а после перезапуска не
 * применялась. Теперь путь один на всё приложение.
 */
function findEnvPath() {
    try {
        return path.join(require('electron').app.getPath('userData'), '.env');
    } catch (_) {
        return path.join(__dirname, '../../.env'); // режим разработки, без Electron
    }
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
    fs.mkdirSync(path.dirname(envPath), { recursive: true });
    fs.writeFileSync(envPath, updated.filter((l,i,a) => l || i < a.length-1).join('\n'), 'utf8');
    // Значение должно примениться сразу, не дожидаясь перезапуска: sync.js и
    // routes/auth.js читают process.env заново на каждый запрос.
    process.env[key] = value;
}

// GET /api/config — состав ответа зависит от роли.
// Раньше этот эндпоинт отдавал ТОКЕН СИНХРОНИЗАЦИИ вообще без проверки прав
// (роль проверялась только на клиенте) — токен мог прочитать любой процесс
// или человек на компьютере точки. Секретные поля теперь только супер-админу,
// а часовой пояс и версия остаются доступны всем: они нужны обычному экрану
// настроек оператора.
router.get('/', (req, res) => {
    const env = readEnv();
    const out = {
        ok: true,
        tz_offset: tz.getTzOffset(),
        version:   process.env.npm_package_version || '1.0.0',
    };

    if (guard.isAdmin()) {
        out.sync_url = env.SERVER_URL
            ? env.SERVER_URL.replace(/\/$/, '') + '/api/offline_sync.php'
            : 'https://www.severfoods.ru/api/offline_sync.php';
        out.db_path  = db.getDbPath ? db.getDbPath() : '—';
        out.env_path = findEnvPath();
        out.commit_date = (() => { try { return execSync('git log -1 --format=%cd --date=format:%d.%m.%Y', { cwd: path.join(__dirname, '../..'), stdio: ['pipe','pipe','pipe'] }).toString().trim(); } catch(_){ return '—'; } })();
    }

    // Секрет — только супер-администратору.
    if (guard.isSuperAdmin()) {
        out.sync_token = env.OFFLINE_SYNC_TOKEN || '';
    }

    res.json(out);
});

// POST /api/config — update sync_url and/or sync_token and/or tz_offset
router.post('/', (req, res) => {
    const { sync_url, sync_token, tz_offset } = req.body || {};

    // Адрес сервера и токен меняет только супер-админ; часовой пояс — любой
    // администратор (это его штатная настройка точки).
    if ((sync_url !== undefined || sync_token !== undefined) && !guard.isSuperAdmin()) {
        return res.status(403).json({ ok: false, error: 'Доступно только супер-администратору' });
    }
    if (tz_offset !== undefined && !guard.isAdmin()) {
        return res.status(403).json({ ok: false, error: 'Доступно только администратору' });
    }

    if (sync_url !== undefined) {
        // Поле в UI показывает полный URL до offline_sync.php, а реальный код
        // синхронизации (sync.js/routes/auth.js) читает SERVER_URL как ГОЛЫЙ
        // адрес сервера и сам достраивает /api/offline_sync.php — приводим
        // к этому виду перед записью, иначе ключ снова стал бы "молчаливо" не
        // тем, что реально используется.
        const base = sync_url.trim().replace(/\/api\/offline_sync\.php\/?$/, '').replace(/\/$/, '');
        writeEnvKey('SERVER_URL', base);
    }
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
