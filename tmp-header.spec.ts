import { test } from '@playwright/test';

test('capture logged-in arabic home metrics', async ({ page }) => {
  const email = `qa_${Date.now()}@example.com`;
  await page.goto('http://127.0.0.1:8081/ar/register', { waitUntil: 'networkidle' });
  await page.fill('#name', 'QA User');
  await page.fill('#email', email);
  await page.fill('#password', 'Password123!');
  await page.fill('#password_confirmation', 'Password123!');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  await page.goto('http://127.0.0.1:8081/ar', { waitUntil: 'networkidle' });

  const metrics = await page.evaluate(() => {
    const header = document.querySelector('.app-header') as HTMLElement | null;
    const inner = document.querySelector('.header-inner') as HTMLElement | null;
    if (!header || !inner) return null;
    const hs = getComputedStyle(header);
    const is = getComputedStyle(inner);
    const hr = header.getBoundingClientRect();
    const ir = inner.getBoundingClientRect();
    return {
      headerRect: { top: hr.top, height: hr.height, bottom: hr.bottom },
      innerRect: { top: ir.top, height: ir.height, bottom: ir.bottom },
      headerPaddingTop: hs.paddingTop,
      headerPaddingBottom: hs.paddingBottom,
      innerPaddingTop: is.paddingTop,
      innerPaddingBottom: is.paddingBottom,
    };
  });

  console.log('METRICS', JSON.stringify(metrics));
  await page.screenshot({ path: 'tmp-home-ar-auth.png', fullPage: false });
});
