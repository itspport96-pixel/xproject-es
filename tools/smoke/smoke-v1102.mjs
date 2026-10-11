/**
 * Smoke jsdom GUI de idioma v1.10.2 (ajustado en v1.10.3).
 *
 * Cubre: carga pre-sesion sin GET (evita el 401 con efectos secundarios),
 * descarga del diccionario tras el login, selector en el menu de usuario,
 * cambio de idioma sin recarga (shell + sidebar + pantalla activa via
 * router.refresh), persistencia en localStorage['lang'], prioridad
 * usuario > localStorage > default y el PATCH /users/me/preferences que
 * v1.10.3 emite al cambiar de idioma CON sesion (sin sesion no se llama).
 * El flujo de la secuencia en + /auth/me vive en smoke-v1103.mjs.
 *
 * Uso: node tools/smoke/smoke-v1102.mjs
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const PUB = 'C:/xampp/htdocs/xp/public/js';
const LOCALES = 'C:/xampp/htdocs/xp/resources/locales';
const read = (p) => readFileSync(path.join(PUB, p), 'utf8');
const DICT = {
  es: JSON.parse(readFileSync(path.join(LOCALES, 'es.json'), 'utf8')),
  en: JSON.parse(readFileSync(path.join(LOCALES, 'en.json'), 'utf8')),
  pt: JSON.parse(readFileSync(path.join(LOCALES, 'pt.json'), 'utf8')),
  fr: JSON.parse(readFileSync(path.join(LOCALES, 'fr.json'), 'utf8'))
};

const FILES = {
  i18n: read('core/i18n.js'),
  router: read('core/router.js'),
  state: read('modules/auth/state.js'),
  sidebar: read('modules/dashboard/sidebar.js')
};
const UI_FILES = ['ui/dom.js', 'ui/render.js', 'ui/view.js', 'ui/modal.js', 'ui/actions.js'].map(read);

let pass = 0;
let fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${extra ? ' :: ' + extra : ''}`); }
}
async function tick(n = 8) {
  for (let i = 0; i < n; i++) await new Promise(r => setTimeout(r, 0));
}

// =====================================================================
// FIXTURES
// =====================================================================

const ADMIN_PERMS = [
  'audit.log.view', 'inventory.item.view', 'inventory.stock.view', 'inventory.location.view',
  'inventory.movement.create', 'inventory.reservation.view', 'inventory.reservation.create',
  'inventory.reservation.cancel', 'users.user.view', 'inventory.receiving.view'
];

const LOCATIONS = [
  { id: 'loc1', codigo: 'HOUSEA23', tipo: 'ALMACENAMIENTO', capacidad: 20, capacidad_usada: 5, activa: true },
  { id: 'loc2', codigo: 'LLENA01', tipo: 'ALMACENAMIENTO', capacidad: 10, capacidad_usada: 10, activa: true },
  { id: 'loc3', codigo: '312-EDIT', tipo: 'ALMACENAMIENTO', capacidad: 30, capacidad_usada: 2, activa: true }
];

const UNITS = [
  {
    unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111', producto_id: 'p1', sku: 'PROD2000',
    producto_nombre: 'Producto Demo', ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23',
    estado: 'DISPONIBLE', cantidad_fisica: 5, cantidad_reservada: 0, cantidad_en_transito: 0, cantidad_disponible: 5
  },
  {
    unidad_fisica_id: 'u2', codigo_barcode: 'SU-BBBBBB-22222222', producto_id: 'p1', sku: 'PROD2000',
    producto_nombre: 'Producto Demo', ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23',
    estado: 'RESERVADO', cantidad_fisica: 3, cantidad_reservada: 3, cantidad_en_transito: 0, cantidad_disponible: 0
  }
];

const STOCK_PAYLOAD = {
  status: 200,
  data: {
    summary: { unidades: 2, cantidad_fisica: 8, cantidad_reservada: 3, cantidad_en_transito: 0, cantidad_disponible: 5 },
    data: UNITS,
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

const AUDIT_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 1, exitosos: 1, fallidos: 0 },
    data: [{
      id: 'a1', tenant_id: 't1', usuario_id: 'user-1', operacion: 'GET /api/inventory/stock', modulo: 'inventory',
      recurso: null, entidad_id: null, tipo_cambio: 'READ',
      valores_anteriores: null, valores_nuevos: null,
      resultado: 'SUCCESS', razon_fallo: null, ip_address: '127.0.0.1', user_agent: 'test',
      request_id: 'req-1', timestamp: '2026-10-01 21:00:00.123456', duracion_operacion: 3,
      detalles_adicionales: null, prev_hash: 'prev1', hash_integridad: 'hash1'
    }],
    pagination: { page: 1, per_page: 25, total: 1, last_page: 1 }
  }
};

const TENANT_STATS = {
  status: 200,
  data: {
    stats: {
      productos: 12, ubicaciones: 5, usuarios_activos: 3, unidades_fisicas: 40,
      productos_sin_stock: 1, ubicaciones_criticas: 2, unidades_total: 40,
      ubicaciones_disponibles: 3, reservas_activas: 2, transporte_transito: 1, incidencias_abiertas: 0
    }
  }
};

const LIST_PAYLOAD = (rows) => ({
  status: 200,
  data: { data: rows, pagination: { total: rows.length, page: 1, per_page: 100, has_next: false } }
});

const USER = {
  id: 'user-1', tenant_id: 't1', nombre: 'Ana', apellido: 'Admin', email: 'ana@acme.local',
  roles: [{ nombre: 'ADMIN', permisos: ADMIN_PERMS }]
};

// AppRouter.isAuthenticated() parsea el JWT (3 partes + exp): los tokens del
// smoke son JWTs de mentira con exp en el futuro (4102444800 = 2100-01-01).
const JWT = 'eyJhbGciOiJIUzI1NiJ9.' + btoa('{"exp":4102444800}') + '.sig';

// =====================================================================
// ENTORNO
// =====================================================================

const MODULE_FILES = {
  'dashboard/dashboard': { file: 'modules/dashboard/dashboard.js', name: 'DashboardDashboardModule' },
  'inventory/stock': { file: 'modules/inventory/stock.js', name: 'InventoryStockModule' },
  'audit/logs': { file: 'modules/audit/logs.js', name: 'AuditLogsModule' },
  // Sólo para que AppRouter.isModuleAvailable() sea true (el sidebar y los
  // accesos rápidos filtran por módulos registrados); el smoke navega sólo
  // a dashboard/stock/audit.
  'inventory/products': { availableOnly: true },
  'inventory/locations': { availableOnly: true },
  'inventory/receiving': { availableOnly: true },
  'inventory/movements': { availableOnly: true },
  'inventory/reservations': { availableOnly: true },
  'inventory/picking': { availableOnly: true },
  'inventory/shipments': { availableOnly: true },
  'inventory/deliveries': { availableOnly: true },
  'inventory/returns': { availableOnly: true },
  'inventory/incidents': { availableOnly: true },
  'inventory/counts': { availableOnly: true },
  'users/users': { availableOnly: true },
  'roles/roles': { availableOnly: true },
  'groups/groups': { availableOnly: true },
  'settings/settings': { availableOnly: true }
};

function createEnv({ token = null, storedLang = null } = {}) {  const dom = new JSDOM(
    `<!DOCTYPE html><html><body>
      <div id="app" class="app-layout">
        <aside id="sidebar" class="sidebar hidden"></aside>
        <div id="sidebar-overlay"></div>
        <div class="main-content">
          <header class="app-header">
            <button id="sidebar-toggle" aria-label="Alternar menu" data-i18n-aria="shell.toggle_menu"></button>
            <span id="user-name">Invitado</span>
            <label data-i18n="shell.language">Idioma</label>
            <select id="lang-select">
              <option value="es">Espanol</option>
              <option value="en">English</option>
              <option value="pt">Portugues</option>
              <option value="fr">Francais</option>
            </select>
            <a href="#" id="logout-btn" data-i18n="shell.logout">Cerrar sesion</a>
          </header>
          <main id="main-content"><div id="module-container"></div></main>
        </div>
      </div>
    </body></html>`,
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], posts: [], patches: [], toasts: [], errors: [], emits: [] };
  const listeners = {};

  Object.defineProperty(w.Element.prototype, 'scrollIntoView', { value: function() {}, configurable: true });
  w.console = {
    log() {}, info() {}, debug() {}, warn(...a) { calls.errors.push(a.map(String).join(' ')); },
    error(...a) { calls.errors.push(a.map(String).join(' ')); }
  };
  Object.defineProperty(w, 'crypto', {
    configurable: true, writable: true,
    value: { randomUUID: (() => { let n = 0; return () => `00000000-0000-4000-8000-${String(++n).padStart(12, '0')}`; })() }
  });

  w.AppConfig = { apiBase: '/api', baseUrl: '/xp/public', appName: 'XP Platform', sessionTimeout: 900 };

  if (storedLang) w.localStorage.setItem('lang', storedLang);

  // Storage (fachada sobre memoria; los tests pre-setean token/idioma)
  const store = {
    token: token ? JWT : null,
    refresh: token ? 'refresh-token' : null,
    user: null,
    tenant: null
  };
  w.Storage = {
    getToken: () => store.token,
    setToken: (v) => { store.token = v; },
    getRefreshToken: () => store.refresh,
    setRefreshToken: (v) => { store.refresh = v; },
    getUser: () => store.user,
    setUser: (v) => { store.user = v; },
    getTenantId: () => store.tenant,
    setTenantId: (v) => { store.tenant = v; },
    clearAuth: () => { store.token = null; store.refresh = null; store.user = null; }
  };

  // EventBus con registro real de listeners (sidebar/router/i18n dependen)
  w.EventBus = {
    on(evt, fn) { (listeners[evt] = listeners[evt] || []).push(fn); },
    off(evt, fn) {
      if (listeners[evt]) listeners[evt] = listeners[evt].filter(f => f !== fn);
    },
    emit(evt, data) {
      calls.emits.push(evt);
      if (evt === 'toast:show') calls.toasts.push(data);
      (listeners[evt] || []).slice().forEach(fn => {
        try { fn(data); } catch (e) { calls.errors.push('listener ' + evt + ': ' + e.message); }
      });
    }
  };

  const env = { w, calls, dom, store };

  // HttpClient stub: /locales depende de la sesion (como el endpoint real)
  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (p.startsWith('/locales/')) {
        if (!store.token) {
          const err = new Error('Unauthorized');
          err.status = 401;
          throw err;
        }
        const loc = p.slice('/locales/'.length).replace(/\.json$/, '');
        if (!DICT[loc]) {
          const err = new Error('Not Found');
          err.status = 404;
          throw err;
        }
        return { status: 200, data: { locale: loc, version: 'smoke-' + loc, dictionary: DICT[loc] } };
      }
      if (p === '/tenant/stats') return TENANT_STATS;
      if (p === '/inventory/dashboard/critical') return { status: 200, data: null };
      if (p === '/inventory/dashboard/secondary') return { status: 200, data: null };
      if (p === '/inventory/stock') return STOCK_PAYLOAD;
      if (p === '/users') return LIST_PAYLOAD([]);
      if (p === '/inventory/locations') return LIST_PAYLOAD(LOCATIONS);
      if (p === '/inventory/products') return LIST_PAYLOAD([]);
      if (p === '/audit/logs') return AUDIT_PAYLOAD;
      if (p === '/auth/me') {
        const u = store.user || USER;
        return { status: 200, data: { user: u, roles: u.roles, tenant_id: u.tenant_id } };
      }
      const err = new Error('GET no soportado: ' + p);
      err.status = 404;
      throw err;
    },
    async post(p, body, options = {}) {
      calls.posts.push({ path: p, body, options });
      if (p === '/auth/logout') return { status: 200, data: {} };
      const err = new Error('POST no soportado: ' + p);
      err.status = 404;
      throw err;
    },
    async patch(p, body, options = {}) {
      calls.patches.push({ path: p, body, options });
      if (p === '/users/me/preferences') return { status: 200, data: { preferencias: body || {} } };
      const err = new Error('PATCH no soportado: ' + p);
      err.status = 404;
      throw err;
    }
  };

  const loaded = {};
  w.ModuleLoader = {
    has: (n) => !!MODULE_FILES[n],
    isLoaded: (n) => !!loaded[n],
    getInstance: (n) => (MODULE_FILES[n] ? w[MODULE_FILES[n].name] : null),
    async load(n, containerId, params) {
      const def = MODULE_FILES[n];
      if (!def) throw new Error('modulo no registrado: ' + n);
      if (def.availableOnly) throw new Error('modulo no cubierto por el smoke: ' + n);
      if (!loaded[n]) {
        evalIn(read(def.file));
        loaded[n] = true;
      }
      const inst = w[def.name];
      if (!inst || typeof inst.init !== 'function') throw new Error('init inexistente: ' + n);
      inst.init(params || {}, w.document.getElementById(containerId));
    },
    unload(n) {
      if (!loaded[n]) return;
      const def = MODULE_FILES[n];
      const inst = w[def.name];
      if (inst && typeof inst.destroy === 'function') {
        try { inst.destroy(); } catch (e) { /* destruccion best-effort */ }
      }
      // El loader real vuelve a inyectar el script al cargar: re-evaluar el
      // fichero refresca los templates interpolados con t() en el nuevo idioma.
      loaded[n] = false;
    }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  env.evalUi = () => UI_FILES.forEach(evalIn);
  env.container = w.document.getElementById('module-container');
  env.label = () => w.document.querySelector('[data-i18n="shell.language"]').textContent;
  env.select = () => w.document.getElementById('lang-select');
  env.setLang = (loc) => {
    const sel = env.select();
    sel.value = loc;
    sel.dispatchEvent(new w.Event('change', { bubbles: true }));
  };
  env.emitCount = (evt) => calls.emits.filter(e => e === evt).length;
  env.localeGets = () => calls.gets.filter(g => g.path.startsWith('/locales/'));
  env.sidebarText = () => w.document.getElementById('sidebar').textContent;
  env.close = () => dom.window.close();

  // Runtime i18n + estado real de auth (mismo orden que index.php)
  evalIn(FILES.i18n);
  env.evalUi();
  evalIn(FILES.state);

  return env;
}

