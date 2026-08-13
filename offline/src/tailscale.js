/**
 * Установка и подключение Tailscale (VPN-меш без белых IP/проброса портов)
 * на точке — фундамент для полноценного удалённого доступа (RDP и т.п.)
 * поверх виртуальной сети, вместо кастомного WebRTC-модуля с риском
 * ложного срабатывания антивируса на инъекции ввода.
 *
 * Официальный установщик Tailscale ставит системную службу — это требует
 * прав администратора Windows. Наше приложение работает как обычный
 * пользователь (perMachine:false), поэтому именно ЭТОТ шаг запрашивает
 * один UAC-промпт (через PowerShell Start-Process -Verb RunAs) — не всё
 * приложение целиком, а только сам инсталлятор/tailscale.exe.
 *
 * ВНИМАНИЕ: этот модуль не проверялся на реальной Windows-машине (среда
 * разработки не даёт этого сделать) — перед массовым разворачиванием
 * стоит один раз протестировать вручную на одной точке.
 */
const { execFile } = require('child_process');
const https = require('https');
const fs    = require('fs');
const path  = require('path');
const os    = require('os');

const INSTALLER_URL = 'https://pkgs.tailscale.com/stable/tailscale-setup-latest.exe';
const TAILSCALE_EXE = 'C:\\Program Files\\Tailscale\\tailscale.exe';

function isInstalled() {
    try { return fs.existsSync(TAILSCALE_EXE); } catch (_) { return false; }
}

function downloadFile(url, dest) {
    return new Promise((resolve, reject) => {
        const file = fs.createWriteStream(dest);
        const req = https.get(url, (res) => {
            if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                file.close();
                fs.unlink(dest, () => {});
                return downloadFile(res.headers.location, dest).then(resolve, reject);
            }
            if (res.statusCode !== 200) {
                file.close();
                fs.unlink(dest, () => {});
                return reject(new Error(`Не удалось скачать установщик Tailscale: HTTP ${res.statusCode}`));
            }
            res.pipe(file);
            file.on('finish', () => file.close(() => resolve()));
        });
        req.on('error', (err) => { fs.unlink(dest, () => {}); reject(err); });
        req.setTimeout(120000, () => { req.destroy(new Error('Таймаут скачивания установщика Tailscale')); });
    });
}

function psQuote(s) {
    return `'${String(s).replace(/'/g, "''")}'`;
}

/** Запускает файл с повышением прав (один UAC-промпт), ждёт завершения. */
/**
 * Окно киоска держится поверх всех окон — системный UAC-запрос оказался бы
 * ЗА ним, оператор его не увидел бы и установка выглядела бы как зависание.
 * На время elevation снимаем "поверх всех" и возвращаем после.
 */
function withTopMostSuspended(fn) {
    let windows = [];
    try {
        const { BrowserWindow } = require('electron');
        windows = BrowserWindow.getAllWindows().filter(w => w.isAlwaysOnTop());
        windows.forEach(w => w.setAlwaysOnTop(false));
    } catch (_) { /* вне Electron (тесты) — просто выполняем */ }

    const restore = () => {
        windows.forEach(w => { try { if (!w.isDestroyed()) w.setAlwaysOnTop(true, 'screen-saver'); } catch (_) {} });
    };
    return fn().then(
        (res) => { restore(); return res; },
        (err) => { restore(); throw err; }
    );
}

function runElevated(file, args) {
    return withTopMostSuspended(() => new Promise((resolve, reject) => {
        const argList = args.length ? `-ArgumentList @(${args.map(psQuote).join(',')}) ` : '';
        const script = `Start-Process -FilePath ${psQuote(file)} ${argList}-Verb RunAs -Wait -WindowStyle Hidden`;
        execFile('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', script],
            { timeout: 180000 },
            (err, stdout, stderr) => {
                if (err) return reject(new Error(stderr?.trim() || err.message));
                resolve({ stdout, stderr });
            }
        );
    }));
}

/**
 * Ставит (если ещё не стоит) и подключает Tailscale с указанным auth key.
 * Ключ генерируется в консоли Tailscale (https://login.tailscale.com/admin/settings/keys)
 * — рекомендуется многоразовый (reusable) ключ с тегом для точек питания,
 * чтобы не создавать новый на каждую машину.
 */
async function installAndJoin(authKey, hostname) {
    if (process.platform !== 'win32') throw new Error('Поддерживается только Windows');
    if (!authKey) throw new Error('Не указан Tailscale auth key');

    if (!isInstalled()) {
        const installerPath = path.join(os.tmpdir(), 'tailscale-setup.exe');
        await downloadFile(INSTALLER_URL, installerPath);
        await runElevated(installerPath, ['/quiet']);
        // Даём службе время подняться после установки
        await new Promise(r => setTimeout(r, 6000));
        if (!isInstalled()) throw new Error('Установка Tailscale не завершилась (tailscale.exe не найден)');
    }

    const args = ['up', `--authkey=${authKey}`, '--accept-routes'];
    if (hostname) args.push(`--hostname=${hostname}`);
    await runElevated(TAILSCALE_EXE, args);

    return { ok: true };
}

function getStatus() {
    return new Promise((resolve) => {
        if (!isInstalled()) return resolve({ installed: false, running: false });
        execFile(TAILSCALE_EXE, ['status', '--json'], { timeout: 10000 }, (err, stdout) => {
            if (err) return resolve({ installed: true, running: false });
            try {
                const data = JSON.parse(stdout);
                const self = data.Self || {};
                resolve({
                    installed: true,
                    running:   true,
                    ip:        (self.TailscaleIPs || [])[0] || null,
                    hostname:  self.HostName || null,
                });
            } catch (_) {
                resolve({ installed: true, running: false });
            }
        });
    });
}

/**
 * Автоподключение при старте приложения, если в локальном .env этой машины
 * задан TAILSCALE_AUTH_KEY — тогда точку не нужно подключать вручную через
 * Настройки при каждой переустановке/новой машине. Ключ НЕ хранится в коде
 * и не попадает в git — только в .env конкретной машины, рядом с
 * OFFLINE_SYNC_TOKEN (тот же принцип, см. main.js writeEnvFile/readEnvFile).
 * Тихая, не блокирующая запуск приложения операция — ошибки только логируются.
 */
async function autoJoinFromEnv(hostname) {
    const authKey = process.env.TAILSCALE_AUTH_KEY;
    if (!authKey) return;
    try {
        const status = await getStatus();
        if (status.running) return; // уже подключено — ничего не делаем
        console.log('[tailscale] TAILSCALE_AUTH_KEY найден в .env — подключаю…');
        await installAndJoin(authKey, hostname);
        console.log('[tailscale] Подключено');
    } catch (e) {
        console.error('[tailscale] Автоподключение не удалось:', e.message);
    }
}

module.exports = { isInstalled, installAndJoin, getStatus, autoJoinFromEnv };
