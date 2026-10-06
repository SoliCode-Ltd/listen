// Plays every sample on the STAGING sign-in page with this branch's player
// script and auth.css swapped in, and records, frame by frame, how the
// waveform fill and the word highlight follow the audio. Writes report.json,
// a video per page, and screenshots. Read-only: it only loads public pages.
import { chromium } from 'playwright';
import fs from 'fs';

const base = (process.env.STAGING_URL || '').replace(/\/+$/, '');
const script = fs.readFileSync('resources/views/components/design-new/player-script.blade.php', 'utf8')
  .replace(/^\{\{--.*--\}\}\n/, '');
const css = fs.readFileSync('public/css/auth.css', 'utf8');
fs.mkdirSync('out', { recursive: true });

const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
const report = { base, pages: [] };

for (const [name, path, theme] of [['en-dark', '/login', 'dark'], ['en-light', '/login', 'light'], ['ar-dark', '/ar/login', 'dark']]) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, recordVideo: { dir: 'out/video-' + name, size: { width: 1440, height: 900 } } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  await page.route('**/*', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    if (req.resourceType() === 'document' && url.origin === new URL(base).origin) {
      const res = await route.fetch();
      let html = await res.text();
      const start = html.indexOf('<script>\n(function () {\n  if (window.__ntPlayerBooted)');
      if (start >= 0) {
        const end = html.indexOf('</script>', start) + '</script>'.length;
        html = html.slice(0, start) + script.trim() + html.slice(end);
      } else {
        errors.push('player script block not found in page');
      }
      return route.fulfill({ response: res, body: html, headers: { ...res.headers(), 'content-type': 'text/html; charset=utf-8' } });
    }
    if (url.pathname.endsWith('/css/auth.css')) return route.fulfill({ body: css, contentType: 'text/css' });
    return route.continue();
  });
  const resp = await page.goto(base + path, { waitUntil: 'networkidle' });
  await page.evaluate(t => { document.documentElement.setAttribute('data-theme', t); }, theme);
  const tabs = await page.$$eval('.auth-tab', els => els.map(e => e.textContent.trim()));
  const entry = { name, status: resp && resp.status(), tabs, samples: [], errors };
  await page.waitForTimeout(2500);

  for (let i = 0; i < Math.max(1, tabs.length); i++) {
    if (tabs.length) { await page.locator('.auth-tab').nth(i).click(); await page.waitForTimeout(1500); }
    const meta = await page.evaluate(() => {
      const track = document.querySelector('[data-nt-track]');
      return { src: track.getAttribute('data-src'), text: document.querySelector('[data-nt-try-text]').textContent };
    });
    await page.evaluate(() => {
      window.__log = [];
      const a = window.__ntAudio || null;
      function rec() {
        const words = [...document.querySelectorAll('.auth-player-text .w')];
        const cur = words.findIndex(w => w.classList.contains('is-current'));
        const spoken = words.filter(w => w.classList.contains('is-spoken')).length;
        const wave = parseFloat(getComputedStyle(document.querySelector('.auth-wave')).getPropertyValue('--nt-progress')) || 0;
        const wp = cur >= 0 ? parseFloat(words[cur].style.getPropertyValue('--wp')) || 0 : null;
        window.__log.push({ now: performance.now(), wave, cur, wp, spoken, words: words.length, playing: !!document.querySelector('.auth-player-bar.is-playing') });
        if (window.__log.length < 4000) requestAnimationFrame(rec);
      }
      requestAnimationFrame(rec);
    });
    await page.click('[data-nt-play]');
    // Wait for the end (up to 40 s).
    await page.waitForFunction(() => !document.querySelector('.auth-player-bar.is-playing') && window.__log.some(l => l.wave > 0.98), null, { timeout: 40000 }).catch(() => {});
    await page.waitForTimeout(300);
    const log = await page.evaluate(() => window.__log);
    const info = await page.evaluate(() => {
      const t = document.querySelector('[data-nt-track]');
      const d = t.__ntInfo && t.__ntInfo.data;
      return d ? { duration: d.duration, segments: d.segments.length, phrases: (function () { const out = []; d.segments.forEach(s => { const l = out[out.length - 1]; if (l && s[0] - l[1] < 0.2) l[1] = s[1]; else out.push([s[0], s[1]]); }); return out.length; })(), textPhrases: (() => { const w = [...document.querySelectorAll('.auth-player-text .w')]; return w.length ? parseInt(w[w.length - 1].getAttribute('data-phrase'), 10) + 1 : 0; })() } : null;
    });
    // Smoothness while playing.
    const playing = log.filter(l => l.playing && l.wave > 0 && l.wave < 0.999);
    let maxWaveStep = 0, back = 0, frozenRuns = 0, run = 0, wordJumps = 0, maxGapMs = 0;
    for (let k = 1; k < playing.length; k++) {
      const d = playing[k].wave - playing[k - 1].wave;
      maxWaveStep = Math.max(maxWaveStep, d);
      maxGapMs = Math.max(maxGapMs, playing[k].now - playing[k - 1].now);
      if (d < -1e-6) back++;
      if (Math.abs(d) < 1e-7) { run++; if (run === 6) frozenRuns++; } else run = 0;
      if (playing[k].spoken - playing[k - 1].spoken > 1) wordJumps++;
    }
    const frames = playing.length;
    const seconds = frames ? (playing[frames - 1].now - playing[0].now) / 1000 : 0;
    entry.samples.push({ tab: tabs[i] || null, src: meta.src, text: meta.text, info, frames, seconds: +seconds.toFixed(2), fps: seconds ? +(frames / seconds).toFixed(1) : 0, maxWaveStepPct: +(maxWaveStep * 100).toFixed(2), backwardSteps: back, frozenRuns, wordsSkippedAtOnce: wordJumps, maxFrameGapMs: +maxGapMs.toFixed(1), endSpoken: log.length ? log[log.length - 1].spoken : 0, wordCount: log.length ? log[log.length - 1].words : 0 });
    await page.screenshot({ path: `out/${name}-${i}.png` });
  }
  report.pages.push(entry);
  await context.close();
}
await browser.close();
fs.writeFileSync('out/report.json', JSON.stringify(report, null, 2));
for (const p of report.pages) {
  console.log(`\n== ${p.name} HTTP ${p.status} tabs: ${p.tabs.join(' | ')} errors: ${p.errors.join(' ; ') || 'none'}`);
  for (const s of p.samples) {
    console.log(`  [${s.tab}] ${s.seconds}s ${s.fps}fps maxStep=${s.maxWaveStepPct}% back=${s.backwardSteps} frozen=${s.frozenRuns} skips=${s.wordsSkippedAtOnce} maxGap=${s.maxFrameGapMs}ms words=${s.endSpoken}/${s.wordCount} analysis=${JSON.stringify(s.info)}`);
    console.log(`     ${s.src}`);
    console.log(`     ${s.text}`);
  }
}
