/**
 * CIERRE navegación Inventory (panel + subnav): capturas y checks Playwright.
 * Uso: node tools/smoke/verify-dashboard-inventory.mjs
 *
 * Contra Apache (localhost/xp/public) y la BD viva:
 *  - Sidebar: sección "Módulos" con la acción "Inventory" (→ panel); los items
 *    detallados de inventario ya NO están en el sidebar.
 *  - Panel #/dashboard/inventory: zonas del nuevo diseño (chips de alerta,
 *    tabla de stock 2/3 con conmutador, flujo operativo 1/3, línea de 7 días)
 *    + subnav con TODAS las opciones de inventario (sin opción "Dashboard").
 *  - Subnav persiste en #/inventory/* y gatea cada opción por permiso.
 *  - Cero respuestas 403 en toda la navegación (MANAGER).
 *  - Panel en paralelo: las zonas se piden a la entrada y el "Flujo
 *    operativo" se pinta SIN espera de 5s; al salir no queda ninguna
 *    petición de secondary pendiente.
 *  - Responsive: escritorio con pestanas (sin scroll horizontal) y movil con
 *    desplegable (sin scroll horizontal); el subnav NO aparece en el general.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const BASE = 'http://localhost/xp/public';
const OUT = path.resolve('C:/xampp/htdocs/xp/tools/smoke/capturas-dashboard');
mkdirSync(OUT, { recursive: true });

const INVENTORY_ROUTES = [
  'inventory', 'inventory/locations', 'inventory/receiving', 'inventory/stock',
  'inventory/movements', 'inventory/reservations', 'inventory/picking',
  'inventory/shipments', 'inventory/deliveries', 'inventory/returns',
  'inventory/incidents', 'inventory/counts'
];

let pass = 0;
let fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${extra ? ' :: ' + extra : ''}`); }
}

let net = { api403: 0, secondary: 0, secondaryAfterNav: 0, leaving: false };
function resetNet() { net = { api403: 0, secondary: 0, secondaryAfterNav: 0, leaving: false }; }

function hookNetwork(page) {
  page.on('response', (res) => {
    const url = res.url();
    if (!url.includes('/api/')) return;
    if (res.status() === 403) net.api403++;
    if (url.includes('/inventory/dashboard/secondary')) {
      net.secondary++;
      if (net.leaving) net.secondaryAfterNav++;
    }
  });
}

async function login(page, email, pass) {
  await page.goto(BASE + '/#login', { waitUntil: 'networkidle' });
  await page.waitForSelector('input[type="email"], input[name="email"], #email', { timeout: 15000 });
  await page.locator('input[type="email"], input[name="email"], #email').first().fill(email);
  await page.locator('input[type="password"], input[name="password"], #password').first().fill(pass);
  await page.getByRole('button', { name: 'Iniciar sesión' }).click();
  await page.waitForURL(/dashboard|\/#\/dashboard/, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1200);
}

async function logout(page) {
  await page.goto(BASE + '/#login', { waitUntil: 'networkidle' });
  await page.evaluate(() => { try { window.AuthState && window.AuthState.logout(); } catch (e) {} });
  await page.goto(BASE + '/#login', { waitUntil: 'networkidle' });
}

async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: false });
}

async function state(page) {
  return page.evaluate(() => {
    const vis = (el) => el ? (window.getComputedStyle(el).display !== 'none') : false;
    const sub = document.getElementById('inventory-subnav');
    const tabs = Array.from(document.querySelectorAll('.subnav-tab'));
    const opts = Array.from(document.querySelectorAll('.subnav-select-option'));
    const titles = Array.from(document.querySelectorAll('.card-title')).map(e => (e.textContent || '').trim());
    const routes = tabs.map(a => a.dataset.route);
    return {
      route: window.AppRouter && window.AppRouter.getCurrentRoute ? window.AppRouter.getCurrentRoute() : null,
      subHidden: sub ? sub.hidden : null,
      tabsVisible: vis(document.querySelector('.subnav-tabs')),
      selectVisible: vis(document.querySelector('.subnav-select')),
      tabRoutes: routes,
      hasDashboardTab: routes.includes('dashboard'),
      menuOptions: opts.length,
      hasStockCard: titles.includes('Stock actual'),
      hasFlowCard: titles.includes('Flujo operativo'),
      hasCapacityCard: titles.includes('Ocupación de almacenes'),
      // La tira de 7 días ya no lleva .card-title: el rótulo va dentro de la
      // propia tira (UI.summaryCards en modo inline).
      hasWeekCard: (() => {
        const w = document.querySelector('#module-container [data-zone="week"]');
        return w ? /Últimos 7 días/.test(w.textContent) : false;
      })(),
      hasOnboarding: titles.includes('Empieza con los pasos básicos'),
      zoneCount: document.querySelectorAll(
        '#module-container [data-zone="alerts"], #module-container [data-zone="stock"],' +
        ' #module-container [data-zone="flow"], #module-container [data-zone="capacity"],' +
        ' #module-container [data-zone="week"]').length,
      chipCount: document.querySelectorAll('#module-container .chip').length,
      statCards: document.querySelectorAll('.stat-card').length,
      sidebarHasPanel: !!document.querySelector('.sidebar-item[data-route="dashboard/inventory"]'),
      sidebarHasProducts: !!document.querySelector('.sidebar-item[data-route="inventory"]'),
      sidebarHasStock: !!document.querySelector('.sidebar-item[data-route="inventory/stock"]'),
      sidebarModulesLabel: (() => {
        const sec = document.querySelector('.sidebar-section-title[data-section="nav.section_modules"]');
        return sec ? (sec.querySelector('.sidebar-section-label') || {}).textContent : null;
      })(),
      sidebarPanelActive: (() => {
        const it = document.querySelector('.sidebar-item[data-route="dashboard/inventory"]');
        return it ? it.classList.contains('is-active') : false;
      })(),
      hScrollPage: document.documentElement.scrollWidth > window.innerWidth + 1
    };
  });
}

async function subnavMetrics(page) {
  return page.evaluate(() => {
    const header = document.querySelector('.app-header');
    const sub = document.getElementById('inventory-subnav');
    if (!header || !sub) return null;
    const h = header.getBoundingClientRect();
    const s = sub.getBoundingClientRect();
    return {
      headerBottom: Math.round(h.bottom * 100) / 100,
      subTop: Math.round(s.top * 100) / 100,
      subBottom: Math.round(s.bottom * 100) / 100,
      hidden: sub.hidden,
      inViewport: s.top >= 0 && s.top < window.innerHeight,
      innerHeight: window.innerHeight,
      maxScroll: document.documentElement.scrollHeight - window.innerHeight
    };
  });
}

async function ensureSectionExpanded(page) {
  const title = page.locator('.sidebar-section-title[data-section="nav.section_modules"]');
  if (await title.count()) {
    if ((await title.getAttribute('aria-expanded')) !== 'true') {
      await title.click();
      await page.waitForTimeout(250);
    }
  }
}

async function openMobileMenu(page) {
  await page.locator('#subnav-select-btn').click();
  await page.waitForTimeout(200);
  return page.evaluate(() => {
    const btn = document.getElementById('subnav-select-btn');
    const menu = document.getElementById('subnav-select-menu');
    return {
      expanded: btn ? btn.getAttribute('aria-expanded') : null,
      menuHidden: menu ? menu.hidden : null,
      btnLabel: btn ? (btn.textContent || '').trim() : null,
      optionRoutes: Array.from(document.querySelectorAll('.subnav-select-option')).map(o => o.dataset.route)
    };
  });
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  hookNetwork(page);

  try {
    console.log('MANAGER (manager@acme.local): sidebar + panel + subnav');
    await login(page, 'manager@acme.local', 'Manager2026!');

    // ---------- 1) Dashboard general: sin subnav, sidebar con accion Inventory ----------
    resetNet();
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto(BASE + '/#/dashboard', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    let s = await state(page);
    check('general: subnav oculto', s.subHidden === true, `hidden=${s.subHidden}`);
    check('general: sidebar seccion etiquetada Módulos', /M[oó]dulos/i.test(s.sidebarModulesLabel || ''), s.sidebarModulesLabel);
    check('general: sidebar accion Inventory presente', s.sidebarHasPanel === true);
    check('general: sidebar SIN items detallados inventario', s.sidebarHasProducts === false && s.sidebarHasStock === false);
    check('general: sin widget Stock actual', s.hasStockCard === false);
    check('general: sin widget Flujo operativo', s.hasFlowCard === false);
    check('general: cero 403', net.api403 === 0, `403=${net.api403}`);
    await shot(page, 'nav-manager-general-1920');

    // ---------- 2) Sidebar -> panel inventario ----------
    await ensureSectionExpanded(page);
    await page.locator('.sidebar-item[data-route="dashboard/inventory"]').click();
    await page.waitForURL(/dashboard\/inventory/, { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(1500);

    s = await state(page);
    check('panel: ruta activa es el panel', s.route === 'dashboard/inventory', s.route);
    check('panel: subnav visible', s.subHidden === false && s.tabsVisible === true,
      JSON.stringify({ hidden: s.subHidden, tabs: s.tabsVisible }));
    check('panel: subnav SIN opcion Dashboard', s.hasDashboardTab === false, JSON.stringify(s.tabRoutes));
    const missing = INVENTORY_ROUTES.filter(r => !s.tabRoutes.includes(r));
    check('panel: subnav con las 12 opciones de inventario', s.tabRoutes.length === 12 && missing.length === 0,
      JSON.stringify({ n: s.tabRoutes.length, missing }));
    check('panel: sidebar accion Inventory activa', s.sidebarPanelActive === true);
    check('panel: tarjeta Stock actual presente', s.hasStockCard === true);
    check('panel: tarjeta Flujo operativo presente en el PRIMER render (sin espera)',
      s.hasFlowCard === true);
    check('panel: las 5 zonas del panel montadas', s.zoneCount === 5,
      `zones=${s.zoneCount}`);
    check('panel: tira de 7 dias presente', s.hasWeekCard === true);
    check('panel: grafico de ocupacion presente', s.hasCapacityCard === true);
    check('panel: secondary pedida a la entrada (sin timer de 5s)', net.secondary >= 1,
      `secondary=${net.secondary}`);
    check('panel: sin scroll horizontal', s.hScrollPage === false, `hScroll=${s.hScrollPage}`);
    check('panel: cero 403', net.api403 === 0, `403=${net.api403}`);
    await shot(page, 'nav-manager-panel-1920');
    // Captura legada del escenario "CAPA 2 a los 5s": la carga ya es por
    // zona, sin timer, y el panel no cambia tras la primera carga; se
    // conserva el nombre de fichero.
    await shot(page, 'nav-manager-panel-1920-capa2');

    // ---------- 3) Subnav navega a una opcion y persiste ----------
    await page.locator('.subnav-tab[data-route="inventory/locations"]').click();
    await page.waitForURL(/inventory\/locations/, { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(1200);
    s = await state(page);
    check('subnav: navega a ubicaciones', s.route === 'inventory/locations', s.route);
    check('subnav: sigue visible en la opcion', s.subHidden === false && s.tabsVisible === true);
    check('subnav: ubicaciones activa',
      s.tabRoutes.includes('inventory/locations') && await page.locator('.subnav-tab.is-active[data-route="inventory/locations"]').count() > 0);
    check('subnav: cero 403', net.api403 === 0, `403=${net.api403}`);
    await shot(page, 'nav-manager-locations-1920');

    // ---------- 3b) Sticky: la subnav no se pierde con el scroll ----------
    resetNet();
    await page.goto(BASE + '/#/inventory/stock', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const atRest = await subnavMetrics(page);
    check('sticky: subnav pegada al header en reposo',
      !!atRest && Math.abs(atRest.subTop - atRest.headerBottom) <= 1.5, JSON.stringify(atRest));
    const maxScroll = atRest ? atRest.maxScroll : 0;
    check('sticky: la pagina permite scroll', maxScroll >= 120, `maxScroll=${maxScroll}`);
    if (maxScroll >= 120) {
      await page.evaluate((y) => window.scrollTo(0, y), Math.min(400, maxScroll));
      await page.waitForTimeout(300);
      const scrolled = await subnavMetrics(page);
      check('sticky: subnav sigue justo bajo el header tras scroll',
        !!scrolled && Math.abs(scrolled.subTop - scrolled.headerBottom) <= 1.5, JSON.stringify(scrolled));
      check('sticky: subnav visible en viewport tras scroll',
        !!scrolled && scrolled.hidden === false && scrolled.inViewport === true, JSON.stringify(scrolled));
      await shot(page, 'nav-manager-stock-sticky-1920');
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.waitForTimeout(200);
    }

    // ---------- 4) Panel en paralelo: secondary a la entrada, sin fugas al salir ----------
    resetNet();
    await page.goto(BASE + '/#/dashboard', { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await page.goto(BASE + '/#/dashboard/inventory', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200); // carga progresiva por zona
    check('panel paralelo: secondary pedida en la entrada (sin timer de 5s)',
      net.secondary >= 1, `secondary=${net.secondary}`);
    const s4 = await state(page);
    check('panel paralelo: Flujo operativo visible sin espera extra', s4.hasFlowCard === true);
    net.leaving = true;
    await page.goto(BASE + '/#/dashboard', { waitUntil: 'networkidle' }); // salir
    await page.waitForTimeout(6000);
    check('panel paralelo: al salir NO quedan peticiones secondary pendientes',
      net.secondaryAfterNav === 0, `afterNav=${net.secondaryAfterNav} total=${net.secondary}`);
    net.leaving = false;

    // ---------- 5) Responsive movil ----------
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(BASE + '/#/dashboard', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    s = await state(page);
    check('movil general: subnav oculto', s.subHidden === true, `hidden=${s.subHidden}`);
    check('movil general: sin scroll horizontal', s.hScrollPage === false, `hScroll=${s.hScrollPage}`);

    await page.goto(BASE + '/#/dashboard/inventory', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    s = await state(page);
    check('movil panel: desplegable visible y pestanas ocultas',
      s.selectVisible === true && s.tabsVisible === false,
      JSON.stringify({ select: s.selectVisible, tabs: s.tabsVisible }));
    check('movil panel: sin scroll horizontal', s.hScrollPage === false, `hScroll=${s.hScrollPage}`);
    await shot(page, 'nav-manager-panel-movil');

    const menu = await openMobileMenu(page);
    check('movil panel: menu abre (aria-expanded)', menu.expanded === 'true' && menu.menuHidden === false,
      JSON.stringify({ e: menu.expanded, h: menu.menuHidden }));
    const missOpt = INVENTORY_ROUTES.filter(r => !menu.optionRoutes.includes(r));
    check('movil panel: menu con las 12 opciones', menu.optionRoutes.length === 12 && missOpt.length === 0,
      JSON.stringify({ n: menu.optionRoutes.length, missing: missOpt }));
    check('movil panel: sin opcion Dashboard en el menu', !menu.optionRoutes.includes('dashboard'),
      JSON.stringify(menu.optionRoutes));
    await shot(page, 'nav-manager-panel-movil-menu');

    await page.locator('.subnav-select-option[data-route="inventory/stock"]').click();
    await page.waitForURL(/inventory\/stock/, { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(1000);
    s = await state(page);
    check('movil: navega a stock y subnav persiste', s.route === 'inventory/stock' && s.subHidden === false,
      JSON.stringify({ route: s.route, hidden: s.subHidden }));
    await shot(page, 'nav-manager-stock-movil');

    const mobRest = await subnavMetrics(page);
    check('sticky movil: subnav pegada al header en reposo',
      !!mobRest && Math.abs(mobRest.subTop - mobRest.headerBottom) <= 1.5, JSON.stringify(mobRest));
    if (mobRest && mobRest.maxScroll >= 120) {
      await page.evaluate((y) => window.scrollTo(0, y), Math.min(300, mobRest.maxScroll));
      await page.waitForTimeout(300);
      const mobScroll = await subnavMetrics(page);
      check('sticky movil: subnav sigue bajo el header tras scroll',
        !!mobScroll && Math.abs(mobScroll.subTop - mobScroll.headerBottom) <= 1.5, JSON.stringify(mobScroll));
      check('sticky movil: subnav visible en viewport tras scroll',
        !!mobScroll && mobScroll.hidden === false && mobScroll.inViewport === true, JSON.stringify(mobScroll));
      await shot(page, 'nav-manager-stock-sticky-movil');
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.waitForTimeout(200);
    } else {
      check('sticky movil: la pagina permite scroll', false, JSON.stringify(mobRest));
    }

    check('MANAGER: cero 403 global', net.api403 === 0, `403=${net.api403}`);

    // ---------- 6) ADMIN ----------
    console.log('ADMIN (aleon@acme.local): general con tarjetas de tenant');
    await logout(page);
    await login(page, 'aleon@acme.local', 'Admin123!');
    resetNet();
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto(BASE + '/#/dashboard', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    s = await state(page);
    check('admin general: subnav oculto', s.subHidden === true, `hidden=${s.subHidden}`);
    check('admin general: tarjetas de tenant (>=4)', s.statCards >= 4, `statCards=${s.statCards}`);
    check('admin general: sin widget inventario', s.hasStockCard === false && s.hasFlowCard === false);
    check('admin: cero 403', net.api403 === 0, `403=${net.api403}`);
    await shot(page, 'nav-admin-general-1920');
  } finally {
    await browser.close();
  }

  console.log(`RESULTADO: ${pass} OK, ${fail} FAIL`);
  console.log('Capturas en ' + OUT);
  if (fail > 0) process.exit(1);
}

main().catch(err => { console.error(err); process.exit(1); });
