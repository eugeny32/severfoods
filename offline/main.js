// Настройки (.env) читаются раньше всех остальных модулей — они смотрят в
// process.env уже при загрузке.
const fs   = require('fs');
const path = require('path');
const { app, BrowserWindow, ipcMain, Tray, Menu, nativeImage, dialog, globalShortcut } = require('electron');

// ГДЕ ЛЕЖАТ НАСТРОЙКИ.
//
// Раньше .env лежал рядом с exe, в каталоге установки. Установщик при
// обновлении сносит этот каталог целиком — и точка теряла токен и адрес
// сервера: вместо рабочего окна открывалось окно первичной настройки, а
// синхронизация не запускалась вовсе. Каталог userData (там же локальная база)
// обновление переживает — deleteAppDataOnUninstall: false, — поэтому настройки
// переехали туда же. Старый путь остаётся только для разовой миграции.
const exeDir      = path.dirname(process.execPath);
const legacyEnv   = path.join(exeDir, '.env');
const settingsDir = app.getPath('userData');
const envPath     = path.join(settingsDir, '.env');

function loadEnvFile(p) {
    if (!p || !fs.existsSync(p)) return false;
    fs.readFileSync(p, 'utf8').split(/\r?\n/).forEach(line => {
        const m = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)\s*$/);
        if (m) process.env[m[1]] = m[2].trim();
    });
    return true;
}

/** Разовый перенос настроек из каталога установки в userData. */
function migrateEnv() {
    try {
        if (fs.existsSync(envPath) || !fs.existsSync(legacyEnv)) return;
        fs.mkdirSync(settingsDir, { recursive: true });
        fs.copyFileSync(legacyEnv, envPath);
        console.log('[env] настройки перенесены в', envPath);
    } catch (e) {
        console.error('[env] перенос настроек не удался:', e.message);
    }
}

migrateEnv();
// Порядок: новое место → старое (если миграция не удалась) → каталог разработки.
if (!loadEnvFile(envPath) && !loadEnvFile(legacyEnv)) loadEnvFile(path.join(__dirname, '.env'));
const db        = require('./src/db');
const server    = require('./src/server');
const sync      = require('./src/sync');
const updater   = require('./src/updater');
const tailscale = require('./src/tailscale');
const osk       = require('./src/osk');

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

// Приложение действительно завершается — блокировки киоска больше не действуют.
//
// Без этого флага автообновление было невозможно в принципе: quitAndInstall()
// вызывает app.quit(), тот шлёт окну 'close', а обработчик ниже отменял его,
// потому что kioskLocked в рабочем режиме всегда true. Процесс продолжал жить,
// установщик видел запущенное приложение и не мог заменить файлы — отсюда
// «не удаётся удалить старую версию автоматически». Ручной запуск установщика
// ломался так же: он тоже просит приложение закрыться штатным образом.
let isQuitting = false;

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
    const target = process.env.NODE_ENV === 'development'
        ? path.join(__dirname, '.env')
        : envPath;
    fs.mkdirSync(path.dirname(target), { recursive: true }); // при первом запуске каталога может не быть
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
    mainWindow.once('ready-to-show', () => { mainWindow.show(); applyTopMost(true); });

    // Пока заблокировано — не даём ни закрыть (Alt+F4), ни свернуть иным
    // способом, кроме скрытого жеста. Единственный "легальный" путь наружу —
    // requestUnlock() через IPC (см. ниже), инициируемый 10 кликами в UI.
    mainWindow.on('close', (e) => {
        if (isQuitting) return; // настоящий выход (обновление, трей, завершение сеанса)
        if (kioskLocked) { e.preventDefault(); return; }
        if (tray) { e.preventDefault(); mainWindow.hide(); }
    });
    // Если фокус вдруг ушёл с окна (например, системный диалог или удачный
    // Alt+Tab) — пока заблокировано, агрессивно возвращаем фокус и киоск.
    //
    // ВАЖНО: экранная клавиатура Windows при появлении сама забирает фокус.
    // Без проверки osk.isOpen() этот обработчик отбирал бы фокус обратно
    // через 50 мс, и клавиатура закрывалась бы сразу после открытия —
    // именно из-за этого на терминалах с сенсором нельзя было набрать текст.
    mainWindow.on('blur', () => {
        if (!kioskLocked || !mainWindow) return;
        if (osk.isOpen()) return; // клавиатура открыта — фокус не отбираем
        setTimeout(() => {
            if (kioskLocked && !osk.isOpen() && mainWindow && !mainWindow.isDestroyed()) {
                mainWindow.setKiosk(true);
                mainWindow.show();
                mainWindow.focus();
            }
        }, 50);
    });
    mainWindow.on('closed', () => { mainWindow = null; });
}

/**
 * "Поверх всех окон". Уровень 'screen-saver' выше, чем у экранной клавиатуры
 * Windows, поэтому пока она открыта — режим снимается совсем, иначе окно
 * закрыло бы клавиатуру собой. Сюда же сведены все места, где раньше
 * вызывался setAlwaysOnTop, чтобы поведение было в одном месте.
 */
function applyTopMost(on) {
    if (!mainWindow || mainWindow.isDestroyed()) return;
    if (on && !osk.isOpen()) {
        mainWindow.setAlwaysOnTop(true, 'screen-saver');
    } else {
        mainWindow.setAlwaysOnTop(false);
    }
}

