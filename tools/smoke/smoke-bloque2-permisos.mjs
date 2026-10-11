/**
 * Smoke jsdom para BLOQUE 2 (v1.13.13): si el usuario no tiene un permiso,
 * la interfaz no muestra el elemento y NO dispara la peticion a la API.
 * Uso: node tools/smoke/smoke-bloque2-permisos.mjs
 *
 * Cobertura:
 *  B2-1 Dashboard: sin tenants.tenant.view no llama /tenant/stats
 *  B2-2 Dashboard: sin inventory.stock.view no llama /inventory/dashboard/*
 *  B2-3 Dashboard: sin item/location view no llama /inventory/products|locations
 *  B2-4 Products: sin inventory.item.view no llama /inventory/products y pinta denied
 *  B2-5 Locations: sin inventory.location.view no llama /inventory/locations y pinta denied
 *  B2-6 Stock: sin inventory.stock.view no llama /inventory/stock y pinta denied
 *  B2-7 Receiving: sin inventory.receiving.create no llama catalogs y pinta denied
 *  B2-8 Sidebar: oculta items sin permiso (users sin users.user.view)
 *  B2-9 Products con item.view pero sin stock.view: lista OK, no llama /inventory/stock
 *  B2-10 Locaciones con location.view pero sin stock.view: lista OK, no muestra boton unidades
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  dashboard: read('modules/dashboard/dashboard.js'),
  products: read('modules/inventory/products.js'),
  locations: read('modules/inventory/locations.js'),
  stock: read('modules/inventory/stock.js'),
  receiving: read('modules/inventory/receiving.js'),
  sidebar: read('modules/dashboard/sidebar.js'),
  i18n: read('core/i18n.js'),
  eventBus: read('core/event-bus.js')
};

const UI_FILES = ['ui/dom.js', 'ui/render.js', 'ui/view.js', 'ui/modal.js', 'ui/actions.js'].map(read);

let pass = 0;
let fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${extra ? ' :: ' + extra : ''}`); }
}
async function tick(n = 10) {
  for (let i = 0; i < n; i++) await new Promise(r => setTimeout(r, 0));
}

const MANAGER_PERMS = [
  'inventory.item.view', 'inventory.item.create', 'inventory.item.edit', 'inventory.item.delete',
  'inventory.location.view', 'inventory.location.manage',
  'inventory.stock.view', 'inventory.stock.block',
  'inventory.movement.view', 'inventory.movement.create',
  'inventory.reservation.view', 'inventory.reservation.create', 'inventory.reservation.cancel',
  'inventory.picking.view', 'inventory.picking.execute',
  'inventory.shipping.view', 'inventory.delivery.view', 'inventory.return.view',
  'inventory.incident.view', 'inventory.incident.report', 'inventory.incident.investigate',
  'inventory.count.view', 'inventory.count.create', 'inventory.count.execute',
  'inventory.receiving.create'
  // NO: tenants.tenant.view, users.user.view, roles.role.view
];

function createEnv({ perms, roles }) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body>' +
    '<div id="sidebar"></div>' +
    '<div id="module-container"></div>' +
    '</body></html>',
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;
  const calls = { gets: [] };

  Object.defineProperty(w.Element.prototype, 'scrollIntoView', { value: function() {}, configurable: true });
  w.console = { log() {}, info() {}, debug() {}, warn() {}, error() {} };
  w.AppConfig = { apiBase: '/api', baseUrl: '/xp/public', appName: 'XP Platform' };
  w.EventBus = { on() {}, off() {}, emit() {} };
  w.Storage = { get: () => false, set() {}, remove() {}, getUser: () => null, getToken: () => 'x' };
  w.AuthState = {
    getUser: () => ({ nombre: 'Test', apellido: 'User', email: 'test@acme.local', roles: roles.map(nombre => ({ nombre, permisos: perms })) }),
    getPermissions: () => perms.slice(),
    getRoles: () => roles.slice(),
    hasPermission: (p) => perms.includes(p),
    isAuthenticated: () => true,
    logout() {}
  };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push(p);
      if (p === '/tenant/stats') {
        if (!perms.includes('tenants.tenant.view')) {
          throw Object.assign(new Error('403'), { status: 403, data: null });
        }
        return { status: 200, data: { stats: { productos: 5, ubicaciones: 3, usuarios_activos: 2, unidades_fisicas: 10 } } };
      }
      if (p === '/inventory/dashboard/critical') {
        if (!perms.includes('inventory.stock.view')) {
          throw Object.assign(new Error('403'), { status: 403, data: null });
        }
        return { status: 200, data: { counts: {}, stock: [], tenant: { nombre: 'ACME' } } };
      }
      if (p === '/inventory/dashboard/secondary') {
        if (!perms.includes('inventory.stock.view')) {
          throw Object.assign(new Error('403'), { status: 403, data: null });
        }
        return { status: 200, data: {} };
      }
      if (p === '/inventory/products') {
        if (!perms.includes('inventory.item.view')) {
          throw Object.assign(new Error('403'), { status: 403, data: null });
        }
        return { status: 200, data: { data: [{ id: 'p1', sku: 'SKU-1', nombre: 'Prod', unidad_medida: 'UNIDAD', activo: true, created_at: '2026-01-01' }], pagination: { total: 1, page: 1, per_page: 20, has_next: false } } };
      }
      if (p === '/inventory/locations') {
        if (!perms.includes('inventory.location.view')) {
          throw Object.assign(new Error('403'), { status: 403, data: null });
        }
        return { status: 200, data: { data: [{ id: 'l1', codigo: 'A-01', nombre: 'Pasillo', tipo: 'PASILLO', activa: true, created_at: '2026-01-01', capacidad_maxima: 100, capacidad_ocupada: 10 }], pagination: { total: 1, page: 1, per_page: 20, has_next: false } } };
      }
      if (p === '/inventory/stock') {
        if (!perms.includes('inventory.stock.view')) {
          throw Object.assign(new Error('403'), { status: 403, data: null });
        }
        return { status: 200, data: { data: [], summary: { unidades: 0 }, pagination: { total: 0, page: 1, per_page: 20, has_next: false } } };
      }
      if (p === '/inventory/receive') {
        throw Object.assign(new Error('404'), { status: 404, data: null });
      }
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    },
    async post() { return { status: 201, data: {} }; },
    async patch() { return { status: 200, data: {} }; },
    async delete() { return { status: 200, data: {} }; }
  };

  const evalIn = (code) => w.eval(code);
  evalIn(FILES.i18n);
  w.i18n.inject('es', ES_DICT);
  UI_FILES.forEach(evalIn);
  evalIn(FILES.eventBus);

  return { w, calls, container: w.document.getElementById('module-container'), sidebarEl: w.document.getElementById('sidebar'), evalIn, dom };
}

// =====================================================================
// B2-1..3 DASHBOARD
// =====================================================================
async function s_dashboard() {
  console.log('B2 dashboard: guards de /tenant/stats y capas');
  {
    // Manager SIN tenants.tenant.view: no debe llamar /tenant/stats
    const env = createEnv({ perms: MANAGER_PERMS, roles: ['MANAGER'] });
    env.evalIn(FILES.dashboard);
    env.w.DashboardDashboardModule.init({}, env.container);
    await tick();
    check('B2-1 no llama /tenant/stats sin tenants.tenant.view',
      !env.calls.gets.includes('/tenant/stats'),
      'gets=' + JSON.stringify(env.calls.gets));
    check('B2-2 llama capas con inventory.stock.view',
      env.calls.gets.includes('/inventory/dashboard/critical'));
    check('B2-3 llama inventory fallback con item+location view',
      env.calls.gets.includes('/inventory/products') && env.calls.gets.includes('/inventory/locations'));
    env.dom.window.close();
  }
  {
    // Sin stock.view: no capas
    const perms = MANAGER_PERMS.filter(p => p !== 'inventory.stock.view');
    const env = createEnv({ perms, roles: ['MANAGER'] });
    env.evalIn(FILES.dashboard);
    env.w.DashboardDashboardModule.init({}, env.container);
    await tick();
    check('B2-2b no llama dashboard/critical sin inventory.stock.view',
      !env.calls.gets.includes('/inventory/dashboard/critical'),
      'gets=' + JSON.stringify(env.calls.gets));
    env.dom.window.close();
  }
  {
    // Sin item.view ni location.view: no fallback inventario
    const perms = MANAGER_PERMS.filter(p =>
      p !== 'inventory.item.view' && p !== 'inventory.location.view' && p !== 'tenants.tenant.view');
    const env = createEnv({ perms, roles: ['VIEWER'] });
    env.evalIn(FILES.dashboard);
    env.w.DashboardDashboardModule.init({}, env.container);
    await tick();
    check('B2-3b no llama products/locations sin item/location view',
      !env.calls.gets.includes('/inventory/products') && !env.calls.gets.includes('/inventory/locations'),
      'gets=' + JSON.stringify(env.calls.gets));
    env.dom.window.close();
  }
  {
    // Admin con tenants.tenant.view: sí llama /tenant/stats
    const env = createEnv({ perms: [...MANAGER_PERMS, 'tenants.tenant.view', 'users.user.view'], roles: ['ADMIN'] });
    env.evalIn(FILES.dashboard);
    env.w.DashboardDashboardModule.init({}, env.container);
    await tick();
    check('B2-1b admin sí llama /tenant/stats',
      env.calls.gets.includes('/tenant/stats'));
    env.dom.window.close();
  }
}

// =====================================================================
// B2-4..7 MÓDULOS SIN PERMISO → DENIED SIN PETICIÓN
// =====================================================================
async function s_modules_denied() {
  console.log('B2 modules: denied sin petición');
  {
    // Permisos cargados pero SIN inventory.item.view (sin fallback a roles).
    const env = createEnv({ perms: ['audit.log.view'], roles: [] });
    env.evalIn(FILES.products);
    env.w.InventoryProductsModule.init({}, env.container);
    await tick();
    check('B2-4 products sin item.view: denied y sin GET',
      env.container.innerHTML.includes('Sin permiso') && !env.calls.gets.includes('/inventory/products'),
      'gets=' + JSON.stringify(env.calls.gets));
    env.w.InventoryProductsModule.onActivate({});
    await tick();
    check('B2-4b products onActivate sin item.view: sin GET',
      !env.calls.gets.includes('/inventory/products'));
    env.dom.window.close();
  }
  {
    const env = createEnv({ perms: ['audit.log.view'], roles: [] });
    env.evalIn(FILES.locations);
    env.w.InventoryLocationsModule.init({}, env.container);
    await tick();
    check('B2-5 locations sin location.view: denied y sin GET',
      env.container.innerHTML.includes('Sin permiso') && !env.calls.gets.includes('/inventory/locations'),
      'gets=' + JSON.stringify(env.calls.gets));
    env.dom.window.close();
  }
  {
    const env = createEnv({ perms: ['audit.log.view'], roles: [] });
    env.evalIn(FILES.stock);
    env.w.InventoryStockModule.init({}, env.container);
    await tick();
    check('B2-6 stock sin stock.view: denied y sin GET',
      env.container.innerHTML.includes('Sin permiso') && !env.calls.gets.includes('/inventory/stock'),
      'gets=' + JSON.stringify(env.calls.gets));
    env.dom.window.close();
  }
  {
    const env = createEnv({ perms: [], roles: ['VIEWER'] });
    env.evalIn(FILES.receiving);
    env.w.InventoryReceivingModule.init({}, env.container);
    await tick();
    check('B2-7 receiving sin receiving.create: denied y sin GET',
      env.container.innerHTML.includes('Sin permiso') && env.calls.gets.length === 0,
      'gets=' + JSON.stringify(env.calls.gets));
    env.dom.window.close();
  }
}

// =====================================================================
// B2-8 SIDEBAR
// =====================================================================
async function s_sidebar() {
  console.log('B2 sidebar: items ocultos sin permiso');
  const env = createEnv({ perms: MANAGER_PERMS, roles: ['MANAGER'] });
  env.evalIn(FILES.sidebar);
  env.w.AppSidebar.init();
  await tick();
  const html = env.sidebarEl.innerHTML;
  check('B2-8a oculta users sin users.user.view',
    !html.includes('data-route="users"'));
  check('B2-8b oculta roles sin roles.role.view',
    !html.includes('data-route="roles"'));
  check('B2-8c muestra products con inventory.item.view',
    html.includes('data-route="inventory"'));
  check('B2-8d muestra stock con inventory.stock.view',
    html.includes('data-route="inventory/stock"'));
  env.dom.window.close();
}

// =====================================================================
// B2-9..10 CON PERMISO PARCIAL
// =====================================================================
async function s_partial() {
  console.log('B2 partial: lista sin stock.view');
  {
    const perms = MANAGER_PERMS.filter(p => p !== 'inventory.stock.view');
    const env = createEnv({ perms, roles: ['MANAGER'] });
    env.evalIn(FILES.products);
    env.w.InventoryProductsModule.init({}, env.container);
    await tick();
    check('B2-9 products con item.view sin stock.view: lista OK',
      env.calls.gets.includes('/inventory/products') && env.container.innerHTML.includes('SKU-1'));
    check('B2-9b no llama /inventory/stock en products',
      !env.calls.gets.includes('/inventory/stock'),
      'gets=' + JSON.stringify(env.calls.gets));
    check('B2-9c no muestra boton stock',
      !env.container.innerHTML.includes('data-action="stock"'));
    env.dom.window.close();
  }
  {
    const perms = MANAGER_PERMS.filter(p => p !== 'inventory.stock.view');
    const env = createEnv({ perms, roles: ['MANAGER'] });
    env.evalIn(FILES.locations);
    env.w.InventoryLocationsModule.init({}, env.container);
    await tick();
    check('B2-10 locations con location.view sin stock.view: lista OK',
      env.calls.gets.includes('/inventory/locations') && env.container.innerHTML.includes('A-01'));
    check('B2-10b no muestra boton unidades',
      !env.container.innerHTML.includes('data-action="units"'));
    env.dom.window.close();
  }
}

// =====================================================================
async function main() {
  console.log('BLOQUE 2 smoke: permisos en la interfaz');
  await s_dashboard();
  await s_modules_denied();
  await s_sidebar();
  await s_partial();
  console.log(`\nResultado: ${pass} ok, ${fail} fail`);
  if (fail > 0) process.exit(1);
}

main().catch(err => { console.error(err); process.exit(1); });
