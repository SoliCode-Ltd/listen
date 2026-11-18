// Makes the App Store screenshots: eight for each language, at the two sizes Apple takes for
// iPhone (1206x2622, the required one, and 1320x2868), in the Play Store pictures' style.
// Each one is a real page of the site as the iPhone app shows it (the app's user agent, the
// page area the app gives a page), under a headline. No price, no plan to buy, no other
// company and no other phone platform is in any of them.
// Run against a LOCAL copy of the site with the test members of ../../checks/seed.php, and
// video dubbing switched as it is on live. It makes no voice and no payment: it only opens pages.
//   BASE=http://127.0.0.1:8123 AGENT=agt_... node make-screenshots.js [folder to write to]
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8123';
const AGENT = process.env.AGENT;
const OUT = process.argv[2] || __dirname;
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 ListenApp/1.0.0 (iOS)';

// Width and height in points (three pixels to a point), and the bar above the page (the
// status bar with the Dynamic Island), which the app leaves in the page's own colour.
const SIZES = [
  { name: '1206x2622', w: 402, h: 874, top: 62 },
  { name: '1320x2868', w: 440, h: 956, top: 62 },
];

const tab = (name) => async (page) => { await page.getByRole('tab', { name }).or(page.getByRole('button', { name })).or(page.getByRole('link', { name })).first().click(); };
// Words in the studio's text box, so the first picture shows the studio in use (nothing is generated).
const write = (text) => async (page) => { await page.fill('#contentTextarea', text); await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); }); };
const SHOTS = [
  { slug: '1-studio', path: { en: 'en/dashboard', ar: 'ar/dashboard' }, then: { en: write("Good evening, and welcome to tonight's episode. We start with the story everyone is talking about."), ar: write('مساء الخير، وأهلاً بكم في حلقة الليلة. نبدأ بالقصة التي يتحدث عنها الجميع.') },
    en: ['Type it.<br>Hear it in seconds.', 'Natural AI voice-overs, ready to use'],
    ar: ['اكتب نصك<br>واسمعه في ثوانٍ', 'تعليق صوتي طبيعي بالذكاء الاصطناعي'] },
  { slug: '2-arabic-dialects', path: { en: 'ar/voice-samples', ar: 'ar/voice-samples' },
    en: ['140+ Arabic dialects', 'From Riyadh to Casablanca, each voice sounds local'],
    ar: ['+140 لهجة عربية', 'من الرياض إلى الدار البيضاء، كل صوت كأهل بلده'] },
  { slug: '3-english-accents', path: { en: 'en/voice-samples', ar: 'en/voice-samples' },
    en: ['60+ English accents', 'American, British, Australian and many more'],
    ar: ['+60 لكنة إنجليزية', 'أمريكية وبريطانية وأسترالية وغيرها'] },
  { slug: '4-emotions', path: { en: 'en/voice-samples', ar: 'ar/voice-samples' }, then: { en: tab(/Moods and styles/), ar: tab(/المشاعر والأساليب/) },
    en: ['Emotion and performance', 'Laughter, whispers, excitement: voices that act'],
    ar: ['مشاعر وأداء حيّ', 'ضحك، همس، حماس... والصوت يمثّل النص'] },
  { slug: '5-voice-cloning', path: { en: 'en/dashboard#voiceCloningSection', ar: 'ar/dashboard#voiceCloningSection' },
    en: ['Clone your own voice', 'Record 30 seconds, then use it for any text'],
    ar: ['استنسخ صوتك', 'سجّل 30 ثانية واستخدم صوتك في كل نص'] },
  { slug: '6-voice-design', path: { en: 'en/dashboard', ar: 'ar/dashboard' }, then: { en: tab(/Voice Design/), ar: tab(/تصميم الصوت/) },
    en: ['Design any voice<br>from a description', 'Describe it in a sentence and hear it'],
    ar: ['صمّم أي صوت<br>بوصف بسيط', 'اكتب وصف الصوت في جملة واسمعه'] },
  { slug: '7-agents', path: { en: 'en/agents/' + AGENT, ar: 'ar/agents/' + AGENT },
    en: ['A voice agent for<br>your customers', 'Answers in their dialect, any time of day'],
    ar: ['وكيل صوتي لعملائك', 'يردّ على عملائك بلهجتهم، في أي وقت'] },
  { slug: '8-more-languages', path: { en: 'en/voice-samples', ar: 'en/voice-samples' }, then: { en: tab(/Other languages/), ar: tab(/Other languages/) },
    en: ['Voices in more languages', 'French, Spanish, German, Turkish and more'],
    ar: ['وأصوات بلغات أخرى', 'الفرنسية والإسبانية والألمانية والتركية وغيرها'] },
];

