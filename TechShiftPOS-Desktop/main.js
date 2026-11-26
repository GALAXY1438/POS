'use strict';

const { app, BrowserWindow, Menu, dialog, shell, ipcMain } = require('electron');
const { autoUpdater } = require('electron-updater');
const path = require('path');
const fs = require('fs');
const http = require('http');
const { spawn, spawnSync } = require('child_process');

const APP_PORT = 8787;
const APP_URL = `http://localhost:${APP_PORT}/pos/`;
const isDev = !app.isPackaged;
const bootStartedAt = Date.now();

let mainWindow = null;
let splashWindow = null;
let stackStarted = false;
let shuttingDown = false;
let bootRunning = false;

// ── Paths ────────────────────────────────────────────────────────────────────
function posDir() {
    return isDev
        ? path.resolve(__dirname, '..', 'PointShiftPOS')
        : path.join(process.resourcesPath, 'pos');
}

// Database, photos, logs and backups live here so app updates never touch them.
function dataRoot() {
    return path.join(app.getPath('userData'), 'pos-data');
}

function logLine() {
    let line = new Date().toISOString() + ' ' + Array.prototype.map.call(arguments, String).join(' ') + '\n';
    if (line.length > 4000) line = line.slice(0, 4000) + ' …\n';
    try {
        const dir = path.join(app.getPath('userData'), 'logs');
        fs.mkdirSync(dir, { recursive: true });
        fs.appendFileSync(path.join(dir, 'desktop.log'), line);
    } catch (e) { /* logging must never stop the app */ }
    process.stdout.write(line);
}

// ── Server lifecycle ─────────────────────────────────────────────────────────
function startStack() {
    if (stackStarted) return;
    stackStarted = true;

    const dir = posDir();
    const bat = path.join(dir, 'Start POS.bat');
    if (!fs.existsSync(bat)) {
        fatal('TechShift POS files are missing', 'Could not find:\n' + bat);
        return;
    }

    const env = Object.assign({}, process.env, { TS_NO_BROWSER: '1' });
    try {
        const root = dataRoot();
        fs.mkdirSync(root, { recursive: true });
        env.TECHSHIFT_DATA_ROOT = root;
    } catch (e) {
        logLine('Could not create data folder:', e.message);
    }

    logLine('Starting POS stack from', dir);
    const child = spawn('cmd.exe', ['/c', bat], {
        cwd: dir,
        env: env,
        windowsHide: true,
        stdio: 'ignore'
    });
    child.on('exit', (code) => logLine('Start script exited with code', code));
    child.on('error', (err) => logLine('Start script error:', err.message));
}

function pingServer() {
    return new Promise((resolve) => {
        const req = http.get(APP_URL + 'login.php', { timeout: 2500 }, (res) => {
            res.resume();
            resolve(true);
        });
        req.on('timeout', () => { req.destroy(); resolve(false); });
        req.on('error', () => resolve(false));
    });
}

async function waitForServer(maxMs) {
    const deadline = Date.now() + (maxMs || 240000);
    while (Date.now() < deadline) {
        if (await pingServer()) return true;
        await new Promise((r) => setTimeout(r, 1500));
    }
    return false;
}

// ── Splash ───────────────────────────────────────────────────────────────────
function splashCall(code) {
    if (splashWindow && !splashWindow.isDestroyed()) {
        splashWindow.webContents.executeJavaScript(code).catch(() => {});
    }
}

function splashStage(text, pct) {
    splashCall('window.splash && window.splash.stage(' +
        JSON.stringify(text) + ',' + (typeof pct === 'number' ? pct : 'null') + ')');
}

function splashFail(reason) {
    splashCall('window.splash && window.splash.fail(' + JSON.stringify(reason) + ')');
}

function createSplash() {
    splashWindow = new BrowserWindow({
        width: 520,
        height: 340,
        frame: false,
        resizable: false,
        movable: true,
        transparent: true,
        backgroundColor: '#00000000',
        show: false,
        skipTaskbar: true,
        alwaysOnTop: true,
        icon: path.join(__dirname, 'build', 'icon.png'),
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true
        }
    });
    splashWindow.setAlwaysOnTop(true, 'floating');
    splashWindow.loadFile(path.join(__dirname, 'splash.html'));
    splashWindow.once('ready-to-show', () => {
        splashWindow.show();
        splashWindow.focus();
    });
    splashWindow.webContents.once('did-finish-load', () => {
        splashCall('window.splash && window.splash.version(' +
            JSON.stringify(app.getVersion()) + ',' + JSON.stringify(String(new Date().getFullYear())) + ')');
    });
    splashWindow.on('closed', () => { splashWindow = null; });
}

