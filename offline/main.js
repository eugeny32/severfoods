// Load .env before any other module reads process.env
const fs   = require('fs');
const path = require('path');

// In packaged app __dirname is inside asar; exe directory is the install root
const exeDir = path.dirname(process.execPath);
const envPath = path.join(exeDir, '.env');

function loadEnv(dir) {
    const p = path.join(dir, '.env');
    if (!fs.existsSync(p)) return false;
    fs.readFileSync(p, 'utf8').split(/\r?\n/).forEach(line => {
        const m = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)\s*$/);
        if (m) process.env[m[1]] = m[2].trim();
    });
    return true;
}

if (!loadEnv(exeDir)) loadEnv(__dirname);

const { app, BrowserWindow, ipcMain, Tray, Menu, nativeImage } = require('electron');
const db        = require('./src/db');
const server    = require('./src/server');
const sync      = require('./src/sync');
const updater   = require('./src/updater');
const tailscale = require('./src/tailscale');

const PORT = 3847;

let mainWindow  = null;
let setupWindow = null;
let tray        = null;
// Киоск-режим: пока true — окно развёрнуто на весь экран без рамки,
// свернуть на рабочий стол нельзя штатными средствами (Alt+F4, закрытие,
// сворачивание) — только через скрытый жест (10 кликов по блоку типа
// питания в самом приложении, см. public/assets/app.js). Взводится заново
// при каждом восстановлении окна из трея.
let kioskLocked = true;

Menu.setApplicationMenu(null);

// ── Удалённый доступ супер-администратора (команды с сервера, см. sync.js) ──
// 'unlock_kiosk' и 'restart' нуждаются в доступе к mainWindow/app, которого
// нет у sync.js — поэтому обрабатываются здесь, через событие.
sync.remoteEvents.on('unlock_kiosk', () => {
    console.log('[remote] unlock_kiosk — сворачиваю на рабочий стол');
    unlockAndMinimize();
});
sync.remoteEvents.on('restart', () => {
    console.log('[remote] restart — перезапуск приложения');
    app.relaunch();
    app.exit(0);
});

// ── Check if setup is needed ──────────────────────────────

function needsSetup() {
    return !process.env.OFFLINE_SYNC_TOKEN;
}

function readEnvFile() {
    const p = fs.existsSync(envPath) ? envPath : path.join(__dirname, '.env');
    if (!fs.existsSync(p)) return {};
    const result = {};
    fs.readFileSync(p, 'utf8').split(/\r?\n/).forEach(line => {
        const m = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)\s*$/);
        if (m) result[m[1]] = m[2].trim();
    });
    return result;
}

function writeEnvFile(vars) {
    const existing = readEnvFile();
    const merged   = { ...existing, ...vars };
    const content  = Object.entries(merged)
        .map(([k, v]) => `${k}=${v}`)
        .join('\r\n') + '\r\n';
    // Write to exe directory (writable in production), fallback to __dirname in dev
    const target = process.env.NODE_ENV === 'development'
        ? path.join(__dirname, '.env')
        : envPath;
    fs.writeFileSync(target, content, 'utf8');
    // Reload into process.env immediately
    Object.entries(merged).forEach(([k, v]) => { process.env[k] = v; });
}

// ── Setup window ──────────────────────────────────────────

function createSetupWindow() {
    setupWindow = new BrowserWindow({
        width:  520,
        height: 480,
        resizable: false,
        center: true,
        icon: path.join(__dirname, 'public/assets/img/icon.ico'),
        title: 'Настройка СеверФудс',
        backgroundColor: '#17212b',
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            preload: path.join(__dirname, 'src/preload_setup.js'),
        },
    });

    setupWindow.loadFile(path.join(__dirname, 'src/setup.html'));
    setupWindow.on('closed', () => { setupWindow = null; });
}

// ── Main window ───────────────────────────────────────────

function createWindow() {
    kioskLocked = true;
    mainWindow = new BrowserWindow({
        width:  1180,
        height: 720,
        minWidth:  900,
        minHeight: 600,
        icon: path.join(__dirname, 'public/assets/img/icon.ico'),
        title: 'СеверФудс',
        show: false,
        fullscreen: true,
        kiosk: true,
        frame: false,
        autoHideMenuBar: true,
        skipTaskbar: true,
        backgroundColor: '#17212b',
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            webviewTag: true,
            preload: path.join(__dirname, 'src/preload.js'),
        },
    });

    mainWindow.loadURL(`http://localhost:${PORT}/`);
    mainWindow.once('ready-to-show', () => { mainWindow.show(); mainWindow.setAlwaysOnTop(true, 'screen-saver'); });

    // Пока заблокировано — не даём ни закрыть (Alt+F4), ни свернуть иным
    // способом, кроме скрытого жеста. Единственный "легальный" путь наружу —
    // requestUnlock() через IPC (см. ниже), инициируемый 10 кликами в UI.
    mainWindow.on('close', (e) => {
        if (kioskLocked) { e.preventDefault(); return; }
        if (tray) { e.preventDefault(); mainWindow.hide(); }
    });
    // Если фокус вдруг ушёл с окна (например, системный диалог или удачный
    // Alt+Tab) — пока заблокировано, агрессивно возвращаем фокус и киоск.
    mainWindow.on('blur', () => {
        if (!kioskLocked || !mainWindow) return;
        setTimeout(() => {
            if (kioskLocked && mainWindow && !mainWindow.isDestroyed()) {
                mainWindow.setKiosk(true);
                mainWindow.show();
                mainWindow.focus();
            }
        }, 50);
    });
    mainWindow.on('closed', () => { mainWindow = null; });
}

