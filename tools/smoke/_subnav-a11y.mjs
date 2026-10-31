// Verifica interacciones del menú de grupo de la subnav: abrir/cerrar,
// Escape con devolución de foco, clic fuera, y que sólo haya un menú abierto.
import { chromium } from 'playwright';

const BASE = 'http://localhost/xp/public';
let pass = 0, fail = 0;
const check = (n, c, x) => { if (c) { pass++; console.log('  ok   ' + n); } else { fail++; console.log('  FAIL ' + n + (x ? ' :: ' + x : '')); } };

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
const page = await ctx.newPage();

await page.goto(BASE + '/#/login', { waitUntil: 'networkidle' });
await page.fill('#email, input[name="email"]', 'aleon@acme.local');
await page.fill('#password, input[name="password"]', 'Admin123!');
await page.click('button[type="submit"]');
await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 15000 }).catch(() => {});
await page.goto(BASE + '/#/dashboard/inventory', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);

const catTrigger = page.locator('[data-group-toggle][aria-controls="subnav-group-catalog"]');
const stockTrigger = page.locator('[data-group-toggle][aria-controls="subnav-group-stock"]');
const catMenu = page.locator('#subnav-group-catalog');
const stockMenu = page.locator('#subnav-group-stock');

check('menu cerrado al inicio', await catMenu.isHidden() && (await catTrigger.getAttribute('aria-expanded')) === 'false');

await catTrigger.click();
await page.waitForTimeout(150);
check('clic abre el menu (aria-expanded=true)', (await catTrigger.getAttribute('aria-expanded')) === 'true');
check('el menu es visible', await catMenu.isVisible());
check('aria-hidden=false al abrir', (await catMenu.getAttribute('aria-hidden')) === 'false');

// El disparador NO navega (sólo abre el menú): la ruta no cambia.
check('clic en el grupo NO navega', (await page.evaluate(() => window.AppRouter.getCurrentRoute())) === 'dashboard/inventory');

// Abrir otro grupo cierra el anterior (una sola instancia).
await stockTrigger.click();
await page.waitForTimeout(150);
check('abrir otro grupo cierra el anterior', await catMenu.isHidden() && await stockMenu.isVisible());
check('sólo un aria-expanded=true', (await page.locator('[data-group-toggle][aria-expanded="true"]').count()) === 1);

// Escape cierra y devuelve el foco al disparador.
await page.keyboard.press('Escape');
await page.waitForTimeout(150);
check('Escape cierra el menu', await stockMenu.isHidden());
check('Escape devuelve el foco al disparador',
  await page.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-group-toggle')));

// Clic fuera cierra.
await stockTrigger.click();
await page.waitForTimeout(150);
await page.locator('.app-header').click({ position: { x: 5, y: 5 }, force: true });
await page.waitForTimeout(150);
check('clic fuera cierra el menu', await stockMenu.isHidden());

// Teclado: Enter sobre el disparador abre.
await catTrigger.focus();
await page.keyboard.press('Enter');
await page.waitForTimeout(150);
check('Enter abre el menu', await catMenu.isVisible());
await page.keyboard.press('Escape');

// La opción activa dentro del menú está marcada al navegar.
await page.goto(BASE + '/#/inventory/stock', { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
await stockTrigger.click();
await page.waitForTimeout(150);
check('opcion activa marcada en el menu (is-active)',
  await page.locator('#subnav-group-stock .dropdown-item.is-active[data-route="inventory/stock"]').count() > 0);
check('disparador del grupo activo (is-active)',
  await page.locator('[data-group-toggle][aria-controls="subnav-group-stock"].is-active').count() > 0);

// Accesibilidad de los roles.
check('trigger con aria-haspopup=menu', (await catTrigger.getAttribute('aria-haspopup')) === 'menu');
check('menu con role=menu', (await catMenu.getAttribute('role')) === 'menu');
check('opciones con role=menuitem', (await page.locator('#subnav-group-catalog [role="menuitem"]').count()) >= 2);
check('el disparador NO tiene data-route (no navega)', (await catTrigger.getAttribute('data-route')) === null);

console.log('RESULTADO: ' + pass + ' OK, ' + fail + ' FAIL');
await browser.close();
if (fail > 0) process.exit(1);
