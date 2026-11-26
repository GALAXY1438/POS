'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('desktop', {
    retry: () => ipcRenderer.send('splash:retry'),
    openLogs: () => ipcRenderer.send('splash:open-logs'),
    onInfo: (cb) => ipcRenderer.on('splash:info', (event, info) => cb(info))
});
