/**
 * Smoke test jsdom para la UI v1.16.0 (modulo settings/settings).
 * Uso: node tools/smoke/smoke-v11358.mjs   (desde la raiz del repo)
 *
 * Cobertura:
 *  S1  Registro de ruta/modulo y wiring (loader, router)
 *  S2  Pantalla sin permiso: deniedState con el permiso requerido
 *  S3  Pantalla con permiso view: GET /tenant, 4 pestanas, datos y SIN MFA
 *  S4  Cambio de pestanas (?tab=), pais ISO y logo sin form de URL
 *  S5  Error de carga: estado de error + reintento
 *  S6  Edicion (tenants.tenant.edit): UN boton global, validacion, PATCH diff
 *  S7  Apariencia, regional, caducidad de contrasena y SMTP enmascarado
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
  i18n: read('core/i18n.js'),
  branding: read('core/branding.js'),
  sidebar: read('modules/dashboard/sidebar.js'),
  storage: read('core/storage.js')
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
// FIXTURES (payloads reales de GET /api/tenant, v1.14.2)
// =====================================================================

const TENANT = {
  id: 'cb22db62-5379-4b83-8c51-a3c695c6de51',
  nombre_publico: 'Acme Corporation',
  nombre_interno: 'acme-corp',
  email_contacto: 'contact@acme.local',
  telefono: '+52 55 1234 5678',
  estado: 'ACTIVO',
  plan: 'FREE',
  pais: 'MX',
  zona_horaria: 'America/Mexico_City',
  idioma_default: 'es',
  logo_url: null,
  dominio_custom: null,
  configuracion: null
};

const TENANT_PAYLOAD = {
  status: 200,
  data: { tenant: TENANT, plataforma_version: '1.17.0' }
};

// =====================================================================
// ENTORNO (una ventana jsdom con stubs)
// =====================================================================

function createEnv({ perms = [], roles = [], getHandlers = {}, getImpl = null, patchImpl = null, postImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/#/settings', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], patches: [], posts: [], toasts: [], errors: [] };

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

  const env = { w, calls, getImpl, patchImpl, postImpl, dom };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (env.getImpl) return env.getImpl(p, options, env);
      if (getHandlers[p]) return getHandlers[p](options, env);
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    },
    async post(p, body, options = {}) {
      calls.posts.push({ path: p, body, options });
      if (env.postImpl) return env.postImpl(p, body, options, env);
      return { status: 200, data: {} };
    },
    async upload(p) {
      calls.posts.push({ path: p, body: null, options: { upload: true } });
      return { status: 200, data: { tenant: TENANT } };
    },
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
  check('sin pestanas', !env.container.querySelector('[data-tabs]'));
  env.close();
}

// =====================================================================
// S3 - CON PERMISO: 4 pestanas y datos (sin stats)
// =====================================================================
async function s3_read_only() {
  console.log('S3 con permiso: GET /tenant, 4 pestanas y datos');
  const env = createEnv({
    perms: VIEW_PERMS,
    roles: ['ADMIN'],
    getHandlers: { '/tenant': () => TENANT_PAYLOAD }
  });
  env.loadSettings();
  env.w.SettingsSettingsModule.init({}, env.container);
  await tick();
  await tick();

  check('GET /tenant lanzado', env.calls.gets.some(g => g.path === '/tenant'));
  check('sin GET /tenant/stats', !env.calls.gets.some(g => g.path === '/tenant/stats'));

  const tabs = env.container.querySelectorAll('[data-action="tab"]');
  check('4 pestanas', tabs.length === 4, String(tabs.length));
  check('pestañas: general, apariencia, localizacion, seguridad',
    Array.from(tabs).map(b => b.getAttribute('data-tab')).join(',') === 'general,apariencia,localizacion,seguridad',
    Array.from(tabs).map(b => b.getAttribute('data-tab')).join(','));
  check('pestaña general activa por defecto',
    env.container.querySelector('[data-panel="general"]') &&
    !env.container.querySelector('[data-panel="general"]').classList.contains('hidden'));

  const general = env.container.querySelector('[data-panel="general"]');
  check('general: nombre publico visible', general.textContent.includes('Acme Corporation'));
  check('general: correo de contacto visible', general.textContent.includes('contact@acme.local'));
  check('general: nombre_interno visible', general.textContent.includes('acme-corp'));
  check('general: plan FREE visible', general.textContent.includes('FREE'));
  check('general: version 1.17.0 visible', general.textContent.includes('1.17.0'));

  const loc = env.container.querySelector('[data-panel="localizacion"]');
  check('localizacion: pais ISO MX visible', loc.textContent.includes('MX'));
  check('localizacion: zona horaria visible', loc.textContent.includes('America/Mexico_City'));

  const seg = env.container.querySelector('[data-panel="seguridad"]');
  check('seguridad: sin MFA (movido al perfil)',
    !seg.textContent.includes('MFA'));
  check('seguridad read-only: sin inputs editables',
    !seg.querySelector('[name="smtp.host"]') && !seg.querySelector('[name="seguridad.caducidad_password_dias"]'));
  check('seguridad read-only: etiqueta de caducidad visible',
    seg.textContent.includes(ES_DICT.settings.label_caducidad_password));

  env.close();
}

// =====================================================================
// S4 - pestana por params, pais ISO y sin form de URL de logo
// =====================================================================
async function s4_tabs_and_logo() {
  console.log('S4 pestana por params, pais ISO y logo sin URL');
  const env = createEnv({
    perms: EDIT_PERMS,
    roles: ['ADMIN'],
    getHandlers: { '/tenant': () => TENANT_PAYLOAD }
  });
  env.loadSettings();
  env.w.SettingsSettingsModule.init({ tab: 'localizacion' }, env.container);
  await tick();
  await tick();

  check('tab localizacion activa por params',
    env.container.querySelector('[data-panel="localizacion"]') &&
    !env.container.querySelector('[data-panel="localizacion"]').classList.contains('hidden'));

  const loc = env.container.querySelector('[data-panel="localizacion"]');
  const paisSelect = loc.querySelector('[name="pais"]');
  check('select pais es ISO (no input texto)', paisSelect && paisSelect.tagName === 'SELECT');
  check('select pais tiene opciones', paisSelect && paisSelect.options.length > 200, paisSelect ? String(paisSelect.options.length) : 'sin select');
  check('opciones incluyen MX', paisSelect && Array.from(paisSelect.options).some(o => o.value === 'MX'));
  check('valor actual MX seleccionado', paisSelect && paisSelect.value === 'MX', paisSelect ? paisSelect.value : '');

  const general = env.container.querySelector('[data-panel="general"]');
  check('sin form de URL de logo', !env.container.querySelector('#settings-logo-url-form'));
  check('sin input logo_url', !env.container.querySelector('[name="logo_url"]'));
  check('boton subir logo presente', !!env.container.querySelector('[data-testid="settings-logo-file-input"]'));

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
// S6 - UN SOLO BOTON GLOBAL: validacion, PATCH diff y toasts
// =====================================================================
async function s6_global_save() {
  console.log('S6 edicion: UN boton global, validacion, PATCH diff');

  // Sin edit: sin boton global ni campos editables
  {
    const env = createEnv({
      perms: VIEW_PERMS,
      roles: ['ADMIN'],
      getHandlers: { '/tenant': () => TENANT_PAYLOAD }
    });
    env.loadSettings();
    env.w.SettingsSettingsModule.init({}, env.container);
    await tick();
    await tick();
    check('sin edit: no hay boton guardar global',
      !env.container.querySelector('[data-action="settings-save-all"]'));
    check('sin edit: nombre_interno como label, no input',
      !env.container.querySelector('[name="nombre_interno"]'));
    env.close();
  }

  // Con edit: UN boton global que recoge diffs de todos los forms
  {
    const env = createEnv({
      perms: EDIT_PERMS,
      roles: ['ADMIN'],
      getHandlers: { '/tenant': () => TENANT_PAYLOAD },
      patchImpl: (p, body) => {
        if (p !== '/tenant') throw Object.assign(new Error('PATCH no soportado: ' + p), { status: 404 });
        return { status: 200, data: { tenant: { ...TENANT, ...body } } };
      }
    });
    env.loadSettings();
    env.w.SettingsSettingsModule.init({}, env.container);
    await tick();
    await tick();

    const saveBtn = env.container.querySelector('[data-action="settings-save-all"]');
    check('con edit: UN boton global presente', !!saveBtn);
    check('con edit: sin botones submit por formulario',
      !env.container.querySelector('#settings-general-form [type="submit"]') &&
      !env.container.querySelector('[data-action="settings-save-general"]'));

    const clickSave = () => env.click(env.container.querySelector('[data-action="settings-save-all"]'));

    // Slug invalido: error de campo, sin PATCH, salta a la pestana general
    env.container.querySelector('[name="nombre_interno"]').value = 'Acme Corp';
    clickSave();
    await tick();
    const errEl = env.container.querySelector('[data-field="nombre_interno"]');
    check('slug invalido: error de campo visible',
      errEl && !errEl.classList.contains('hidden') && errEl.textContent.length > 0,
      errEl ? errEl.textContent : 'sin elemento');
    check('slug invalido: sin PATCH', env.calls.patches.length === 0, JSON.stringify(env.calls.patches));

    // Sin cambios: toast info, sin PATCH
    env.container.querySelector('[name="nombre_interno"]').value = 'acme-corp';
    clickSave();
    await tick();
    check('sin cambios: toast info', env.calls.toasts.some(t => t.type === 'info'));
    check('sin cambios: sin PATCH', env.calls.patches.length === 0, JSON.stringify(env.calls.patches));

    // Cambio valido en general + cambio en localizacion = UN solo PATCH combinado
    env.container.querySelector('[name="nombre_interno"]').value = 'acme-corp-verify';
    env.container.querySelector('[name="pais"]').value = 'AR';
    clickSave();
    await tick();
    check('cambio valido: un solo PATCH', env.calls.patches.length === 1, JSON.stringify(env.calls.patches));
    const body = env.calls.patches[0] && env.calls.patches[0].body;
    check('PATCH combina nombre_interno y pais',
      body && body.nombre_interno === 'acme-corp-verify' && body.pais === 'AR',
      JSON.stringify(body));
    check('PATCH no arrastra campos sin cambios',
      body && !('email_contacto' in body) && !('telefono' in body) && !('zona_horaria' in body),
      JSON.stringify(body));
    check('toast de exito', env.calls.toasts.some(t => t.type === 'success'));

    // Correo invalido en perfil: validacion cruzada sin PATCH
    env.container.querySelector('[name="email_contacto"]').value = 'correo-malo';
    clickSave();
    await tick();
    const emailErr = env.container.querySelector('[data-field="email_contacto"]');
    check('correo invalido: error inline y saltta a general',
      emailErr && !emailErr.classList.contains('hidden') &&
      env.container.querySelector('[data-panel="general"]').classList.contains('hidden') === false);
    check('correo invalido: sin PATCH extra', env.calls.patches.length === 1, String(env.calls.patches.length));

    env.close();
  }
}

// =====================================================================
// S7 - APARIENCIA, REGIONAL, CADUCIDAD Y SMTP ENMASCARADO
// =====================================================================
async function s7_config_sections() {
  console.log('S7 apariencia, regional, caducidad y SMTP');
  const CONFIG = {
    perfil: { direccion: 'Av. Reforma 1' },
    regional: { moneda: 'MXN', formato_fecha: 'DD/MM/YYYY' },
    seguridad: { caducidad_password_dias: 90 },
    smtp: {
      host: 'smtp.example.com',
      puerto: 587,
      encryption: 'tls',
      username: 'user@example.com',
      password: null,
      password_configurada: true,
      from_email: 'noreply@example.com',
      from_name: 'Acme'
    },
    extra: { x: 1 }
  };
  const env = createEnv({
    perms: EDIT_PERMS,
    roles: ['ADMIN'],
    getHandlers: {
      '/tenant': () => ({ status: 200, data: { tenant: { ...TENANT, configuracion: CONFIG }, plataforma_version: '1.14.2' } })
    },
    // Simula el backend: el PATCH responde con toPublicArray (password oculta + flag)
    patchImpl: (p, body) => {
      const merged = JSON.parse(JSON.stringify(body.configuracion || CONFIG));
      if (merged.smtp && typeof merged.smtp === 'object') {
        if (merged.smtp.password != null) delete merged.smtp.password;
        merged.smtp.password_configurada = true;
      }
      return { status: 200, data: { tenant: { ...TENANT, ...body, configuracion: merged } } };
    },
    postImpl: (p) => {
      if (p === '/tenant/smtp/test') return { status: 200, data: { enviado_a: 'contact@acme.local' } };
      return { status: 200, data: {} };
    }
  });
  env.loadSettings();
  env.w.SettingsSettingsModule.init({}, env.container);
  await tick();
  await tick();
  const clickSave = () => env.click(env.container.querySelector('[data-action="settings-save-all"]'));

  // General: direccion cargada desde configuracion
  check('direccion desde configuracion.perfil',
    env.container.querySelector('[name="perfil.direccion"]').value === 'Av. Reforma 1');

  // Apariencia: swatch + el guardado va por el boton global
  env.click(env.container.querySelector('[data-action="tab"][data-tab="apariencia"]'));
  await tick();
  env.click(env.container.querySelector('[data-action="settings-pick-color"][data-color="#248A3D"]'));
  check('apariencia: swatch marcado',
    env.container.querySelector('.color-swatch.is-selected[data-color="#248A3D"]') !== null);
  clickSave();
  await tick();
  let last = env.calls.patches[env.calls.patches.length - 1];
  check('apariencia: PATCH color_primario via boton global',
    last && last.body.configuracion && last.body.configuracion.apariencia &&
    last.body.configuracion.apariencia.color_primario === '#248A3D',
    JSON.stringify(last && last.body));

  // Localizacion: regional ya cargado + muestras
  env.click(env.container.querySelector('[data-action="tab"][data-tab="localizacion"]'));
  await tick();
  const loc = env.container.querySelector('[data-panel="localizacion"]');
  check('regional: moneda MXN seleccionada',
    loc.querySelector('[name="regional.moneda"]').value === 'MXN');
  check('regional: muestra de fecha usa formato del tenant',
    loc.querySelector('[data-sample-date]').textContent.includes('/'),
    loc.querySelector('[data-sample-date]').textContent);

  // Seguridad: caducidad cargada, password enmascarada y test SMTP
  env.click(env.container.querySelector('[data-action="tab"][data-tab="seguridad"]'));
  await tick();
  const seg = env.container.querySelector('[data-panel="seguridad"]');
  check('caducidad: valor 90 cargado',
    seg.querySelector('[name="seguridad.caducidad_password_dias"]').value === '90');
  check('SMTP: host cargado', seg.querySelector('[name="smtp.host"]').value === 'smtp.example.com');
  check('SMTP: password enmascarada (vacia + placeholder)',
    seg.querySelector('[name="smtp.password"]').value === '' &&
    seg.querySelector('[name="smtp.password"]').placeholder === '••••••');
  check('SMTP: boton de prueba presente', !!seg.querySelector('[data-action="settings-smtp-test"]'));

  // Boton de prueba SMTP
  env.click(seg.querySelector('[data-action="settings-smtp-test"]'));
  await tick();
  check('POST /tenant/smtp/test lanzado',
    env.calls.posts.some(p => p.path === '/tenant/smtp/test'));
  check('toast de exito del test', env.calls.toasts.some(t => t.type === 'success'));

  // Cambio de caducidad via boton global (re-consultar: cada save reconstruye el DOM)
  env.container.querySelector('[name="seguridad.caducidad_password_dias"]').value = '30';
  clickSave();
  await tick();
  last = env.calls.patches[env.calls.patches.length - 1];
  check('caducidad: PATCH seguridad.caducidad_password_dias=30',
    last && last.body.configuracion && last.body.configuracion.seguridad &&
    last.body.configuracion.seguridad.caducidad_password_dias === 30,
    JSON.stringify(last && last.body));
  check('SMTP sin cambios: password NO enviada',
    last && last.body.configuracion && last.body.configuracion.smtp &&
    !('password' in last.body.configuracion.smtp),
    JSON.stringify(last && last.body.configuracion && last.body.configuracion.smtp));

  // Caducidad invalida: validacion sin PATCH (sobre el input vivo, no el nodo obsoleto)
  const patchesBefore = env.calls.patches.length;
  env.container.querySelector('[name="seguridad.caducidad_password_dias"]').value = '999';
  clickSave();
  await tick();
  const diasErr = env.container.querySelector('[data-field="seguridad.caducidad_password_dias"]');
  check('caducidad invalida: error de campo',
    diasErr && !diasErr.classList.contains('hidden'));
  check('caducidad invalida: sin PATCH', env.calls.patches.length === patchesBefore,
    `${patchesBefore} -> ${env.calls.patches.length}`);

  // UI.formatDate con formato del tenant
  env.w.TenantBranding = { get: () => ({ regional: { formato_fecha: 'MM/DD/YYYY' } }) };
  check('UI.formatDate respeta el formato', env.w.UI.formatDate('2026-10-15 21:43:40') === '10/15/2026',
    env.w.UI.formatDate('2026-10-15 21:43:40'));
  check('UI.formatDateTime anade hora', env.w.UI.formatDateTime('2026-10-15 21:43:40') === '10/15/2026 21:43');
  check('UI.formatDate vacio', env.w.UI.formatDate(null) === '—');

  env.close();
}

// =====================================================================
// S8 - IDIOMA POR DEFECTO DEL TENANT (v1.17.4, i18n + TenantBranding)
// =====================================================================
async function s8_tenant_default_lang() {
  console.log('S8 idioma por defecto del tenant via branding:changed');

  // Sin eleccion previa: el default del tenant se aplica
  {
    const env = createEnv({ perms: EDIT_PERMS, roles: ['ADMIN'] });
    env.evalIn(FILES.branding);
    env.w.i18n.init();
    await tick(2);
    check('inicial: locale es', env.w.i18n.getLocale() === 'es', env.w.i18n.getLocale());

    env.w.TenantBranding.set({ idioma_default: 'en', zona_horaria: null, apariencia: null, regional: null });
    await tick(2);
    check('branding con idioma_default: se aplica', env.w.i18n.getLocale() === 'en', env.w.i18n.getLocale());

    env.w.TenantBranding.set({ idioma_default: null, zona_horaria: null, apariencia: null, regional: null });
    await tick(2);
    check('branding sin idioma_default: no cambia', env.w.i18n.getLocale() === 'en', env.w.i18n.getLocale());

    env.w.EventBus.emit('auth:logout', {});
    env.w.TenantBranding.set({ idioma_default: 'fr', zona_horaria: null, apariencia: null, regional: null });
    await tick(2);
    check('tras logout se vuelve a aplicar el default', env.w.i18n.getLocale() === 'fr', env.w.i18n.getLocale());

    env.close();
  }

  // Preferencia propia del usuario: gana sobre el default del tenant
  {
    const env = createEnv({ perms: EDIT_PERMS, roles: ['ADMIN'] });
    env.evalIn(FILES.branding);
    env.w.i18n.init();
    await tick(2);
    env.w.i18n.syncFromUser({ preferencias: { idioma: 'fr' } });
    await tick(2);
    check('preferencia de usuario aplicada', env.w.i18n.getLocale() === 'fr', env.w.i18n.getLocale());

    env.w.TenantBranding.set({ idioma_default: 'en', zona_horaria: null, apariencia: null, regional: null });
    await tick(2);
    check('preferencia de usuario gana sobre el tenant', env.w.i18n.getLocale() === 'fr', env.w.i18n.getLocale());
    env.close();
  }

  // Eleccion explicita de la sesion: gana sobre el default del tenant
  {
    const env = createEnv({ perms: EDIT_PERMS, roles: ['ADMIN'] });
    env.evalIn(FILES.branding);
    env.w.i18n.init();
    await tick(2);
    await env.w.i18n.setLocale('pt');
    check('sessionChoice aplicado', env.w.i18n.getLocale() === 'pt', env.w.i18n.getLocale());

    env.w.TenantBranding.set({ idioma_default: 'en', zona_horaria: null, apariencia: null, regional: null });
    await tick(2);
    check('sessionChoice gana sobre el tenant', env.w.i18n.getLocale() === 'pt', env.w.i18n.getLocale());
    env.close();
  }
}

// =====================================================================
// S9 - SIDEBAR: RAIL DE ICONOS EN DESKTOP (v1.17.5, sidebar.js)
// =====================================================================
async function s9_sidebar_icon_rail() {
  console.log('S9 sidebar: rail de iconos, persistencia y mobile');

  function seedShell(env) {
    env.w.document.body.insertAdjacentHTML('beforeend',
      '<div id="app" class="app-layout"><aside id="sidebar"></aside><main class="main-content"></main></div>');
    env.w.AuthState.getUser = () => ({ nombre: 'Test', apellido: 'User', email: 'test@acme.local', roles: ['ADMIN'] });
    env.evalIn(FILES.storage);
    env.evalIn(FILES.sidebar);
  }

  // Desktop (jsdom arranca con innerWidth 1024): toggle alterna rail y persiste
  {
    const env = createEnv({ perms: EDIT_PERMS, roles: ['ADMIN'] });
    seedShell(env);
    const app = env.w.document.getElementById('app');
    env.w.AppSidebar.init();
    check('inicial: sidebar expandido', !app.classList.contains('sidebar-collapsed'));

    env.w.AppSidebar.toggle();
    check('toggle: rail de iconos', app.classList.contains('sidebar-collapsed'));
    check('toggle: persiste true', env.w.Storage.get('sidebar.collapsed', false) === true);

    const item = env.w.document.querySelector('.sidebar-item');
    check('items con aria-label', !!(item && item.getAttribute('aria-label')));

    env.w.AppSidebar.toggle();
    check('segundo toggle: expandido', !app.classList.contains('sidebar-collapsed'));
    check('expandir persiste false', env.w.Storage.get('sidebar.collapsed', false) === false);
    env.close();
  }

  // Sesion nueva con estado guardado: init() lo restaura sin flash
  {
    const env = createEnv({ perms: EDIT_PERMS, roles: ['ADMIN'] });
    seedShell(env);
    env.w.Storage.set('sidebar.collapsed', true);
    env.w.AppSidebar.init();
    const app = env.w.document.getElementById('app');
    check('init restaura rail persistido', app.classList.contains('sidebar-collapsed'));
    env.close();
  }

  // Mobile (innerWidth 500): el toggle abre el panel, no colapsa a rail
  {
    const env = createEnv({ perms: EDIT_PERMS, roles: ['ADMIN'] });
    seedShell(env);
    Object.defineProperty(env.w, 'innerWidth', { value: 500, configurable: true });
    env.w.AppSidebar.init();
    const app = env.w.document.getElementById('app');
    const sidebar = env.w.document.getElementById('sidebar');
    env.w.AppSidebar.toggle();
    check('mobile: abre panel deslizante', sidebar.classList.contains('is-open'));
    check('mobile: sin rail colapsado', !app.classList.contains('sidebar-collapsed'));
    env.close();
  }
}

// =====================================================================
// RUN
// =====================================================================
async function main() {
  await s1_registration();
  await s2_denied();
  await s3_read_only();
  await s4_tabs_and_logo();
  await s5_error_retry();
  await s6_global_save();
  await s7_config_sections();
  await s8_tenant_default_lang();
  await s9_sidebar_icon_rail();
  console.log(`\n${pass} ok, ${fail} fail`);
  process.exit(fail > 0 ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
