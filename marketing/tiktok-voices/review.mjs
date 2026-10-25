// Stills of the ad at the given times (seconds), for review: node review.mjs out/review 1.2 4.5 ...
import { bundle } from '@remotion/bundler';
import { renderStill, selectComposition } from '@remotion/renderer';
import path from 'node:path';
import fs from 'node:fs';

const [dir, ...times] = process.argv.slice(2);
fs.mkdirSync(dir, { recursive: true });
const serveUrl = await bundle({ entryPoint: path.resolve('src/index.ts'), rspack: true });
const browserExecutable = fs.readdirSync('/opt/pw-browsers').filter((d) => d.startsWith('chromium_headless_shell'))
  .map((d) => path.join('/opt/pw-browsers', d, fs.readdirSync(path.join('/opt/pw-browsers', d)).find((x) => x.startsWith('chrome-')), 'headless_shell'))[0];
const opts = { browserExecutable, chromiumOptions: { gl: 'swangle' } };
const composition = await selectComposition({ serveUrl, id: 'Ad', ...opts });
for (const s of times) {
  const frame = Math.min(composition.durationInFrames - 1, Math.round(Number(s) * composition.fps));
  await renderStill({ serveUrl, composition, frame, output: path.join(dir, `t${String(s).padStart(5, '0')}.png`), ...opts });
  process.stdout.write('.');
}
console.log(' done');
