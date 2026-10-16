/**
 * Smoke test jsdom para el modulo profile/profile (v1.15.0).
 * Uso: node tools/smoke/smoke-profile-mfa.mjs   (desde la raiz del repo)
 *
 * Cobertura:
 *  S1  Tab Seguridad: card de password + card MFA, lazy-load de /auth/mfa/status
 *  S2  MFA desactivado -> setup -> QR svg en caja blanca + secreto en details
 *  S3  Enable -> codigos de respaldo mostrados una sola vez
 *  S4  MFA activado -> badge success, restantes y desactivacion con codigo
 *  S5  Cancelar setup vuelve al estado desactivado
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  profile: read('modules/profile/profile.js'),
  i18n: read('core/i18n.js'),
  qrcode: read('vendor/qrcode.js')
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

const USER = { nombre: 'Ale', apellido: 'On', email: 'aleon@acme.local' };

function createEnv({ getImpl = null, postImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/#/profile', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;
  const calls = { gets: [], posts: [], patches: [], toasts: [] };

  w.console = { log() {}, info() {}, debug() {}, warn() {}, error() {} };
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
    getUser: () => USER,
    getPermissions: () => [],
    getRoles: () => ['ADMIN'],
    hasPermission: () => false,
    isAuthenticated: () => true,
    updateUser(u) { this._u = u; },
    logout() {}
  };

  const env = { w, calls, getImpl, postImpl, dom };
  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (env.getImpl) return env.getImpl(p, options, env);
      if (p === '/auth/mfa/status') {
        return { status: 200, data: { habilitado: false, metodo: null, pending_setup: false, backup_codes_remaining: 0 } };
      }
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    },
    async post(p, body, options = {}) {
      calls.posts.push({ path: p, body, options });
      if (env.postImpl) return env.postImpl(p, body, options, env);
      return { status: 200, data: {} };
    },
    async patch(p, body, options = {}) {
      calls.patches.push({ path: p, body, options });
      return { status: 200, data: { user: USER } };
    },
    async upload() { return { status: 200, data: {} }; },
    async delete() { return { status: 200, data: {} }; }
  };

  w.ModuleLoader = { async load() {}, has: () => false };

  const evalIn = (code) => w.eval(code);
  evalIn(FILES.i18n);
  w.i18n.inject('es', ES_DICT);
  UI_FILES.forEach(evalIn);
  evalIn(FILES.qrcode);
  evalIn(FILES.profile);

  env.container = w.document.getElementById('module-container');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.init = () => { w.ProfileProfileModule.init({}, env.container); };
  env.close = () => dom.window.close();
  return env;
}

async function openSecurityTab(env) {
  env.init();
  await tick();
  const secTab = env.container.querySelector('.tab[data-tab="security"]');
  env.click(secTab);
  await tick();
}

// =====================================================================
// S1 - TAB SEGURIDAD: password + MFA y lazy-load del status
// =====================================================================
async function s1_security_tab() {
  console.log('S1 tab Seguridad: cards password y MFA + lazy-load');
  const env = createEnv();
  await openSecurityTab(env);

  check('card de password visible',
    env.container.textContent.includes(ES_DICT.profile.section_password));
  check('card MFA visible',
    env.container.textContent.includes(ES_DICT.profile.mfa_title));
  check('GET /auth/mfa/status lanzado al entrar',
    env.calls.gets.some(g => g.path === '/auth/mfa/status'),
    JSON.stringify(env.calls.gets));
  check('estado desactivado con badge y boton setup',
    env.container.textContent.includes(ES_DICT.profile.mfa_status_off) &&
    env.container.textContent.includes(ES_DICT.profile.mfa_btn_setup));
  check('boton setup con testid', !!env.container.querySelector('[data-testid="profile-mfa-setup-btn"]'));
  env.close();
}

// =====================================================================
// S2 - SETUP: QR svg + secreto en details
// =====================================================================
async function s2_setup_qr() {
  console.log('S2 setup: QR svg en caja blanca + secreto en details');
  const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
  const OTPAUTH = `otpauth://totp/XP:aleon@acme.local?secret=${SECRET}&issuer=XP&algorithm=SHA1&digits=6&period=30`;
  const env = createEnv({
    postImpl: (p) => {
      if (p === '/auth/mfa/setup') {
        return { status: 200, data: { secret: SECRET, otpauth_url: OTPAUTH } };
      }
      return { status: 200, data: {} };
    }
  });
  await openSecurityTab(env);

  check('window.qrcode expuesta por el vendor', typeof env.w.qrcode === 'function');
  env.click(env.container.querySelector('[data-testid="profile-mfa-setup-btn"]'));
  await tick();

  check('POST /auth/mfa/setup lanzado',
    env.calls.posts.some(pp => pp.path === '/auth/mfa/setup'));
  const qr = env.container.querySelector('[data-testid="mfa-qr"]');
  check('QR renderizado (svg)', !!qr && !!qr.querySelector('svg'));
  check('QR en caja blanca (escaneable en tema oscuro)', !!qr && qr.className.includes('bg-white'));
  const details = env.container.querySelector('[data-testid="mfa-secret"]');
  check('secreto dentro de details colapsado',
    !!details && !!details.closest('details') && details.closest('details').open === false);
  check('summary manual usa i18n profile.mfa_manual_title',
    env.container.textContent.includes(ES_DICT.profile.mfa_manual_title));
  const otpLink = env.container.querySelector('[data-testid="mfa-otpauth-uri"]');
  check('URI otpauth dentro del details', !!otpLink && !!otpLink.closest('details'));
  check('campo de codigo de confirmacion presente',
    !!env.container.querySelector('[name="mfa_code_setup"]'));
  env.close();
}

// =====================================================================
// S3 - ENABLE: codigos de respaldo una sola vez
// =====================================================================
async function s3_enable_backup_codes() {
  console.log('S3 enable: codigos de respaldo mostrados una sola vez');
  const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
  const OTPAUTH = `otpauth://totp/XP:aleon@acme.local?secret=${SECRET}&issuer=XP`;
  const BACKUPS = ['K7QW-2MTR', 'P3XZ-9BND', 'J5VC-4LKA'];
  let habilitado = false;
  const env = createEnv({
    postImpl: (p, body) => {
      if (p === '/auth/mfa/setup') return { status: 200, data: { secret: SECRET, otpauth_url: OTPAUTH } };
      if (p === '/auth/mfa/enable') {
        habilitado = true;
        return { status: 200, data: { habilitado: true, backup_codes: BACKUPS } };
      }
      return { status: 200, data: {} };
    },
    getImpl: (p) => {
      if (p === '/auth/mfa/status') {
        return { status: 200, data: { habilitado: habilitado, metodo: habilitado ? 'totp' : null, pending_setup: false, backup_codes_remaining: habilitado ? 9 : 0 } };
      }
      throw Object.assign(new Error('GET no soportado'), { status: 404, data: null });
    }
  });
  await openSecurityTab(env);

  env.click(env.container.querySelector('[data-testid="profile-mfa-setup-btn"]'));
  await tick();

  // enable sin codigo -> error de campo
  env.click(env.container.querySelector('[data-testid="profile-mfa-enable-btn"]'));
  await tick();
  check('enable sin codigo marca error de campo',
    env.container.textContent.includes(ES_DICT.profile.mfa_code_required));
  check('POST /auth/mfa/enable NO lanzado sin codigo',
    !env.calls.posts.some(pp => pp.path === '/auth/mfa/enable'));

  // enable con codigo
  const input = env.container.querySelector('[name="mfa_code_setup"]');
  input.value = '123456';
  env.click(env.container.querySelector('[data-testid="profile-mfa-enable-btn"]'));
  await tick();

  check('POST /auth/mfa/enable lanzado con el codigo',
    env.calls.posts.some(pp => pp.path === '/auth/mfa/enable' && pp.body && pp.body.code === '123456'));
  check('codigos de respaldo en el DOM (una sola vez)',
    env.container.textContent.includes(BACKUPS[0]) &&
    env.container.textContent.includes(ES_DICT.profile.mfa_backup_title));
  check('boton Ya los guardé presente',
    !!env.container.querySelector('[data-testid="profile-mfa-backup-done-btn"]'));

  // tras "ya los guardé" y con status habilitado, se muestra estado activo
  env.click(env.container.querySelector('[data-testid="profile-mfa-backup-done-btn"]'));
  await tick();
  check('tras backup-done: badge success y restantes',
    env.container.textContent.includes(ES_DICT.profile.mfa_status_on) &&
    env.container.textContent.includes('9'));
  check('campo de desactivacion presente',
    !!env.container.querySelector('[name="mfa_code_disable"]'));
  env.close();
}

// =====================================================================
// S4 - MFA ACTIVADO: badge, restantes y desactivar
// =====================================================================
async function s4_enabled_disable() {
  console.log('S4 activado: badge success, restantes y desactivar');
  const env = createEnv({
    getImpl: (p) => {
      if (p === '/auth/mfa/status') {
        return { status: 200, data: { habilitado: true, metodo: 'totp', pending_setup: false, backup_codes_remaining: 7 } };
      }
      throw Object.assign(new Error('GET no soportado'), { status: 404, data: null });
    },
    postImpl: (p, body) => {
      if (p === '/auth/mfa/disable') return { status: 200, data: { habilitado: false } };
      return { status: 200, data: {} };
    }
  });
  await openSecurityTab(env);

  check('badge MFA activado', env.container.textContent.includes(ES_DICT.profile.mfa_status_on));
  check('restantes visibles (7)', env.container.textContent.includes('7'));
  check('sin boton setup cuando esta activo',
    !env.container.querySelector('[data-testid="profile-mfa-setup-btn"]'));

  // disable sin codigo
  env.click(env.container.querySelector('[data-testid="profile-mfa-disable-btn"]'));
  await tick();
  check('disable sin codigo marca error de campo',
    env.container.textContent.includes(ES_DICT.profile.mfa_code_required));
  check('POST /auth/mfa/disable NO lanzado sin codigo',
    !env.calls.posts.some(pp => pp.path === '/auth/mfa/disable'));

  // disable con codigo
  const input = env.container.querySelector('[name="mfa_code_disable"]');
  input.value = '654321';
  env.click(env.container.querySelector('[data-testid="profile-mfa-disable-btn"]'));
  await tick();
  check('POST /auth/mfa/disable lanzado con el codigo',
    env.calls.posts.some(pp => pp.path === '/auth/mfa/disable' && pp.body && pp.body.code === '654321'));
  check('toast de desactivado', env.calls.toasts.length > 0);
  env.close();
}

// =====================================================================
// S5 - CANCELAR SETUP
// =====================================================================
async function s5_cancel_setup() {
  console.log('S5 cancelar setup: vuelve al estado desactivado');
  const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
  const OTPAUTH = `otpauth://totp/XP:aleon@acme.local?secret=${SECRET}&issuer=XP`;
  const env = createEnv({
    postImpl: (p) => {
      if (p === '/auth/mfa/setup') return { status: 200, data: { secret: SECRET, otpauth_url: OTPAUTH } };
      return { status: 200, data: {} };
    }
  });
  await openSecurityTab(env);

  env.click(env.container.querySelector('[data-testid="profile-mfa-setup-btn"]'));
  await tick();
  check('setup: QR presente', !!env.container.querySelector('[data-testid="mfa-qr"]'));

  env.click(env.container.querySelector('[data-testid="profile-mfa-cancel-btn"]'));
  await tick();
  check('cancel: QR ausente y estado desactivado de nuevo',
    !env.container.querySelector('[data-testid="mfa-qr"]') &&
    env.container.textContent.includes(ES_DICT.profile.mfa_status_off));
  env.close();
}

// =====================================================================
// RUN
// =====================================================================
async function main() {
  await s1_security_tab();
  await s2_setup_qr();
  await s3_enable_backup_codes();
  await s4_enabled_disable();
  await s5_cancel_setup();
  console.log(`\n${pass} ok, ${fail} fail`);
  process.exit(fail > 0 ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