function fadeOutSplash() {
    if (!splashWindow || splashWindow.isDestroyed()) return;
    let opacity = 1;
    const timer = setInterval(() => {
        if (!splashWindow || splashWindow.isDestroyed()) { clearInterval(timer); return; }
        opacity -= 0.1;
        if (opacity <= 0) {
            clearInterval(timer);
            splashWindow.close();
            splashWindow = null;
            return;
        }
        splashWindow.setOpacity(opacity);
    }, 20);
}

function fadeInWindow(win) {
    if (!win || win.isDestroyed()) return;
    win.setOpacity(0);
    win.show();
    win.focus();
    let opacity = 0;
    const timer = setInterval(() => {
        if (win.isDestroyed()) { clearInterval(timer); return; }
        opacity += 0.1;
        if (opacity >= 1) { win.setOpacity(1); clearInterval(timer); return; }
        win.setOpacity(opacity);
    }, 20);
}

// ── Windows ──────────────────────────────────────────────────────────────────
function createMainWindow() {
    if (mainWindow) return;
    mainWindow = new BrowserWindow({
        width: 1440,
        height: 920,
        minWidth: 1024,
        minHeight: 640,
        show: false,
        title: 'TechShift POS',
        autoHideMenuBar: true,
        backgroundColor: '#F5F5F5',
        icon: path.join(__dirname, 'build', 'icon.png'),
        webPreferences: {
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
            spellcheck: false
        }
    });

    mainWindow.loadURL(APP_URL);

    mainWindow.once('ready-to-show', async () => {
        splashStage('Ready', 100);
        const minWait = 1200 - (Date.now() - bootStartedAt);
        if (minWait > 0) await new Promise((r) => setTimeout(r, minWait));
        fadeOutSplash();
        fadeInWindow(mainWindow);
        bootRunning = false;
        initUpdater();
    });

    mainWindow.on('closed', () => { mainWindow = null; });

    mainWindow.on('page-title-updated', (event) => {
        event.preventDefault();
        mainWindow.setTitle('TechShift POS');
    });

    // Same-origin windows are printed pages; external links open in the browser.
    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        if (url.startsWith(`http://localhost:${APP_PORT}/`)) {
            return {
                action: 'allow',
                overrideBrowserWindowOptions: {
                    width: 900,
                    height: 800,
                    autoHideMenuBar: true,
                    webPreferences: { contextIsolation: true, nodeIntegration: false, sandbox: true }
                }
            };
        }
        if (/^https?:/i.test(url)) shell.openExternal(url);
        return { action: 'deny' };
    });

    mainWindow.webContents.on('will-navigate', (event, url) => {
        if (!url.startsWith(`http://localhost:${APP_PORT}/`)) {
            event.preventDefault();
            if (/^https?:/i.test(url)) shell.openExternal(url);
        }
    });

    mainWindow.webContents.on('render-process-gone', (event, details) => {
        logLine('Renderer stopped:', details && details.reason);
    });
}

