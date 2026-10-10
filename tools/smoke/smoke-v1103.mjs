/**
 * Smoke jsdom preferencias de idioma v1.10.3 (sprint Correccion v1.10.3-A).
 *
 * Secuencia principal exigida: elegir 'en' con sesion -> PATCH
 * /users/me/preferences; a continuacion /auth/me devuelve
 * preferencias.idioma=es y el idioma SIGUE en 'en' (la eleccion explicita
 * de la sesion gana sobre lo que devuelva /auth/me hasta que el PATCH
 * confirme). Cubre tambien:
 *  - fallo del PATCH: la eleccion local se conserva y se avisa con toast
 *  - /auth/me, auth:user-updated y auth:synced NO cambian el idioma
 *  - syncFromUser (preferencia del servidor) solo en el login (setSession)
 *  - eleccion pre-sesion: sin PATCH hasta auth:login, que la confirma
 *  - auth:logout reinicia la eleccion de sesion
 *  - un solo PATCH por eleccion (dedupe y reintento tras fallo)
 *
 * Uso: node tools/smoke/smoke-v1103.mjs
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
  state: read('modules/auth/state.js')
};

let pass = 0;
let fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${extra ? ' :: ' + extra : ''}`); }
}
async function tick(n = 10) {
  for (let i = 0; i < n; i++) await new Promise(r => setTimeout(r, 0));
}

const USER = {
  id: 'user-1', tenant_id: 't1', nombre: 'Ana', apellido: 'Admin', email: 'ana@acme.local',
  roles: [{ nombre: 'ADMIN', permisos: ['users.user.view'] }]
};
const TOKENS = { access_token: 'jwt', refresh_token: 'rk' };
// JWT fake con exp en el futuro por si AppRouter llegara a parsearlo.
const JWT = 'eyJhbGciOiJIUzI1NiJ9.' + btoa('{"exp":4102444800}') + '.sig';

function createEnv({ token = null, storedLang = null } = {}) {
  const dom = new JSDOM(
    `<!DOCTYPE html><html><body>
      <header class="app-header">
        <span id="user-name">Invitado</span>
        <label data-i18n="shell.language">Idioma</label>
        <select id="lang-select">
          <option value="es">Espanol</option>
          <option value="en">English</option>
          <option value="pt">Portugues</option>
          <option value="fr">Francais</option>
        </select>
        <a href="#" id="logout-btn">Cerrar sesion</a>
      </header>
    </body></html>`,
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], posts: [], patches: [], toasts: [], errors: [], emits: [] };
  const listeners = {};
  const env = { w, calls, dom, failPatch: false, meUser: null };

  w.console = {
    log() {}, info() {}, debug() {}, warn(...a) { calls.errors.push(a.map(String).join(' ')); },
    error(...a) { calls.errors.push(a.map(String).join(' ')); }
  };

  w.AppConfig = { apiBase: '/api', baseUrl: '/xp/public', appName: 'XP Platform', sessionTimeout: 900 };
  if (storedLang) w.localStorage.setItem('lang', storedLang);

  const store = {
    token: token ? JWT : null,
    refresh: token ? 'refresh-token' : null,
    user: null,
    tenant: 't1'
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

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (p.startsWith('/locales/')) {
        if (!store.token) {
          const err = new Error('Unauthorized'); err.status = 401; throw err;
        }
        const loc = p.slice('/locales/'.length).replace(/\.json$/, '');
        if (!DICT[loc]) { const err = new Error('Not Found'); err.status = 404; throw err; }
        return { status: 200, data: { locale: loc, version: 'smoke-' + loc, dictionary: DICT[loc] } };
      }
      if (p === '/auth/me') {
        // Respuesta configurable: los tests cambian env.meUser para simular
        // lo que devuelve el backend en cada momento.
        const u = env.meUser || store.user || USER;
        return { status: 200, data: { user: u, roles: u.roles, tenant_id: u.tenant_id } };
      }
      const err = new Error('GET no soportado: ' + p); err.status = 404; throw err;
    },
    async post(p, body, options = {}) {
      calls.posts.push({ path: p, body, options });
      if (p === '/auth/logout') return { status: 200, data: {} };
      const err = new Error('POST no soportado: ' + p); err.status = 404; throw err;
    },
    async patch(p, body, options = {}) {
      calls.patches.push({ path: p, body, options });
      if (p === '/users/me/preferences') {
        if (env.failPatch) {
          const err = new Error('boom preferencias'); err.status = 500; throw err;
        }
        return { status: 200, data: { preferencias: body || {} } };
      }
      const err = new Error('PATCH no soportado: ' + p); err.status = 404; throw err;
    }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  env.select = () => w.document.getElementById('lang-select');
  env.setLang = (loc) => {
    const sel = env.select();
    sel.value = loc;
    sel.dispatchEvent(new w.Event('change', { bubbles: true }));
  };
  env.locale = () => w.i18n.getLocale();
  env.langAttr = () => w.document.documentElement.getAttribute('lang');
  env.localeGets = () => calls.gets.filter(g => g.path.startsWith('/locales/'));
  env.prefsPatches = () => calls.patches.filter(p => p.path === '/users/me/preferences');

  // Runtime i18n + estado de auth (mismo orden que index.php)
  evalIn(FILES.i18n);
  evalIn(FILES.state);

  env.close = () => dom.window.close();
  return env;
}

// =====================================================================
// S1 - secuencia principal: elegir en, /auth/me con es, sigue en
// =====================================================================
async function s1_secuencia() {
  console.log('S1 elegir en con sesion y /auth/me con preferencias.idioma=es: sigue en');
  const env = createEnv({ token: 'tk' });
  const w = env.w;

  await w.i18n.init();
  await tick();
  w.AuthState.init();
  w.AuthState.setSession(USER, TOKENS);
  await tick();

  check('arranca en es tras el login', env.locale() === 'es', env.locale());

  env.setLang('en');
  await tick();

  check('elegir en aplica el idioma en la UI', env.locale() === 'en', env.locale());
  check('guarda localStorage[lang]=en', w.localStorage.getItem('lang') === 'en');
  check('envia PATCH /users/me/preferences con idioma en',
    env.prefsPatches().length === 1 && env.prefsPatches()[0].body.idioma === 'en',
    JSON.stringify(env.prefsPatches()));
  check('el PATCH va autenticado (requiresAuth)',
    env.prefsPatches()[0].options.requiresAuth !== false);

  // El backend responde al proximo /auth/me con la preferencia guardada (es)
  env.meUser = { ...USER, preferencias: { idioma: 'es' } };
  const refreshed = await w.AuthState.refreshUser();
  await tick();

  check('/auth/me trajo preferencias.idioma=es',
    refreshed && refreshed.preferencias && refreshed.preferencias.idioma === 'es',
    JSON.stringify(refreshed && refreshed.preferencias));
  check('el idioma SIGUE en (eleccion de sesion gana)', env.locale() === 'en', env.locale());
  check('lang attribute sigue en', env.langAttr() === 'en', env.langAttr());
  check('selector sigue marcado en', env.select().value === 'en', env.select().value);
  check('localStorage sigue en', w.localStorage.getItem('lang') === 'en');
  check('sin PATCH adicionales durante /auth/me', env.prefsPatches().length === 1,
    JSON.stringify(env.prefsPatches()));

  // Guardas: ningun otro evento de sesion toca el idioma
  w.EventBus.emit('auth:user-updated', { user: { ...USER, preferencias: { idioma: 'pt' } } });
  w.EventBus.emit('auth:synced', { user: { ...USER, preferencias: { idioma: 'pt' } } });
  await tick();
  check('auth:user-updated no cambia el idioma', env.locale() === 'en', env.locale());
  check('auth:synced no cambia el idioma', env.locale() === 'en', env.locale());

  env.close();
}

// =====================================================================
// S2 - fallo del PATCH: conserva eleccion local + toast
// =====================================================================
async function s2_falloPatch() {
  console.log('S2 el PATCH fallido conserva la eleccion local y avisa con toast');
  const env = createEnv({ token: 'tk' });
  const w = env.w;

  await w.i18n.init();
  await tick();
  w.AuthState.setSession(USER, TOKENS);
  await tick();

  env.failPatch = true;
  env.setLang('pt');
  await tick();

  check('la eleccion local se aplica aunque el PATCH falle', env.locale() === 'pt', env.locale());
  check('localStorage conserva la eleccion', w.localStorage.getItem('lang') === 'pt');
  check('el PATCH falido se intento', env.prefsPatches().length === 1
    && env.prefsPatches()[0].body.idioma === 'pt', JSON.stringify(env.prefsPatches()));

  const toast = env.calls.toasts[env.calls.toasts.length - 1];
  check('emite toast de aviso', !!toast && toast.type === 'warning', JSON.stringify(toast));
  check('mensaje del toast resuelto (no clave cruda)',
    !!toast && typeof toast.message === 'string'
    && toast.message !== 'ui.toast_lang_save_failed'
    && toast.message.indexOf('ui.') !== 0, JSON.stringify(toast));

  // Tras el fallo se permite reintentar en la proxima eleccion
  env.failPatch = false;
  env.setLang('es');
  await tick();
  check('reintento tras el fallo con la nueva eleccion', env.prefsPatches().length === 2
    && env.prefsPatches()[1].body.idioma === 'es', JSON.stringify(env.prefsPatches()));
  check('sin toasts adicionales cuando el reintento funciona', env.calls.toasts.length === 1,
    JSON.stringify(env.calls.toasts));

  env.close();
}

// =====================================================================
// S3 - syncFromUser solo en el login; /auth/me posterior no cambia nada
// =====================================================================
async function s3_syncSoloEnLogin() {
  console.log('S3 la preferencia del servidor se aplica SOLO en el login');
  const env = createEnv({ token: 'tk' });
  const w = env.w;

  await w.i18n.init();
  await tick();

  w.AuthState.setSession({ ...USER, preferencias: { idioma: 'fr' } }, TOKENS);
  await tick();

  check('el login aplica la preferencia del servidor (fr)', env.locale() === 'fr', env.locale());
  check('el login NO escribe preferencias en el servidor', env.prefsPatches().length === 0,
    JSON.stringify(env.prefsPatches()));

  env.meUser = { ...USER, preferencias: { idioma: 'pt' } };
  const refreshed = await w.AuthState.refreshUser();
  await tick();

  check('/auth/me posterior trajo otra preferencia (pt)',
    refreshed && refreshed.preferencias.idioma === 'pt');
  check('el idioma NO cambia tras /auth/me', env.locale() === 'fr', env.locale());
  check('lang attribute sigue fr', env.langAttr() === 'fr', env.langAttr());
  check('cero PATCH durante todo el flujo', env.prefsPatches().length === 0,
    JSON.stringify(env.prefsPatches()));

  env.close();
}

// =====================================================================
// S4 - eleccion pre-sesion, confirmacion en auth:logout/login y dedupe
// =====================================================================
async function s4_presesionYLogout() {
  console.log('S4 eleccion pre-sesion, confirmacion al login y reset en logout');
  const env = createEnv(); // sin token: pantalla de login
  const w = env.w;

  await w.i18n.init();
  await tick();

  env.setLang('fr');
  await tick();
  check('eleccion pre-sesion aplica fr', env.locale() === 'fr', env.locale());
  check('sin sesion NO se envia PATCH', env.prefsPatches().length === 0,
    JSON.stringify(env.prefsPatches()));

  w.AuthState.init();
  // El login trae preferencias es, pero la eleccion explicita de la sesion manda
  w.AuthState.setSession({ ...USER, preferencias: { idioma: 'es' } }, TOKENS);
  await tick();

  check('la eleccion pre-sesion gana sobre la preferencia del login',
    env.locale() === 'fr', env.locale());
  check('auth:login confirma la eleccion con un PATCH',
    env.prefsPatches().length === 1 && env.prefsPatches()[0].body.idioma === 'fr',
    JSON.stringify(env.prefsPatches()));

  // 'auth:login' se emite mas de una vez en el flujo real (setSession +
  // login.js): no debe duplicar el PATCH.
  w.EventBus.emit('auth:login', { user: USER });
  w.EventBus.emit('auth:login', { user: USER });
  await tick();
  check('el PATCH no se duplica con auth:login repetido', env.prefsPatches().length === 1,
    JSON.stringify(env.prefsPatches()));

  // Re-elegir el mismo idioma tampoco repite el PATCH
  env.setLang('fr');
  await tick();
  check('re-elegir el mismo idioma no repite el PATCH', env.prefsPatches().length === 1,
    JSON.stringify(env.prefsPatches()));

  // Logout: reinicia la eleccion de sesion; el proximo login si aplica servidor
  await w.AuthState.logout();
  await tick();
  check('tras logout el idioma no cambia', env.locale() === 'fr', env.locale());

  w.AuthState.setSession({ ...USER, preferencias: { idioma: 'pt' } }, TOKENS);
  await tick();
  check('tras logout el nuevo login aplica la preferencia del servidor (pt)',
    env.locale() === 'pt', env.locale());

  env.close();
}

// =====================================================================
async function main() {
  await s1_secuencia();
  await s2_falloPatch();
  await s3_syncSoloEnLogin();
  await s4_presesionYLogout();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
