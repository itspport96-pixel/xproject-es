/**
 * Smoke test jsdom del modal "Anadir miembro" (M1, docs/UI_STANDARD.md §6.6).
 * Uso: node tools/smoke/smoke-m1-add-member.mjs   (desde la raiz del repo)
 *
 * Cubre: apertura con combobox (foco, carga inicial, aria), primera opcion
 * resaltada, chips (anadir/quitar), exclusion de miembros existentes,
 * debounce y respuestas fuera de secuencia, teclado (flechas/Enter/Esc),
 * validacion sin seleccion, alta multiple con POSTs secuenciales, fallo
 * parcial y escenarios de busqueda (error, vacio, stale).
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const UI_FILES = ['ui/dom.js', 'ui/render.js', 'ui/view.js', 'ui/modal.js', 'ui/actions.js'].map(read);
const I18N_FILE = read('core/i18n.js');
const GROUPS_FILE = read('modules/groups/groups.js');

const GROUPS = [
  { id: 'g1', nombre: 'Almacen Central', descripcion: 'Operarios', activo: true }
];

const MEMBERS = {
  g1: [
    { membership_id: 'm1', user_id: 'u1', email: 'ana@acme.local', nombre: 'Ana', apellido: 'Torres', estado: 'ACTIVO', added_at: '2026-01-01' },
    { membership_id: 'm2', user_id: 'u2', email: 'bruno@acme.local', nombre: 'Bruno', apellido: 'Diaz', estado: 'ACTIVO', added_at: '2026-02-01' }
  ]
};

const USERS = [
  { id: 'u1', email: 'ana@acme.local', nombre: 'Ana', apellido: 'Torres', estado: 'ACTIVO' },
  { id: 'u2', email: 'bruno@acme.local', nombre: 'Bruno', apellido: 'Diaz', estado: 'ACTIVO' },
  { id: 'u3', email: 'carla@acme.local', nombre: 'Carla', apellido: 'Rios', estado: 'ACTIVO' },
  { id: 'u4', email: 'diego@acme.local', nombre: 'Diego', apellido: 'Santos', estado: 'ACTIVO' }
];

const ADMIN_PERMS = [
  'users.user.view', 'users.user.create', 'users.user.edit', 'users.user.status',
  'users.password.reset', 'temporary.permission.view', 'temporary.permission.grant',
  'temporary.permission.revoke', 'roles.role.view', 'roles.role.create',
  'roles.role.edit', 'roles.role.delete', 'groups.group.view', 'groups.group.create',
  'groups.group.edit', 'groups.group.delete', 'groups.group.manage_members',
  'groups.group.manage_roles', 'permissions.permission.view', 'inventory.stock.view'
];

let pass = 0;
let fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${extra ? ' :: ' + extra : ''}`); }
}
async function tick(n = 8) {
  for (let i = 0; i < n; i++) await new Promise(r => setTimeout(r, 0));
}
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// =====================================================================
// ENTORNO
// =====================================================================

function createEnv({ postImpl = null, getUsersImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;
  const calls = { gets: [], posts: [], toasts: [], errors: [] };

  Object.defineProperty(w.Element.prototype, 'scrollIntoView', { value: function() {}, configurable: true });
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
  w.AuthState = {
    getUser: () => ({ nombre: 'Test', apellido: 'User', email: 'test@acme.local', roles: [{ nombre: 'ADMIN', permisos: ADMIN_PERMS }] }),
    getPermissions: () => ADMIN_PERMS.slice(),
    getRoles: () => ['ADMIN'],
    hasPermission: (p) => ADMIN_PERMS.includes(p),
    isAuthenticated: () => true,
    logout() {}
  };

  const env = { w, calls, postImpl, getUsersImpl, dom };

  const resolveGet = (p, options) => {
    if (p === '/groups') return { status: 200, data: { groups: GROUPS } };
    const mMembers = /^\/groups\/([^/]+)\/members$/.exec(p);
    if (mMembers) {
      const list = MEMBERS[mMembers[1]] || [];
      return { status: 200, data: { members: list, total: list.length } };
    }
    const mRoles = /^\/groups\/([^/]+)\/roles$/.exec(p);
    if (mRoles) return { status: 200, data: { roles: [] } };
    if (p.startsWith('/locales/')) {
      const loc = p.slice('/locales/'.length).replace(/\.json$/, '');
      return { status: 200, data: { locale: loc, version: 'smoke', dictionary: ES_DICT } };
    }
    throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
  };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (p === '/users' && env.getUsersImpl) return env.getUsersImpl(options);
      if (p === '/users') {
        return { status: 200, data: { data: USERS, pagination: { total: USERS.length, page: 1, per_page: 50, has_next: false } } };
      }
      return resolveGet(p, options);
    },
    async post(p, body, options = {}) {
      calls.posts.push({ path: p, body, options });
      if (env.postImpl) return env.postImpl(p, body, options, env);
      return { status: 201, data: { ok: true } };
    },
    async delete(p, options = {}) {
      return { status: 200, data: { ok: true } };
    }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  evalIn(I18N_FILE);
  w.i18n.inject('es', ES_DICT);
  UI_FILES.forEach(evalIn);
  evalIn(GROUPS_FILE);
  // jsdom deja el documento "loading": view.js/modal.js difieren su binde
  // global a DOMContentLoaded (patron de los smokes M0/M1).
  if (w.document.readyState === 'loading') {
    w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  }

  env.container = w.document.getElementById('module-container');
  env.modalRoot = () => env.container.querySelector('[data-modal-root]');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.key = (key, target) => {
    const ev = new w.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    (target || env.modalRoot()).dispatchEvent(ev);
    return !ev.defaultPrevented;
  };
  env.type = (el, value) => {
    el.value = value;
    el.dispatchEvent(new w.Event('input', { bubbles: true }));
  };
  env.membersGets = () => env.calls.gets.filter(g => /^\/groups\/[^/]+\/members$/.test(g.path)).length;
  env.close = () => dom.window.close();
  return env;
}

async function openAdd(env) {
  w_LoadGroups(env);
  await tick(12);
  env.click(env.container.querySelector('[data-action="open-detail"][data-id="g1"]'));
  await tick(12);
  const root = env.modalRoot();
  const addBtn = root && root.querySelector('[data-action="open-add-member"]');
  if (addBtn) env.click(addBtn);
  await tick(12);
  return env.modalRoot();
}

function w_LoadGroups(env) {
  env.w.GroupsGroupsModule.init({}, env.container);
}

// =====================================================================
// S1 - apertura: combobox, foco, aria, carga inicial, pie, exclusiones
// =====================================================================
async function s1_open() {
  console.log('S1 apertura: combobox, foco, aria, carga inicial, pie, exclusiones');
  const env = createEnv();
  const root = await openAdd(env);
  const combo = root && root.querySelector('[data-combo-input]');

  check('modal abierto con combobox', !!root && !!combo, root ? 'sin [data-combo-input]' : 'sin modal');
  check('foco en el combobox al abrir',
    !!combo && env.w.document.activeElement === combo,
    env.w.document.activeElement ? env.w.document.activeElement.tagName : 'sin foco');
  check('aria del combobox (role/expanded/controls/autocomplete)',
    !!combo && combo.getAttribute('role') === 'combobox'
      && combo.getAttribute('aria-controls') && combo.getAttribute('aria-autocomplete') === 'list'
      && combo.getAttribute('data-modal-field') === 'user_id');
  check('lista role=listbox', !!root.querySelector('ul.combo-list[role="listbox"]'));

  const label = root.querySelector('.combo-group .form-label');
  check('etiqueta Usuarios', !!label && label.textContent.includes('Usuarios'), label ? label.textContent : 'sin label');
  const hint = root.querySelector('.combo-group .form-hint');
  check('ayuda existente', !!hint && hint.textContent.includes('Sólo se listan usuarios activos'), hint ? hint.textContent : 'sin hint');

  const modal = root.querySelector('.modal');
  check('tamaño md (modal-md)', !!modal && modal.classList.contains('modal-md'), modal ? modal.className : 'sin .modal');
  const footer = root.querySelector('.modal-footer');
  check('pie: Cancelar vuelve al detalle', !!footer && !!footer.querySelector('[data-action="back-detail"]')
    && footer.querySelector('[data-action="back-detail"]').textContent.trim() === 'Cancelar');
  check('pie: primario Añadir con data-modal-send',
    !!footer && !!footer.querySelector('[data-action="submit-add-member"][data-modal-send]'));

  check('carga inicial de candidatos (sin término)',
    env.calls.gets.some(g => g.path === '/users'
      && g.options.params && g.options.params.estado === 'ACTIVO'
      && g.options.params.per_page === 50 && !g.options.params.search),
    JSON.stringify(env.calls.gets.filter(g => g.path === '/users')));
  check('aria-expanded=true con la lista visible', combo.getAttribute('aria-expanded') === 'true');

  const opts = [...root.querySelectorAll('.combo-option')];
  check('sugerencias pintadas (sólo candidatos)', opts.length === 2
    && opts.every(o => o.dataset.value === 'u3' || o.dataset.value === 'u4'),
    JSON.stringify(opts.map(o => o.dataset.value)));
  check('los miembros existentes quedan excluidos',
    !root.querySelector('.combo-list').textContent.includes('Ana Torres')
      && !root.querySelector('.combo-list').textContent.includes('Bruno'));
  check('primera opción resaltada por defecto', opts[0] && opts[0].classList.contains('is-active')
    && combo.getAttribute('aria-activedescendant') === opts[0].id, combo.getAttribute('aria-activedescendant'));
  env.close();
}

// =====================================================================
// S2 - chips: anadir, limpiar input, dedupe, quitar y reintegrar
// =====================================================================
async function s2_chips() {
  console.log('S2 chips: anadir, limpiar input, excluir elegidos, quitar, reintegrar');
  const env = createEnv();
  const root = await openAdd(env);

  // buscar con debounce: el GET no sale hasta los 250 ms
  env.type(root.querySelector('[data-combo-input]'), 'car');
  await tick(3);
  check('debounce: sin GET con search antes de 250 ms',
    !env.calls.gets.some(g => g.path === '/users' && g.options.params && g.options.params.search === 'car'),
    JSON.stringify(env.calls.gets.filter(g => g.path === '/users')));
  await sleep(320);
  await tick(6);
  check('búsqueda enviada tras el debounce',
    env.calls.gets.some(g => g.path === '/users' && g.options.params && g.options.params.search === 'car'),
    JSON.stringify(env.calls.gets.filter(g => g.path === '/users')));

  let r = env.modalRoot();
  let opts = [...r.querySelectorAll('.combo-option')];
  check('opciones tras buscar', opts.length === 2, JSON.stringify(opts.map(o => o.dataset.value)));
  env.click(opts.find(o => o.dataset.value === 'u3'));
  await tick();
  r = env.modalRoot();
  const chip = r.querySelector('[data-combo-chips] .chip');
  check('chip tras elegir', !!chip && chip.textContent.includes('Carla'), chip ? chip.textContent : 'sin chip');
  check('chip con quitar accesible', !!chip && !!chip.querySelector('[data-action="combo-remove"][aria-label]'));
  check('input limpio tras elegir', r.querySelector('[data-combo-input]').value === '');
  check('lista reabierta con los restantes (aria-expanded=true)',
    r.querySelector('[data-combo-input]').getAttribute('aria-expanded') === 'true');
  opts = [...r.querySelectorAll('.combo-option')];
  check('el elegido queda excluido de la lista', opts.length === 1 && opts[0].dataset.value === 'u4',
    JSON.stringify(opts.map(o => o.dataset.value)));

  // quitar el chip: sale de la selección y vuelve al conjunto inicial
  env.click(r.querySelector('[data-action="combo-remove"]'));
  await tick(6);
  r = env.modalRoot();
  check('chip eliminado', !r.querySelector('[data-combo-chips] .chip'));
  check('tras quitar, el usuario vuelve a la lista',
    !!r.querySelector(`.combo-option[data-value="u3"]`),
    r.querySelector('.combo-list') ? r.querySelector('.combo-list').textContent : 'sin lista');
  check('quitar devuelve el foco al combobox', env.w.document.activeElement === r.querySelector('[data-combo-input]'),
    env.w.document.activeElement ? env.w.document.activeElement.tagName : 'sin foco');
  env.close();
}

// =====================================================================
// S3 - teclado: flechas, Enter elige (no envía), Esc cierra lista y modal
// =====================================================================
async function s3_keyboard() {
  console.log('S3 teclado: flechas, Enter con lista, Esc lista->modal');
  const env = createEnv();
  const root = await openAdd(env);
  const combo = root.querySelector('[data-combo-input]');

  env.key('ArrowDown', combo);
  let ad = combo.getAttribute('aria-activedescendant');
  check('ArrowDown pasa a la segunda opción', /-opt-1$/.test(ad || ''), ad);
  env.key('ArrowUp', combo);
  ad = combo.getAttribute('aria-activedescendant');
  check('ArrowUp vuelve a la primera', /-opt-0$/.test(ad || ''), ad);

  const delivered = env.key('Enter', combo);
  await tick();
  const r = env.modalRoot();
  check('Enter con lista abierta se consume (no envía)', delivered === false && env.calls.posts.length === 0,
    `delivered=${delivered} posts=${env.calls.posts.length}`);
  check('Enter con lista abierta eligió la opción resaltada',
    !!r.querySelector('[data-combo-chips] .chip'), 'sin chip');
  check('la lista sigue abierta con los restantes', combo.getAttribute('aria-expanded') === 'true');
  const restantes = [...r.querySelectorAll('.combo-option')];
  check('quedan sólo los no elegidos', restantes.length === 1 && restantes[0].dataset.value === 'u4',
    JSON.stringify(restantes.map(o => o.dataset.value)));

  env.key('Escape', combo);
  check('Esc cierra la lista sin cerrar el modal',
    env.modalRoot().querySelector('[data-combo-input]').getAttribute('aria-expanded') === 'false'
      && !!env.modalRoot().querySelector('[data-modal]'));
  env.key('Escape', combo);
  check('segunda Esc cierra el modal', !env.modalRoot().querySelector('[data-modal]'));
  env.close();
}

// =====================================================================
// S4 - validación sin selección y Cancelar de vuelta al detalle
// =====================================================================
async function s4_validation() {
  console.log('S4 validación: sin selección no envía; Cancelar vuelve al detalle');
  const env = createEnv();
  const root = await openAdd(env);
  const combo = root.querySelector('[data-combo-input]');

  // Enter con la lista cerrada dispara el envío del pie
  env.key('Escape', combo);
  env.key('Enter', combo);
  await tick(6);
  let r = env.modalRoot();
  const err = r.querySelector('[data-error="user_id"]');
  check('Enter con lista cerrada intenta enviar y valida', !!err && !err.classList.contains('hidden'),
    err ? err.textContent : 'sin [data-error=user_id]');
  check('mensaje de validación add_required',
    !!err && err.textContent.includes('Selecciona al menos un usuario'),
    err ? err.textContent : '');
  check('sin POST sin selección', env.calls.posts.length === 0, String(env.calls.posts.length));

  env.click(r.querySelector('[data-action="submit-add-member"]'));
  await tick(6);
  check('clic en Añadir también valida y no envía', env.calls.posts.length === 0,
    String(env.calls.posts.length));

  const before = env.membersGets();
  r = env.modalRoot();
  env.click(r.querySelector('[data-action="back-detail"]'));
  await tick(8);
  r = env.modalRoot();
  check('Cancelar vuelve al detalle', !!r.querySelector('[data-action="detail-tab"]'),
    r.textContent.slice(0, 120));
  check('sin fallo no recarga los miembros', env.membersGets() === before,
    `${before} -> ${env.membersGets()}`);
  env.close();
}

// =====================================================================
// S5 - alta multiple: 2 POSTs secuenciales, toast plural y recarga
// =====================================================================
async function s5_multi_add() {
  console.log('S5 alta múltiple: 2 POSTs secuenciales, toast plural, recarga');
  const env = createEnv();
  const root = await openAdd(env);

  env.type(root.querySelector('[data-combo-input]'), 'car');
  await sleep(320);
  await tick(6);
  let r = env.modalRoot();
  env.click([...r.querySelectorAll('.combo-option')].find(o => o.dataset.value === 'u3'));
  await tick();

  r = env.modalRoot();
  env.type(r.querySelector('[data-combo-input]'), 'die');
  await sleep(320);
  await tick(6);
  r = env.modalRoot();
  const optU4 = [...r.querySelectorAll('.combo-option')].find(o => o.dataset.value === 'u4');
  check('segundo candidato disponible tras la primera selección', !!optU4,
    JSON.stringify([...r.querySelectorAll('.combo-option')].map(o => o.dataset.value)));
  env.click(optU4);
  await tick(6);

  r = env.modalRoot();
  const chips = [...r.querySelectorAll('[data-combo-chips] .chip')];
  check('dos chips seleccionados', chips.length === 2
    && chips[0].textContent.includes('Carla') && chips[1].textContent.includes('Diego'),
    JSON.stringify(chips.map(c => c.textContent.trim())));

  const membersBefore = env.membersGets();
  env.click(r.querySelector('[data-action="submit-add-member"]'));
  await tick(15);

  const posts = env.calls.posts;
  check('2 POSTs a /groups/g1/members en orden',
    posts.length === 2 && posts[0].path === '/groups/g1/members' && posts[0].body.user_id === 'u3'
      && posts[1].path === '/groups/g1/members' && posts[1].body.user_id === 'u4',
    JSON.stringify(posts.map(p => [p.path, p.body.user_id])));
  check('toast de éxito plural',
    env.calls.toasts.some(t => t.type === 'success' && /Miembros añadidos al grupo/.test(t.message || '')),
    JSON.stringify(env.calls.toasts));
  check('vuelve al detalle tras añadir',
    !!env.modalRoot().querySelector('[data-action="detail-tab"]'));
  check('detalle recargado tras añadir (1 GET más)', env.membersGets() === membersBefore + 1,
    `${membersBefore} -> ${env.membersGets()}`);
  env.close();
}

// =====================================================================
// S6 - fallo parcial: chips de los añadidos, error inline, recarga al volver
// =====================================================================
async function s6_partial_failure() {
  console.log('S6 fallo parcial: chips depurados, error inline, recarga al volver');
  let postCount = 0;
  const env = createEnv({
    postImpl: (p, body) => {
      postCount++;
      if (postCount === 2) {
        throw Object.assign(new Error('boom'), { status: 500, data: { message: 'boom' } });
      }
      return { status: 201, data: { ok: true } };
    }
  });
  const root = await openAdd(env);

  env.type(root.querySelector('[data-combo-input]'), 'car');
  await sleep(320);
  await tick(6);
  let r = env.modalRoot();
  env.click([...r.querySelectorAll('.combo-option')].find(o => o.dataset.value === 'u3'));
  await tick();
  r = env.modalRoot();
  env.type(r.querySelector('[data-combo-input]'), 'die');
  await sleep(320);
  await tick(6);
  r = env.modalRoot();
  env.click([...r.querySelectorAll('.combo-option')].find(o => o.dataset.value === 'u4'));
  await tick();

  const membersBefore = env.membersGets();
  r = env.modalRoot();
  env.click(r.querySelector('[data-action="submit-add-member"]'));
  await tick(15);
  r = env.modalRoot();

  check('intenta los 2 POSTs', env.calls.posts.length === 2,
    JSON.stringify(env.calls.posts.map(p => [p.path, p.body.user_id])));
  const chips = [...r.querySelectorAll('[data-combo-chips] .chip')];
  check('el añadido sale de los chips y el fallido permanece',
    chips.length === 1 && chips[0].textContent.includes('Diego'),
    JSON.stringify(chips.map(c => c.textContent.trim())));
  const formErr = r.querySelector('[data-form-error]');
  check('error inline visible', !!formErr && !formErr.classList.contains('hidden')
    && formErr.textContent.includes('boom'), formErr ? formErr.textContent : 'sin [data-form-error]');
  check('sin toast de éxito', !env.calls.toasts.some(t => t.type === 'success'),
    JSON.stringify(env.calls.toasts));
  check('toast de error', env.calls.toasts.some(t => t.type === 'danger' || t.type === 'warning'),
    JSON.stringify(env.calls.toasts));

  env.click(r.querySelector('[data-action="back-detail"]'));
  await tick(12);
  check('al volver se recarga el detalle (miembros marcados como null)',
    env.membersGets() === membersBefore + 1, `${membersBefore} -> ${env.membersGets()}`);
  check('vuelve al detalle', !!env.modalRoot().querySelector('[data-action="detail-tab"]'));
  env.close();
}

// =====================================================================
// S7 - búsqueda: error, vacío y respuesta fuera de secuencia
// =====================================================================
async function s7_search_edges() {
  console.log('S7 búsqueda: error, vacío y respuesta fuera de secuencia');

  // a) el fetch falla: se muestra el mensaje de error
  const envErr = createEnv({
    getUsersImpl: () => { throw Object.assign(new Error('net'), { status: 500, data: null }); }
  });
  let root = await openAdd(envErr);
  await tick(6);
  const msgErr = root.querySelector('.combo-msg-error');
  check('fetch fallido muestra search_error', !!msgErr
    && msgErr.textContent.includes('No se pudieron cargar los usuarios'),
    msgErr ? msgErr.textContent : 'sin .combo-msg-error');
  envErr.close();

  // b) sin resultados: mensaje vacío
  const envEmpty = createEnv({
    getUsersImpl: () => ({ status: 200, data: { data: [], pagination: { total: 0 } } })
  });
  root = await openAdd(envEmpty);
  await tick(6);
  const msgEmpty = root.querySelector('.combo-msg');
  check('sin resultados muestra combo_empty',
    !!msgEmpty && !msgEmpty.classList.contains('combo-msg-error')
      && msgEmpty.textContent.includes('Sin resultados para esa búsqueda'),
    msgEmpty ? msgEmpty.textContent : 'sin .combo-msg');
  envEmpty.close();

  // c) respuesta lenta de la primera carga: la última gana (seq)
  const envStale = createEnv({
    getUsersImpl: (options) => {
      if (!options.params || !options.params.search) {
        return sleep(400).then(() => ({
          status: 200,
          data: { data: [{ id: 'u8', email: 'olga@acme.local', nombre: 'Olga', apellido: 'Paz', estado: 'ACTIVO' }] }
        }));
      }
      return { status: 200, data: { data: [{ id: 'u9', email: 'zoe@acme.local', nombre: 'Zoe', apellido: 'Luna', estado: 'ACTIVO' }] } };
    }
  });
  root = await openAdd(envStale);
  envStale.type(root.querySelector('[data-combo-input]'), 'x');
  await sleep(700);
  await tick(6);
  root = envStale.modalRoot();
  const listText = root.querySelector('.combo-list') ? root.querySelector('.combo-list').textContent : '';
  check('la respuesta rápida (última) es la que se pinta', listText.includes('Zoe'), listText);
  check('la respuesta lenta (antigua) se descarta', !listText.includes('Olga'), listText);
  envStale.close();
}

// =====================================================================
async function main() {
  await s1_open();
  await s2_chips();
  await s3_keyboard();
  await s4_validation();
  await s5_multi_add();
  await s6_partial_failure();
  await s7_search_edges();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
