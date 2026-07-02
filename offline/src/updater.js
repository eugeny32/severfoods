/**
 * Автообновление оффлайн-приложения.
 *
 * Логика: проверяем обновления при наличии интернета (переиспользуем момент
 * успешной синхронизации/пинга), тихо скачиваем в фоне, но НИКОГДА не
 * устанавливаем сразу — установка (перезапуск приложения) откладывается до
 * момента, когда компьютер реально простаивает (нет ввода с клавиатуры/мыши
 * дольше IDLE_THRESHOLD_SEC), чтобы не прервать оператора посреди сканирования.
 */
const { autoUpdater } = require('electron-updater');
const { powerMonitor, BrowserWindow } = require('electron');

const IDLE_THRESHOLD_SEC    = 5 * 60;   // 5 минут бездействия — считаем, что у терминала никого нет
const IDLE_POLL_INTERVAL_MS = 30 * 1000;
const CHECK_INTERVAL_MS     = 60 * 60 * 1000; // раз в час, как обычная синхронизация

autoUpdater.autoDownload         = false; // скачиваем сами, по своему решению
autoUpdater.autoInstallOnAppQuit = false; // устанавливаем только по idle-таймеру, не при обычном закрытии

let _status = {
    checking:   false,
    available:  false,
    downloaded: false,
    version:    null,
    error:      null,
};
let _idleTimer  = null;
let _checkTimer = null;

function notify() {
    try {
        BrowserWindow.getAllWindows().forEach(w => w.webContents.send('update-status', getStatus()));
    } catch (_) {}
}

autoUpdater.on('checking-for-update', () => {
    _status.checking = true;
    notify();
});

autoUpdater.on('update-available', (info) => {
    _status.checking  = false;
    _status.available = true;
    _status.version   = info.version;
    _status.error      = null;
    notify();
    autoUpdater.downloadUpdate().catch((e) => {
        _status.error = e.message;
        notify();
    });
});

autoUpdater.on('update-not-available', () => {
    _status.checking  = false;
    _status.available = false;
    notify();
});

autoUpdater.on('error', (e) => {
    _status.checking = false;
    _status.error    = (e && e.message) || String(e);
    notify();
});

autoUpdater.on('update-downloaded', (info) => {
    _status.downloaded = true;
    _status.version    = info.version;
    notify();
    scheduleIdleInstallCheck();
});

function scheduleIdleInstallCheck() {
    if (_idleTimer) return;
    _idleTimer = setInterval(() => {
        if (!_status.downloaded) return;
        const idleSec = powerMonitor.getSystemIdleTime();
        if (idleSec >= IDLE_THRESHOLD_SEC) {
            clearInterval(_idleTimer);
            _idleTimer = null;
            installNow();
        }
    }, IDLE_POLL_INTERVAL_MS);
}

/** Тихая установка с автоматическим перезапуском приложения. */
function installNow() {
    autoUpdater.quitAndInstall(true, true);
}

function checkNow() {
    return autoUpdater.checkForUpdates().catch((e) => {
        _status.error = e.message;
        notify();
    });
}

function init() {
    checkNow();
    if (_checkTimer) clearInterval(_checkTimer);
    _checkTimer = setInterval(checkNow, CHECK_INTERVAL_MS);
}

function getStatus() {
    return { ..._status, currentVersion: autoUpdater.currentVersion?.version || null };
}

module.exports = { init, checkNow, installNow, getStatus };
