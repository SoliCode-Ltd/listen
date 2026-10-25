// Films the studio tour: the six voices the ad was made with, each one in the studio again (the
// speaker picked, the input typed), the option it shows off open (the dialects, the performance
// styles, the live sounds, the feelings, voice and rhythm), and its own clip ready in the
// studio's player and playing. Nothing is generated: when Generate is pressed, the page is given
// the studio's saved result of that same generation (GET /dashboard/jobs/<id>, as the history
// reads it), so the player shows and plays the clip that was really made from that input.
//
//   STUDIO_EMAIL=… STUDIO_PASSWORD=… node capture-tour.cjs
//
// Needs the site running locally (http://127.0.0.1:8123) with the account that made the clips
// (make-clips.cjs). Writes public/tour/*.png (whole page, 2x), public/tour/play/<slug>-NNN.jpg
// (the player's row while the clip plays) and public/tour/tour.json (where each control sits, as
// fractions of the page, and when each player image was taken).
const { chromium } = require('playwright');
const crypto = require('crypto');
const fs = require('fs');
const OUT = __dirname + '/public/tour/';
const SITE = 'http://127.0.0.1:8123/ar';
const W = 1920, VH = 1563;
const MSG = 'تم توليد الصوت بنجاح!'; // ui.backend.dashboard_voice_generated
// the studio's generations of the six clips (make-clips.cjs), and the two left out of the
// history: the first try that failed (no key set up yet) and Sawsan's first take
const VOICES = [
  { slug: 'sumaya-excited', job: 1074, name: 'سمية', dialect: 'ar-EG', option: 'dialect', input: '[متحمس] يا جماعة الحقوا! <ضحكة خفيفة> العرض ده مش هيتكرر تاني… بجد!' },
  { slug: 'abdulaziz-proud', job: 1075, name: 'عبدالعزيز', dialect: 'ar-SA-QS', option: 'library', input: '[بفخر] من قلب نجد… للعالم كله. هذي قصتنا.' },
  { slug: 'sawsan-calm', job: 1080, name: 'سوسن', dialect: 'ar-LB', option: 'style', input: '[هادئ] كان في مرّة… بنت زغيرة، عم تحلم تغنّي عالمسرح.' },
  { slug: 'abdelilah-surprised', job: 1077, name: 'عبد الإله', dialect: 'ar-MA-MK', option: 'sounds', item: 'ضحكة', input: '[متفاجئ] واش بصّح؟ <ضحكة> لا لا، هادشي بزاف عليّا!' },
  { slug: 'sundus-sad', job: 1078, name: 'سندس', dialect: 'ar-IQ-OB', option: 'feelings', item: 'حزين', input: '[حزين] <تنهيدة> ما توقعت يوم… تروح وتخليني وحدي.' },
  { slug: 'hamed-whisper', job: 1079, name: 'حامد', dialect: 'ar-AE', option: 'rhythm', item: 'هامس', input: '[هامس] سمعت؟ في حد ورا الباب… لا تتحرك.' },
];
const LEFT_OUT = [1073, 1076];
const MENUS = { sounds: 'أصوات حيّة', feelings: 'المشاعر', rhythm: 'الصوت والإيقاع' };

