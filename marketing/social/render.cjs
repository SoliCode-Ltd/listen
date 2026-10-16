// Renders the Facebook page images from social.html at twice their layout size.
//   node marketing/social/render.cjs [out dir]   (default: marketing/social/out)
const { chromium } = require(process.env.PLAYWRIGHT_PATH || '/opt/node22/lib/node_modules/playwright');
const path = require('path');
const fs = require('fs');

const VARIANTS = {
  profile: 'listen-fb-profile.png',
  'profile-light': 'listen-fb-profile-light.png',
  'cover-ar': 'listen-fb-cover-ar.png',
  'cover-en': 'listen-fb-cover-en.png',
};

(async () => {
  const out = path.resolve(process.argv[2] || path.join(__dirname, 'out'));
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ args: ['--force-color-profile=srgb', '--font-render-hinting=none', '--allow-file-access-from-files'] });
  for (const [v, file] of Object.entries(VARIANTS)) {
    const page = await browser.newPage({ viewport: { width: 1640, height: 1640 }, deviceScaleFactor: 2 });
    page.on('pageerror', e => console.error(v, 'page error:', e.message));
    await page.goto('file://' + path.join(__dirname, 'social.html') + '?v=' + v, { waitUntil: 'load' });
    const { W, H } = await page.evaluate(() => window.ready);
    await page.screenshot({ path: path.join(out, file), clip: { x: 0, y: 0, width: W, height: H } });
    console.log(`${file}: ${W * 2}x${H * 2}`);
    await page.close();
  }
  await browser.close();
})();