function buildMenu() {
    const template = [
        {
            label: 'File',
            submenu: [
                { label: 'Open in Browser', click: () => shell.openExternal(APP_URL) },
                { type: 'separator' },
                { role: 'quit' }
            ]
        },
        {
            label: 'View',
            submenu: [
                { role: 'reload' },
                { role: 'togglefullscreen' },
                { type: 'separator' },
                { role: 'zoomIn' },
                { role: 'zoomOut' },
                { role: 'resetZoom' }
            ]
        },
        {
            label: 'Help',
            submenu: [
                {
                    label: 'Check for Updates…',
                    click: () => checkForUpdates(true)
                },
                { type: 'separator' },
                {
                    label: 'About TechShift POS',
                    click: () => dialog.showMessageBox(mainWindow, {
                        type: 'info',
                        title: 'About TechShift POS',
                        message: 'TechShift POS',
                        detail: 'Version ' + app.getVersion() + '\nDesktop edition',
                        buttons: ['Close']
                    })
                }
            ]
        }
    ];
    if (isDev) {
        template[1].submenu.push({ role: 'toggleDevTools' });
    }
    Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

function fatal(title, detail) {
    logLine('FATAL:', title, detail);
    try {
        dialog.showErrorBox(title, detail +
            '\n\nLog file:\n' + path.join(app.getPath('userData'), 'logs', 'desktop.log'));
    } catch (e) { /* ignore */ }
    app.quit();
}

function logFilePath() {
    return path.join(app.getPath('userData'), 'logs', 'desktop.log');
}

function openLogs() {
    try {
        const file = logFilePath();
        if (fs.existsSync(file)) shell.showItemInFolder(file);
        else shell.openPath(path.dirname(file));
    } catch (e) {
        logLine('Could not open logs:', e.message);
    }
}

// ── Auto update ──────────────────────────────────────────────────────────────
let updaterReady = false;
let manualCheck = false;

function checkForUpdates(manual) {
    if (isDev) {
        dialog.showMessageBox(mainWindow, {
            type: 'info',
            title: 'Check for Updates',
            message: 'Development build',
            detail: 'Automatic updates are only available in the installed application.'
        });
        return;
    }
    if (!updaterReady) initUpdater();
    manualCheck = !!manual;
    autoUpdater.checkForUpdates().catch((err) => {
        logLine('Update check error:', err.message);
        if (manualCheck) {
            manualCheck = false;
            dialog.showMessageBox(mainWindow, {
                type: 'warning',
                title: 'Check for Updates',
                message: 'Could not check for updates.',
                detail: 'Make sure this computer is connected to the internet.\n\n' + String(err.message).slice(0, 500)
            });
        }
    });
}

function initUpdater() {
    if (isDev || updaterReady) return;
    updaterReady = true;

    autoUpdater.autoDownload = false;
    autoUpdater.autoInstallOnAppQuit = true;
    autoUpdater.on('error', (err) => {
        logLine('Update check failed:', err.message);
        if (manualCheck) {
            manualCheck = false;
            dialog.showMessageBox(mainWindow, {
                type: 'warning',
                title: 'Check for Updates',
                message: 'Could not check for updates.',
                detail: 'Make sure this computer is connected to the internet.\n\n' + String(err.message).slice(0, 500)
            });
        }
    });

    autoUpdater.on('update-not-available', (info) => {
        logLine('No update available (latest', info.version + ')');
        if (manualCheck) {
            manualCheck = false;
            dialog.showMessageBox(mainWindow, {
                type: 'info',
                title: 'Check for Updates',
                message: 'TechShift POS is up to date.',
                detail: 'Version ' + app.getVersion() + ' is the latest release.',
                buttons: ['Close']
            });
        }
    });

    autoUpdater.on('update-available', (info) => {
        manualCheck = false;
        logLine('Update available:', info.version);
        dialog.showMessageBox(mainWindow, {
            type: 'info',
            title: 'Update available',
            message: `TechShift POS ${info.version} is available (you have ${app.getVersion()}).`,
            detail: 'Download it now? Your sales data is kept and the app restarts after installing.',
            buttons: ['Download now', 'Later'],
            defaultId: 0,
            cancelId: 1
        }).then((r) => {
            if (r.response === 0) autoUpdater.downloadUpdate();
        });
    });

    autoUpdater.on('update-downloaded', (info) => {
        logLine('Update downloaded:', info.version);
        dialog.showMessageBox(mainWindow, {
            type: 'info',
            title: 'Update ready',
            message: `TechShift POS ${info.version} is ready to install.`,
            detail: 'The app will close and reopen. Your data is not affected.',
            buttons: ['Restart now', 'Later'],
            defaultId: 0,
            cancelId: 1
        }).then((r) => {
            if (r.response === 0) autoUpdater.quitAndInstall(false, true);
        });
    });

    setTimeout(() => checkForUpdates(false), 8000);
}

// ── Boot sequence ────────────────────────────────────────────────────────────
async function runBoot() {
    if (bootRunning || shuttingDown) return;
    bootRunning = true;

    startStack();
    splashStage('Starting database', null);

    const slowTimer = setTimeout(() => splashStage('Taking longer than usual…', null), 15000);
    const serverTimer = setTimeout(() => splashStage('Starting server', null), 3500);

    const ready = await waitForServer(240000);
    clearTimeout(slowTimer);
    clearTimeout(serverTimer);

    if (!ready) {
        logLine('POS stack did not respond; showing splash error');
        splashFail('The database or web server did not start. Check that ports 8787 and 55433 are free.');
        bootRunning = false;
        return;
    }

    splashStage('Loading your store', 70);
    createMainWindow();
}

ipcMain.on('splash:open-logs', () => openLogs());
ipcMain.on('splash:retry', () => {
    if (bootRunning) return;
    logLine('Retry requested from splash');
    stackStarted = false;
    runBoot();
});

// ── App lifecycle ────────────────────────────────────────────────────────────
if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', () => {
        if (mainWindow) {
            if (mainWindow.isMinimized()) mainWindow.restore();
            mainWindow.focus();
        }
    });

    app.whenReady().then(async () => {
        buildMenu();
        createSplash();
        await runBoot();
    });

    app.on('window-all-closed', () => {
        app.quit();
    });

    app.on('before-quit', (event) => {
        if (shuttingDown) return;
        shuttingDown = true;
        if (!isDev) {
            event.preventDefault();
            const dir = posDir();
            const bat = path.join(dir, 'Stop POS.bat');
            if (fs.existsSync(bat)) {
                logLine('Stopping POS stack');
                const env = Object.assign({}, process.env, { TECHSHIFT_DATA_ROOT: dataRoot() });
                try {
                    spawnSync('cmd.exe', ['/c', bat], {
                        cwd: dir,
                        env: env,
                        windowsHide: true,
                        timeout: 12000
                    });
                } catch (e) {
                    logLine('Stop script error:', e.message);
                }
            }
            app.quit();
        }
    });
}