// =====================================================================
// S1+S2+S3+S4 - ciclo real: precarga, login, selector y pantallas
// =====================================================================
async function s1_s4_ciclo() {
  console.log('S1 carga inicial pre-sesion (sin GET de locales)');
  const env = createEnv();
  const w = env.w;

  await w.i18n.init();
  await tick();

  check('sin sesion NO se pide el diccionario (cero GET /locales)',
    env.localeGets().length === 0, JSON.stringify(env.localeGets()));
  check('selector presente con 4 idiomas', [...env.select().options].map(o => o.value).join(',') === 'es,en,pt,fr');
  check('idioma actual marcado en el selector', env.select().value === 'es', env.select().value);
  check('shell pintado con builtin (label Idioma)', env.label() === 'Idioma', env.label());
  check('lang attribute es', w.document.documentElement.getAttribute('lang') === 'es');
  check('clave de modulo sin diccionario se devuelve en crudo (builtin parcial)',
    w.t('dashboard.cards.quick_access') === 'dashboard.cards.quick_access');

  console.log('S2 tras el login se descarga el diccionario completo');
  w.AuthState.init();
  w.AuthState.setSession(USER, { access_token: JWT, refresh_token: 'rk-1' });
  await tick();

  const esGets = env.localeGets();
  check('auth:login dispara la descarga de es.json', esGets.length === 1 && esGets[0].path === '/locales/es.json',
    JSON.stringify(esGets));
  check('clave de modulo resuelta tras el login',
    w.t('dashboard.cards.quick_access') === 'Accesos rápidos',
    w.t('dashboard.cards.quick_access'));
  check('header muestra el nombre del usuario', w.document.getElementById('user-name').textContent === 'Ana Admin',
    w.document.getElementById('user-name').textContent);

  console.log('S3 dashboard: cambio de idioma sin recarga (shell + sidebar + pantalla)');
  w.location.hash = '#/dashboard';
  env.evalIn(FILES.router);
  env.evalIn(FILES.sidebar);
  w.AppSidebar.init();
  w.AppRouter.init();
  await tick(14);

  const container = env.container;
  check('dashboard renderizado en es', /Accesos rápidos/.test(container.textContent),
    container.textContent.slice(0, 120));
  check('sidebar en es (Auditoria)', /Auditoría/.test(env.sidebarText()), env.sidebarText().slice(0, 160));
  check('label del shell en es', env.label() === 'Idioma', env.label());
  const emitsBefore = env.emitCount('i18n:changed');
  const hrefBefore = w.location.href;

  env.setLang('en');
  await tick(14);

  check('guarda localStorage[lang]=en', w.localStorage.getItem('lang') === 'en', w.localStorage.getItem('lang'));
  check('descarga el diccionario en',
    env.localeGets().some(g => g.path === '/locales/en.json'), JSON.stringify(env.localeGets()));
  check('emite un solo i18n:changed', env.emitCount('i18n:changed') - emitsBefore === 1,
    String(env.emitCount('i18n:changed') - emitsBefore));
  check('shell repintado (label Language)', env.label() === 'Language', env.label());
  check('lang attribute en', w.document.documentElement.getAttribute('lang') === 'en');
  check('selector marcado en', env.select().value === 'en', env.select().value);
  check('sidebar repintado (Audit)', /\bAudit\b/.test(env.sidebarText()) && !/Auditoría/.test(env.sidebarText()),
    env.sidebarText().slice(0, 160));
  check('pantalla activa repintada (Quick access) via router.refresh',
    /Quick access/.test(container.textContent) && !/Accesos rápidos/.test(container.textContent),
    container.textContent.slice(0, 160));
  check('subtitulo del dashboard en ingles', /Summary of your operation/.test(container.textContent));
  check('sin recarga de pagina (misma href)', w.location.href === hrefBefore,
    `${hrefBefore} -> ${w.location.href}`);

  console.log('S4 Inventory y Auditoria cambian de texto y vuelven a es');
  w.location.hash = '#/inventory/stock';
  await tick(14);
  check('stock en ingles (columna Location)', /Location/.test(container.textContent) && !/Ubicación/.test(container.textContent),
    container.textContent.slice(0, 200));
  check('titulo de documento traducido (Stock)',
    w.document.title === 'Stock - XP Platform', w.document.title);

  w.location.hash = '#/audit';
  await tick(14);
  check('auditoria en ingles (titulo Audit)', /\bAudit\b/.test(container.textContent) && !/Auditoría/.test(container.textContent),
    container.textContent.slice(0, 160));

  env.setLang('es');
  await tick(14);
  check('vuelta a es: sidebar Recepcion', /Recepción/.test(env.sidebarText()), env.sidebarText().slice(0, 160));
  check('vuelta a es: titulo del documento', w.document.title === 'Auditoría - XP Platform', w.document.title);
  check('vuelta a es: label Idioma', env.label() === 'Idioma', env.label());
  check('localStorage[lang] vuelve a es', w.localStorage.getItem('lang') === 'es');

  w.location.hash = '#/inventory/stock';
  await tick(14);
  check('stock de vuelta en espanol (Ubicación)', /Ubicación/.test(container.textContent),
    container.textContent.slice(0, 200));

  console.log('S6 con sesion el cambio de idioma persiste la preferencia (v1.10.3)');
  const prefPatches = env.calls.patches.filter(p => p.path === '/users/me/preferences');
  check('PATCH /users/me/preferences emitido con la ultima eleccion (es)',
    prefPatches.some(p => p.body && p.body.idioma === 'es'), JSON.stringify(prefPatches));
  check('el PATCH de preferencias se envia autenticado',
    prefPatches.every(p => p.options && p.options.requiresAuth !== false));
  const userWrites = [...env.calls.posts, ...env.calls.patches]
    .map(p => p.path)
    .filter(p => /^\/users(\/|$)/.test(p) && p !== '/users/me/preferences');
  check('cero escrituras a otros endpoints de usuarios',
    userWrites.length === 0, JSON.stringify(userWrites));

  // Sin sesion el selector no debe llamar al backend (no hay token aun).
  const envNo = createEnv();
  await envNo.w.i18n.init();
  await tick();
  envNo.setLang('fr');
  await tick();
  check('sin sesion NO se envia PATCH de preferencias',
    envNo.calls.patches.filter(p => p.path === '/users/me/preferences').length === 0,
    JSON.stringify(envNo.calls.patches));
  envNo.close();

  env.close();
}

