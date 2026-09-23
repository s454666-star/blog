'use strict';
const { contextBridge, webUtils } = require('electron');
// Only the local journal's top-level document receives this narrow capability.
if (window.top === window && location.origin === 'https://blog' &&
    (location.pathname === '/video-journal' || location.pathname.startsWith('/video-journal/'))) {
    contextBridge.exposeInMainWorld('videoJournalDesktop', {
        version: '1.0.0',
        pathForFile(file) {
            if (!(file instanceof File) || !/\.(mp4|webm|ogv|mov|m4v)$/i.test(file.name)) return '';
            return webUtils.getPathForFile(file);
        }
    });
}
