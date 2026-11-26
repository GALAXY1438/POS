'use strict';

// Copies the portable POS (runtime, www, setup, batch launchers) into
// Desktop/pos-app so electron-builder can ship it inside the installer.
// Runtime state (database, logs, backups, setup marker) is never staged.

const fs = require('fs');
const path = require('path');

const DESKTOP_DIR = path.resolve(__dirname, '..');
const SOURCE = process.env.POS_SOURCE
    ? path.resolve(process.env.POS_SOURCE)
    : path.resolve(DESKTOP_DIR, '..', 'PointShiftPOS');
const TARGET = path.resolve(DESKTOP_DIR, 'pos-app');

const SKIP_DIRS = new Set(['data', 'logs', 'backups', 'node_modules', '.git']);
const SKIP_FILES = new Set([
    '.db_ready',
    'TechShift-POS-Setup.exe',
    'Uninstall TechShift POS.exe'
]);

function fail(message) {
    console.error('\n[stage] ERROR: ' + message);
    process.exit(1);
}

if (!fs.existsSync(path.join(SOURCE, 'Start POS.bat'))) {
    fail('POS source not found at ' + SOURCE +
        '\nSet POS_SOURCE to the portable POS folder, e.g. POS_SOURCE="D:\\wajiku stores\\PointShiftPOS".');
}
if (!fs.existsSync(path.join(SOURCE, 'runtime', 'php', 'php.exe'))) {
    fail('runtime\\php\\php.exe missing in ' + SOURCE + ' — is this the portable build?');
}
if (!fs.existsSync(path.join(SOURCE, 'runtime', 'pgsql', 'bin', 'pg_ctl.exe'))) {
    fail('runtime\\pgsql missing in ' + SOURCE + ' — is this the portable build?');
}

console.log('[stage] Source: ' + SOURCE);
console.log('[stage] Target: ' + TARGET);

fs.rmSync(TARGET, { recursive: true, force: true });
fs.mkdirSync(TARGET, { recursive: true });

function shouldSkip(src) {
    const rel = path.relative(SOURCE, src);
    if (!rel) return false;
    const parts = rel.split(path.sep);
    if (parts.some((p) => SKIP_DIRS.has(p))) return true;
    const base = path.basename(src);
    if (SKIP_FILES.has(base) && fs.statSync(src).isFile()) return true;
    return false;
}

fs.cpSync(SOURCE, TARGET, {
    recursive: true,
    filter: (src) => {
        if (shouldSkip(src)) {
            console.log('[stage] skip ' + path.relative(SOURCE, src));
            return false;
        }
        return true;
    }
});

function dirSize(dir) {
    let total = 0;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) total += dirSize(full);
        else total += fs.statSync(full).size;
    }
    return total;
}

const mb = (dirSize(TARGET) / (1024 * 1024)).toFixed(1);
console.log('[stage] Done — ' + mb + ' MB staged into pos-app');

require('child_process').execFileSync(process.execPath, [path.join(__dirname, 'gen-eula-nsh.js')], {
    stdio: 'inherit',
    env: Object.assign({}, process.env, { POS_EULA_FILE: path.join(TARGET, 'www', 'pos', 'app', 'legal', 'eula.txt') })
});