// =====================================================================
// S5 - prioridad al iniciar sesion: usuario > localStorage > default
// =====================================================================
async function s5_prioridad() {
  console.log('S5 prioridad de idioma al iniciar (recarga)');

  // a) localStorage guardado, con sesion: se aplica sin accion del usuario
  const envA = createEnv({ token: 'tk-reload', storedLang: 'en' });
  await envA.w.i18n.init();
  await tick();
  check('recarga con lang guardado: aplica en', envA.w.i18n.getLocale() === 'en', envA.w.i18n.getLocale());
  check('recarga con lang guardado: descarga en.json',
    envA.localeGets().some(g => g.path === '/locales/en.json'), JSON.stringify(envA.localeGets()));
  check('recarga con lang guardado: label Language', envA.label() === 'Language', envA.label());
  envA.close();

  // b) preferencia del usuario en el backend (si la hubiera) manda
  const envB = createEnv();
  await envB.w.i18n.init();
  envB.w.AuthState.init();
  const userPrefs = { ...USER, preferencias: { idioma: 'fr' } };
  envB.w.AuthState.setSession(userPrefs, { access_token: JWT, refresh_token: 'rk-fr' });
  await tick();
  check('preferencia del usuario aplica fr', envB.w.i18n.getLocale() === 'fr', envB.w.i18n.getLocale());
  check('preferencia del usuario: descarga fr.json',
    envB.localeGets().some(g => g.path === '/locales/fr.json'), JSON.stringify(envB.localeGets()));
  check('preferencia del usuario: lang attribute fr',
    envB.w.document.documentElement.getAttribute('lang') === 'fr');
  check('la sincronia de login NO escribe preferencias en el servidor',
    envB.calls.patches.length === 0, JSON.stringify(envB.calls.patches));
  envB.close();

  // c) prioridad: preferencia del usuario > localStorage
  const envC = createEnv({ storedLang: 'en' });
  await envC.w.i18n.init();
  check('con lang guardado en, arranca en', envC.w.i18n.getLocale() === 'en', envC.w.i18n.getLocale());
  envC.w.AuthState.init();
  envC.w.AuthState.setSession(userPrefs, { access_token: JWT, refresh_token: 'rk-fr2' });
  await tick();
  check('preferencia del usuario gana a localStorage', envC.w.i18n.getLocale() === 'fr', envC.w.i18n.getLocale());
  envC.close();

  // d) sin nada guardado ni preferencia: es por defecto
  const envD = createEnv();
  await envD.w.i18n.init();
  envD.w.AuthState.init();
  envD.w.AuthState.setSession(USER, { access_token: JWT, refresh_token: 'rk-es' });
  await tick();
  check('sin preferencia ni guardado: es por defecto', envD.w.i18n.getLocale() === 'es', envD.w.i18n.getLocale());
  envD.close();
}

// =====================================================================
async function main() {
  await s1_s4_ciclo();
  await s5_prioridad();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
