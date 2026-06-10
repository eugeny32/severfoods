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
const db     = require('./src/db');
const server = require('./src/server');
const sync   = require('./src/sync');

const PORT = 3847;

let mainWindow  = null;
let setupWindow = null;
let tray        = null;

Menu.setApplicationMenu(null);

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
    mainWindow = new BrowserWindow({
        width:  1180,
        height: 720,
        minWidth:  900,
        minHeight: 600,
        icon: path.join(__dirname, 'public/assets/img/icon.ico'),
        title: 'СеверФудс',
        show: false,
        backgroundColor: '#17212b',
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            webviewTag: true,
            preload: path.join(__dirname, 'src/preload.js'),
        },
    });

    mainWindow.loadURL(`http://localhost:${PORT}/`);
    mainWindow.once('ready-to-show', () => { mainWindow.show(); });
    mainWindow.on('close', (e) => { if (tray) { e.preventDefault(); mainWindow.hide(); } });
    mainWindow.on('closed', () => { mainWindow = null; });
}

function createTray() {
    const imgPath = path.join(__dirname, 'public/assets/tray.png');
    const img = fs.existsSync(imgPath)
        ? nativeImage.createFromPath(imgPath)
        : nativeImage.createEmpty();
    tray = new Tray(img);
    tray.setToolTip('SeverFoods Offline');
    tray.on('click', () => { if (mainWindow) { mainWindow.show(); mainWindow.focus(); } });
    tray.setContextMenu(Menu.buildFromTemplate([
        { label: 'Открыть',          click: () => { if (mainWindow) { mainWindow.show(); mainWindow.focus(); } } },
        { label: 'Синхронизировать', click: () => sync.runSync() },
        { label: 'Настройки',        click: () => openSettings() },
        { type: 'separator' },
        { label: 'Выход',            click: () => { tray = null; app.quit(); } },
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
        createWindow();
        createTray();
    } else {
        mainWindow.show();
        mainWindow.focus();
        sync.runSync();
    }
});

ipcMain.handle('setup-get-current', () => ({
    token:     process.env.OFFLINE_SYNC_TOKEN || '',
    serverUrl: process.env.SERVER_URL || 'https://www.severfoods.ru',
}));

// ── App startup ───────────────────────────────────────────

app.whenReady().then(async () => {
    if (needsSetup()) {
        // First launch or missing token — show setup before main app
        createSetupWindow();
    } else {
        await db.init();
        await server.start(PORT);
        sync.init();
        createWindow();
        createTray();
    }
});

app.on('window-all-closed', () => {});
app.on('activate', () => { if (!mainWindow && !setupWindow) createWindow(); });
