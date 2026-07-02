const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electron', {
    syncNow:    () => ipcRenderer.invoke('sync-now'),
    syncStatus: () => ipcRenderer.invoke('sync-status'),

    updateStatus:    () => ipcRenderer.invoke('update-status'),
    updateCheckNow:  () => ipcRenderer.invoke('update-check-now'),
    updateInstallNow:() => ipcRenderer.invoke('update-install-now'),
});