/** Разблокировка по жесту из UI — сворачивает окно на рабочий стол. */
function unlockAndMinimize() {
    if (!mainWindow) return;
    kioskLocked = false;
    applyTopMost(false);
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
    applyTopMost(true);
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

// Скрытый жест — 10 кликов по блоку типа питания или по логотипу, см.
// public/assets/app.js. Штатный способ свернуть киоск на рабочий стол;
// аварийный, на случай если жест не даётся, — сочетание клавиш ниже.
ipcMain.handle('kiosk-unlock', () => { unlockAndMinimize(); return { ok: true }; });

// Экранная клавиатура по долгому нажатию (~3 с) на том же элементе.
// Пока она открыта, окно снимается с "поверх всех" и обработчик blur не
// отбирает фокус — иначе клавиатура закрылась бы сразу (см. createWindow).
ipcMain.handle('osk-toggle', async () => {
    const wasOpen = osk.isOpen();
    const res = await osk.toggle();
    if (!res.ok) return res;
    if (!wasOpen) {
        applyTopMost(false); // открыли — пропускаем клавиатуру вперёд
    } else {
        applyTopMost(kioskLocked); // закрыли — возвращаем киоск наверх
        if (mainWindow && !mainWindow.isDestroyed()) mainWindow.focus();
    }
    return { ok: true, open: osk.isOpen() };
});

ipcMain.handle('osk-status', () => ({ open: osk.isOpen(), available: osk.isAvailable() }));

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
        registerKioskEscape();
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

// Только один экземпляр. После установки обновления electron-updater сам
// запускает приложение заново, а оно к тому же прописано в автозапуск — без
// этой блокировки на точке могли оказаться два процесса, дерущихся за порт
// 3847 и за файл локальной базы. Запуск вынесен в startApp() и вызывается
// ТОЛЬКО при захваченной блокировке: иначе вторая копия успела бы дойти до
// server.start(), упереться в занятый порт и показать оператору сообщение об
// ошибке вместо того, чтобы тихо уйти.
const gotSingleInstanceLock = app.requestSingleInstanceLock();

if (!gotSingleInstanceLock) {
    app.quit();
} else {
    app.on('second-instance', () => {
        // Вторую копию запускать не даём, но окно первой показываем — иначе
        // оператору покажется, что запуск просто ничего не сделал.
        if (mainWindow && !mainWindow.isDestroyed()) restoreKiosk();
        else if (setupWindow) setupWindow.focus();
    });
    startApp();
}

function startApp() {
  return app.whenReady().then(async () => {
    if (needsSetup()) {
        // First launch or missing token — show setup before main app
        createSetupWindow();
    } else {
        await db.init();
        // Занятый порт означает, что рядом уже работает другая копия. Раньше
        // ошибка отсюда просто «терялась» в необработанном промисе, и
        // приложение поднималось вообще без окна — оператор видел пустой экран.
        try {
            await server.start(PORT);
        } catch (e) {
            dialog.showErrorBox('СеверФудс',
                `Не удалось занять порт ${PORT}: ${e.message}\n\n` +
                'Скорее всего приложение уже запущено. Закройте вторую копию и запустите снова.');
            app.exit(1);
            return;
        }
        sync.init();
        updater.init();
        tailscale.autoJoinFromEnv().catch(() => {}); // тихо, не блокирует запуск
        createWindow();
        registerKioskEscape();
        // Трей НЕ создаём здесь намеренно — пока приложение заблокировано
        // (kioskLocked), тея-иконки с пунктом "Выход" быть не должно, иначе
        // это был бы обход блокировки в обход скрытого жеста. Трей появляется
        // только после unlockAndMinimize().
    }
  });
}

// Сбой в необязательной функции не должен ронять терминал раздачи.
//
// Без этого обработчика любая ошибка в основном процессе выводит на весь экран
// системное окно «A JavaScript error occurred», которое на киоске нечем
// закрыть. Так и случилось с экранной клавиатурой: неудачный запуск TabTip
// приходил асинхронным событием, обработчика не было, и приложение вставало
// колом (см. src/osk.js). Пишем в журнал и продолжаем работу — раздача важнее.
process.on('uncaughtException', (e) => {
    console.error('[fatal] необработанная ошибка:', e && e.stack ? e.stack : e);
});
process.on('unhandledRejection', (e) => {
    console.error('[fatal] необработанный отказ промиса:', e && e.stack ? e.stack : e);
});

// Аварийный выход из киоска, не зависящий ни от жеста, ни от связи.
//
// Штатный путь — 10 кликов; удалённая команда unlock_kiosk требует связи с
// сервером, а именно её и не бывает в аварии. Сочетание клавиш работает
// всегда, пока окно приложения активно.
function registerKioskEscape() {
    try {
        globalShortcut.register('Control+Alt+Shift+K', () => {
            console.log('[kiosk] аварийная разблокировка сочетанием клавиш');
            unlockAndMinimize();
        });
    } catch (e) {
        console.error('[kiosk] не удалось назначить аварийное сочетание:', e.message);
    }
}

// Единая точка настоящего выхода. Срабатывает раньше 'close' у окна при любом
// пути завершения: установка обновления, «Выход» из трея, завершение сеанса
// Windows. Именно здесь снимаются все блокировки киоска — иначе выход был бы
// отменён и установщик не смог бы заменить файлы приложения.
app.on('before-quit', () => {
    isQuitting  = true;
    kioskLocked = false;

    if (mainWindow && !mainWindow.isDestroyed()) {
        applyTopMost(false);          // окно не должно висеть поверх установщика
        try { mainWindow.setKiosk(false); } catch (_) {}
    }
    if (tray) { tray.destroy(); tray = null; } // иначе сработает ветка hide() в 'close'

    try { osk.hide(); } catch (_) {}   // экранная клавиатура не должна пережить приложение
    try { server.stop(); } catch (_) {} // освобождаем порт для новой копии
    try { globalShortcut.unregisterAll(); } catch (_) {} // иначе сочетание останется занятым
});

app.on('window-all-closed', () => {});
app.on('activate', () => { if (!mainWindow && !setupWindow) createWindow(); });
