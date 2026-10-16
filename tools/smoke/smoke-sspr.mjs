/**
 * Smoke test jsdom para el Password Reset SSPR (v1.17.0).
 * Uso: node smoke-sspr.mjs   (desde .../Temp/opencode/jsdom-env)
 *
 * Cubre: enlace "¿Olvidaste tu contraseña?" en login, formulario de reset
 * con mensaje SIEMPRE genérico (anti-enumeración) y validación de política
 * en el cliente del formulario de fijar contraseña.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  i18n: read('core/i18n.js'),
  login: read('modules/auth/login.js'),
  forgot: read('modules/auth/forgot-password.js'),
  reset: read('modules/auth/reset-password.js')
};

let pass = 0;
let fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${extra ? ' :: ' + extra : ''}`); }
}
async function tick(n = 6) {
  for (let i = 0; i < n; i++) await new Promise(r => setTimeout(r, 0));
}

// =====================================================================
// ENTORNO (una ventana jsdom con stubs; sin AuthState: son vistas públicas)
// =====================================================================
function createEnv({ postImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { posts: [], toasts: [], errors: [] };

  w.console = {
    log() {}, info() {}, debug() {},
    warn(...a) { calls.errors.push(a.map(String).join(' ')); },
    error(...a) { calls.errors.push(a.map(String).join(' ')); }
  };

  w.AppConfig = { apiBase: '/api', baseUrl: '/xp/public', appName: 'XP Platform' };

  w.EventBus = {
    on() {}, off() {},
    emit(event, data) { if (event === 'toast:show') calls.toasts.push(data); }
  };

  const env = { w, calls, dom, postImpl };

  w.HttpClient = {
    async post(p, body, options = {}) {
      calls.posts.push({ path: p, body, options });
      if (env.postImpl) return env.postImpl(p, body, options);
      return { status: 200, data: { ok: true } };
    },
    async get() { throw new Error('GET no soportado en smoke SSPR'); }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;

  // i18n runtime + diccionario es inyectado (sin red).
  evalIn(FILES.i18n);
  w.i18n.inject('es', ES_DICT);

  env.container = w.document.getElementById('module-container');
  env.submit = (form) => form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  env.close = () => dom.window.close();

  env.loadLogin = () => { evalIn(FILES.login); w.AuthLoginModule.init({}, env.container); };
  env.loadForgot = () => { evalIn(FILES.forgot); w.AuthForgotPasswordModule.init({}, env.container); };
  env.loadReset = (params) => { evalIn(FILES.reset); w.AuthResetPasswordModule.init(params, env.container); };

  return env;
}

// =====================================================================
// S1 - LOGIN: enlace "¿Olvidaste tu contraseña?"
// =====================================================================
async function s1_login_forgot_link() {
  console.log('S1 login: enlace de recuperación siempre visible');
  const env = createEnv();
  env.loadLogin();
  await tick();

  const wrap = env.container.querySelector('#login-forgot-wrap');
  const link = env.container.querySelector('#login-forgot');
  check('contenedor del enlace presente', !!wrap);
  check('enlace #login-forgot presente', !!link);
  check('enlace apunta a #/forgot-password', !!link && link.getAttribute('href') === '#/forgot-password', link && link.getAttribute('href'));
  check('texto del enlace traducido (no vacío)', !!link && link.textContent.trim().length > 0, link && link.textContent);
  check('formulario de login renderizado', !!env.container.querySelector('#login-form'));
  env.close();
}

// =====================================================================
// S2 - OLVIDÉ MI CONTRASEÑA: SIEMPRE mensaje genérico
// =====================================================================
async function s2_forgot_form() {
  console.log('S2 olvidé: formulario genérico anti-enumeración');

  // Form inicial
  const env = createEnv();
  env.loadForgot();
  await tick();
  check('formulario #forgot-form renderizado', !!env.container.querySelector('#forgot-form'));
  check('campo email presente', !!env.container.querySelector('#forgot-email'));
  check('panel de éxito oculto al inicio', env.container.querySelector('#forgot-success').classList.contains('hidden'));

  // Email inválido: no envía, muestra error de campo
  env.container.querySelector('#forgot-email').value = 'no-es-email';
  env.submit(env.container.querySelector('#forgot-form'));
  await tick();
  check('email inválido no envía POST', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo email visible', !env.container.querySelector('[data-error="email"]').classList.contains('hidden'));
  env.close();

  // Email válido: envía y muestra SIEMPRE el panel genérico
  const env2 = createEnv();
  env2.loadForgot();
  await tick();
  env2.container.querySelector('#forgot-email').value = 'trabajador@acme.local';
  env2.submit(env2.container.querySelector('#forgot-form'));
  await tick();
  check('POST /auth/forgot-password', env2.calls.posts[0] && env2.calls.posts[0].path === '/auth/forgot-password', JSON.stringify(env2.calls.posts[0] || null));
  check('payload con email', env2.calls.posts[0].body.email === 'trabajador@acme.local');
  check('marcado requiresAuth:false', env2.calls.posts[0].options.requiresAuth === false);
  check('formulario oculto tras enviar', env2.container.querySelector('#forgot-form').classList.contains('hidden'));
  check('panel de éxito visible', !env2.container.querySelector('#forgot-success').classList.contains('hidden'));
  check('mensaje genérico (sin revelar existencia)', env2.container.querySelector('#forgot-success').textContent.trim().length > 0);
  env2.close();

  // Anti-enumeración: aunque el backend falle, se muestra el MISMO aviso
  const env3 = createEnv({ postImpl: () => { throw Object.assign(new Error('fallo'), { status: 500 }); } });
  env3.loadForgot();
  await tick();
  env3.container.querySelector('#forgot-email').value = 'nadie@acme.local';
  env3.submit(env3.container.querySelector('#forgot-form'));
  await tick();
  check('en error también se muestra el éxito genérico',
    !env3.container.querySelector('#forgot-success').classList.contains('hidden'));
  check('sin alerta de error global', env3.container.querySelector('#forgot-error').classList.contains('hidden'));
  env3.close();
}

// =====================================================================
// S3 - FIJAR CONTRASEÑA: token, política de cliente y envío
// =====================================================================
async function s3_reset_form() {
  console.log('S3 reset: token, política de cliente y envío');

  // Sin token: el formulario se oculta y avisa
  const envNone = createEnv();
  envNone.loadReset({});
  await tick();
  check('sin token el formulario está oculto', envNone.container.querySelector('#reset-form').classList.contains('hidden'));
  check('aviso de token inválido', !envNone.container.querySelector('#reset-error').classList.contains('hidden'));
  envNone.close();

  // Con token: formulario visible
  const env = createEnv();
  env.loadReset({ token: 'tok-abc123' });
  await tick();
  check('con token el formulario está visible', !env.container.querySelector('#reset-form').classList.contains('hidden'));
  check('campo password_nuevo presente', !!env.container.querySelector('#reset-password_nuevo'));
  check('campo password_confirm presente', !!env.container.querySelector('#reset-password_confirm'));

  // Política débil (8 chars, sin especial): no envía, error de campo
  env.container.querySelector('#reset-password_nuevo').value = 'Abcdef12';
  env.container.querySelector('#reset-password_confirm').value = 'Abcdef12';
  env.submit(env.container.querySelector('#reset-form'));
  await tick();
  check('password débil no envía POST', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo password_nuevo', !env.container.querySelector('[data-error="password_nuevo"]').classList.contains('hidden'));

  // Confirmación distinta: no envía, error en confirm
  env.container.querySelector('#reset-password_nuevo').value = 'NuevaClave123!';
  env.container.querySelector('#reset-password_confirm').value = 'OtraClave123!';
  env.submit(env.container.querySelector('#reset-form'));
  await tick();
  check('confirm distinto no envía POST', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo password_confirm', !env.container.querySelector('[data-error="password_confirm"]').classList.contains('hidden'));
  env.close();

  // Válido: envía token + password y muestra éxito + toast
  const env2 = createEnv();
  env2.loadReset({ token: 'tok-abc123' });
  await tick();
  env2.container.querySelector('#reset-password_nuevo').value = 'NuevaClave123!';
  env2.container.querySelector('#reset-password_confirm').value = 'NuevaClave123!';
  env2.submit(env2.container.querySelector('#reset-form'));
  await tick();
  check('POST /auth/reset-password', env2.calls.posts[0] && env2.calls.posts[0].path === '/auth/reset-password', JSON.stringify(env2.calls.posts[0] || null));
  check('payload con token y password_nuevo',
    env2.calls.posts[0].body.token === 'tok-abc123' && env2.calls.posts[0].body.password_nuevo === 'NuevaClave123!');
  check('marcado requiresAuth:false', env2.calls.posts[0].options.requiresAuth === false);
  check('formulario oculto tras éxito', env2.container.querySelector('#reset-form').classList.contains('hidden'));
  check('panel de éxito visible', !env2.container.querySelector('#reset-success').classList.contains('hidden'));
  check('toast de éxito emitido', env2.calls.toasts.some(t => t.type === 'success'), JSON.stringify(env2.calls.toasts));
  env2.close();

  // Error del backend (token inválido): se muestra el mensaje traducido
  const env3 = createEnv({ postImpl: () => { throw Object.assign(new Error('El enlace de restablecimiento no es válido o ya fue usado.'), { status: 400 }); } });
  env3.loadReset({ token: 'tok-malo' });
  await tick();
  env3.container.querySelector('#reset-password_nuevo').value = 'NuevaClave123!';
  env3.container.querySelector('#reset-password_confirm').value = 'NuevaClave123!';
  env3.submit(env3.container.querySelector('#reset-form'));
  await tick();
  check('error del backend visible en alerta', !env3.container.querySelector('#reset-error').classList.contains('hidden'));
  check('muestra el mensaje del backend', /enlace|restablecimiento|usado/i.test(env3.container.querySelector('#reset-error').textContent),
    env3.container.querySelector('#reset-error').textContent);
  env3.close();
}

// =====================================================================
async function main() {
  await s1_login_forgot_link();
  await s2_forgot_form();
  await s3_reset_form();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
