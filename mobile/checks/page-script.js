// The app's page script (public/js/listen-app.js) against a stand-in for the native side.
// The requests that would make a voice are answered by the stand-in, never by the site.
// Run against a LOCAL copy of the site with the test members of seed.php (see mobile/README.md,
// section 8). It makes no voice and no payment: it only opens pages.
//   BASE=http://127.0.0.1:8123 node page-script.js
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8123';
const UA = 'Mozilla/5.0 (Linux; Android 14; wv) AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36 ListenApp/1.0.0 (Android)';
require('fs').mkdirSync(__dirname + '/out', { recursive: true });
const results = [];
const check = (name, ok, extra) => { results.push((ok ? 'PASS ' : 'FAIL ') + name + (ok ? '' : ' :: ' + JSON.stringify(extra))); };

(async () => {
  const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] });
  const context = await browser.newContext({ viewport: { width: 360, height: 800 }, userAgent: UA, isMobile: true, hasTouch: true, locale: 'ar-EG' });
  await context.addInitScript(() => {
    const calls = []; const listeners = {};
    const state = { mic: true, notifications: false, notifyGrant: true };
    window.__native = { calls, listeners, state, emit(plugin, event, data) { (listeners[plugin + ':' + event] || []).forEach((cb) => cb(data)); } };
    window.Capacitor = {
      nativePromise(plugin, method, args) {
        calls.push({ plugin, method, args });
        if (method === 'getInfo') return Promise.resolve({ version: '1.0.0', build: 1, platform: 'android', pushAvailable: true, notificationsAllowed: state.notifications, micPermission: 'prompt' });
        if (method === 'ensureMic') return Promise.resolve({ granted: state.mic });
        if (method === 'ensureNotifications') { state.notifications = state.notifyGrant; return Promise.resolve({ granted: state.notifyGrant }); }
        if (method === 'saveAudio') return Promise.resolve({ uri: 'content://x', displayName: args.fileName });
        if (method === 'register') { setTimeout(() => window.__native.emit('PushNotifications', 'registration', { value: 'tok_' + 'a'.repeat(60) }), 50); return Promise.resolve(); }
        return Promise.resolve({});
      },
      addListener(plugin, event, cb) { (listeners[plugin + ':' + event] = listeners[plugin + ':' + event] || []).push(cb); return { remove() {} }; },
    };
  });
  const page = await context.newPage();
  const jsErrors = []; page.on('pageerror', (e) => jsErrors.push(e.message));
  const calls = (m) => page.evaluate((m) => window.__native.calls.filter((c) => c.method === m), m);
  const clear = () => page.evaluate(() => { window.__native.calls.length = 0; });

  // Sign in as the paid member.
  await page.goto(BASE + '/ar/login');
  await page.fill('input[name="email"]', 'starter@example.test');
  await page.fill('input[name="password"]', 'password-123');
  await Promise.all([page.waitForNavigation(), page.locator('form:has(input[name="password"]) button[type="submit"]').first().click()]);
  await page.waitForTimeout(600);
  check('lands on the studio', /\/ar\/dashboard/.test(page.url()), page.url());
  check('boot: back handler on', (await calls('setBackHandler')).some((c) => c.args.enabled === true));
  check('boot: pull to refresh off in the studio', (await calls('setPullToRefresh')).some((c) => c.args.enabled === false));
  check('boot: theme sent (dark)', (await calls('setTheme')).some((c) => c.args.dark === true));
  check('no notification question at launch', (await calls('ensureNotifications')).length === 0 && !(await page.$('.la-sheet')));

  // Theme toggle reaches the status bar.
  await clear();
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
  await page.waitForTimeout(100);
  check('theme change sent (light)', (await calls('setTheme')).some((c) => c.args.dark === false));

  // A download the page makes itself (a blob it revokes right after).
  await clear();
  await page.evaluate(() => {
    const url = URL.createObjectURL(new Blob(['abc'], { type: 'audio/mpeg' }));
    const a = document.createElement('a'); a.href = url; a.download = 'listen-ar-مرحبا-kore.mp3';
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
  });
  await page.waitForSelector('.la-sheet.is-on');
  const labels = await page.$$eval('.la-sheet button', (bs) => bs.map((b) => b.textContent.trim()));
  check('download opens the save/share sheet in Arabic', labels.join('|') === 'حفظ على الهاتف|مشاركة|إلغاء', labels);
  await page.screenshot({ path: __dirname + '/out/glue_sheet_ar_360.png' });
  await page.click('.la-sheet button.is-primary');
  await page.waitForTimeout(500);
  const saved = await calls('saveAudio');
  check('save hands the file to the phone', saved.length === 1 && saved[0].args.base64 === 'YWJj' && saved[0].args.fileName === 'listen-ar-مرحبا-kore.mp3' && saved[0].args.mimeType === 'audio/mpeg', saved);
  check('sheet closes after saving', !(await page.$('.la-sheet.is-on')));
  const toastText = await page.$eval('.la-toast', (t) => t.textContent);
  check('says where it was saved', /التنزيلات/.test(toastText), toastText);

  // A plain link to a file on the site is shared by address (the phone downloads it with the session).
  await clear();
  await page.evaluate(() => { const a = document.createElement('a'); a.href = '/media/audio/x/y.mp3'; a.download = 'voice.mp3'; a.textContent = 'd'; a.id = 'dl2'; document.body.appendChild(a); a.click(); });
  await page.waitForSelector('.la-sheet.is-on');
  await page.locator('.la-sheet button').nth(1).click();
  await page.waitForTimeout(300);
  const shared = await calls('shareAudio');
  check('share hands the address to the phone', shared.length === 1 && shared[0].args.url === BASE + '/media/audio/x/y.mp3' && shared[0].args.fileName === 'voice.mp3', shared);
  check('the page did not navigate away', /\/ar\/dashboard/.test(page.url()));

  // Back button: closes the sheet first, then leaves the app from the first page.
  await page.evaluate(() => document.getElementById('dl2').click());
  await page.waitForSelector('.la-sheet.is-on');
  await clear();
  await page.evaluate(() => window.__native.emit('ListenShell', 'backPressed', {}));
  await page.waitForTimeout(350);
  check('back closes the sheet', !(await page.$('.la-sheet')) && (await calls('minimize')).length === 0);
  await page.evaluate(() => window.__native.emit('ListenShell', 'backPressed', {}));
  await page.waitForTimeout(100);
  check('back on the studio steps out of the app', (await calls('minimize')).length === 1);
  // The menu closes on back too.
  await page.click('#mobileMenuToggle');
  await page.waitForTimeout(200);
  await clear();
  await page.evaluate(() => window.__native.emit('ListenShell', 'backPressed', {}));
  await page.waitForTimeout(200);
  check('back closes the menu', await page.evaluate(() => !document.getElementById('mobileNav').classList.contains('open')) && (await calls('minimize')).length === 0);

  // Microphone: asked through the phone first; a refusal is a refusal.
  await clear();
  const micOk = await page.evaluate(() => navigator.mediaDevices.getUserMedia({ audio: true }).then((s) => { s.getTracks().forEach((t) => t.stop()); return 'ok'; }, (e) => e.name));
  const micCalls = await calls('ensureMic');
  check('microphone asked through the phone, in Arabic', micOk === 'ok' && micCalls.length === 1 && micCalls[0].args.lang === 'ar', { micOk, micCalls });
  await page.evaluate(() => { window.__native.state.mic = false; });
  const micNo = await page.evaluate(() => navigator.mediaDevices.getUserMedia({ audio: true }).then(() => 'ok', (e) => e.name));
  check('microphone refused stays refused', micNo === 'NotAllowedError', micNo);

  // Lock-screen player: long audio only.
  await clear();
  const wav = (seconds) => page.evaluate((seconds) => {
    const rate = 8000, n = rate * seconds, buf = new ArrayBuffer(44 + n), v = new DataView(buf);
    const w = (o, s) => [...s].forEach((c, i) => v.setUint8(o + i, c.charCodeAt(0)));
    w(0, 'RIFF'); v.setUint32(4, 36 + n, true); w(8, 'WAVEfmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
    v.setUint32(24, rate, true); v.setUint32(28, rate, true); v.setUint16(32, 1, true); v.setUint16(34, 8, true); w(36, 'data'); v.setUint32(40, n, true);
    new Uint8Array(buf, 44).fill(128);
    const a = document.createElement('audio'); a.src = URL.createObjectURL(new Blob([buf], { type: 'audio/wav' })); a.dataset.downloadText = 'نص طويل للتجربة'; a.id = 'a' + seconds;
    document.body.appendChild(a); return a.play().then(() => true, (e) => String(e));
  }, seconds);
  check('short clip plays', (await wav(4)) === true);
  await page.waitForTimeout(600);
  check('a short preview does not start the lock-screen player', (await calls('mediaUpdate')).length === 0);
  await page.evaluate(() => document.getElementById('a4').pause());
  check('long audio plays', (await wav(60)) === true);
  await page.waitForTimeout(1500);
  const updates = await calls('mediaUpdate');
  const last = updates[updates.length - 1];
  check('long audio shows on the lock screen', !!last && last.args.playing === true && Math.round(last.args.duration) === 60 && last.args.title === 'نص طويل للتجربة', last);
  await page.evaluate(() => window.__native.emit('ListenShell', 'mediaAction', { action: 'pause' }));
  await page.waitForTimeout(200);
  check('pause from the lock screen pauses the page', await page.evaluate(() => document.getElementById('a60').paused));
  check('the lock screen is told it paused', (await calls('mediaUpdate')).slice(-1)[0].args.playing === false);
  await page.evaluate(() => window.__native.emit('ListenShell', 'mediaAction', { action: 'seek', position: 30 }));
  await page.waitForTimeout(200);
  check('seek from the lock screen', Math.round(await page.evaluate(() => document.getElementById('a60').currentTime)) === 30);
  await page.evaluate(() => window.__native.emit('ListenShell', 'mediaAction', { action: 'play' }));
  await page.waitForTimeout(300);
  check('play from the lock screen', await page.evaluate(() => !document.getElementById('a60').paused));
  await page.evaluate(() => window.__native.emit('ListenShell', 'mediaAction', { action: 'stop' }));
  await page.waitForTimeout(200);
  check('stop clears the lock-screen player', (await calls('mediaStop')).length === 1 && await page.evaluate(() => document.getElementById('a60').paused));

  // Notifications: asked after slow work is started, never before. The request itself is
  // answered here, so no voice is made.
  await clear();
  let reached = 0;
  await page.route('**/dashboard/queue', (route) => { reached++; route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); });
  await page.evaluate(() => fetch('/ar/dashboard/queue', { method: 'POST', body: '{}' }));
  await page.waitForSelector('.la-sheet.is-on', { timeout: 5000 });
  check('the stand-in answered the request (no voice made)', reached === 1);
  const title = await page.$eval('.la-sheet h2', (h) => h.textContent);
  check('asks about notifications after slow work starts', title === 'نخبرك عندما يجهز؟', title);
  await page.screenshot({ path: __dirname + '/out/glue_notify_ar_360.png' });
  const devicePost = page.waitForRequest((r) => r.url().endsWith('/app/devices') && r.method() === 'POST', { timeout: 5000 }).catch(() => null);
  await page.click('.la-sheet button.is-primary');
  const req = await devicePost;
  check('saying yes asks the phone, registers, and sends the token to the site', !!req && JSON.parse(req.postData()).token.startsWith('tok_') && (await calls('ensureNotifications')).length === 1, req && req.postData());
  const resp = req ? await req.response() : null;
  check('the site keeps the token', !!resp && resp.status() === 200, resp && resp.status());

  // Declined: not asked again for a while.
  await page.evaluate(() => { localStorage.removeItem('listen_app_notify_asked'); window.__native.state.notifications = false; });
  await page.evaluate(() => fetch('/ar/dashboard/queue', { method: 'POST', body: '{}' }));
  await page.waitForSelector('.la-sheet.is-on');
  await page.locator('.la-sheet button.is-quiet').click();
  await page.waitForTimeout(400);
  await page.evaluate(() => fetch('/ar/dashboard/queue', { method: 'POST', body: '{}' }));
  await page.waitForTimeout(1800);
  check('"not now" is respected', !(await page.$('.la-sheet')));

  // The sign-in button hands over to the phone's browser.
  await clear();
  await page.evaluate(() => { const b = document.createElement('button'); b.setAttribute('data-app-signin', ''); b.id = 'gs'; document.body.appendChild(b); b.click(); });
  await page.waitForTimeout(100);
  const signIn = await calls('signIn');
  check('sign-in button asks the phone to open the browser', signIn.length === 1 && signIn[0].args.locale === 'ar', signIn);

  // Pull to refresh is on where nothing typed can be lost.
  await clear();
  await page.goto(BASE + '/en/app/plan'); await page.waitForTimeout(400);
  check('pull to refresh on the plan page', (await calls('setPullToRefresh')).some((c) => c.args.enabled === true));
  await page.screenshot({ path: __dirname + '/out/plan_en_dark_360_final.png' });
  await page.evaluate(() => window.__native.emit('ListenShell', 'backPressed', {}));
  await page.waitForTimeout(600);
  check('back from an inner page goes back', /\/ar\/dashboard/.test(page.url()), page.url());

  check('no script errors', jsErrors.length === 0, jsErrors);
  await browser.close();
  console.log(results.join('\n'));
  const failed = results.filter((r) => r.startsWith('FAIL')).length;
  console.log(failed + ' failed of ' + results.length);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.log(results.join('\n')); console.error('CRASH', e.message); process.exit(1); });
