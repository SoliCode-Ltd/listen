// Makes the ad's six clips in the studio itself, as a member would: search the speaker, pick
// them, check the dialect, type the input, press Generate. Sumaya's run is filmed state by state
// for the ad's how-to (public/studio/v2-N.png), with where each control sits (v2-boxes.json,
// fractions of the page). Each clip is then copied from storage to public/audio/clips/<slug>.mp3.
//
//   node make-clips.cjs [slug ...]      (all six, or only these)
//
// Needs the site running locally (http://127.0.0.1:8123), a member account on a plan with the
// premium speakers (STUDIO_EMAIL, STUDIO_PASSWORD), premium speech set up (site setting tts_mode
// "hybrid", a GEMINI_TTS_API_KEY in .env). Every run spends speech quota shared with live: ask first.
const { chromium } = require('playwright');
const fs = require('fs');
const SHOTS = __dirname + '/public/studio/';
const LOG = __dirname + '/out/clips/';
const RUN = [
  { slug: 'sumaya-excited', name: 'سمية', dialect: 'ar-EG', emo: 'متحمس', sound: 'ضحكة خفيفة',
    input: '[متحمس] يا جماعة الحقوا! <ضحكة خفيفة> العرض ده مش هيتكرر تاني… بجد!' },
  { slug: 'abdulaziz-proud', name: 'عبدالعزيز', dialect: 'ar-SA-QS', input: '[بفخر] من قلب نجد… للعالم كله. هذي قصتنا.' },
  { slug: 'sawsan-calm', name: 'سوسن', dialect: 'ar-LB', input: '[هادئ] كان في مرّة… بنت زغيرة، عم تحلم تغنّي عالمسرح.' },
  { slug: 'abdelilah-surprised', name: 'عبد الإله', dialect: 'ar-MA-MK', input: '[متفاجئ] واش بصّح؟ <ضحكة> لا لا، هادشي بزاف عليّا!' },
  { slug: 'sundus-sad', name: 'سندس', dialect: 'ar-IQ-OB', input: '[حزين] <تنهيدة> ما توقعت يوم… تروح وتخليني وحدي.' },
  { slug: 'hamed-whisper', name: 'حامد', dialect: 'ar-AE', input: '[هامس] سمعت؟ في حد ورا الباب… لا تتحرك.' },
];
const only = process.argv.slice(2);
(async () => {
  fs.mkdirSync(LOG, { recursive: true });
  const b = await chromium.launch();
  const W = 1920;
  const ctx = await b.newContext({ viewport: { width: W, height: 1300 }, deviceScaleFactor: 2, colorScheme: 'light' });
  const p = await ctx.newPage();
  await p.addInitScript(() => { try { localStorage.setItem('listen_theme', 'light'); localStorage.setItem('theme', 'light'); } catch (e) {} });
  await p.goto('http://127.0.0.1:8123/ar/login', { waitUntil: 'domcontentloaded' });
  await p.fill('input[name="email"]', process.env.STUDIO_EMAIL);
  await p.fill('input[name="password"]', process.env.STUDIO_PASSWORD);
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.locator('form button[type="submit"]').first().click()]);
  const results = {};
  for (const L of RUN) {
    if (only.length && !only.includes(L.slug)) continue;
    const film = L.slug === 'sumaya-excited';
    await p.setViewportSize({ width: W, height: 1300 });
    await p.goto('http://127.0.0.1:8123/ar/dashboard', { waitUntil: 'networkidle', timeout: 90000 }).catch(() => {});
    await p.waitForTimeout(2000);
    // the window tall enough to show where the voice appears, like a large monitor
    const slot = await p.getByText('سيظهر صوتك هنا', { exact: true }).first().boundingBox();
    const VH = Math.round(slot.y + slot.height + 48);
    await p.setViewportSize({ width: W, height: VH });
    await p.evaluate(() => scrollTo(0, 0));
    await p.waitForTimeout(800);
    const frac = (r) => r && { x: +(r.x / W).toFixed(4), y: +(r.y / VH).toFixed(4), w: +(r.width / W).toFixed(4), h: +(r.height / VH).toFixed(4) };
    const B = { size: [W, VH] };
    let n = 0;
    const shot = async () => { if (film) await p.screenshot({ path: SHOTS + `v2-${n++}.png` }); };
    const ta = p.locator('textarea:visible').first();
    const marks = () => p.evaluate(() => [...document.querySelectorAll('.tag-editor-backdrop mark')].map((m) => { const r = m.getBoundingClientRect(); return { kind: m.className, x: r.x, y: r.y, width: r.width, height: r.height }; }));
    const rowOf = async (loc) => loc.evaluate((e) => { let n = e; while (n && n.getBoundingClientRect().width < 160) n = n.parentElement; const r = n.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height }; });
    const calm = async () => { await ta.evaluate((e) => e.blur()); await p.mouse.click(5, VH - 5); await p.waitForTimeout(400); };

    await shot();                                                         // 0: the studio as it opens
    const search = p.locator('input[placeholder*="ابحث عن متحدث"]').first();
    B.search = frac(await search.boundingBox());
    await search.click(); await search.pressSequentially(L.name, { delay: 50 }); await p.waitForTimeout(800);
    await shot();                                                         // 1: the speaker found
    const card = p.locator('.vs-voice', { has: p.locator('.vs-voice-name', { hasText: L.name }) }).filter({ visible: true }).first();
    B.card = frac(await card.boundingBox());
    await card.locator('.vs-voice-name').click();
    for (let i = 0; i < 20 && /is-playing/.test(await card.getAttribute('class')); i++) await p.waitForTimeout(500);
    await calm();
    const dialect = await p.evaluate(() => document.getElementById('dialect').value);
    if (dialect !== L.dialect) {
      console.log(L.slug, 'dialect came as', dialect, '- setting', L.dialect);
      await p.evaluate((d) => { const s = document.getElementById('dialect'); s.value = d; s.dispatchEvent(new Event('change', { bubbles: true })); }, L.dialect);
      await p.waitForTimeout(500);
    }
    B.dialect = frac(await p.locator('[data-vs-pick="dialect"]').first().boundingBox());
    await shot();                                                         // 2: the speaker picked, her dialect with her
    if (film) {
      const plain = L.input.replace(/\[[^\]]+\]\s*|<[^>]+>\s*/g, '').trim();
      await ta.fill(plain); await calm();
      B.text = frac(await ta.boundingBox());
      await shot();                                                       // 3: the line typed
      const emo = p.locator('button:visible', { hasText: 'المشاعر' }).last();
      B.emotions = frac(await emo.boundingBox());
      await emo.click(); await p.waitForTimeout(700);
      B.emoItem = frac(await rowOf(p.getByText(L.emo, { exact: true }).filter({ visible: true }).first()));
      await shot();                                                       // 4: the feelings
      await p.keyboard.press('Escape'); await calm();
      await ta.fill(`[${L.emo}] ${plain}`); await calm();
      B.emoTag = frac((await marks()).find((m) => /delivery/.test(m.kind)));
      await shot();                                                       // 5: the feeling tag in
      const snd = p.locator('button:visible', { hasText: 'أصوات حيّة' }).first();
      B.sounds = frac(await snd.boundingBox());
      await snd.click(); await p.waitForTimeout(700);
      B.soundItem = frac(await rowOf(p.getByText(L.sound, { exact: true }).filter({ visible: true }).first()));
      await shot();                                                       // 6: the sounds
      await p.keyboard.press('Escape'); await calm();
    }
    await ta.fill(L.input); await calm();
    if (film) {
      B.soundTag = frac((await marks()).find((m) => /sound/.test(m.kind)));
      await shot();                                                       // 7: the whole input
    }
    const btn = p.locator('button:has-text("توليد الصوت الآن")').first();
    B.button = frac(await btn.boundingBox());
    B.slot = frac(slot);
    const answer = p.waitForResponse((r) => /\/dashboard\/(generate|queue)/.test(r.url()) && r.request().method() === 'POST', { timeout: 240000 });
    await btn.click();
    await p.waitForTimeout(450);
    await shot();                                                         // 8: generating
    const res = await answer;
    let body = null; try { body = await res.json(); } catch (e) { body = await res.text().catch(() => ''); }
    const job = body && (body.job || body.data || body);
    console.log(L.slug, 'HTTP', res.status(), JSON.stringify(job).slice(0, 400));
    // the clip as the studio saved it (/media/audio/<file> is storage/app/public/audio/<file>)
    const url = job && job.audio_url;
    if (url) fs.copyFileSync(__dirname + '/../../storage/app/public/audio/' + url.split('/').pop(), __dirname + `/public/audio/clips/${L.slug}.mp3`);
    await p.waitForTimeout(2500);
    await p.evaluate(() => scrollTo(0, 0));
    await shot();                                                         // 9: the voice, ready
    await p.screenshot({ path: LOG + `result-${L.slug}.png` });
    if (film) {
      const after = await p.evaluate(() => {
        const a = document.querySelector('audio[src], audio source[src]');
        const box = a ? (a.closest('[class]') || a).getBoundingClientRect() : null;
        return { src: a && (a.src || a.getAttribute('src')), box: box && { x: box.x, y: box.y, width: box.width, height: box.height } };
      });
      console.log('player', JSON.stringify(after));
      results.boxes = B;
    }
    results[L.slug] = { status: res.status() };
    await p.waitForTimeout(6000);
  }
  if (results.boxes) fs.writeFileSync(SHOTS + 'v2-boxes.json', JSON.stringify(results.boxes, null, 1));
  await b.close();
})();
