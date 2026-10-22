// Captures a prompts film page frame by frame and encodes it with its soundtrack.
//   node capture.cjs <build dir> <out.mp4> [--workers 3] [--from s --to s]
//   node capture.cjs <build dir> <out.mp4> --frames 0,30,90     (PNG stills for review)
// Workers render separate frame ranges into near-lossless segments; the
// segments are joined and encoded once with the soundtrack.
const { chromium } = require(process.env.PLAYWRIGHT_PATH || '/opt/node22/lib/node_modules/playwright');
const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');

const run = (cmd, args) => new Promise((resolve, reject) => {
  const p = spawn(cmd, args, { stdio: ['ignore', 'inherit', 'inherit'] });
  p.on('close', code => (code === 0 ? resolve() : reject(new Error(`${cmd} exited ${code}`))));
});

(async () => {
  const [dir, out] = process.argv.slice(2);
  const arg = name => { const i = process.argv.indexOf(name); return i > 0 ? process.argv[i + 1] : null; };
  const html = 'file://' + path.resolve(dir, 'film.html');
  const timeline = fs.readFileSync(path.resolve(dir, 'timeline.js'), 'utf8');
  const T = JSON.parse(timeline.replace(/^window\.TIMELINE = /, '').replace(/;\s*$/, ''));
  const [W, H] = [T.W, T.H];

  const browser = await chromium.launch({ args: ['--force-color-profile=srgb', '--font-render-hinting=none', '--ignore-gpu-blocklist', '--enable-unsafe-swiftshader'] });
  const open = async () => {
    const page = await browser.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 1 });
    page.on('pageerror', e => console.error('page error:', e.message));
    await page.goto(html, { waitUntil: 'load' });
    await page.evaluate(() => window.ready);
    const cdp = await page.context().newCDPSession(page);
    return { page, cdp };
  };

  const stills = arg('--frames');
  if (stills) {
    const { page } = await open();
    for (const f of stills.split(',').map(Number)) {
      await page.evaluate(t => window.seek(t), f / T.fps);
      await page.screenshot({ path: out.replace(/\.mp4$/, '') + `-f${String(f).padStart(4, '0')}.png` });
    }
    await browser.close();
    return;
  }

  const from = Number(arg('--from') || 0); const to = Number(arg('--to') || T.duration);
  const first = Math.round(from * T.fps); const last = Math.round(to * T.fps);
  const workers = Number(arg('--workers') || 3);
  const per = Math.ceil((last - first + 1) / workers);
  const tmp = fs.mkdtempSync(path.join(path.dirname(path.resolve(out)), '.segments-'));
  const started = Date.now();
  let done = 0;

  await Promise.all(Array.from({ length: workers }, async (_, w) => {
    const a = first + w * per; const b = Math.min(last, a + per - 1);
    if (a > b) return;
    const { cdp, page } = await open();
    const seg = path.join(tmp, `seg${w}.mp4`);
    const ff = spawn('ffmpeg', ['-v', 'error', '-y', '-f', 'image2pipe', '-framerate', String(T.fps), '-c:v', 'mjpeg', '-i', '-',
      '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '8', '-pix_fmt', 'yuv444p', seg], { stdio: ['pipe', 'inherit', 'inherit'] });
    for (let f = a; f <= b; f++) {
      await page.evaluate(t => window.seek(t), f / T.fps);
      const shot = await cdp.send('Page.captureScreenshot', { format: 'jpeg', quality: 98 });
      if (!ff.stdin.write(Buffer.from(shot.data, 'base64'))) await new Promise(r => ff.stdin.once('drain', r));
      if (++done % 150 === 0) console.log(`${done}/${last - first + 1} frames (${((Date.now() - started) / 1000).toFixed(0)}s)`);
    }
    ff.stdin.end();
    await new Promise(r => ff.on('close', r));
  }));
  await browser.close();

  const list = path.join(tmp, 'list.txt');
  fs.writeFileSync(list, Array.from({ length: workers }, (_, w) => `file 'seg${w}.mp4'`).filter((_, w) => fs.existsSync(path.join(tmp, `seg${w}.mp4`))).join('\n'));
  await run('ffmpeg', ['-v', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', list,
    '-ss', String(from), '-t', String((last - first + 1) / T.fps), '-i', path.resolve(dir, 'mix.wav'),
    '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-preset', 'slow', '-crf', '17', '-maxrate', '16M', '-bufsize', '32M', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
    '-colorspace', 'bt709', '-color_primaries', 'bt709', '-color_trc', 'bt709', '-movflags', '+faststart',
    '-c:a', 'aac', '-b:a', '256k', '-ar', '48000', '-shortest', out]);
  fs.rmSync(tmp, { recursive: true, force: true });
  console.log(`done ${out} in ${((Date.now() - started) / 1000).toFixed(0)}s`);
})();
