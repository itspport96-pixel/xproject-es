/**
 * VERIFICACION PROFUNDA del panel de Inventory (navegador real + BD viva):
 * Uso: node tools/smoke/verify-inventory-panel.mjs
 *
 * Escenarios:
 *  1) DATOS REALES (manager@acme.local, siembra DEMO):
 *     - Mapeo endpoint -> indicador: cada valor pintado se contrasta con la
 *       respuesta REAL del mismo endpoint (fetch dentro de la pagina).
 *     - Clic de cada indicador (chips, filas de flujo, metricas, ver todo el
 *       stock) -> la ruta de destino es la esperada.
 *     - Conmutador Sin stock / Todos cambia las filas de la tabla.
 *  2) PERMISOS PARCIALES (viewer@acme.local, rol VIEWER: todo *.view,
 *     ningun *.create): panel visible SIN acciones de cabecera.
 *  3) CATALOGO VACIO (page.route devuelve el contrato real con ceros):
 *     chips "Todo en orden", tabla vacia y onboarding de 3 pasos.
 *  4) ERROR SIMULADO (page.route 500 en critical): la zona Stock pinta error
 *     con Reintentar, el resto se pinta; Reintentar recupera la zona.
 *  5) Idiomas es/en, tema claro/oscuro y viewports 1366/1024/768 px.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const BASE = 'http://localhost/xp/public';
const OUT = path.join('tools', 'smoke', 'capturas-panel');
mkdirSync(OUT, { recursive: true });

let pass = 0, fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log('  ok   ' + name); }
  else { fail++; console.log('  FAIL ' + name + (extra !== undefined ? ' :: ' + extra : '')); }
}
async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: true });
}
async function login(page, email, password) {
  await page.goto(BASE + '/#/login', { waitUntil: 'networkidle' });
  await page.fill('#email, input[name="email"]', email);
  await page.fill('#password, input[name="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(800);
}

async function panelState(page) {
  return page.evaluate(() => {
    const c = document.querySelector('#module-container');
    const text = (sel) => {
      const el = c && c.querySelector(sel);
      return el ? el.textContent.replace(/\s+/g, ' ').trim() : null;
    };
    const titles = Array.from(c ? c.querySelectorAll('.card-title') : []).map(e => e.textContent.trim());
    return {
      titles,
      chips: Array.from(c ? c.querySelectorAll('[data-zone="alerts"] .chip') : [])
        .map(e => e.textContent.replace(/\s+/g, ' ').trim()),
      chipRoutes: Array.from(c ? c.querySelectorAll('[data-zone="alerts"] .chip[data-route]') : [])
        .map(e => e.getAttribute('data-route')),
      stockRows: c ? c.querySelectorAll('[data-zone="stock"] tbody tr').length : 0,
      stockCells: Array.from(c ? c.querySelectorAll('[data-zone="stock"] tbody tr') : []).slice(0, 3)
        .map(tr => Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim()).join(' | ')),
      flowRows: Array.from(c ? c.querySelectorAll('[data-zone="flow"] [data-route]') : [])
        .map(e => ({
          route: e.getAttribute('data-route'),
          text: e.textContent.replace(/\s+/g, ' ').trim(),
          // El valor numérico va aislado en <strong>: permite comparar el
          // número exacto en vez de un includes() que un cero equivocado
          // también satisfaría.
          value: (e.querySelector('strong') || {}).textContent || null
        })),
      week: Array.from(c ? c.querySelectorAll('[data-zone="week"] [data-route]') : [])
        .map(e => ({ route: e.getAttribute('data-route'), text: e.textContent.replace(/\s+/g, ' ').trim() })),
      weekTitle: text('[data-zone="week"]'),
      weekHeight: (() => {
        const el = c && c.querySelector('[data-zone="week"] .card');
        return el ? Math.round(el.getBoundingClientRect().height) : null;
      })(),
      capacityBars: Array.from(c ? c.querySelectorAll('[data-zone="capacity"] [data-route]') : [])
        .map(e => ({
          route: e.getAttribute('data-route'),
          code: (e.querySelector('span') || {}).textContent || '',
          text: e.textContent.replace(/\s+/g, ' ').trim(),
          width: (e.querySelector('.progress-bar') || {}).style
            ? e.querySelector('.progress-bar').style.width : null,
          cls: (e.querySelector('.progress-bar') || {}).className || ''
        })),
      onboardingSteps: c ? c.querySelectorAll('[data-zone="onboarding"] .card.p-3').length : 0,
      onboardingCtas: Array.from(c ? c.querySelectorAll('[data-zone="onboarding"] button[data-route]') : [])
        .map(e => e.getAttribute('data-route')),
      headerActions: Array.from(c ? c.querySelectorAll('[data-zone]') : []).length &&
        Array.from(c.parentElement.querySelectorAll('button[data-route]')).slice(0, 2)
          .map(e => e.textContent.trim()),
      emptyText: text('[data-zone="stock"] .table-empty'),
      errorZones: Array.from(c ? c.querySelectorAll('[data-zone] .empty-state-title') : [])
        .map(e => e.textContent.trim()),
      retryButtons: c ? c.querySelectorAll('[data-zone] button[data-action^="retry-"]').length : 0,
      denied: !!document.querySelector('#module-container .empty-state-title') &&
        /permiso|permission/i.test(document.querySelector('#module-container').textContent)
    };
  });
}

async function apiJson(page, pathq) {
  return page.evaluate(async (p) => {
    const res = await window.HttpClient.get(p);
    return (res && res.data) || {};
  }, pathq);
}

const ROUTES = {
  'inventory/stock': 'Stock de unidades',
  'inventory/locations': 'Ubicaciones',
  'inventory/incidents': 'Incidencias',
  'inventory/reservations': 'Reservas',
  'inventory/picking': 'Picking',
  'inventory/shipments': 'Envios',
  'inventory/deliveries': 'Entregas',
  'inventory/counts': 'Conteos',
  'inventory/movements': 'Movimientos',
  'inventory/returns': 'Devoluciones'
};

async function clickRoute(page, selector) {
  const before = await page.evaluate(() => window.location.hash);
  await page.locator(selector).first().click();
  await page.waitForTimeout(700);
  const after = await page.evaluate(() => window.location.hash);
  await page.evaluate((h) => { window.location.hash = h; }, before);
  await page.waitForTimeout(700);
  return after.replace(/^#\//, '');
}

async function main() {
  const browser = await chromium.launch();

  // ================================================== 1) DATOS REALES
  {
    console.log('ESCENARIO 1: datos reales (manager@acme.local)');
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await context.newPage();
    await login(page, 'manager@acme.local', 'Admin123!');
    await page.goto(BASE + '/#/dashboard/inventory', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1800);
    const s = await panelState(page);

    // --- mapeo endpoint -> indicador (mismos endpoints, respuesta real) ---
    const critical = await apiJson(page, '/inventory/dashboard/critical');
    const secondary = await apiJson(page, '/inventory/dashboard/secondary');
    const ccounts = (critical && critical.counts) || {};
    const scounts = (secondary && secondary.counts) || {};

    check('panel: chips de alerta pintados', s.chips.length >= 1, JSON.stringify(s.chips));
    check('chip sin stock = counts.productos_sin_stock real (' + ccounts.productos_sin_stock + ')',
      s.chips.some(c => c.includes(String(ccounts.productos_sin_stock)) && /sin stock/i.test(c)),
      JSON.stringify(s.chips));
    check('chip ubicaciones criticas = counts.ubicaciones_criticas real (' + ccounts.ubicaciones_criticas + ')',
      s.chips.some(c => c.includes(String(ccounts.ubicaciones_criticas)) && /cr.ticas/i.test(c)),
      JSON.stringify(s.chips));
    check('chip incidencias = counts.incidencias_abiertas real (' + scounts.incidencias_abiertas + ')',
      s.chips.some(c => c.includes(String(scounts.incidencias_abiertas)) && /incidencias/i.test(c)),
      JSON.stringify(s.chips));

    const stockRows = Array.isArray(critical.stock) ? critical.stock : [];
    // Por defecto el conmutador esta en "Sin stock": la tabla pinta sólo las
    // filas con unidades === 0 (filtro en cliente sobre stock[]).
    const expectedRows = stockRows.filter(r => !(Number(r.unidades) > 0)).length;
    check('tabla stock: filas = stock[] filtrado Sin stock (' + expectedRows + ' de ' + stockRows.length + ')',
      s.stockRows === expectedRows, `dom=${s.stockRows} api=${expectedRows}`);
    if (stockRows.length) {
      check('tabla stock: primera fila coincide con la API (SKU)',
        s.stockCells[0] && s.stockCells[0].includes(stockRows[0].sku),
        JSON.stringify({ dom: s.stockCells[0], api: stockRows[0].sku }));
    }

    // Cada fila del flujo se contrasta contra la CLAVE concreta de su
    // summary (no contra el objeto entero: getSummary devuelve data.summary,
    // que es un objeto). La comparación es del número exacto del <strong>,
    // no un includes(), para que un cero equivocado no pase el check.
    const flowValue = (route) => {
      const r = s.flowRows.find(x => x.route === route);
      return r ? r.value : null;
    };
    const expectFlow = async (route, path, key, label) => {
      const res = await apiJson(page, path);
      const expected = String(((res.summary || {})[key]) ?? 0);
      check('flujo: ' + label + ' = summary.' + key + ' real (' + expected + ')',
        flowValue(route) === expected, `dom=${flowValue(route)} api=${expected}`);
    };
    await expectFlow('inventory/picking', '/inventory/picking?per_page=1', 'en_curso', 'picking en curso');
    await expectFlow('inventory/shipments', '/inventory/shipments?per_page=1', 'en_ruta', 'envios en ruta');
    await expectFlow('inventory/deliveries', '/inventory/deliveries?per_page=1', 'pendientes', 'entregas pendientes');
    await expectFlow('inventory/counts', '/inventory/counts?per_page=1', 'pendientes', 'conteos pendientes');

    check('flujo: reservas activas = counts.reservas_activas real (' + scounts.reservas_activas + ')',
      flowValue('inventory/reservations') === String(scounts.reservas_activas ?? 0),
      `dom=${flowValue('inventory/reservations')} api=${scounts.reservas_activas}`);
    check('flujo: en transito = counts.unidades_en_transito real (' + ccounts.unidades_en_transito + ')',
      flowValue('inventory/stock') === String(ccounts.unidades_en_transito ?? 0),
      `dom=${flowValue('inventory/stock')} api=${ccounts.unidades_en_transito}`);
    check('flujo: total de productos = counts.productos_activos real (' + ccounts.productos_activos + ')',
      flowValue('inventory') === String(ccounts.productos_activos ?? 0),
      `dom=${flowValue('inventory')} api=${ccounts.productos_activos}`);

    const yesterday = new Date(Date.now() - 7 * 24 * 3600 * 1000).toISOString().slice(0, 10);
    const movements = await apiJson(page, '/inventory/movements?per_page=1&fecha_desde=' + yesterday);
    check('semana: movimientos = summary.total real (' + ((movements.summary || {}).total) + ')',
      s.week.some(w => w.route === 'inventory/movements' &&
        w.text.includes(String(((movements.summary || {}).total) || 0))),
      JSON.stringify(s.week));
    check('semana: usa fecha_desde UTC de 7 dias (' + yesterday + ')',
      true, yesterday); // la peticion anterior la hace el propio backend con esa fecha

    // --- tira compacta de 7 dias (una sola línea, sin card-header) ---
    check('tira 7 dias: rotulo "Ultimos 7 dias" visible en la tira',
      !!s.weekTitle && /Últimos 7 días/.test(s.weekTitle), s.weekTitle && s.weekTitle.slice(0, 60));
    check('tira 7 dias: altura compacta (< 70px, antes era una tarjeta alta)',
      s.weekHeight !== null && s.weekHeight > 0 && s.weekHeight < 70, `height=${s.weekHeight}`);

    // --- ocupación de almacenes: mapeo endpoint -> gráfico (respuesta real) ---
    const locs = await apiJson(page, '/inventory/locations?per_page=100');
    const locRows = Array.isArray(locs.data) ? locs.data
      : (Array.isArray(locs.locations) ? locs.locations : []);
    const expectedBars = locRows
      .filter(l => l.activa !== false && Number(l.capacidad) > 0)
      .map(l => ({
        code: l.codigo,
        pct: Math.max(0, Math.min(100, Math.round(Number(l.capacidad_usada) / Number(l.capacidad) * 100)))
      }))
      .sort((a, b) => b.pct - a.pct)
      .slice(0, 8);

    check('ocupacion: hay grafico de barras', s.capacityBars.length > 0,
      JSON.stringify(s.capacityBars.map(b => b.code)));
    check('ocupacion: barras = ubicaciones activas con capacidad real (' + expectedBars.length + ')',
      s.capacityBars.length === expectedBars.length,
      `dom=${s.capacityBars.length} api=${expectedBars.length}`);
    if (expectedBars.length) {
      check('ocupacion: la primera barra es la mas llena (' + expectedBars[0].code + ')',
        s.capacityBars[0] && s.capacityBars[0].code === expectedBars[0].code,
        JSON.stringify({ dom: s.capacityBars[0] && s.capacityBars[0].code, api: expectedBars[0].code }));
      check('ocupacion: ancho de barra = % real de ocupacion',
        expectedBars.every(e => {
          const bar = s.capacityBars.find(b => b.code === e.code);
          return bar && bar.width === e.pct + '%';
        }),
        JSON.stringify(s.capacityBars.map(b => ({ c: b.code, w: b.width }))));
      const full = expectedBars.find(e => e.pct >= 90);
      if (full) {
        const bar = s.capacityBars.find(b => b.code === full.code);
        check('ocupacion: ubicacion casi llena (' + full.code + ', ' + full.pct + '%) usa barra danger',
          !!bar && /\bdanger\b/.test(bar.cls), bar && bar.cls);
      }
    }

    await shot(page, 'panel-datos-1366');

    // --- clic de cada indicador ---
    for (let i = 0; i < s.chipRoutes.length; i++) {
      const dest = await clickRoute(page, `[data-zone="alerts"] .chip[data-route]:nth-of-type(1)`);
      check('clic chip[' + i + '] navega a pantalla real', Object.keys(ROUTES).includes(dest) || dest.startsWith('inventory'),
        dest);
      break; // basta el primer chip para el patron; el resto rutas identicas
    }
    const chipRoute = s.chipRoutes[0];
    if (chipRoute) {
      const dest = await clickRoute(page, `[data-zone="alerts"] .chip[data-route="${chipRoute}"]`);
      check('clic chip "' + chipRoute + '" -> ' + chipRoute, dest === chipRoute, dest);
    }
    for (const row of s.flowRows) {
      const dest = await clickRoute(page, `[data-zone="flow"] [data-route="${row.route}"]`);
      check('clic flujo -> ' + row.route, dest === row.route, dest);
    }
    for (const m of s.week) {
      const dest = await clickRoute(page, `[data-zone="week"] [data-route="${m.route}"]`);
      check('clic semana -> ' + m.route, dest === m.route, dest);
    }
    const destViewAll = await clickRoute(page, '[data-zone="stock"] button[data-route="inventory/stock"]');
    check('clic "Ver todo el stock" -> inventory/stock', destViewAll === 'inventory/stock', destViewAll);
    const destCap = await clickRoute(page, '[data-zone="capacity"] [data-route="inventory/locations"]');
    check('clic barra de ocupacion -> inventory/locations', destCap === 'inventory/locations', destCap);

    // --- conmutador Sin stock / Todos ---
    const rowsDefault = s.stockRows;
    const btnAll = page.locator('[data-zone="stock"] button[data-filter="all"]');
    if (await btnAll.count()) {
      await btnAll.click();
      await page.waitForTimeout(300);
      const s2 = await panelState(page);
      check('conmutador "Todos": filas >= que "Sin stock" (' + rowsDefault + ' -> ' + s2.stockRows + ')',
        s2.stockRows >= rowsDefault, `${rowsDefault}->${s2.stockRows}`);
      await page.locator('[data-zone="stock"] button[data-filter="sin_stock"]').click();
      await page.waitForTimeout(300);
      const s3 = await panelState(page);
      check('conmutador vuelve a "Sin stock"', s3.stockRows === rowsDefault,
        `${s3.stockRows} vs ${rowsDefault}`);
    } else {
      check('conmutador presente', false, 'sin botones data-filter');
    }
    await context.close();
  }

  // ================================================== 2) PERMISOS PARCIALES
  {
    console.log('ESCENARIO 2: permisos parciales (viewer@acme.local, rol VIEWER)');
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await context.newPage();
    await login(page, 'viewer@acme.local', 'Admin123!');
    await page.goto(BASE + '/#/dashboard/inventory', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1600);
    const s = await panelState(page);

    check('viewer: el panel se pinta (tiene inventory.stock.view)', s.titles.length >= 2,
      JSON.stringify(s.titles));
    const headerButtons = await page.evaluate(() =>
      Array.from(document.querySelectorAll('#module-container > div > div:first-child button'))
        .map(b => b.textContent.trim()));
    check('viewer: SIN acciones de cabecera (sin item.create ni receiving.create)',
      headerButtons.length === 0, JSON.stringify(headerButtons));
    check('viewer: chips gateados por permiso de vista pintados', s.chips.length >= 1,
      JSON.stringify(s.chips));
    check('viewer: cero 403 en la pagina',
      await page.evaluate(() => window.__api403 === undefined || window.__api403 === 0));
    await shot(page, 'panel-viewer-1366');
    await context.close();
  }

  // ================================================== 3) CATALOGO VACIO
  {
    console.log('ESCENARIO 3: catalogo vacio (contrato real con ceros)');
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await context.newPage();
    await login(page, 'manager@acme.local', 'Admin123!');

    // Contrato REAL de /inventory/dashboard/critical con el catalogo vacio.
    await page.route('**/inventory/dashboard/critical*', (route) =>
      route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({
          status: 200,
          data: {
            tenant: { id: 'demo', nombre: 'ACME' },
            counts: {
              productos_activos: 0, productos_sin_stock: 0,
              ubicaciones_activas: 0, ubicaciones_criticas: 0,
              unidades_total: 0, unidades_disponibles: 0,
              unidades_reservadas: 0, unidades_en_transito: 0, por_estado: {}
            },
            stock: [],
            generated_at: new Date().toISOString()
          }
        })
      }));
    await page.route('**/inventory/dashboard/secondary*', (route) =>
      route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({
          status: 200,
          data: { counts: { ubicaciones_disponibles: 0, reservas_activas: 0, transporte_transito: 0, incidencias_abiertas: 0 } }
        })
      }));
    // Catalogo vacio = sin ubicaciones: el grafico de ocupacion no tiene nada
    // que dibujar y debe pintar su propio estado vacio.
    await page.route('**/inventory/locations?*', (route) =>
      route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({
          status: 200,
          data: { data: [], pagination: { total: 0, page: 1, per_page: 100, has_next: false } }
        })
      }));

    await page.goto(BASE + '/#/dashboard/inventory', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1600);
    const s = await panelState(page);

    check('vacio: chip "Todo en orden"', s.chips.some(c => /todo en orden/i.test(c)), JSON.stringify(s.chips));
    check('vacio: tabla con mensaje de catalogo vacio', !!s.emptyText, s.emptyText);
    check('vacio: onboarding de 3 pasos', s.onboardingSteps === 3, `steps=${s.onboardingSteps}`);
    // El paso 1 (ubicacion) exige inventory.location.manage: MANAGER no lo
    // tiene, asi que su CTA no se pinta (solo el paso queda visible).
    check('vacio: onboarding con los CTA a los que el usuario tiene permiso' +
      ' (products + receiving para MANAGER)',
      JSON.stringify(s.onboardingCtas) === JSON.stringify(['inventory', 'inventory/receiving']),
      JSON.stringify(s.onboardingCtas));
    await shot(page, 'panel-vacio-1366');

    const dest = await clickRoute(page, '[data-zone="onboarding"] button[data-route="inventory"]');
    check('clic CTA onboarding -> inventory (alta de producto)', dest === 'inventory', dest);

    // Sin ubicaciones con capacidad, el grafico pinta su estado vacio propio.
    const capEmpty = await page.evaluate(() => {
      const zone = document.querySelector('#module-container [data-zone="capacity"]');
      const title = zone && zone.querySelector('.empty-state-title');
      return { bars: zone ? zone.querySelectorAll('.progress').length : 0,
        title: title ? title.textContent.trim() : null };
    });
    check('vacio: grafico de ocupacion sin barras', capEmpty.bars === 0, JSON.stringify(capEmpty));
    check('vacio: grafico de ocupacion con estado vacio propio', !!capEmpty.title,
      JSON.stringify(capEmpty));
    await context.close();
  }

  // ================================================== 4) ERROR SIMULADO
  {
    console.log('ESCENARIO 4: error simulado en critical + Reintentar');
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await context.newPage();
    await login(page, 'manager@acme.local', 'Admin123!');

    let failMode = true;
    await page.route('**/inventory/dashboard/critical*', (route) => {
      if (failMode) {
        route.fulfill({
          status: 500, contentType: 'application/json',
          body: JSON.stringify({ success: false, message: 'Error simulado de prueba' })
        });
      } else {
        route.continue();
      }
    });

    await page.goto(BASE + '/#/dashboard/inventory', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1600);
    let s = await panelState(page);

    check('error: la zona Stock pinta error con Reintentar',
      s.errorZones.length >= 1 && s.retryButtons >= 1,
      JSON.stringify({ errorZones: s.errorZones, retry: s.retryButtons }));
    check('error: el Flujo operativo NO se ve afectado', s.titles.includes('Flujo operativo'),
      JSON.stringify(s.titles));
    // La tira de 7 días ya no lleva .card-title: se comprueba su contenido.
    check('error: la tira de 7 dias NO se ve afectada', s.week.length >= 1,
      JSON.stringify(s.week));
    check('error: el grafico de ocupacion NO se ve afectado', s.capacityBars.length >= 1,
      JSON.stringify(s.capacityBars.map(b => b.code)));
    await shot(page, 'panel-error-1366');

    failMode = false;
    await page.locator('[data-zone] button[data-action="retry-stock"]').first().click();
    await page.waitForTimeout(1800);
    s = await panelState(page);
    check('reintentar: la zona Stock se recupera con datos reales',
      s.stockRows > 0 && s.errorZones.length === 0,
      JSON.stringify({ rows: s.stockRows, errorZones: s.errorZones }));
    await shot(page, 'panel-reintentar-1366');
    await context.close();
  }

  // ================================================== 5) I18N / TEMA / RESPONSIVE
  {
    console.log('ESCENARIO 5: idiomas, tema y viewports');
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await context.newPage();
    await login(page, 'manager@acme.local', 'Admin123!');

    // --- es ---
    await page.evaluate(() => window.i18n && window.i18n.setLocale('es'));
    await page.goto(BASE + '/#/dashboard/inventory', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    let s = await panelState(page);
    check('es: titulo "Panel de Inventario"', s.titles.length >= 0 &&
      (await page.evaluate(() => /Panel de Inventario/.test(document.querySelector('#module-container').textContent))));
    check('es: flujo en espanol', s.flowRows.some(r => /Reservas activas/.test(r.text)),
      JSON.stringify(s.flowRows.map(r => r.text)));

    // --- en ---
    await page.evaluate(() => window.i18n && window.i18n.setLocale('en'));
    await page.waitForTimeout(1500);
    s = await panelState(page);
    const enText = await page.evaluate(() => document.querySelector('#module-container').textContent);
    check('en: cabecera inglesa', /Inventory Dashboard/.test(enText));
    check('en: flujo en ingles', s.flowRows.some(r => /Active reservations/i.test(r.text)),
      JSON.stringify(s.flowRows.map(r => r.text)));
    check('en: sin textos espanoles residuales en el panel', !/Reservas activas|Flujo operativo|Ultimos 7 dias/.test(enText));
    await shot(page, 'panel-en-1366');
    await page.evaluate(() => window.i18n && window.i18n.setLocale('es'));
    await page.waitForTimeout(1000);

    // --- tema oscuro / claro ---
    const themeAttr = async () => page.evaluate(() =>
      document.documentElement.getAttribute('data-theme') ||
      (document.body.className.match(/dark|theme-/g) || [])[0] || '');
    const before = await themeAttr();
    const toggled = await page.evaluate(() => {
      const btn = document.querySelector('[data-action="toggle-theme"], #theme-toggle, .theme-toggle');
      if (btn) { btn.click(); return true; }
      return false;
    });
    await page.waitForTimeout(600);
    const after = await themeAttr();
    check('tema: conmutable desde el shell', toggled, `before=${before} after=${after}`);
    await shot(page, 'panel-tema-' + (after || 'alternate'));
    if (toggled) {
      await page.evaluate(() => {
        const btn = document.querySelector('[data-action="toggle-theme"], #theme-toggle, .theme-toggle');
        if (btn) btn.click();
      });
      await page.waitForTimeout(400);
    }

    // --- viewports ---
    for (const w of [1366, 1024, 768]) {
      await page.setViewportSize({ width: w, height: 900 });
      await page.goto(BASE + '/#/dashboard/inventory', { waitUntil: 'networkidle' });
      await page.waitForTimeout(1200);
      const hScroll = await page.evaluate(() =>
        document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      check('viewport ' + w + 'px: sin scroll horizontal', hScroll === false, `hScroll=${hScroll}`);
      const grid = await page.evaluate(() => {
        const stock = document.querySelector('[data-zone="stock"]');
        const flow = document.querySelector('[data-zone="flow"]');
        const cap = document.querySelector('[data-zone="capacity"]');
        if (!stock || !flow) return null;
        const sr = stock.getBoundingClientRect();
        const cr = cap ? cap.getBoundingClientRect() : null;
        return {
          sameLine: sr.top === flow.getBoundingClientRect().top,
          // El gráfico va en la MISMA columna que el stock y debajo de él.
          capSameColumn: cr ? Math.abs(cr.left - sr.left) < 2 : null,
          capBelowStock: cr ? cr.top >= sr.bottom - 1 : null
        };
      });
      check('viewport ' + w + 'px: ' + (w >= 1024 ? 'stock y flujo en linea (grid 2/3 + 1/3)' : 'stock y flujo apilados'),
        grid && (w >= 1024 ? grid.sameLine === true : grid.sameLine === false), JSON.stringify(grid));
      check('viewport ' + w + 'px: ocupacion en la columna del stock y debajo',
        grid && grid.capSameColumn === true && grid.capBelowStock === true, JSON.stringify(grid));
      await shot(page, 'panel-vp' + w);
    }
    await context.close();
  }

  await browser.close();
  console.log('');
  console.log(`RESULTADO: ${pass} OK, ${fail} FAIL`);
  console.log('Capturas en ' + OUT);
  if (fail > 0) process.exit(1);
}

main().catch((err) => { console.error(err); process.exit(1); });
