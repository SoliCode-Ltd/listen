// Phone-size look at the site as the Listen app shows it: every member page, Arabic and
// English, light and dark, at two phone sizes, with the app's user agent.
// APP=android (the default): 360x800 and 412x915. APP=ios: the page area of the smallest and
// the largest current iPhone (the app keeps pages out of the notch and the home indicator),
// plus the iPhone app's own rules: no "Upgrade" button, nothing that opens the browser to
// pay, no other phone platform or store named, and the question it asks before a first voice.
// It fails on a purchase link, selling words, sideways scrolling, anything cut off, the wrong
// direction or theme, or a script error, and keeps a screenshot of every page.
// It checks the "hidden" setting, the default: the local .env must not say ANDROID_APP_PLANS=web.
// Run against a LOCAL copy of the site with the test members of seed.php (see mobile/README.md,
// section 8). It makes no voice and no payment: it only opens pages.
//   BASE=http://127.0.0.1:8123 AGENT=agt_... [APP=ios] node in-app-pages.js [guest,free,starter,max]
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8123';
const IOS = process.env.APP === 'ios';
const OUT = __dirname + (IOS ? '/out-ios' : '/out');
fs.mkdirSync(OUT, { recursive: true });
const UA = IOS
  ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 ListenApp/1.0.0 (iOS)'
  : 'Mozilla/5.0 (Linux; Android 14; SM-A546E Build/UP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.0.0 Mobile Safari/537.36 ListenApp/1.0.0 (Android)';
const AGENT = process.env.AGENT;
// iPhone: the screen less the status bar and the home indicator (iPhone 17e: 390x844, iPhone 17 Pro Max: 440x956).
const SIZES = IOS ? [[390, 763], [440, 860]] : [[360, 800], [412, 915]];
const only = process.argv[2] || '';

const PAGES = IOS ? {
  guest: ['login', 'register', 'voice-samples', 'contact-us'],
  // The free member has not answered the iPhone app's question yet: it comes first, then they agree.
  free: ['app/consent', 'demo', 'dashboard', 'app/plan', 'agents', 'account/delete', 'contact-us?topic=delete'],
  starter: ['dashboard', 'dashboard#planDefaultsSection', 'app/plan', 'app/consent', 'agents', 'pricing', 'billing', 'app/upgrade'],
  max: ['dashboard', 'dashboard#voiceCloningSection', 'dashboard#generateVoiceSection', 'dub-my-video', 'agents', 'agents/' + AGENT, 'agents/' + AGENT + '/setup', 'app/plan', 'account/delete', 'privacy-policy'],
} : {
  guest: ['login', 'register', 'voice-samples', 'contact-us'],
  free: ['demo', 'dashboard', 'app/plan', 'agents', 'contact-us?topic=delete'],
  starter: ['dashboard', 'dashboard#planDefaultsSection', 'app/plan', 'agents', 'pricing', 'billing'],
  max: ['dashboard', 'dashboard#voiceCloningSection', 'dashboard#generateVoiceSection', 'dub-my-video', 'agents', 'agents/' + AGENT, 'agents/' + AGENT + '/setup', 'app/plan'],
};

