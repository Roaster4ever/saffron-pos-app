// Bukhari POS — Electron Preload Script
// Provides secure bridge between Electron and the PHP application.

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('bukhariPOS', {
  appInfo: {
    name: 'Bukhari POS',
    version: '1.0.0',
    platform: process.platform,
    isDesktop: true
  },
  getAppInfo: () => ipcRenderer.invoke('get-app-info')
});
