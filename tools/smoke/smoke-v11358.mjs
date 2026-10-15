/**
 * Smoke test jsdom para la UI v1.13.58 (modulo settings/settings, solo lectura).
 * Uso: node tools/smoke/smoke-v11358.mjs   (desde la raiz del repo)
 *
 * Cobertura:
 *  S1  Registro de ruta/modulo y wiring (loader, router)
 *  S2  Pantalla sin permiso: deniedState con el permiso requerido
 *  S3  Pantalla con permiso: GET /tenant + /tenant/stats, pestañas y datos
 *  S4  Cambio de pestañas (?tab=) y ocultamiento de nombre_publico (ciphertext)
 *  S5  Error de carga: estado de error + reintento
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  settings: read('modules/settings/settings.js'),
  loader: read('core/loader.js'),
  router: read('core/router.js'),
  i18n: read('core/i18n.js')
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
// FIXTURES (payloads reales de GET /api/tenant y /api/tenant/stats)
// =====================================================================

const TENANT = {
  id: 'cb22db62-5379-4b83-8c51-a3c695c6de51',
  nombre_publico: 'HIaSDpSrUXta51g3rRXbhF8Yx/PmH4dyaXO99ZcEm/djucl7gkHs1ok=',
  nombre_interno: 'acme-corp',
  estado: 'ACTIVO',
  plan: 'FREE',
  pais: 'México',
  zona_horaria: 'America/Mexico_City',
  idioma_default: 'es',
  logo_url: null,
  dominio_custom: null,
  configuracion: null
};

const TENANT_PAYLOAD = {
  status: 200,
  data: { tenant: TENANT, plataforma_version: '1.13.58' }
};

const STATS_PAYLOAD = {
  status: 200,
  data: {
    stats: {
      usuarios: 4,
      usuarios_activos: 4,
      roles_asignados: 5,
      productos: 1,
      ubicaciones: 14,
      unidades_fisicas: 20
    }
  }
};

// =====================================================================
// ENTORNO (una ventana jsdom con stubs)
// =====================================================================

function createEnv({ perms = [], roles = [], getHandlers = {}, getImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/#/settings', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], toasts: [], errors: [] };

  w.console = {
    log() {}, info() {}, debug() {}, warn(...a) { calls.errors.push(a.map(String).join(' ')); },
    error(...a) { calls.errors.push(a.map(String).join(' ')); }
  };

  w.AppConfig = { apiBase: '/api', baseUrl: '/xp/public', appName: 'XP Platform' };

  w.EventBus = {
    handlers: {},
    on(ev, fn) { (this.handlers[ev] = this.handlers[ev] || []).push(fn); },
    off(ev, fn) {
      if (!this.handlers[ev]) return;
      this.handlers[ev] = this.handlers[ev].filter(f => f !== fn);
    },
    emit(ev, data) {
      if (ev === 'toast:show') calls.toasts.push(data);
      (this.handlers[ev] || []).forEach(fn => fn(data));
    }
  };

  w.AuthState = {
    getUser: () => ({ nombre: 'Test', apellido: 'User', email: 'test@acme.local' }),
    getPermissions: () => perms.slice(),
    getRoles: () => roles.slice(),
    hasPermission: (p) => perms.includes(p),
    isAuthenticated: () => true,
    logout() {}
  };

  const env = { w, calls, getImpl, dom };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (env.getImpl) return env.getImpl(p, options, env);
      if (getHandlers[p]) return getHandlers[p](options, env);
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    },
    async post() { return { status: 200, data: {} }; },
    async patch() { return { status: 200, data: {} }; },
    async delete() { return { status: 200, data: {} }; }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  evalIn(FILES.i18n);
  w.i18n.inject('es', ES_DICT);
  env.evalUi = () => UI_FILES.forEach(evalIn);
  env.loadSettings = () => { env.evalUi(); evalIn(FILES.settings); };

  env.container = w.document.getElementById('module-container');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.close = () => dom.window.close();

  return env;
}

const VIEW_PERMS = ['tenants.tenant.view'];

// =====================================================================
// S1 - REGISTRO Y WIRING
// =====================================================================
async function s1_registration() {
  console.log('S1 registro: ruta settings y modulo settings/settings');
  const env = createEnv({ perms: VIEW_PERMS, roles: ['ADMIN'] });
  env.evalIn(FILES.loader);
  env.evalIn(FILES.router);
  const w = env.w;

  const routes = w.AppRouter.getRoutes();
  check('ruta settings -> modulo settings/settings',
    routes['settings'] && routes['settings'].module === 'settings/settings',
    JSON.stringify(routes['settings'] || null));
  check('ruta settings no publica', routes['settings'].public === false);
  check('titleKey routes.settings', routes['settings'].titleKey === 'routes.settings');
  check('modulo settings/settings registrado', w.ModuleLoader.has('settings/settings'));
  check('resolveGlobalKey settings/settings',
    w.ModuleLoader.resolveGlobalKey('settings/settings') === 'SettingsSettingsModule');
  check('isModuleAvailable settings', w.AppRouter.isModuleAvailable('settings') === true);
  env.close();
}

// =====================================================================
// S2 - SIN PERMISO
// =====================================================================
async function s2_denied() {
  console.log('S2 sin permiso: deniedState con tenants.tenant.view');
  const env = createEnv({ perms: [], roles: ['VIEWER'] });
  env.loadSettings();
  env.w.SettingsSettingsModule.init({}, env.container);
  await tick();

  const text = env.container.textContent;
  check('pinta deniedState (card con CTA)', !!env.container.querySelector('.card a[href="#/dashboard"]'));
  check('menciona el permiso requerido', text.includes('tenants.tenant.view'));
  check('sin GETs al API', env.calls.gets.length === 0, JSON.stringify(env.calls.gets));
  check('sin pestañas', !env.container.querySelector('[data-tabs]'));
  env.close();
}

// =====================================================================
// S3 - CON PERMISO: datos y pestañas
// =====================================================================
async function s3_read_only() {
  console.log('S3 con permiso: GET /tenant + /tenant/stats, pestañas y datos');
  const env = createEnv({
    perms: VIEW_PERMS,
    roles: ['ADMIN'],
    getHandlers: {
      '/tenant': () => TENANT_PAYLOAD,
      '/tenant/stats': () => STATS_PAYLOAD
    }
  });
  env.loadSettings();
  env.w.SettingsSettingsModule.init({}, env.container);
  await tick();
  await tick();

  check('GET /tenant lanzado', env.calls.gets.some(g => g.path === '/tenant'));
  check('GET /tenant/stats lanzado', env.calls.gets.some(g => g.path === '/tenant/stats'));

  const tabs = env.container.querySelectorAll('[data-action="tab"]');
  check('5 pestañas', tabs.length === 5, String(tabs.length));
  check('pestaña general activa',
    env.container.querySelector('[data-panel="general"]') &&
    !env.container.querySelector('[data-panel="general"]').classList.contains('hidden'));

  const general = env.container.querySelector('[data-panel="general"]');
  check('nombre_interno visible', general.textContent.includes('acme-corp'));
  check('plan FREE visible', general.textContent.includes('FREE'));
  check('version 1.13.58 visible', general.textContent.includes('1.13.58'));
  check('configuracion null con texto', general.textContent.includes('Sin configuración adicional'));

  const loc = env.container.querySelector('[data-panel="localizacion"]');
  check('pais visible', loc.textContent.includes('México'));
  check('zona horaria visible', loc.textContent.includes('America/Mexico_City'));

  const seg = env.container.querySelector('[data-panel="seguridad"]');
  check('tab seguridad con hueco declarado', seg.textContent.includes('Próximamente'));

  const correo = env.container.querySelector('[data-panel="correo"]');
  check('tab correo con hueco declarado', correo.textContent.includes('Próximamente'));

  // Resumen: al cambiar de pestaña se repinta con los contadores
  const tabResumen = Array.from(tabs).find(b => b.getAttribute('data-tab') === 'resumen');
  env.click(tabResumen);
  await tick();
  const resumen = env.container.querySelector('[data-panel="resumen"]');
  check('resumen sin hidden tras clic', !resumen.classList.contains('hidden'));
  check('contador usuarios (4)', resumen.textContent.includes('4'));
  check('contador unidades_fisicas (20)', resumen.textContent.includes('20'));
  check('etiqueta Ubicaciones', resumen.textContent.includes('Ubicaciones'));

  env.close();
}

// =====================================================================
// S4 - nombre_publico ciphertext y tab via params
// =====================================================================
async function s4_ciphertext_and_params() {
  console.log('S4 nombre_publico oculto y pestaña por params');
  const env = createEnv({
    perms: VIEW_PERMS,
    roles: ['ADMIN'],
    getHandlers: {
      '/tenant': () => TENANT_PAYLOAD,
      '/tenant/stats': () => STATS_PAYLOAD
    }
  });
  env.loadSettings();
  env.w.SettingsSettingsModule.init({ tab: 'localizacion' }, env.container);
  await tick();
  await tick();

  check('tab localizacion activa por params',
    env.container.querySelector('[data-panel="localizacion"]') &&
    !env.container.querySelector('[data-panel="localizacion"]').classList.contains('hidden'));

  const text = env.container.textContent;
  check('nombre_publico (ciphertext) NO visible',
    !text.includes('HIaSDpSrUXta51g3rRXbhF8Yx'));
  check('ID del tenant visible', text.includes(TENANT.id));

  env.close();
}

// =====================================================================
// S5 - ERROR DE CARGA + RETRY
// =====================================================================
async function s5_error_retry() {
  console.log('S5 error de carga y reintento');
  let failFirst = true;
  const env = createEnv({
    perms: VIEW_PERMS,
    roles: ['ADMIN'],
    getImpl: (p) => {
      if (p === '/tenant') {
        if (failFirst) {
          failFirst = false;
          throw Object.assign(new Error('boom'), { status: 500, data: null });
        }
        return TENANT_PAYLOAD;
      }
      if (p === '/tenant/stats') return STATS_PAYLOAD;
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    }
  });
  env.loadSettings();
  env.w.SettingsSettingsModule.init({}, env.container);
  await tick();
  await tick();

  const errorState = env.container.querySelector('[data-action="settings-retry"]');
  check('estado de error con boton retry', !!errorState);

  if (errorState) {
    env.click(errorState);
    await tick();
    await tick();

    const general = env.container.querySelector('[data-panel="general"]');
    check('tras retry carga el tenant', general && general.textContent.includes('acme-corp'));
    check('segundo GET /tenant hecho',
      env.calls.gets.filter(g => g.path === '/tenant').length >= 2,
      String(env.calls.gets.filter(g => g.path === '/tenant').length));
  }

  env.close();
}

// =====================================================================
// RUN
// =====================================================================
async function main() {
  await s1_registration();
  await s2_denied();
  await s3_read_only();
  await s4_ciphertext_and_params();
  await s5_error_retry();
  console.log(`\n${pass} ok, ${fail} fail`);
  process.exit(fail > 0 ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
