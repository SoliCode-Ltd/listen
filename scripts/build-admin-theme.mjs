// The admin's stylesheet (resources/css/filament/admin/theme.css), compiled into
// public/css/filament/admin/theme.css, which is committed: the servers need no build.
// Its first line holds a fingerprint of what it was built from (the theme, every file
// in app/Filament and resources/views/filament, and the Filament version), which
// tests/Feature/AdminThemeTest.php checks, so a class added without a rebuild is caught.
//
//   npm install && npm run build:admin-theme
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const input = 'resources/css/filament/admin/theme.css';
const output = 'public/css/filament/admin/theme.css';

const files = (dir) => readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name).split('\\').join('/');
    return entry.isDirectory() ? files(path) : [path];
});

export function sourcesFingerprint() {
    const paths = [input, ...files('app/Filament'), ...files('resources/views/filament')].sort();
    const lock = JSON.parse(readFileSync('composer.lock', 'utf8'));
    const filament = [...lock.packages, ...(lock['packages-dev'] ?? [])].find((p) => p.name === 'filament/filament')?.version ?? '';
    let text = `filament:${filament}\n`;
    for (const path of paths) {
        text += `${path}\n${createHash('sha256').update(readFileSync(path)).digest('hex')}\n`;
    }
    return createHash('sha256').update(text).digest('hex');
}

execFileSync('node_modules/.bin/tailwindcss', ['--input', input, '--output', output, '--minify'], { stdio: 'inherit' });
writeFileSync(output, `/* listen-admin-theme sources:${sourcesFingerprint()} */\n` + readFileSync(output, 'utf8'));
console.log(`Built ${output}`);
