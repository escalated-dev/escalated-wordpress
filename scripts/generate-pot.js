const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');

function findPhpFiles(dir) {
  let results = [];
  const items = fs.readdirSync(dir);
  for (const item of items) {
    if (['node_modules', '.git', 'vendor', 'scripts'].includes(item)) continue;
    const full = path.join(dir, item);
    const stat = fs.statSync(full);
    if (stat.isDirectory()) results.push(...findPhpFiles(full));
    else if (item.endsWith('.php')) results.push(full);
  }
  return results;
}

const strings = {};
const files = findPhpFiles(rootDir);

for (const filepath of files) {
  const content = fs.readFileSync(filepath, 'utf8');
  const relPath = path.relative(rootDir, filepath).replace(/\\/g, '/');

  // Match single-quoted i18n calls
  const pat1 = /(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'escalated'\s*\)/g;
  let m;
  while ((m = pat1.exec(content)) !== null) {
    const s = m[1];
    if (!strings[s]) strings[s] = [];
    if (!strings[s].includes(relPath)) strings[s].push(relPath);
  }

  // Match double-quoted i18n calls
  const pat2 = /(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*"((?:[^"\\]|\\.)*)"\s*,\s*"escalated"\s*\)/g;
  while ((m = pat2.exec(content)) !== null) {
    const s = m[1];
    if (!strings[s]) strings[s] = [];
    if (!strings[s].includes(relPath)) strings[s].push(relPath);
  }

  // _n patterns
  const nPat = /_n\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'/g;
  while ((m = nPat.exec(content)) !== null) {
    for (const s of [m[1], m[2]]) {
      if (!strings[s]) strings[s] = [];
      if (!strings[s].includes(relPath)) strings[s].push(relPath);
    }
  }
}

console.log('Found ' + Object.keys(strings).length + ' unique strings');

// Write .pot
let pot = `# Copyright (C) 2025 Escalated
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: Escalated 1.0.0\\n"
"Report-Msgid-Bugs-To: https://github.com/escalated-dev/escalated-wordpress/issues\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: 2026-04-05T00:00:00+00:00\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"X-Generator: Escalated\\n"
"X-Domain: escalated\\n"

`;

const sortedKeys = Object.keys(strings).sort();
for (const s of sortedKeys) {
  for (const ref of strings[s]) {
    pot += `#: ${ref}\n`;
  }
  const escaped = s.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  pot += `msgid "${escaped}"\n`;
  pot += `msgstr ""\n`;
  pot += `\n`;
}

fs.writeFileSync(path.join(rootDir, 'languages', 'escalated.pot'), pot);
console.log('POT file written with ' + sortedKeys.length + ' entries');

// Output the sorted keys as JSON for use by translation script
fs.writeFileSync(path.join(rootDir, 'scripts', 'strings.json'), JSON.stringify(sortedKeys, null, 2));
console.log('Strings JSON written');
