'use strict';
const { app, BrowserWindow, Menu, dialog } = require('electron');
const path = require('node:path');
const fs = require('node:fs');
const { isJournalURL, allowsSessionPermission } = require('./policy.cjs');
const home = 'https://blog/video-journal';
const smoke = process.argv.includes('--smoke');
if (smoke) app.setPath('userData', path.join(app.getPath('temp'), 'frame-journal-smoke'));
const smokeOutput = path.join(app.getPath('userData'), 'smoke-result.json');
let mainWindow;
// The journal service must remain local even if the computer's DNS changes.
app.commandLine.appendSwitch('host-resolver-rules', 'MAP blog 127.0.0.1');
app.setAppUserModelId('local.frame.videojournal');
function finishSmoke(result) {
    fs.mkdirSync(path.dirname(smokeOutput), { recursive: true });
    fs.writeFileSync(smokeOutput, JSON.stringify(result));
    app.exit(result.ok ? 0 : 1);
}
async function loadJournal() {
    try {
        await mainWindow.loadURL(smoke ? home + '?q=__desktop_smoke_no_records__' : home);
        if (smoke) {
            const result = await mainWindow.webContents.executeJavaScript(`({
                bridge: window.videoJournalDesktop?.version,
                nodeExposed: typeof window.require !== 'undefined',
                journal: !!document.querySelector('#video-dropzone')
            })`);
            const fullscreen = await mainWindow.webContents.executeJavaScript(`(async () => {
                const video = document.createElement('video');
                document.body.append(video);
                try {
                    await video.requestFullscreen();
                    const entered = document.fullscreenElement === video;
                    await document.exitFullscreen();
                    return { entered, exited: document.fullscreenElement === null };
                } finally { video.remove(); }
            })()`, true);
            finishSmoke({ok: result.bridge === '1.0.0' && !result.nodeExposed && result.journal && fullscreen.entered && fullscreen.exited, ...result, fullscreen});
        }
    } catch (error) {
        if (smoke) return finishSmoke({ok: false, error: error.code || 'LOAD_FAILED'});
        const choice = await dialog.showMessageBox(mainWindow, {
            type: 'error', title: '美少女映像管暫時無法連線',
            message: '無法開啟美少女映像管',
            detail: '請確認本機 https://blog/video-journal 能正常開啟，再按重試。桌面版使用相同的本機網站與資料庫。',
            buttons: ['重試', '關閉'], defaultId: 0, cancelId: 1
        });
        if (choice.response === 0) return loadJournal();
        app.quit();
    }
}
if (!app.requestSingleInstanceLock()) app.quit();
else {
    app.on('second-instance', () => {
        if (mainWindow) { if (mainWindow.isMinimized()) mainWindow.restore(); mainWindow.show(); mainWindow.focus(); }
    });
    app.whenReady().then(() => {
        mainWindow = new BrowserWindow({
            width: 1440, height: 960, minWidth: 720, minHeight: 600,
            icon: path.join(__dirname, 'assets/icon.png'), show: !smoke, title: '美少女映像管', backgroundColor: '#faf2f7',
            webPreferences: {
                preload: path.join(__dirname, 'preload.cjs'),
                contextIsolation: true, nodeIntegration: false, sandbox: true,
                webviewTag: false, partition: 'persist:video-journal'
            }
        });
        const contents = mainWindow.webContents;
        contents.session.setPermissionRequestHandler((webContents, permission, callback, details) => {
            callback(allowsSessionPermission(permission, webContents?.getURL(), details?.requestingUrl, details?.isMainFrame));
        });
        contents.session.setPermissionCheckHandler((webContents, permission, requestingOrigin, details) => {
            return allowsSessionPermission(permission, webContents?.getURL(), details?.requestingUrl || requestingOrigin, details?.isMainFrame);
        });
        contents.setWindowOpenHandler(() => ({action: 'deny'}));
        for (const eventName of ['will-navigate', 'will-redirect']) {
            contents.on(eventName, (event, url) => { if (!isJournalURL(url)) event.preventDefault(); });
        }
        contents.on('will-attach-webview', event => event.preventDefault());
        Menu.setApplicationMenu(Menu.buildFromTemplate([
            {label: '映像管', submenu: [
                {label: '返回映像庫', click: () => loadJournal()},
                {label: '重新整理', role: 'reload'}, {type: 'separator'}, {label: '結束', role: 'quit'}
            ]},
            {label: '編輯', submenu: [{role: 'undo'}, {role: 'redo'}, {type: 'separator'}, {role: 'cut'}, {role: 'copy'}, {role: 'paste'}, {role: 'selectAll'}]},
            {label: '顯示', submenu: [{role: 'resetZoom'}, {role: 'zoomIn'}, {role: 'zoomOut'}, {role: 'togglefullscreen'}]}
        ]));
        if (smoke) setTimeout(() => finishSmoke({ok: false, error: 'TIMEOUT'}), 30000).unref();
        // Drop stale Chromium HTTP/CSS/JS cache so blog web fixes (e.g. pure-frame screenshot) reach this shell.
        contents.session.clearCache().finally(() => loadJournal());
    });
    app.on('window-all-closed', () => app.quit());
}
