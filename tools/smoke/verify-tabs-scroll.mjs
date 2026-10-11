/**
 * BLOQUE 4: verificacion Playwright del scroll de pestanas.
 * Uso: node tools/smoke/verify-tabs-scroll.mjs
 * Capturas en tools/smoke/capturas-tabs/ (1920 y 768) de Picking,
 * Shipments, Deliveries y Users; mide si .tabs tiene scroll vertical.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const BASE = 'http://localhost/xp/public';
const OUT = path.resolve('C:/xampp/htdocs/xp/tools/smoke/capturas-tabs');
mkdirSync(OUT, { recursive: true });

const VIEWS = [
  { name: 'picking', hash: '#/inventory/picking' },
  { name: 'shipments', hash: '#/inventory/shipments' },
  { name: 'deliveries', hash: '#/inventory/deliveries' },
  { name: 'users', hash: '#/users' }
];

const SIZES = [
  { label: '1920', width: 1920, height: 1080 },
  { label: '768', width: 768, height: 1024 }
];

async function login(page) {
  await page.goto(BASE + '/#login', { waitUntil: 'networkidle' });
  await page.waitForSelector('input[type="email"], input[name="email"], #email', { timeout: 15000 });
  const email = page.locator('input[type="email"], input[name="email"], #email').first();
  const pass = page.locator('input[type="password"], input[name="password"], #password').first();
  await email.fill('admin@acme.local');
  await pass.fill('Admin123!');
  await page.locator('button[type="submit"], form button').first().click();
  await page.waitForURL(/dashboard|\/#\/dashboard/, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1200);
}

async function measureTabs(page) {
  return page.evaluate(() => {
    const bars = Array.from(document.querySelectorAll('.tabs'));
    return bars.map(el => {
      const style = window.getComputedStyle(el);
      return {
        className: el.className,
        clientHeight: el.clientHeight,
        scrollHeight: el.scrollHeight,
        clientWidth: el.clientWidth,
        scrollWidth: el.scrollWidth,
        overflowX: style.overflowX,
        overflowY: style.overflowY,
        verticalScroll: el.scrollHeight > el.clientHeight + 1,
        horizontalScroll: el.scrollWidth > el.clientWidth + 1
      };
    });
  });
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const results = [];
  let fails = 0;

  try {
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();
    await login(page);

    for (const size of SIZES) {
      await page.setViewportSize({ width: size.width, height: size.height });
      for (const view of VIEWS) {
        await page.goto(BASE + '/' + view.hash, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);

        const file = path.join(OUT, `${view.name}-${size.label}.png`);
        await page.screenshot({ path: file, fullPage: false });

        const bars = await measureTabs(page);
        if (bars.length === 0) {
          results.push({ view: view.name, size: size.label, status: 'WARN', detail: 'sin .tabs en la pagina' });
          continue;
        }
        for (const bar of bars) {
          const ok = !bar.verticalScroll;
          if (!ok) fails++;
          results.push({
            view: view.name,
            size: size.label,
            status: ok ? 'ok' : 'FAIL',
            detail: `verticalScroll=${bar.verticalScroll} clientH=${bar.clientHeight} scrollH=${bar.scrollHeight} overflowY=${bar.overflowY}`
          });
        }
      }
    }
  } finally {
    await browser.close();
  }

  for (const r of results) {
    console.log(`  ${r.status === 'ok' ? 'ok  ' : r.status === 'WARN' ? 'warn' : 'FAIL'} ${r.view}@${r.size} :: ${r.detail}`);
  }
  console.log(`Resultado: ${results.filter(r => r.status === 'ok').length} ok, ${results.filter(r => r.status === 'WARN').length} warn, ${fails} fail`);
  console.log('Capturas en ' + OUT);
  if (fails > 0) process.exit(1);
}

main().catch(err => { console.error(err); process.exit(1); });
