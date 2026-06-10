const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronSetup', {
    saveConfig:  (cfg) => ipcRenderer.invoke('setup-save', cfg),
    finishSetup: ()    => ipcRenderer.invoke('setup-finish'),
    getCurrent:  ()    => ipcRenderer.invoke('setup-get-current'),
});
