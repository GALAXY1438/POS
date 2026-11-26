'use strict';

// Builds build/eula-lines.nsh from the app's EULA so the installer page and
// the in-app agreement always show the same text.

const fs = require('fs');
const path = require('path');

const DESKTOP_DIR = path.resolve(__dirname, '..');
const SOURCE = process.env.POS_SOURCE
    ? path.resolve(process.env.POS_SOURCE)
    : path.resolve(DESKTOP_DIR, '..', 'PointShiftPOS');
const EULA_FILE = process.env.POS_EULA_FILE
    ? path.resolve(process.env.POS_EULA_FILE)
    : path.join(SOURCE, 'www', 'pos', 'app', 'legal', 'eula.txt');
const OUTPUT = path.join(DESKTOP_DIR, 'build', 'eula-lines.nsh');

if (!fs.existsSync(EULA_FILE)) {
    console.error('[eula] ERROR: EULA not found at ' + EULA_FILE);
    process.exit(1);
}

const text = fs.readFileSync(EULA_FILE, 'utf8').replace(/^\uFEFF/, '');
const lines = text.split(/\r?\n/);

function quote(line) {
    return '"' + line.replace(/\$/g, '$$$$').replace(/"/g, '$\\"') + '"';
}

const out = [
    '; Generated from ' + path.relative(DESKTOP_DIR, EULA_FILE).replace(/\\/g, '/') +
        ' by scripts/gen-eula-nsh.js. Do not edit.',
    '!macro EulaAppendLine text',
    '  SendMessage $EulaEdit ${EM_SETSEL} -1 -1',
    '  SendMessage $EulaEdit ${EM_REPLACESEL} 0 "STR:${text}$\\r$\\n"',
    '!macroend',
    '',
    '!macro EulaAppendBlank',
    '  SendMessage $EulaEdit ${EM_SETSEL} -1 -1',
    '  SendMessage $EulaEdit ${EM_REPLACESEL} 0 "STR:$\\r$\\n"',
    '!macroend',
    '',
    '!macro EulaFill'
];

for (const line of lines) {
    if (line.trim() === '') {
        out.push('  !insertmacro EulaAppendBlank');
    } else {
        out.push('  !insertmacro EulaAppendLine ' + quote(line));
    }
}
out.push('!macroend');
out.push('');

fs.writeFileSync(OUTPUT, out.join('\n'), 'utf8');
console.log('[eula] Wrote build/eula-lines.nsh (' + lines.length + ' lines)');