/** Разблокировка по жесту из UI — сворачивает окно на рабочий стол. */
function unlockAndMinimize() {
    if (!mainWindow) return;
    kioskLocked = false;
    mainWindow.setAlwaysOnTop(false);
    mainWindow.setKiosk(false);
    mainWindow.setFullScreen(false);
    mainWindow.minimize();
    createTray(); // без трея свернутое окно было бы негде открыть обратно
}

/** Восстановление из трея — снова разворачиваем в киоск. */
function restoreKiosk() {
    if (!mainWindow) return;
    mainWindow.show();
    mainWindow.setFullScreen(true);
    mainWindow.setKiosk(true);
    mainWindow.setAlwaysOnTop(true, 'screen-saver');
    mainWindow.focus();
    kioskLocked = true;
}

function createTray() {
    if (tray) return; // уже создан — не плодим вторую иконку
    const imgPath = path.join(__dirname, 'public/assets/tray.png');
    const img = fs.existsSync(imgPath)
        ? nativeImage.createFromPath(imgPath)
        : nativeImage.createEmpty();
    tray = new Tray(img);
    tray.setToolTip('SeverFoods Offline');
    tray.on('click', () => restoreKiosk());
    // Трей появляется ТОЛЬКО после разблокировки жестом (см. unlockAndMinimize) —
    // "Выход"/"Настройки" тем самым недоступны обычному оператору, пока он не
    // сделал 10 кликов по блоку типа питания. Открыть/восстановить окно можно
    // всегда — это не обход блокировки, а просто способ вернуться в киоск.
    tray.setContextMenu(Menu.buildFromTemplate([
        { label: 'Открыть',              click: () => restoreKiosk() },
        { label: 'Синхронизировать',     click: () => sync.runSync() },
        { label: 'Проверить обновления', click: () => updater.checkNow() },
        { label: 'Настройки',            click: () => openSettings() },
        { type: 'separator' },
        { label: 'Выход',                click: () => { tray.destroy(); tray = null; app.quit(); } },
    ]));
}

function openSettings() {
    if (setupWindow) { setupWindow.focus(); return; }
    createSetupWindow();
}

// ── IPC handlers ──────────────────────────────────────────

ipcMain.handle('sync-now',    async () => { await sync.runSync(); return sync.getStatus(); });
ipcMain.handle('sync-status', ()      => sync.getStatus());
ipcMain.handle('open-settings', ()    => { openSettings(); });

ipcMain.handle('update-status',    ()      => updater.getStatus());
ipcMain.handle('update-check-now', async () => { await updater.checkNow(); return updater.getStatus(); });
ipcMain.handle('update-install-now', ()    => { updater.installNow(); });

// Скрытый жест (10 кликов по блоку типа питания) из renderer — см.
// public/assets/app.js. Единственный штатный способ свернуть киоск на
// рабочий стол.
ipcMain.handle('kiosk-unlock', () => { unlockAndMinimize(); return { ok: true }; });

// Setup window handlers
ipcMain.handle('setup-save', async (_, { token, serverUrl }) => {
    try {
        const vars = { OFFLINE_SYNC_TOKEN: token };
        if (serverUrl && serverUrl !== 'https://www.severfoods.ru') {
            vars.SERVER_URL = serverUrl;
        } else {
            vars.SERVER_URL = serverUrl || 'https://www.severfoods.ru';
        }
        writeEnvFile(vars);
        sync.reloadConfig();
        return { ok: true };
    } catch (e) {
        return { ok: false, error: e.message };
    }
});

ipcMain.handle('setup-finish', async () => {
    if (setupWindow) { setupWindow.close(); setupWindow = null; }
    if (!mainWindow) {
        await db.init();
        await server.start(PORT);
        sync.init();
        updater.init();
        tailscale.autoJoinFromEnv().catch(() => {}); // тихо, не блокирует запуск
        createWindow();
    } else {
        restoreKiosk();
        sync.runSync();
    }
});

ipcMain.handle('setup-get-current', () => ({
    token:     process.env.OFFLINE_SYNC_TOKEN || '',
    serverUrl: process.env.SERVER_URL || 'https://www.severfoods.ru',
}));

// ── App startup ───────────────────────────────────────────

// Автозапуск при включении/перезагрузке компьютера — точка питания должна
// поднимать приложение сама, без участия оператора.
if (process.platform === 'win32') {
    try {
        app.setLoginItemSettings({
            openAtLogin: true,
            path: process.execPath,
            args: [],
        });
    } catch (e) { /* не критично, если не удалось (например, в dev-режиме) */ }
}

app.whenReady().then(async () => {
    if (needsSetup()) {
        // First launch or missing token — show setup before main app
        createSetupWindow();
    } else {
        await db.init();
        await server.start(PORT);
        sync.init();
        updater.init();
        tailscale.autoJoinFromEnv().catch(() => {}); // тихо, не блокирует запуск
        createWindow();
        // Трей НЕ создаём здесь намеренно — пока приложение заблокировано
        // (kioskLocked), тея-иконки с пунктом "Выход" быть не должно, иначе
        // это был бы обход блокировки в обход скрытого жеста. Трей появляется
        // только после unlockAndMinimize().
    }
});

app.on('window-all-closed', () => {});
app.on('activate', () => { if (!mainWindow && !setupWindow) createWindow(); });
