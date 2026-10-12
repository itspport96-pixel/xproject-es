/**
 * Smoke jsdom para BLOQUE 1B (v1.13.20): rol heredado por grupo en el
 * modal de Roles de Usuarios.
 * Uso: node tools/smoke/smoke-bloque1b-roles.mjs   (desde la raiz del repo)
 *
 * Cobertura:
 *  B1B-1 Chip "Por grupo: {group}" en la lista de roles del modal
 *  B1B-2 Accion "Quitar del grupo" presente con groups.group.manage_members
 *  B1B-3 Ausente sin groups.group.manage_members (los permisos no la incluyen)
 *  B1B-4 Ausente por escalada: actor no-ADMIN sobre un usuario ADMIN
 *  B1B-5 Ausente sobre el propio usuario (self, accion peligrosa)
 *  B1B-6 Confirmacion: texto con el grupo y los roles que se pierden;
 *        cancelar no dispara ningun DELETE
 *  B1B-7 Confirmar: DELETE /groups/{id}/members/{userId}, recarga real desde
 *        el servidor y toast de exito
 *  B1B-8 409 ROLE_NOT_DIRECT: sin toast de exito, toast warning not_direct
 *        y recarga de los roles
 *  B1B-9 Tras el flujo el pie vuelve a "Cerrar" (sin confirmacion colgada)
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  users: read('modules/users/users.js'),
  i18n: read('core/i18n.js')
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

// =====================================================================
// FIXTURES
// =====================================================================

const USERS = [
  { id: 'u1', email: 'ana@acme.local', nombre: 'Ana', apellido: 'Torres', estado: 'ACTIVO', roles: [], ultimo_login: '2026-10-01 09:00:00' },
  { id: 'u-admin', email: 'root@acme.local', nombre: 'Root', apellido: 'Admin', estado: 'ACTIVO', roles: [{ nombre: 'ADMIN' }], ultimo_login: null },
  { id: 'actor-1', email: 'actor@acme.local', nombre: 'Actor', apellido: 'Uno', estado: 'ACTIVO', roles: [], ultimo_login: null }
];

const USERS_PAYLOAD = {
  status: 200,
  data: { data: USERS, pagination: { total: USERS.length, page: 1, per_page: 20, has_next: false } }
};

const ROLES_CATALOG = [
  { id: 'r-op', nombre: 'OPERADOR', tipo: 'BUILT_IN', activo: true, permisos: [] }
];

const ROLES_BY_USER = {
  u1: [
    { id: 'r-dir', nombre: 'OPERADOR', tipo: 'BUILT_IN', origen: 'direct' },
    { id: 'r-grp', nombre: 'MANAGER', tipo: 'BUILT_IN', origen: 'group', group_id: 'g1', group_name: 'Rol_Gerencia' }
  ],
  'u-admin': [
    { id: 'r-grp', nombre: 'MANAGER', tipo: 'BUILT_IN', origen: 'group', group_id: 'g1', group_name: 'Rol_Gerencia' }
  ],
  'actor-1': [
    { id: 'r-grp', nombre: 'MANAGER', tipo: 'BUILT_IN', origen: 'group', group_id: 'g1', group_name: 'Rol_Gerencia' }
  ]
};

function cloneRolesByUser() {
  return JSON.parse(JSON.stringify(ROLES_BY_USER));
}

// =====================================================================
// ENTORNO (una ventana jsdom con stubs)
// =====================================================================

function createEnv({ perms = [], roles = ['ADMIN'], userId = 'actor-1', deleteImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body>' +
    '<div id="sidebar"></div>' +
    '<div id="module-container"></div>' +
    '</body></html>',
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], posts: [], deletes: [], toasts: [], errors: [] };

  Object.defineProperty(w.Element.prototype, 'scrollIntoView', { value: function() {}, configurable: true });
  w.console = {
    log() {}, info() {}, debug() {},
    warn(...a) { calls.errors.push(a.map(String).join(' ')); },
    error(...a) { calls.errors.push(a.map(String).join(' ')); }
  };
  Object.defineProperty(w, 'crypto', {
    configurable: true,
    writable: true,
    value: {
      randomUUID: (() => { let n = 0; return () => `00000000-0000-4000-8000-${String(++n).padStart(12, '0')}`; })()
    }
  });

  w.AppConfig = { apiBase: '/api', baseUrl: '/xp/public', appName: 'XP Platform' };
  w.EventBus = {
    on() {}, off() {},
    emit(event, data) { if (event === 'toast:show') calls.toasts.push(data); }
  };
  w.Storage = { get: () => false, set() {}, remove() {}, getUser: () => null, getToken: () => 'x' };
  w.AuthState = {
    getUser: () => ({ nombre: 'Test', apellido: 'User', email: 'test@acme.local', roles: roles.map(nombre => ({ nombre, permisos: perms })) }),
    getPermissions: () => perms.slice(),
    getRoles: () => roles.slice(),
    getUserId: () => userId,
    hasPermission: (p) => perms.includes(p),
    isAuthenticated: () => true,
    logout() {}
  };

  const env = { w, calls, deleteImpl, dom, rolesByUser: cloneRolesByUser() };

  // Verdad del servidor simulada: el DELETE de miembros quita los roles
  // heredados de ese grupo; el DELETE de rol directo quita la asignacion.
  const defaultDelete = (p) => {
    const m = /^\/groups\/([^/]+)\/members\/([^/]+)$/.exec(p);
    if (m) {
      const list = env.rolesByUser[m[2]] || [];
      env.rolesByUser[m[2]] = list.filter(r => !(r.origen === 'group' && r.group_id === m[1]));
      return { status: 200, data: { ok: true } };
    }
    const m2 = /^\/users\/([^/]+)\/roles\/([^/]+)$/.exec(p);
    if (m2) {
      const list = env.rolesByUser[m2[1]] || [];
      env.rolesByUser[m2[1]] = list.filter(r => r.id !== m2[2]);
      return { status: 200, data: { ok: true } };
    }
    return { status: 200, data: { ok: true } };
  };

  w.HttpClient = {
    async get(p) {
      calls.gets.push(p);
      if (p === '/users') return USERS_PAYLOAD;
      if (p === '/roles') return { status: 200, data: { roles: ROLES_CATALOG } };
      const m = /^\/users\/([^/]+)\/roles$/.exec(p);
      if (m) return { status: 200, data: { roles: env.rolesByUser[m[1]] || [] } };
      if (p === '/temporary-permissions') return { status: 200, data: { temporary_permissions: [], total: 0 } };
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    },
    async post(p, body) {
      calls.posts.push({ path: p, body });
      return { status: 201, data: { ok: true } };
    },
    async patch(p, body) {
      calls.posts.push({ path: p, body });
      return { status: 200, data: { ok: true } };
    },
    async delete(p, options = {}) {
      calls.deletes.push(p);
      if (env.deleteImpl) return env.deleteImpl(p, options, env);
      return defaultDelete(p);
    }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  evalIn(FILES.i18n);
  w.i18n.inject('es', ES_DICT);
  UI_FILES.forEach(evalIn);
  evalIn(FILES.users);

  env.container = w.document.getElementById('module-container');
  env.modalRoot = () => env.container.querySelector('[data-modal-root]');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.open = async (userIdToOpen) => {
    env.w.UsersUsersModule.init({}, env.container);
    await tick();
    const btn = env.container.querySelector(`[data-action="open-roles"][data-id="${userIdToOpen}"]`);
    if (btn) env.click(btn);
    await tick();
    return env.modalRoot();
  };
  env.rolesGets = (uid) => env.calls.gets.filter(p => p === `/users/${uid}/roles`).length;
  env.close = () => dom.window.close();

  return env;
}

const PERMS_FULL = ['users.user.view', 'users.user.edit', 'groups.group.manage_members'];
const SIN_MANAGE = ['users.user.view', 'users.user.edit'];

// =====================================================================
// B1B-1/2 CHIP Y ACCION PRESENTE
// =====================================================================
async function s_chip_y_accion() {
  console.log('B1B chip "Por grupo" y accion "Quitar del grupo"');
  const env = createEnv({ perms: PERMS_FULL, roles: ['ADMIN'] });
  const root = await env.open('u1');
  const html = root ? root.innerHTML : '';
  check('B1B-1 chip "Por grupo: Rol_Gerencia" en el modal',
    html.includes('Por grupo: Rol_Gerencia'),
    html.slice(0, 400));
  check('B1B-1b el rol directo no lleva chip de grupo',
    html.includes('OPERADOR') && !/Por grupo/.test(
      (root.querySelector('li') ? root.querySelector('li').textContent : '')));
  const ask = root.querySelector('[data-action="ask-remove-group"]');
  check('B1B-2 accion ask-remove-group con group_id y group_name',
    !!ask && ask.dataset.groupId === 'g1' && ask.dataset.groupName === 'Rol_Gerencia',
    ask ? `groupId=${ask.dataset.groupId} groupName=${ask.dataset.groupName}` : 'sin boton');
  check('B1B-2b el rol directo conserva su "Quitar"',
    !!root.querySelector('[data-action="remove-role"][data-role-id="r-dir"]'));
  env.close();
}

// =====================================================================
// B1B-3 SIN groups.group.manage_members
// =====================================================================
async function s_sin_permiso() {
  console.log('B1B sin groups.group.manage_members');
  const env = createEnv({ perms: SIN_MANAGE, roles: ['ADMIN'] });
  const root = await env.open('u1');
  check('B1B-3 sin manage_members no se pinta ask-remove-group',
    !root.querySelector('[data-action="ask-remove-group"]'),
    root.innerHTML.slice(0, 300));
  check('B1B-3b el chip "Por grupo" sigue visible (es informativo)',
    root.innerHTML.includes('Por grupo: Rol_Gerencia'));
  check('B1B-3c "Quitar" directo sigue (users.user.edit)',
    !!root.querySelector('[data-action="remove-role"]'));
  check('B1B-3d ninguna llamada de borrado',
    env.calls.deletes.length === 0,
    JSON.stringify(env.calls.deletes));
  env.close();
}

// =====================================================================
// B1B-4 ESCALADA: actor MANAGER sobre usuario ADMIN
// =====================================================================
async function s_escalada() {
  console.log('B1B escalada no-ADMIN sobre ADMIN');
  const env = createEnv({ perms: PERMS_FULL, roles: ['MANAGER'] });
  const root = await env.open('u-admin');
  check('B1B-4 actor no-ADMIN no ve ask-remove-group sobre un ADMIN',
    root && !root.querySelector('[data-action="ask-remove-group"]'),
    root ? root.innerHTML.slice(0, 300) : 'sin modal');
  env.close();
}

// =====================================================================
// B1B-5 SELF: el actor sobre si mismo
// =====================================================================
async function s_self() {
  console.log('B1B self: accion peligrosa sobre el propio usuario');
  const env = createEnv({ perms: PERMS_FULL, roles: ['ADMIN'], userId: 'u1' });
  const root = await env.open('u1');
  check('B1B-5 sin ask-remove-group sobre el propio usuario',
    root && !root.querySelector('[data-action="ask-remove-group"]'),
    root ? root.innerHTML.slice(0, 300) : 'sin modal');
  env.close();
}

// =====================================================================
// B1B-6/7/9 FLUJO DE CONFIRMACION
// =====================================================================
async function s_flujo_confirmacion() {
  console.log('B1B flujo de confirmacion: texto, cancelar, confirmar');
  const env = createEnv({ perms: PERMS_FULL, roles: ['ADMIN'] });
  const root = await env.open('u1');
  if (!root) { check('B1B-6 modal abierto', false); env.close(); return; }

  const getsAntes = env.rolesGets('u1');

  // --- abrir confirmacion
  env.click(root.querySelector('[data-action="ask-remove-group"]'));
  await tick();
  const htmlConf = root.innerHTML;
  const esperado = ES_DICT.users.roles.remove_group_confirm
    .replace('{group}', 'Rol_Gerencia')
    .replace('{roles}', 'MANAGER');
  check('B1B-6 confirmacion con grupo y roles perdidos',
    htmlConf.includes(esperado),
    `esperado=${esperado}`);
  check('B1B-6b pie con Cancelar y quitar del grupo',
    !!root.querySelector('[data-action="cancel-remove-group"]') &&
    !!root.querySelector('[data-action="submit-remove-group"]'));
  check('B1B-6c en confirmacion no se ofrecen las acciones de la lista',
    !root.querySelector('[data-action="ask-remove-group"]'));
  check('B1B-6d sin DELETE durante la apertura de la confirmacion',
    env.calls.deletes.length === 0, JSON.stringify(env.calls.deletes));

  // --- cancelar
  env.click(root.querySelector('[data-action="cancel-remove-group"]'));
  await tick();
  check('B1B-6e cancelar vuelve a la lista de roles',
    !!root.querySelector('[data-action="ask-remove-group"]'));
  check('B1B-6f cancelar sin DELETE ni toast',
    env.calls.deletes.length === 0 && env.calls.toasts.length === 0,
    `deletes=${JSON.stringify(env.calls.deletes)} toasts=${JSON.stringify(env.calls.toasts)}`);

  // --- confirmar
  env.click(root.querySelector('[data-action="ask-remove-group"]'));
  await tick();
  env.click(root.querySelector('[data-action="submit-remove-group"]'));
  await tick();
  check('B1B-7 DELETE al endpoint existente de miembros',
    env.calls.deletes.includes('/groups/g1/members/u1'),
    JSON.stringify(env.calls.deletes));
  check('B1B-7b recarga real: GET /users/u1/roles tras confirmar',
    env.rolesGets('u1') > getsAntes,
    `antes=${getsAntes} ahora=${env.rolesGets('u1')}`);
  check('B1B-7c la lista refleja la verdad del servidor (sin el rol del grupo)',
    root.innerHTML.includes('OPERADOR') && !root.innerHTML.includes('Por grupo: Rol_Gerencia'));
  const toastsOk = env.calls.toasts.filter(t => t.type === 'success' &&
    String(t.message).startsWith('Usuario quitado del grupo') &&
    String(t.message).includes('Rol_Gerencia'));
  check('B1B-7d toast de exito con el nombre del grupo', toastsOk.length === 1,
    JSON.stringify(env.calls.toasts));
  check('B1B-9 el pie vuelve a "Cerrar" (confirmacion no colgada)',
    !!root.querySelector('[data-action="close-modal"]') &&
    !root.querySelector('[data-action="submit-remove-group"]'));
  env.close();
}

// =====================================================================
// B1B-8 409 ROLE_NOT_DIRECT
// =====================================================================
async function s_409() {
  console.log('B1B 409 ROLE_NOT_DIRECT sin exito falso');
  const notDirect = Object.assign(new Error('Conflict'), {
    status: 409,
    data: {
      success: false,
      message: 'El rol no esta asignado directamente: proviene de un grupo',
      data: { category: 'conflict', error_code: 'ROLE_NOT_DIRECT' }
    }
  });
  const env = createEnv({
    perms: PERMS_FULL,
    roles: ['ADMIN'],
    deleteImpl: (p) => {
      if (/^\/users\/[^/]+\/roles\/[^/]+$/.test(p)) throw notDirect;
      return { status: 200, data: { ok: true } };
    }
  });
  const root = await env.open('u1');
  if (!root) { check('B1B-8 modal abierto', false); env.close(); return; }

  const getsAntes = env.rolesGets('u1');
  const toastsAntes = env.calls.toasts.length;
  env.click(root.querySelector('[data-action="remove-role"][data-role-id="r-dir"]'));
  await tick();

  const nuevos = env.calls.toasts.slice(toastsAntes);
  const exitos = nuevos.filter(t => t.type === 'success');
  const warning = nuevos.filter(t => t.type === 'warning' &&
    String(t.message) === ES_DICT.users.roles.not_direct);
  check('B1B-8 sin toast de exito en el 409', exitos.length === 0,
    JSON.stringify(nuevos));
  check('B1B-8b toast warning con el texto not_direct', warning.length === 1,
    JSON.stringify(nuevos));
  check('B1B-8c recarga real: GET /users/u1/roles tras el 409',
    env.rolesGets('u1') > getsAntes,
    `antes=${getsAntes} ahora=${env.rolesGets('u1')}`);
  check('B1B-8d el rol directo sigue listado (el servidor no cambio nada)',
    !!root.querySelector('[data-action="remove-role"][data-role-id="r-dir"]'));
  check('B1B-8e modal abierto y sin error inline por defecto',
    !!env.modalRoot().querySelector('.modal') &&
    !env.modalRoot().querySelector('[data-form-error]:not(.hidden)'));
  env.close();
}

// =====================================================================
async function main() {
  console.log('BLOQUE 1B smoke: rol heredado por grupo en el modal de Roles');
  await s_chip_y_accion();
  await s_sin_permiso();
  await s_escalada();
  await s_self();
  await s_flujo_confirmacion();
  await s_409();
  console.log(`\nResultado: ${pass} ok, ${fail} fail`);
  if (fail > 0) process.exit(1);
}

main().catch(err => { console.error(err); process.exit(1); });
