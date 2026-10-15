/**
 * Smoke test jsdom para la UI v1.13.59 (modulo settings/settings: lectura y edicion).
 * Uso: node tools/smoke/smoke-v11358.mjs   (desde la raiz del repo)
 *
 * Cobertura:
 *  S1  Registro de ruta/modulo y wiring (loader, router)
 *  S2  Pantalla sin permiso: deniedState con el permiso requerido
 *  S3  Pantalla con permiso view: GET /tenant + /tenant/stats, pestañas y datos
 *  S4  Cambio de pestañas (?tab=) y ocultamiento de nombre_publico (ciphertext)
 *  S5  Error de carga: estado de error + reintento
 *  S6  Edicion (tenants.tenant.edit): formularios, slug, PATCH diff y toasts
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
  nombre_publico: 'Acme Corporation',
  nombre_interno: 'acme-corp',
  email_contacto: 'contact@acme.local',
  telefono: '+52 55 1234 5678',
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

function createEnv({ perms = [], roles = [], getHandlers = {}, getImpl = null, patchImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/#/settings', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], patches: [], toasts: [], errors: [] };

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

  const env = { w, calls, getImpl, patchImpl, dom };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (env.getImpl) return env.getImpl(p, options, env);
      if (getHandlers[p]) return getHandlers[p](options, env);
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    },
    async post() { return { status: 200, data: {} }; },
    async patch(p, body, options = {}) {
      calls.patches.push({ path: p, body, options });
      if (env.patchImpl) return env.patchImpl(p, body, options, env);
      return { status: 200, data: {} };
    },
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
const EDIT_PERMS = ['tenants.tenant.view', 'tenants.tenant.edit'];

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
  // v1.14.0: Perfil, Apariencia y Preferencias regionales; Perfil por defecto
  check('7 pestañas', tabs.length === 7, String(tabs.length));
  check('pestaña perfil activa',
    env.container.querySelector('[data-panel="perfil"]') &&
    !env.container.querySelector('[data-panel="perfil"]').classList.contains('hidden'));
  const perfil = env.container.querySelector('[data-panel="perfil"]');
  check('perfil: nombre publico visible', perfil.textContent.includes('Acme Corporation'));
  check('perfil: correo de contacto visible', perfil.textContent.includes('contact@acme.local'));

  const general = env.container.querySelector('[data-panel="general"]');
  check('nombre_interno visible', general.textContent.includes('acme-corp'));
  check('plan FREE visible', general.textContent.includes('FREE'));
  check('version 1.13.58 visible', general.textContent.includes('1.13.58'));
  check('general sin volcado JSON de configuracion', !general.textContent.includes('null'));

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
  check('nombre_publico visible (texto plano en la BD viva)', text.includes('Acme Corporation'));
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
// S6 - EDICION (tenants.tenant.edit)
// =====================================================================
async function s6_edit() {
  console.log('S6 edicion: formularios, slug, PATCH diff y toasts');

  // Sin edit: sólo lectura (labelValue, sin form)
  {
    const env = createEnv({
      perms: VIEW_PERMS,
      roles: ['ADMIN'],
      getHandlers: { '/tenant': () => TENANT_PAYLOAD, '/tenant/stats': () => STATS_PAYLOAD }
    });
    env.loadSettings();
    env.w.SettingsSettingsModule.init({}, env.container);
    await tick();
    await tick();
    check('sin edit: no hay form en general', !env.container.querySelector('#settings-general-form'));
    check('sin edit: no hay form en localizacion', !env.container.querySelector('#settings-local-form'));
    env.close();
  }

  // Con edit: formularios presentes
  {
    const env = createEnv({
      perms: EDIT_PERMS,
      roles: ['ADMIN'],
      getHandlers: { '/tenant': () => TENANT_PAYLOAD, '/tenant/stats': () => STATS_PAYLOAD },
      patchImpl: (p, body) => {
        if (p !== '/tenant') throw Object.assign(new Error('PATCH no soportado: ' + p), { status: 404 });
        return { status: 200, data: { tenant: { ...TENANT, ...body } } };
      }
    });
    env.loadSettings();
    env.w.SettingsSettingsModule.init({}, env.container);
    await tick();
    await tick();

    const form = env.container.querySelector('#settings-general-form');
    check('con edit: form general presente', !!form);
    check('con edit: input nombre_interno con valor', !!env.container.querySelector('#settings-nombre-interno'));
    check('con edit: formFooter con boton guardar',
      !!env.container.querySelector('[data-action="settings-save-general"]'));

    // Slug invalido: error de campo, sin PATCH
    const input = env.container.querySelector('#settings-nombre-interno');
    input.value = 'Acme Corp';
    form.dispatchEvent(new env.w.Event('submit', { bubbles: true, cancelable: true }));
    await tick();
    const errEl = env.container.querySelector('[data-field="nombre_interno"]');
    check('slug invalido: error de campo visible',
      errEl && !errEl.classList.contains('hidden') && errEl.textContent.length > 0,
      errEl ? errEl.textContent : 'sin elemento');
    check('slug invalido: sin PATCH', env.calls.patches.length === 0, JSON.stringify(env.calls.patches));

    // Sin cambios: toast info, sin PATCH
    input.value = 'acme-corp';
    form.dispatchEvent(new env.w.Event('submit', { bubbles: true, cancelable: true }));
    await tick();
    check('sin cambios: toast info', env.calls.toasts.some(t => t.type === 'info'));
    check('sin cambios: sin PATCH', env.calls.patches.length === 0, JSON.stringify(env.calls.patches));

    // Cambio valido: PATCH con solo el campo cambiado
    input.value = 'acme-corp-verify';
    form.dispatchEvent(new env.w.Event('submit', { bubbles: true, cancelable: true }));
    await tick();
    check('cambio valido: un PATCH /tenant', env.calls.patches.length === 1, JSON.stringify(env.calls.patches));
    check('PATCH solo nombre_interno',
      env.calls.patches[0] &&
      env.calls.patches[0].body &&
      Object.keys(env.calls.patches[0].body).length === 1 &&
      env.calls.patches[0].body.nombre_interno === 'acme-corp-verify',
      JSON.stringify(env.calls.patches[0] && env.calls.patches[0].body));
    check('toast de exito', env.calls.toasts.some(t => t.type === 'success'));
    check('tras PATCH el form refleja el valor',
      env.container.querySelector('#settings-nombre-interno').value === 'acme-corp-verify');

    // Form localizacion: pais cambiado + dominio vacio -> null
    env.click(Array.from(env.container.querySelectorAll('[data-action="tab"]'))
      .find(b => b.getAttribute('data-tab') === 'localizacion'));
    await tick();
    const localForm = env.container.querySelector('#settings-local-form');
    check('form localizacion presente', !!localForm);
    if (localForm) {
      localForm.querySelector('[name="pais"]').value = 'Argentina';
      localForm.dispatchEvent(new env.w.Event('submit', { bubbles: true, cancelable: true }));
      await tick();
      const last = env.calls.patches[env.calls.patches.length - 1];
      check('PATCH localizacion solo pais',
        last && Object.keys(last.body).length === 1 && last.body.pais === 'Argentina',
        JSON.stringify(last && last.body));
    }

    env.close();
  }
}

// =====================================================================
// S7 - PERFIL, APARIENCIA Y PREFERENCIAS REGIONALES (v1.14.0)
// =====================================================================
async function s7_tenant_config() {
  console.log('S7 perfil, apariencia y preferencias regionales');
  const CONFIG = { perfil: { direccion: 'Av. Reforma 1' }, extra: { x: 1 } };
  const env = createEnv({
    perms: EDIT_PERMS,
    roles: ['ADMIN'],
    getHandlers: {
      '/tenant': () => ({ status: 200, data: { tenant: { ...TENANT, configuracion: CONFIG }, plataforma_version: '1.14.0' } }),
      '/tenant/stats': () => STATS_PAYLOAD
    },
    patchImpl: (p, body) => ({ status: 200, data: { tenant: { ...TENANT, configuracion: CONFIG, ...body } } })
  });
  env.loadSettings();
  env.w.SettingsSettingsModule.init({}, env.container);
  await tick();
  await tick();
  const submit = (f) => f.dispatchEvent(new env.w.Event('submit', { bubbles: true, cancelable: true }));

  const pf = env.container.querySelector('#settings-profile-form');
  check('perfil: form presente', !!pf);
  check('perfil: direccion desde configuracion', pf.querySelector('[name="perfil.direccion"]').value === 'Av. Reforma 1');

  pf.querySelector('[name="email_contacto"]').value = 'correo-malo';
  submit(pf);
  await tick();
  const emailErr = env.container.querySelector('[data-field="email_contacto"]');
  check('perfil: correo invalido muestra error inline', emailErr && !emailErr.classList.contains('hidden'));
  check('perfil: correo invalido sin PATCH', env.calls.patches.length === 0, JSON.stringify(env.calls.patches));

  pf.querySelector('[name="email_contacto"]').value = 'nuevo@acme.local';
  submit(pf);
  await tick();
  let last = env.calls.patches[env.calls.patches.length - 1];
  check('perfil: PATCH solo email_contacto',
    last && Object.keys(last.body).length === 1 && last.body.email_contacto === 'nuevo@acme.local',
    JSON.stringify(last && last.body));

  const pf2 = env.container.querySelector('#settings-profile-form');
  pf2.querySelector('[name="perfil.direccion"]').value = 'Calle 2';
  submit(pf2);
  await tick();
  last = env.calls.patches[env.calls.patches.length - 1];
  check('perfil: direccion va en configuracion.perfil y conserva otras claves',
    last && last.body.configuracion && last.body.configuracion.perfil.direccion === 'Calle 2' &&
    last.body.configuracion.extra && last.body.configuracion.extra.x === 1,
    JSON.stringify(last && last.body));

  // Apariencia: swatch + guardar
  env.click(env.container.querySelector('[data-action="tab"][data-tab="apariencia"]'));
  await tick();
  env.click(env.container.querySelector('[data-action="settings-pick-color"][data-color="#248A3D"]'));
  check('apariencia: swatch marcado', env.container.querySelector('.color-swatch.is-selected[data-color="#248A3D"]') !== null);
  submit(env.container.querySelector('#settings-appearance-form'));
  await tick();
  last = env.calls.patches[env.calls.patches.length - 1];
  check('apariencia: PATCH color_primario',
    last && last.body.configuracion && last.body.configuracion.apariencia &&
    last.body.configuracion.apariencia.color_primario === '#248A3D',
    JSON.stringify(last && last.body));

  // Preferencias regionales: moneda y formato
  env.click(env.container.querySelector('[data-action="tab"][data-tab="localizacion"]'));
  await tick();
  const lf = env.container.querySelector('#settings-local-form');
  lf.querySelector('[name="regional.moneda"]').value = 'MXN';
  lf.querySelector('[name="regional.formato_fecha"]').value = 'YYYY-MM-DD';
  submit(lf);
  await tick();
  last = env.calls.patches[env.calls.patches.length - 1];
  check('regional: PATCH moneda y formato en configuracion.regional',
    last && last.body.configuracion && last.body.configuracion.regional &&
    last.body.configuracion.regional.moneda === 'MXN' &&
    last.body.configuracion.regional.formato_fecha === 'YYYY-MM-DD',
    JSON.stringify(last && last.body));

  // UI.formatDate con formato del tenant
  env.w.TenantBranding = { get: () => ({ regional: { formato_fecha: 'MM/DD/YYYY' } }) };
  check('UI.formatDate respeta el formato', env.w.UI.formatDate('2026-10-15 21:43:40') === '10/15/2026',
    env.w.UI.formatDate('2026-10-15 21:43:40'));
  check('UI.formatDateTime anade hora', env.w.UI.formatDateTime('2026-10-15 21:43:40') === '10/15/2026 21:43');
  check('UI.formatDate vacio', env.w.UI.formatDate(null) === '—');

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
  await s6_edit();
  await s7_tenant_config();
  console.log(`\n${pass} ok, ${fail} fail`);
  process.exit(fail > 0 ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