(async () => {
  fs.mkdirSync(OUT + 'play', { recursive: true });
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: W, height: VH }, deviceScaleFactor: 2, colorScheme: 'light' });
  const p = await ctx.newPage();
  await p.addInitScript(() => { try { localStorage.setItem('listen_theme', 'light'); localStorage.setItem('theme', 'light'); } catch (e) {} });
  await p.goto(SITE + '/login', { waitUntil: 'domcontentloaded' });
  await p.fill('input[name="email"]', process.env.STUDIO_EMAIL);
  await p.fill('input[name="password"]', process.env.STUDIO_PASSWORD);
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.locator('form button[type="submit"]').first().click()]);
  const go = async () => {
    await p.goto(SITE + '/dashboard', { waitUntil: 'networkidle', timeout: 90000 }).catch(() => {});
    await p.waitForTimeout(1500);
    await p.evaluate(() => scrollTo(0, 0));
  };
  const frac = (r) => r && { x: +(r.x / W).toFixed(4), y: +(r.y / VH).toFixed(4), w: +(r.width / W).toFixed(4), h: +(r.height / VH).toFixed(4) };
  const rowOf = (loc, min = 160) => loc.evaluate((e, min) => { let n = e; while (n && n.getBoundingClientRect().width < min) n = n.parentElement; const r = n.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height }; }, min);
  const calm = async () => { await p.locator('textarea:visible').first().evaluate((e) => e.blur()); await p.mouse.click(5, VH - 5); await p.waitForTimeout(400); };
  const close = async () => { await p.keyboard.press('Escape'); await calm(); };
  const shot = (name) => p.screenshot({ path: OUT + name + '.png' });
  const tour = { size: [W, VH], voices: {} };

  await go();
  // the history holds the six clips only
  for (const id of LEFT_OUT) {
    const status = await p.evaluate(async (id) => {
      const token = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value
        || (await (await fetch('/ar/dashboard/csrf-token', { headers: { Accept: 'application/json' } })).json()).token;
      const r = await fetch(`/ar/dashboard/jobs/${id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } });
      return r.status;
    }, id);
    console.log('left out of the history', id, status);
  }

  for (const v of VOICES) {
    await go();
    const B = {};
    const search = p.locator('input[placeholder*="ابحث عن متحدث"]').first();
    await search.click(); await search.pressSequentially(v.name, { delay: 40 }); await p.waitForTimeout(700);
    const card = p.locator('.vs-voice', { has: p.locator('.vs-voice-name', { hasText: v.name }) }).filter({ visible: true }).first();
    await card.locator('.vs-voice-name').click();
    for (let i = 0; i < 24 && /is-playing/.test(await card.getAttribute('class')); i++) await p.waitForTimeout(500);
    await calm();
    if (await p.evaluate(() => document.getElementById('dialect').value) !== v.dialect) {
      await p.evaluate((d) => { const s = document.getElementById('dialect'); s.value = d; s.dispatchEvent(new Event('change', { bubbles: true })); }, v.dialect);
      await p.waitForTimeout(500);
    }
    B.card = frac(await card.boundingBox());
    B.search = frac(await search.boundingBox());
    B.dialect = frac(await p.locator('[data-vs-pick="dialect"]').first().boundingBox());
    if (v.option === 'library') await shot(`pick-${v.slug}`);
    if (v.option === 'dialect') {
      await p.locator('[data-vs-pick="dialect"]').first().click(); await p.waitForTimeout(800);
      B.item = frac(await rowOf(p.getByText('مصري (قاهري)', { exact: true }).filter({ visible: true }).last(), 260)); // the option's card in the picker, over the page's own field
      await shot('opt-dialect'); await close();
    }
    const ta = p.locator('textarea:visible').first();
    await ta.fill(v.input); await calm();
    if (v.option === 'style') {
      await p.locator('[data-vs-pick="performance_style"]').first().click(); await p.waitForTimeout(800);
      B.item = frac(await rowOf(p.getByText('افتراضي', { exact: true }).filter({ visible: true }).last(), 260));
      await shot('opt-style'); await close();
    }
    if (MENUS[v.option]) {
      const menu = p.locator('button:visible', { hasText: MENUS[v.option] }).last();
      B.menu = frac(await menu.boundingBox());
      await menu.click(); await p.waitForTimeout(700);
      const item = p.getByText(v.item, { exact: true }).filter({ visible: true }).first();
      await item.scrollIntoViewIfNeeded(); await p.waitForTimeout(300);
      B.item = frac(await rowOf(item));
      await shot(`opt-${v.option}`); await close();
    }
    B.marks = (await p.evaluate(() => [...document.querySelectorAll('.tag-editor-backdrop mark')].map((m) => { const r = m.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height }; }))).map(frac);
    B.text = frac(await ta.boundingBox());
    B.button = frac(await p.locator('button:has-text("توليد الصوت الآن")').first().boundingBox());
    await shot(`in-${v.slug}`);

    // Generate: the page gets the studio's saved result of this clip's generation
    await p.route(/\/dashboard\/(generate|queue)$/, async (route) => {
      const r = await p.request.get(`${SITE}/dashboard/jobs/${v.job}`, { headers: { Accept: 'application/json' } });
      const saved = await r.json();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, message: MSG, job: saved.job, usage: saved.usage, stats: saved.stats }) });
    });
    await p.locator('button:has-text("توليد الصوت الآن")').first().click();
    await p.getByText('أحدث صوت تم توليده', { exact: false }).first().waitFor({ timeout: 20000 });
    await p.unroute(/\/dashboard\/(generate|queue)$/);
    const audio = await p.evaluateHandle(() => [...document.querySelectorAll('audio')].find((a) => /\/media\/audio\//.test(a.currentSrc || a.src)));
    await audio.evaluate((a) => { a.pause(); a.currentTime = 0; });
    await p.waitForTimeout(500);
    await p.evaluate(() => scrollTo(0, 0));
    const boxes = await p.evaluate(() => {
      const note = [...document.querySelectorAll('*')].find((e) => e.children.length === 0 && /أحدث صوت تم توليده/.test(e.textContent));
      let c = note; while (c && (c.getBoundingClientRect().width < 1000 || c.getBoundingClientRect().height < 80)) c = c.parentElement;
      const cr = c.getBoundingClientRect();
      const row = [...c.querySelectorAll('*')].find((e) => { const r = e.getBoundingClientRect(); return r.width > cr.width * 0.9 && r.height > 34 && r.height < 72 && r.y > cr.y + 30; });
      const rr = row.getBoundingClientRect();
      row.setAttribute('data-tour-row', '1');
      return { result: { x: cr.x, y: cr.y, width: cr.width, height: cr.height }, row: { x: rr.x, y: rr.y, width: rr.width, height: rr.height } };
    });
    B.result = frac(boxes.result); B.row = frac(boxes.row);
    await shot(`res-${v.slug}`);

    // the clip playing: the player's row, each time it changes, with the moment it shows
    const row = p.locator('[data-tour-row="1"]');
    const frames = [];
    let last = '';
    await audio.evaluate((a) => a.play());
    for (let i = 0; i < 400; i++) {
      const [t, ended] = await audio.evaluate((a) => [a.currentTime, a.ended]);
      const buf = await row.screenshot({ type: 'jpeg', quality: 90 });
      const hash = crypto.createHash('md5').update(buf).digest('hex');
      if (hash !== last) {
        const file = `play/${v.slug}-${String(frames.length).padStart(3, '0')}.jpg`;
        fs.writeFileSync(OUT + file, buf);
        frames.push([+t.toFixed(3), file]);
        last = hash;
      }
      if (ended) break;
    }
    B.play = frames;
    B.duration = await audio.evaluate((a) => a.duration);
    tour.voices[v.slug] = B;
    console.log(v.slug, frames.length, 'player images over', B.duration.toFixed(2), 's');
  }

  // the history: every voice made, each with its own player
  await go();
  await p.locator('a[href="#recentVoicesSection"]').first().click(); await p.waitForTimeout(1500);
  await p.evaluate(() => scrollTo(0, 0));
  tour.history = { rows: (await p.evaluate(() => [...document.querySelectorAll('#recentVoicesSection tbody tr')].map((r) => { const b = r.getBoundingClientRect(); return { x: b.x, y: b.y, width: b.width, height: b.height }; }))).map(frac) };
  await shot('history');
  fs.writeFileSync(OUT + 'tour.json', JSON.stringify(tour, null, 1));
  await b.close();
})();