// The picture: the headline, then the phone with the page in it, running off the bottom edge.
const sheet = (size, lang, head, sub, shot, pageColor) => `
<style>
  html, body { margin: 0; padding: 0; width: ${size.w}px; height: ${size.h}px; overflow: hidden; background: #07070B; }
  .shot { position: relative; width: ${size.w}px; height: ${size.h}px; overflow: hidden; direction: ${lang === 'ar' ? 'rtl' : 'ltr'};
    background: radial-gradient(120% 46% at 50% -6%, #3B2A9C 0%, #1B1450 34%, rgba(11,11,15,0) 70%), #07070B; }
  .words { position: absolute; inset: 0 0 auto 0; height: ${Math.round(size.h * 0.255)}px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: ${Math.round(size.w * 0.028)}px; padding: ${Math.round(size.w * 0.06)}px ${Math.round(size.w * 0.06)}px 0; box-sizing: border-box; text-align: center; }
  .words h1 { margin: 0; color: #fff; font-family: ${lang === 'ar' ? 'var(--font-display-ar, var(--font-body-ar))' : 'var(--font-display)'}; font-weight: 800; font-size: ${Math.round(size.w * (lang === 'ar' ? 0.094 : 0.086))}px; line-height: ${lang === 'ar' ? 1.32 : 1.14}; letter-spacing: ${lang === 'ar' ? '0' : '-0.01em'}; }
  .words p { margin: 0; color: #C9C0FF; font-family: ${lang === 'ar' ? 'var(--font-body-ar)' : 'var(--font-body)'}; font-weight: 500; font-size: ${Math.round(size.w * 0.043)}px; line-height: 1.45; }
  .phone { position: absolute; left: 50%; top: ${Math.round(size.h * 0.275)}px; width: ${Math.round(size.w * 0.84)}px; transform: translateX(-50%); border-radius: ${Math.round(size.w * 0.115)}px ${Math.round(size.w * 0.115)}px 0 0; padding: ${Math.round(size.w * 0.022)}px ${Math.round(size.w * 0.022)}px 0; box-sizing: border-box; background: #23232D; box-shadow: 0 0 0 1px #3A3A48, 0 0 ${Math.round(size.w * 0.3)}px rgba(108, 77, 255, .38); }
  .screen { border-radius: ${Math.round(size.w * 0.095)}px ${Math.round(size.w * 0.095)}px 0 0; overflow: hidden; background: ${pageColor}; }
  .bar { position: relative; height: ${Math.round(size.top * 0.84)}px; background: ${pageColor}; }
  .bar i { position: absolute; left: 50%; top: 50%; width: 31%; height: 56%; transform: translate(-50%, -50%); border-radius: 999px; background: #000; }
  .screen img { display: block; width: 100%; }
</style>
<div class="shot">
  <div class="words"><h1>${head}</h1><p>${sub}</p></div>
  <div class="phone"><div class="screen"><div class="bar"><i></i></div><img src="${shot}"></div></div>
</div>`;

(async () => {
  if (!AGENT) throw new Error('AGENT=agt_... is needed (seed.php prints it).');
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  for (const size of SIZES) {
    // What is seen of the page: from under the status bar down to the picture's bottom edge.
    const phoneW = Math.round(size.w * 0.84) - 2 * Math.round(size.w * 0.022);
    const seen = size.h - Math.round(size.h * 0.275) - Math.round(size.w * 0.022) - Math.round(size.top * 0.84);
    const pageH = Math.round(seen * size.w / phoneW);
    for (const lang of ['en', 'ar']) {
      const app = await browser.newContext({ viewport: { width: size.w, height: pageH }, deviceScaleFactor: 3, userAgent: UA, isMobile: true, hasTouch: true, colorScheme: 'dark' });
      await app.addInitScript(() => { try { localStorage.setItem('listen_theme', 'dark'); } catch (e) {} });
      const page = await app.newPage();
      await page.goto(`${BASE}/${lang}/login`, { waitUntil: 'domcontentloaded' });
      await page.fill('input[name="email"]', 'max@example.test');
      await page.fill('input[name="password"]', 'password-123');
      await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('form:has(input[name="password"]) button[type="submit"]').first().click()]);
      // The same member has answered the iPhone app's question before a first voice.
      if (page.url().includes('/app/consent')) await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.ai-ask-actions .btn-primary').click()]);

      const board = await browser.newContext({ viewport: { width: size.w, height: size.h }, deviceScaleFactor: 3 });
      const canvas = await board.newPage();
      await canvas.goto(`${BASE}/${lang}/login`, { waitUntil: 'load' });

      for (const shot of SHOTS) {
        await page.goto(`${BASE}/${shot.path[lang]}`, { waitUntil: 'load' });
        if (page.url().includes('/app/consent')) {
          await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.ai-ask-actions .btn-primary').click()]);
          await page.goto(`${BASE}/${shot.path[lang]}`, { waitUntil: 'load' });
        }
        if (shot.then) {
          await shot.then[lang](page);
          await page.waitForLoadState('load');
          // A tab of the voices page slides the page to its part: wait until it has stopped.
          for (let last = -1, y = 0; y !== last; ) { last = y; await page.waitForTimeout(500); y = await page.evaluate(() => window.scrollY); }
        }
        await page.evaluate(() => document.fonts.ready);
        await page.waitForTimeout(900);
        const words = await page.evaluate(() => document.body.innerText);
        if (/[$€£]\s?\d|\d\s?(USD|EGP|SAR|AED)|upgrade|ترقية|Android|Google Play/i.test(words)) throw new Error(`${shot.slug} (${lang}): a price, an upgrade or another platform is on the page.`);
        const pageColor = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
        const png = await page.screenshot({ type: 'png' });
        await canvas.evaluate((html) => { document.head.querySelectorAll('style[data-shot]').forEach((s) => s.remove()); document.body.className = ''; document.body.innerHTML = html; }, sheet(size, lang, shot[lang][0], shot[lang][1], 'data:image/png;base64,' + png.toString('base64'), pageColor));
        await canvas.evaluate(() => Promise.all([document.fonts.ready, ...[...document.images].map((i) => i.decode().catch(() => {}))]));
        await canvas.waitForTimeout(250);
        // JPEG: Apple takes it, it has no transparency, and it is a quarter of the PNG's size.
        const file = `${OUT}/iphone-${lang}-${size.name}-${shot.slug}.jpg`;
        await canvas.screenshot({ path: file, type: 'jpeg', quality: 92, clip: { x: 0, y: 0, width: size.w, height: size.h } });
        console.log(file);
      }
      await app.close();
      await board.close();
    }
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