(async () => {
  const browser = await chromium.launch();
  const report = [];
  for (const [who, pages] of Object.entries(PAGES)) {
    if (only && !only.split(',').includes(who)) continue;
    for (const locale of ['ar', 'en']) {
      for (const theme of ['dark', 'light']) {
        for (const [w, h] of SIZES) {
          const context = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, userAgent: UA, isMobile: true, hasTouch: true, locale: locale === 'ar' ? 'ar-EG' : 'en-GB' });
          await context.addInitScript((t) => { try { localStorage.setItem('listen_theme', t); } catch (e) {} }, theme);
          const page = await context.newPage();
          const errors = [];
          page.on('pageerror', (e) => errors.push(String(e.message).slice(0, 160)));
          if (who !== 'guest') {
            await page.goto(`${BASE}/${locale}/login`, { waitUntil: 'domcontentloaded' });
            await page.fill('input[name="email"]', `${who}@example.test`);
            await page.fill('input[name="password"]', 'password-123');
            await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('form:has(input[name="password"]) button[type="submit"]').first().click()]);
          }
          // iPhone: the question before a first voice. The free member starts unanswered (so the
          // question itself is looked at); everyone else has agreed.
          if (IOS && who !== 'guest') {
            await page.goto(`${BASE}/${locale}/app/consent`, { waitUntil: 'load' });
            const button = page.locator(who === 'free' ? 'form[action$="/app/consent/withdraw"] button' : 'form[action$="/app/consent"] button');
            if (await button.count()) await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), button.first().click()]);
          }
          for (const path of pages) {
            const name = `${who}_${path.replace(/[^a-z0-9]+/gi, '-').replace(/agt-[a-z0-9]+/, 'agent')}_${locale}_${theme}_${w}`;
            errors.length = 0;
            const response = await page.goto(`${BASE}/${locale}/${path}`, { waitUntil: 'load' }).catch((e) => null);
            await page.waitForTimeout(700);
            const facts = await page.evaluate(() => {
              const vw = document.documentElement.clientWidth;
              const cut = [];
              const sellRe = /[$€£]\s?\d|\d\s?(EGP|USD|SAR|AED)\b|\bupgrad|\bsubscrib|\bcheckout\b|\bpricing\b|\/mo\b|choose a plan|see plans|compare plans|ترقية|رقِّ|اشترِ|اختر (خطة|باقة)|الأسعار والخطط/i;
              const sells = [];
              for (const el of document.body.querySelectorAll('*')) {
                const cs = getComputedStyle(el);
                if (cs.display === 'none' || cs.visibility === 'hidden' || !el.getClientRects().length) continue;
                const r = el.getBoundingClientRect();
                if (r.width === 0 || r.height === 0) continue;
                // Wider than the screen, and not inside something that scrolls sideways on purpose.
                if ((r.right > vw + 2 || r.left < -2) && r.width <= vw * 3) {
                  let p = el.parentElement, scroller = false, fixedOff = false;
                  while (p && p !== document.body) { const o = getComputedStyle(p); if (/(auto|scroll|hidden|clip)/.test(o.overflowX)) { scroller = true; break; } p = p.parentElement; }
                  if (!scroller && cs.position !== 'fixed' && !el.closest('.bg-orb, .mobile-nav, [aria-hidden="true"]')) cut.push((el.tagName + '.' + String(el.className).slice(0, 40) + ' ' + Math.round(r.left) + '..' + Math.round(r.right)));
                }
                if (el.children.length === 0 && el.tagName !== 'TEXTAREA' && el.tagName !== 'OPTION') {
                  const text = (el.textContent || '').trim();
                  if (text && text.length < 300 && sellRe.test(text) && !el.closest('[lang], .vs-card, textarea, audio') && !/Teeth whitening|تبييض/.test(text)) sells.push(text.slice(0, 90));
                }
              }
              const links = [...document.querySelectorAll('a[href]')].filter((a) => /\/(pricing|billing|manage-subscription|premium-pack|free-trial)\b/.test(a.getAttribute('href')) || (/\/app\/plan/.test(a.getAttribute('href')) && !a.hasAttribute('data-in-app-plan'))).filter((a) => a.getClientRects().length).map((a) => a.getAttribute('href') + ' "' + a.textContent.trim().slice(0, 30) + '"');
              return { dir: document.documentElement.dir, theme: document.documentElement.getAttribute('data-theme'), scrollW: document.documentElement.scrollWidth, vw, cut: cut.slice(0, 6), sells: sells.slice(0, 6), links, url: location.pathname + location.hash, inApp: !!document.querySelector('meta[name="listen-in-app"]'), glue: !!window.__listenAppBooted };
            });
            await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false });
            if (IOS) {
              const ios = await page.evaluate(() => {
                const shown = (el) => el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden';
                const text = document.body.innerText || '';
                return {
                  upgrade: [...document.querySelectorAll('[data-app-upgrade], [data-app-open-web], a[href*="/app/upgrade"], a[href*="/app/continue"]')].filter(shown).length,
                  platform: (text.match(/android|google\s?play|play\s?store|أندرويد|اندرويد|جوجل بلاي|غوغل بلاي/i) || [])[0] || '',
                  asks: !!document.querySelector('form[action$="/app/consent"] button'),
                };
              });
              facts.ios = ios;
              // The question is answered once it has been looked at, so the tools after it open.
              if (path === 'app/consent' && ios.asks) {
                await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('form[action$="/app/consent"] button').first().click()]);
              }
            }
            const problems = [];
            if (!response || response.status() >= 400) problems.push('status ' + (response && response.status()));
            if (facts.scrollW > facts.vw + 1) problems.push(`sideways scroll ${facts.scrollW}>${facts.vw}`);
            if (facts.cut.length) problems.push('cut off: ' + facts.cut.join(' | '));
            if (facts.sells.length) problems.push('SELLS: ' + facts.sells.join(' | '));
            if (facts.links.length) problems.push('PURCHASE LINK: ' + facts.links.join(' | '));
            if (facts.dir !== (locale === 'ar' ? 'rtl' : 'ltr')) problems.push('direction ' + facts.dir);
            if (facts.theme !== theme) problems.push('theme ' + facts.theme);
            if (!facts.inApp || !facts.glue) problems.push('not in-app / glue not loaded');
            if (errors.length) problems.push('JS: ' + errors.join(' | '));
            if (IOS && facts.ios.upgrade) problems.push('UPGRADE OR BROWSER HAND-OVER on iPhone');
            if (IOS && facts.ios.platform) problems.push('NAMES ANOTHER PLATFORM: ' + facts.ios.platform);
            if (IOS && who === 'free' && path === 'app/consent' && !facts.ios.asks) problems.push('the question before a first voice is missing');
            if (IOS && path !== 'app/consent' && /\/app\/consent$/.test(facts.url)) problems.push('stopped at the question after agreeing');
            report.push(`${problems.length ? 'PROBLEM' : 'ok     '} ${name} -> ${facts.url}${problems.length ? '\n        ' + problems.join('\n        ') : ''}`);
          }
          await context.close();
        }
      }
    }
  }
  await browser.close();
  fs.writeFileSync(OUT + '/report' + (only ? '-' + only : '') + '.txt', report.join('\n') + '\n');
  // Going to a part of the page already open (#section) has no reply of its own: not a problem.
  const problems = report.filter((l) => l.startsWith('PROBLEM') && !/\n        status null$/.test(l));
  console.log(problems.join('\n'));
  console.log(problems.length + ' problems of ' + report.length + ' pages; screenshots in ' + OUT);
  process.exit(problems.length ? 1 : 0);
})();
