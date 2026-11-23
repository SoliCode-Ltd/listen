// Makes the site's share pictures (images/og/listen-og-ar.png and -en.png, 1200x630) from
// scripts/seo/share_picture.html: the homepage's headline, speakers, logo and font, with the headline
// in the middle so the square Google cuts from it still shows the headline whole.
//
//   node scripts/seo/share_picture.mjs
//
// Then make each picture smaller (256 colours) before committing it: WhatsApp shows a link's picture
// only when it is under about 300 KB, and a full-colour one is about 500 KB. For example:
//   python3 -c "from PIL import Image; [Image.open(f).convert('RGB').quantize(256, dither=Image.Dither.FLOYDSTEINBERG).save(f, optimize=True) for f in ['public/images/og/listen-og-ar.png','public/images/og/listen-og-en.png']]"
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// require() so a Playwright installed for the whole machine is found too (NODE_PATH).
const { chromium } = createRequire(import.meta.url)('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
for (const lang of ['ar', 'en']) {
  await page.goto('file://' + path.join(here, 'share_picture.html') + '?lang=' + lang, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  if (!(await page.evaluate(() => document.fonts.check('800 60px Alexandria')))) throw new Error('The Alexandria font did not load.');
  const out = path.join(here, '../../public/images/og/listen-og-' + lang + '.png');
  await page.screenshot({ path: out });
  console.log('made', path.relative(process.cwd(), out));
}
await browser.close();
