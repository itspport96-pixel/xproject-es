/**
 * Smoke test jsdom para la UI v1.12.0 (pantallas de control: Usuarios,
 * Roles, Grupos y cambio de contrasena).
 * Uso: node tools/smoke/smoke-v1120.mjs   (desde la raiz del repo)
 *
 * Cobertura:
 *  S1  Registro de rutas/modulos y wiring (loader, router, state, login)
 *  S2  Pantalla de Usuarios: listado, filtros, alta, estado, temporales
 *  S3  Pantalla de Roles: listado, selector maestro-detalle, alta y baja
 *  S4  Pantalla de Grupos: listado, detalle, miembros, roles, CRUD
 *  S5  Sidebar: items users/roles/groups segun permisos
 *  S6  Cambio de contrasena: menu, modal y modo obligatorio
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  users: read('modules/users/users.js'),
  roles: read('modules/roles/roles.js'),
  groups: read('modules/groups/groups.js'),
  password: read('modules/auth/password.js'),
  state: read('modules/auth/state.js'),
  login: read('modules/auth/login.js'),
  loader: read('core/loader.js'),
  router: read('core/router.js'),
  sidebar: read('modules/dashboard/sidebar.js'),
  eventBus: read('core/event-bus.js'),
  i18n: read('core/i18n.js')
};

const UI_FILES = ['ui/dom.js', 'ui/render.js', 'ui/view.js', 'ui/modal.js', 'ui/actions.js', 'ui/permission-picker.js'].map(read);

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

const USERS = [
  { id: 'u1', email: 'ana@acme.local', nombre: 'Ana', apellido: 'Torres', estado: 'ACTIVO', ultimo_login: '2026-10-01 09:00:00' },
  { id: 'u2', email: 'bruno@acme.local', nombre: 'Bruno', apellido: 'Diaz', estado: 'SUSPENDIDO', ultimo_login: null },
  // Candidato externo al grupo g1: alimenta el combobox de añadir miembro (M1).
  { id: 'u3', email: 'lucia@acme.local', nombre: 'Lucia', apellido: 'Vidal', estado: 'ACTIVO', ultimo_login: null }
];

const ROLES = [
  { id: 'r1', nombre: 'Administrador', tipo: 'BUILT_IN', descripcion: 'Rol base', activo: true },
  { id: 'r2', nombre: 'Analista', tipo: 'CUSTOM', descripcion: 'Lectura', activo: true }
];

const PERMS = [
  { id: 'p1', identificador: 'inventory.stock.view', nombre: 'Ver stock', modulo: 'inventory', recurso: 'stock', accion: 'view', criticidad: 'MEDIUM', activo: true },
  { id: 'p2', identificador: 'users.user.view', nombre: 'Ver usuarios', modulo: 'users', recurso: 'user', accion: 'view', criticidad: 'HIGH', activo: true },
  { id: 'p3', identificador: 'groups.group.view', nombre: 'Ver grupos', modulo: 'groups', recurso: 'group', accion: 'view', criticidad: 'LOW', activo: true }
];

const ROLE_PERMS = {
  r1: { permisos: [] },
  r2: { permisos: ['inventory.stock.view'] }
};

const TEMP = [{
  id: 'tp1', user_id: 'u1', user_email: 'ana@acme.local',
  permission_id: 'p1', permission_identificador: 'inventory.stock.view',
  permission_criticidad: 'MEDIUM', motivo: 'Cobertura de vacaciones',
  fecha_expiracion: '2099-12-31 00:00:00', activo: true
}];

const GROUPS = [
  { id: 'g1', nombre: 'Almacen Central', descripcion: 'Operarios', activo: true },
  { id: 'g2', nombre: 'Ventas', descripcion: '', activo: false }
];

const GROUP_MEMBERS = {
  g1: [
    { membership_id: 'm1', user_id: 'u1', email: 'ana@acme.local', nombre: 'Ana', apellido: 'Torres', estado: 'ACTIVO', added_at: '2026-01-01' },
    { membership_id: 'm2', user_id: 'u2', email: 'bruno@acme.local', nombre: 'Bruno', apellido: 'Diaz', estado: 'SUSPENDIDO', added_at: '2026-02-01' }
  ],
  g2: []
};

const GROUP_ROLES = {
  g1: [
    { role_id: 'r1', nombre: 'Administrador', tipo: 'BUILT_IN', assigned_at: '2026-01-01', role_deleted_at: null },
    { role_id: 'r9', nombre: 'Borrador', tipo: 'CUSTOM', assigned_at: '2026-02-01', role_deleted_at: '2026-06-01' }
  ],
  g2: []
};

const USERS_PAYLOAD = {
  status: 200,
  data: { data: USERS, pagination: { total: USERS.length, page: 1, per_page: 20, has_next: false } }
};

// =====================================================================
// ENTORNO (una ventana jsdom con stubs)
// =====================================================================

function createEnv({ perms = [], roles = ['ADMIN'], realEventBus = false, getHandlers = {}, postImpl = null, patchImpl = null, deleteImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body>' +
    '<div id="sidebar"></div>' +
    '<div id="module-container"></div>' +
    '<div id="user-menu"><div class="menu"><button id="logout-btn">Salir</button></div></div>' +
    '</body></html>',
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], posts: [], patches: [], deletes: [], toasts: [], errors: [] };

  Object.defineProperty(w.Element.prototype, 'scrollIntoView', { value: function() {}, configurable: true });
  w.console = {
    log() {}, info() {}, debug() {}, warn(...a) { calls.errors.push(a.map(String).join(' ')); },
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

  if (realEventBus) {
    // EventBus real (se evalua despues en el escenario) + captura de toasts.
  } else {
    w.EventBus = {
      on() {}, off() {},
      emit(event, data) { if (event === 'toast:show') calls.toasts.push(data); }
    };
  }

  w.AuthState = {
    getUser: () => ({ nombre: 'Test', apellido: 'User', email: 'test@acme.local', roles: roles.map(nombre => ({ nombre, permisos: perms })) }),
    getPermissions: () => perms.slice(),
    getRoles: () => roles.slice(),
    hasPermission: (p) => perms.includes(p),
    isAuthenticated: () => true,
    logout() { if (w.EventBus) w.EventBus.emit('auth:logout', {}); }
  };

  const env = { w, calls, postImpl, patchImpl, deleteImpl, dom };

  const resolveGet = (p, options) => {
    if (getHandlers[p]) return getHandlers[p](options, env);
    if (p === '/users') return USERS_PAYLOAD;
    if (p === '/roles') return { status: 200, data: { roles: ROLES } };
    if (p === '/permissions') return { status: 200, data: { permissions: PERMS } };
    if (p === '/temporary-permissions') return { status: 200, data: { temporary_permissions: TEMP, total: TEMP.length } };
    if (p === '/groups') return { status: 200, data: { groups: GROUPS } };
    const mMembers = /^\/groups\/([^/]+)\/members$/.exec(p);
    if (mMembers) {
      const list = GROUP_MEMBERS[mMembers[1]] || [];
      return { status: 200, data: { members: list, total: list.length } };
    }
    const mGroupRoles = /^\/groups\/([^/]+)\/roles$/.exec(p);
    if (mGroupRoles) {
      return { status: 200, data: { roles: GROUP_ROLES[mGroupRoles[1]] || [] } };
    }
    const mRolePerms = /^\/roles\/([^/]+)\/permissions$/.exec(p);
    if (mRolePerms) {
      return { status: 200, data: ROLE_PERMS[mRolePerms[1]] || { permisos: [] } };
    }
    if (p.startsWith('/locales/')) {
      const loc = p.slice('/locales/'.length).replace(/\.json$/, '');
      return { status: 200, data: { locale: loc, version: 'smoke', dictionary: ES_DICT } };
    }
    throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
  };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      return resolveGet(p, options, env);
    },
    async post(p, body, options = {}) {
      calls.posts.push({ path: p, body, options });
      if (env.postImpl) return env.postImpl(p, body, options, env);
      if (p === '/users') {
        return {
          status: 201,
          data: {
            user: {
              id: 'u9', email: body.email, nombre: body.nombre, apellido: body.apellido,
              estado: 'PENDIENTE_CONFIRMACION', password_temporal: 'Temp1234abcd'
            }
          }
        };
      }
      if (p === '/auth/change-password') return { status: 200, data: { ok: true } };
      return { status: 201, data: { ok: true } };
    },
    async patch(p, body, options = {}) {
      calls.patches.push({ path: p, body, options });
      if (env.patchImpl) return env.patchImpl(p, body, options, env);
      return { status: 200, data: { ok: true } };
    },
    async delete(p, options = {}) {
      calls.deletes.push({ path: p, options });
      if (env.deleteImpl) return env.deleteImpl(p, options, env);
      return { status: 200, data: { ok: true } };
    }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  evalIn(FILES.i18n);
  w.i18n.inject('es', ES_DICT);
  const evalUi = () => UI_FILES.forEach(evalIn);

  env.evalUi = evalUi;
  env.loadUsers = () => { evalUi(); evalIn(FILES.users); w.UsersUsersModule.init({}, env.container); };
  env.loadRoles = () => { evalUi(); evalIn(FILES.roles); w.RolesRolesModule.init({}, env.container); };
  env.loadGroups = () => { evalUi(); evalIn(FILES.groups); w.GroupsGroupsModule.init({}, env.container); };
  env.loadShell = () => { evalUi(); evalIn(FILES.loader); evalIn(FILES.router); evalIn(FILES.sidebar); };

  env.container = w.document.getElementById('module-container');
  env.modalRoot = () => env.container.querySelector('[data-modal-root]');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.set = (root, name, value) => {
    const el = root.querySelector(`[data-modal-field="${name}"]`);
    if (el) el.value = value;
    return el;
  };
  env.setCheck = (el, on) => {
    if (!el || el.disabled) return;
    if (el.checked !== on) {
      el.checked = on;
      el.dispatchEvent(new w.Event('change', { bubbles: true }));
    }
  };
  env.setSearch = (root, value) => {
    const el = root.querySelector('[data-pp-search]');
    if (!el) return null;
    el.value = value;
    el.dispatchEvent(new w.Event('input', { bubbles: true }));
    return el;
  };
  env.pickerSelected = (root) => {
    const panel = root.querySelector('[data-pp-panel]');
    return panel ? w.UI.permissionPicker.getSelected(panel) : [];
  };
  env.close = () => dom.window.close();

  return env;
}

const ADMIN_PERMS = [
  'users.user.view', 'users.user.create', 'users.user.edit', 'users.user.status',
  'users.password.reset', 'temporary.permission.view', 'temporary.permission.grant',
  'temporary.permission.revoke', 'roles.role.view', 'roles.role.create',
  'roles.role.edit', 'roles.role.delete', 'groups.group.view', 'groups.group.create',
  'groups.group.edit', 'groups.group.delete', 'groups.group.manage_members',
  'groups.group.manage_roles', 'permissions.permission.view', 'inventory.stock.view'
];

// =====================================================================
// S1 - REGISTRO Y WIRING
// =====================================================================
async function s1_registration() {
  console.log('S1 registro: rutas, modulos y wiring');
  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.evalIn(FILES.loader);
  env.evalIn(FILES.router);
  const w = env.w;

  const routes = w.AppRouter.getRoutes();
  check('ruta users -> modulo users/users', routes['users'] && routes['users'].module === 'users/users', JSON.stringify(routes['users'] || null));
  check('ruta roles -> modulo roles/roles', routes['roles'] && routes['roles'].module === 'roles/roles', JSON.stringify(routes['roles'] || null));
  check('ruta groups -> modulo groups/groups', routes['groups'] && routes['groups'].module === 'groups/groups', JSON.stringify(routes['groups'] || null));
  check('titleKey de las 3 rutas', routes['users'].titleKey === 'routes.users' && routes['roles'].titleKey === 'routes.roles' && routes['groups'].titleKey === 'routes.groups');

  check('modulo users/users registrado', w.ModuleLoader.has('users/users'));
  check('modulo roles/roles registrado', w.ModuleLoader.has('roles/roles'));
  check('modulo groups/groups registrado', w.ModuleLoader.has('groups/groups'));
  check('modulo auth/password registrado', w.ModuleLoader.has('auth/password'));

  check('resolveGlobalKey users/users', w.ModuleLoader.resolveGlobalKey('users/users') === 'UsersUsersModule');
  check('resolveGlobalKey roles/roles', w.ModuleLoader.resolveGlobalKey('roles/roles') === 'RolesRolesModule');
  check('resolveGlobalKey groups/groups', w.ModuleLoader.resolveGlobalKey('groups/groups') === 'GroupsGroupsModule');
  check('resolveGlobalKey auth/password', w.ModuleLoader.resolveGlobalKey('auth/password') === 'AuthPasswordModule');

  check('isModuleAvailable users', w.AppRouter.isModuleAvailable('users') === true);
  check('isModuleAvailable roles', w.AppRouter.isModuleAvailable('roles') === true);
  check('isModuleAvailable groups', w.AppRouter.isModuleAvailable('groups') === true);

  // Wiring estatico: state.js precarga el modulo de contrasena y login.js
  // emite el evento del cambio obligatorio.
  check('state.js precarga auth/password', FILES.state.indexOf("preload('auth/password')") !== -1);
  check('login.js emite auth:password-required', FILES.login.indexOf("emit('auth:password-required'") !== -1);
  env.close();
}

// =====================================================================
// S2 - PANTALLA DE USUARIOS
// =====================================================================
async function s2_users_screen() {
  console.log('S2 usuarios: listado, filtros, alta, estado y temporales');
  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadUsers();
  await tick();

  check('GET /users con paginacion',
    env.calls.gets.some(g => g.path === '/users' && g.options.params.page === 1 && g.options.params.per_page === 20),
    JSON.stringify(env.calls.gets));

  const table = env.container.querySelector('[data-users-table] table');
  check('tabla renderizada', !!table);
  check('filas con los 3 usuarios',
    env.container.querySelectorAll('[data-users-table] tbody tr').length === 3,
    String(env.container.querySelectorAll('[data-users-table] tbody tr').length));
  check('email visible en la tabla', /ana@acme\.local/.test(env.container.textContent));
  check('estado en la fila (texto traducido)',
    env.container.textContent.indexOf(ES_DICT.users.status.ACTIVO) !== -1,
    ES_DICT.users.status.ACTIVO);

  const tabs = [...env.container.querySelectorAll('[data-action="tab"]')].map(b => b.dataset.tab);
  check('pestanas Usuarios y Temporales', tabs.includes('users') && tabs.includes('temp'), JSON.stringify(tabs));
  check('boton crear visible con users.user.create', !!env.container.querySelector('[data-action="open-create"]'));

  // --- Alta con validacion local ---
  env.click(env.container.querySelector('[data-action="open-create"]'));
  await tick();
  let root = env.modalRoot();
  check('modal de alta abierto', !!root && !!root.querySelector('[data-modal-field="email"]'));

  env.click(root.querySelector('[data-action="submit-create"]'));
  await tick();
  check('alta vacia no envia', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo email', !!root.querySelector('[data-error="email"]:not(.hidden)'));
  check('error de campo nombre', !!root.querySelector('[data-error="nombre"]:not(.hidden)'));

  env.set(root, 'email', 'carla@acme.local');
  env.set(root, 'nombre', 'Carla');
  env.set(root, 'apellido', 'Ruiz');
  env.click(root.querySelector('[data-action="submit-create"]'));
  await tick();

  check('POST /users con la clave de idempotencia',
    env.calls.posts.some(p => p.path === '/users'
      && p.body.email === 'carla@acme.local' && p.body.nombre === 'Carla'
      && p.options.headers && p.options.headers['Idempotency-Key']),
    JSON.stringify(env.calls.posts));
  check('toast de alta', env.calls.toasts.some(t => t.type === 'success'));
  check('listado recargado tras el alta',
    env.calls.gets.filter(g => g.path === '/users').length >= 2,
    JSON.stringify(env.calls.gets.map(g => g.path)));
  const tempInput = env.modalRoot() && env.modalRoot().querySelector('[data-modal-field="password_display"]');
  check('modal de password temporal abierto',
    !!tempInput && tempInput.value === 'Temp1234abcd',
    tempInput ? tempInput.value : 'sin modal');
  env.click(env.modalRoot().querySelector('[data-action="close-modal"]'));
  await tick();
  check('modal cerrado tras el alta', !env.modalRoot().querySelector('.modal'));

  // --- Cambio de estado ---
  env.click(env.container.querySelector('[data-action="open-status"]'));
  root = env.modalRoot();
  check('modal de estado abierto', !!root && !!root.querySelector('[data-modal-field="estado"]'));
  env.set(root, 'motivo', 'Revision de acceso');
  env.click(root.querySelector('[data-action="submit-status"]'));
  await tick();
  check('POST /users/u1/status',
    env.calls.posts.some(p => p.path === '/users/u1/status' && p.body.motivo === 'Revision de acceso'),
    JSON.stringify(env.calls.posts.map(p => p.path)));

  // --- Permiso temporal (concesion) ---
  env.click(env.container.querySelector('[data-action="open-grant"]'));
  await tick(12);
  root = env.modalRoot();
  check('modal de concesion abierto', !!root && !!root.querySelector('[data-modal-field="permission_id"]'));
  check('catalogo de permisos cargado',
    env.calls.gets.some(g => g.path === '/permissions' && g.options.params.include_inactive === 'false'),
    JSON.stringify(env.calls.gets));
  check('directorio de usuarios cargado para el desplegable',
    env.calls.gets.some(g => g.path === '/users' && g.options.params.per_page === 100));

  env.set(root, 'user_id', 'u1');
  env.set(root, 'permission_id', 'p1');
  env.set(root, 'motivo', 'Cobertura');
  env.click(root.querySelector('[data-action="submit-grant"]'));
  await tick();
  check('POST /temporary-permissions con id de catalogo y horas',
    env.calls.posts.some(p => p.path === '/temporary-permissions'
      && p.body.user_id === 'u1' && p.body.permission_id === 'p1'
      && p.body.duracion_horas === 24),
    JSON.stringify(env.calls.posts.map(p => p.path)));
  check('toast de concesion', env.calls.toasts.filter(t => t.type === 'success').length >= 2);

  // Tras conceder se salta a la pestana Temporales
  check('pestana temporales activa tras conceder',
    env.container.querySelector('[data-action="tab"][data-tab="temp"].is-active') !== null);
  await tick();
  check('GET /temporary-permissions listado', env.calls.gets.some(g => g.path === '/temporary-permissions'));
  check('fila del permiso temporal visible', /inventory\.stock\.view/.test(env.container.textContent));

  // --- Revocacion ---
  env.click(env.container.querySelector('[data-action="open-revoke"]'));
  root = env.modalRoot();
  check('modal de revocacion abierto', !!root && !!root.querySelector('[data-action="submit-revoke"]'));
  env.click(root.querySelector('[data-action="submit-revoke"]'));
  await tick();
  check('DELETE /temporary-permissions/tp1',
    env.calls.deletes.some(d => d.path === '/temporary-permissions/tp1'),
    JSON.stringify(env.calls.deletes));

  // --- Filtro de estado en la pestana Usuarios ---
  env.click(env.container.querySelector('[data-action="tab"][data-tab="users"]'));
  await tick();
  env.container.querySelector('[data-filter="estado"]').value = 'ACTIVO';
  env.click(env.container.querySelector('[data-action="apply-filters"]'));
  await tick();
  const lastUsersGet = env.calls.gets.filter(g => g.path === '/users').pop();
  check('filtro estado enviado al backend',
    lastUsersGet && lastUsersGet.options.params.estado === 'ACTIVO',
    JSON.stringify(lastUsersGet || null));
  env.close();

  // --- Sin permiso ---
  const env2 = createEnv({ perms: ['groups.group.view'], roles: ['VIEWER'] });
  env2.loadUsers();
  await tick();
  check('sin users.user.view: pantalla de sin permiso', !!env2.container.querySelector('h3'));
  check('sin users.user.view: sin llamadas al backend', env2.calls.gets.length === 0, JSON.stringify(env2.calls.gets));
  check('sin users.user.view: sin boton crear', !env2.container.querySelector('[data-action="open-create"]'));
  env2.close();
}

// =====================================================================
// S3 - PANTALLA DE ROLES
// =====================================================================
async function s3_roles_screen() {
  console.log('S3 roles: listado, matriz de permisos, alta y baja');
  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadRoles();
  await tick();

  const rolesGet = env.calls.gets.find(g => g.path === '/roles');
  check('GET /roles sin include_inactive por defecto',
    !!rolesGet && !('include_inactive' in (rolesGet.options.params || {})),
    JSON.stringify(rolesGet ? rolesGet.options.params : null));
  check('2 roles en la tabla',
    env.container.querySelectorAll('[data-roles-table] tbody tr').length === 2,
    String(env.container.querySelectorAll('[data-roles-table] tbody tr').length));
  check('nombres visibles', /Administrador/.test(env.container.textContent) && /Analista/.test(env.container.textContent));

  const editIds = [...env.container.querySelectorAll('[data-action="open-edit"]')].map(b => b.dataset.id);
  const deleteIds = [...env.container.querySelectorAll('[data-action="open-delete"]')].map(b => b.dataset.id);
  check('BUILT_IN sin editar ni borrar',
    !editIds.includes('r1') && !deleteIds.includes('r1'), JSON.stringify({ editIds, deleteIds }));
  check('CUSTOM con editar y borrar',
    editIds.includes('r2') && deleteIds.includes('r2'), JSON.stringify({ editIds, deleteIds }));

  // --- Permisos del rol BUILT_IN (solo lectura) ---
  env.click(env.container.querySelector('[data-action="open-permissions"][data-id="r1"]'));
  await tick(10);
  let root = env.modalRoot();
  check('modal de permisos BUILT_IN abierto', !!root);
  check('modal usa el tamano del picker', !!root.querySelector('.modal-pp'));
  check('BUILT_IN sin boton guardar', !root.querySelector('[data-action="submit-permissions"]'));
  check('BUILT_IN sin toolbar de acciones', !root.querySelector('[data-pp-clear]'));
  const checksR1 = [...root.querySelectorAll('[data-perm-check]')];
  check('BUILT_IN con casillas deshabilitadas',
    checksR1.length > 0 && checksR1.every(c => c.disabled),
    `${checksR1.length} casillas`);
  check('GET /roles/r1/permissions', env.calls.gets.some(g => g.path === '/roles/r1/permissions'));
  env.click(root.querySelector('[data-action="close-modal"]'));
  await tick();

  // --- Permisos del rol CUSTOM (selector maestro-detalle) ---
  env.click(env.container.querySelector('[data-action="open-permissions"][data-id="r2"]'));
  await tick(10);
  root = env.modalRoot();
  check('seleccion inicial con el permiso actual',
    env.pickerSelected(root).includes('inventory.stock.view'),
    JSON.stringify(env.pickerSelected(root)));
  check('toolbar del picker presente', !!root.querySelector('[data-pp-clear]'));
  check('resumen del pie visible', !!root.querySelector('[data-pp-summary]'));

  env.click(root.querySelector('[data-pp-module="users"]'));
  await tick();
  check('panel muestra el modulo users',
    !!root.querySelector('input[data-perm-check][value="users.user.view"]'));
  env.setCheck(root.querySelector('input[data-perm-check][value="users.user.view"]'), true);
  check('marcar users.user.view entra en la seleccion',
    env.pickerSelected(root).includes('users.user.view'),
    JSON.stringify(env.pickerSelected(root)));

  for (const mod of ['groups', 'inventory', 'users']) {
    env.setCheck(root.querySelector(`[data-pp-modulo-all][data-modulo="${mod}"]`), true);
  }
  await tick();
  check('seleccionar todo por modulo marca la matriz',
    env.pickerSelected(root).length === PERMS.length,
    JSON.stringify(env.pickerSelected(root)));

  env.click(root.querySelector('[data-action="submit-permissions"]'));
  await tick();
  check('PATCH /roles/r2/permissions con la matriz completa',
    env.calls.patches.some(p => p.path === '/roles/r2/permissions'
      && Array.isArray(p.body.permisos)
      && p.body.permisos.length === PERMS.length
      && p.body.permisos.includes('users.user.view')),
    JSON.stringify(env.calls.patches));
  check('toast de permisos guardados', env.calls.toasts.some(t => t.type === 'success'));

  // --- Escalada: permiso no poseido queda deshabilitado ---
  const env3 = createEnv({
    perms: ADMIN_PERMS.filter(p => p !== 'inventory.stock.view'),
    roles: ['ADMIN']
  });
  env3.loadRoles();
  await tick();
  env3.click(env3.container.querySelector('[data-action="open-permissions"][data-id="r2"]'));
  await tick(10);
  const root3 = env3.modalRoot();
  env3.click(root3.querySelector('[data-pp-module="inventory"]'));
  await tick();
  const invCheck = root3.querySelector('input[data-perm-check][value="inventory.stock.view"]');
  check('escalada: permiso no poseido deshabilitado', !!invCheck && invCheck.disabled,
    invCheck ? `disabled=${invCheck.disabled}` : 'sin casilla');
  env3.close();

  // --- Alta de rol con picker ---
  env.click(env.container.querySelector('[data-action="open-create"]'));
  await tick(10);
  root = env.modalRoot();
  check('modal de alta de rol abierto', !!root && !!root.querySelector('[data-modal-field="nombre"]'));
  check('alta usa el tamano del picker', !!root.querySelector('.modal-pp'));
  check('alta: buscador del picker presente', !!root.querySelector('[data-pp-search]'));
  check('alta: descripcion en su propia fila (autogrow)',
    !!root.querySelector('textarea.textarea-autogrow[data-modal-field="descripcion"]'));
  env.set(root, 'nombre', 'Operador');
  const descEl = env.set(root, 'descripcion', 'Operario de almacen');
  if (descEl) descEl.dispatchEvent(new env.w.Event('input', { bubbles: true }));

  env.setSearch(root, 'users.user.view');
  await tick();
  const permCheck = root.querySelector('input[data-perm-check][value="users.user.view"]');
  check('busqueda filtra hasta el permiso', !!permCheck);
  env.setCheck(permCheck, true);
  check('resumen refleja la seleccion',
    /1/.test(root.querySelector('[data-pp-summary]').textContent),
    root.querySelector('[data-pp-summary]').textContent);

  env.click(root.querySelector('[data-action="submit-create"]'));
  await tick();
  check('POST /roles con permisos seleccionados',
    env.calls.posts.some(p => p.path === '/roles' && p.body.nombre === 'Operador'
      && Array.isArray(p.body.permisos) && p.body.permisos.includes('users.user.view')
      && p.body.descripcion === 'Operario de almacen'),
    JSON.stringify(env.calls.posts));
  check('modal de alta cerrado', !env.modalRoot().querySelector('.modal'));

  // --- Baja de rol CUSTOM ---
  env.click(env.container.querySelector('[data-action="open-delete"][data-id="r2"]'));
  root = env.modalRoot();
  check('modal de confirmacion de baja abierto', !!root && !!root.querySelector('[data-action="submit-delete"]'));
  env.click(root.querySelector('[data-action="submit-delete"]'));
  await tick();
  check('DELETE /roles/r2', env.calls.deletes.some(d => d.path === '/roles/r2'),
    JSON.stringify(env.calls.deletes));
  check('listado recargado tras la baja',
    env.calls.gets.filter(g => g.path === '/roles').length >= 2);
  env.close();

  // --- Sin permiso ---
  const env2 = createEnv({ perms: ['permissions.permission.view'], roles: ['VIEWER'] });
  env2.loadRoles();
  await tick();
  check('sin roles.role.view: pantalla de sin permiso', !!env2.container.querySelector('h3'));
  check('sin roles.role.view: sin GET /roles',
    !env2.calls.gets.some(g => g.path === '/roles'), JSON.stringify(env2.calls.gets));
  env2.close();
}

// =====================================================================
// S4 - PANTALLA DE GRUPOS
// =====================================================================
async function s4_groups_screen() {
  console.log('S4 grupos: listado, detalle, miembros, roles y CRUD');
  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadGroups();
  await tick(12);

  check('GET /groups', env.calls.gets.some(g => g.path === '/groups'));
  check('2 grupos en la tabla',
    env.container.querySelectorAll('[data-groups-table] tbody tr').length === 2,
    String(env.container.querySelectorAll('[data-groups-table] tbody tr').length));
  check('nombres visibles', /Almacen Central/.test(env.container.textContent) && /Ventas/.test(env.container.textContent));

  const countGets = env.calls.gets.filter(g => /^\/groups\/[^/]+\/members$/.test(g.path));
  check('conteo de miembros N+1 (una llamada por grupo)', countGets.length === 2, JSON.stringify(countGets.map(g => g.path)));
  const g1Row = env.container.querySelector('[data-groups-table] tbody tr');
  check('conteo renderizado en la fila', g1Row && g1Row.querySelector('td.text-right').textContent.trim() === '2',
    g1Row ? g1Row.querySelector('td.text-right').textContent : 'sin fila');

  // --- Detalle: miembros ---
  env.click(env.container.querySelector('[data-action="open-detail"][data-id="g1"]'));
  await tick(12);
  let root = env.modalRoot();
  check('detalle abierto', !!root && !!root.querySelector('.modal'));
  check('miembros del grupo visibles', /ana@acme\.local/.test(root.textContent) && /bruno@acme\.local/.test(root.textContent));
  check('boton quitar miembro visible', !!root.querySelector('[data-action="remove-member"]'));
  check('GET /groups/g1/roles en el detalle', env.calls.gets.some(g => g.path === '/groups/g1/roles'));

  // --- Detalle: roles (incluido rol borrado) ---
  env.click(root.querySelector('[data-action="detail-tab"][data-tab="roles"]'));
  await tick();
  root = env.modalRoot();
  check('roles asignados visibles', /Administrador/.test(root.textContent));
  check('rol borrado con badge', !!root.querySelector('.badge-danger'), root.textContent.slice(0, 200));
  check('boton asignar rol visible', !!root.querySelector('[data-action="open-assign-role"]'));

  // --- Asignar rol ---
  env.click(root.querySelector('[data-action="open-assign-role"]'));
  await tick(10);
  root = env.modalRoot();
  check('modal de asignacion abierto', !!root && !!root.querySelector('[data-modal-field="role_id"]'));
  check('GET /roles para el catalogo', env.calls.gets.some(g => g.path === '/roles'));
  const roleSelect = root.querySelector('[data-modal-field="role_id"]');
  const roleOptions = [...roleSelect.options].map(o => o.value);
  check('solo roles disponibles (sin los asignados)',
    roleOptions.includes('r2') && !roleOptions.includes('r1'), JSON.stringify(roleOptions));
  roleSelect.value = 'r2';
  env.click(root.querySelector('[data-action="submit-assign-role"]'));
  await tick(12);
  check('POST /groups/g1/roles',
    env.calls.posts.some(p => p.path === '/groups/g1/roles' && p.body.role_id === 'r2'),
    JSON.stringify(env.calls.posts.map(p => p.path)));
  check('vuelve al detalle tras asignar',
    !!env.modalRoot().querySelector('[data-action="detail-tab"]'));

  // --- Anadir miembro (combobox con sugerencias y chips, M1) ---
  root = env.modalRoot();
  env.click(root.querySelector('[data-action="detail-tab"][data-tab="members"]'));
  await tick();
  root = env.modalRoot();
  env.click(root.querySelector('[data-action="open-add-member"]'));
  await tick(10);
  root = env.modalRoot();
  const comboInput = root && root.querySelector('[data-combo-input]');
  check('modal de anadir miembro abierto con combobox', !!root && !!comboInput,
    root ? 'sin [data-combo-input]' : 'sin modal');
  check('foco en el combobox al abrir',
    !!comboInput && env.w.document.activeElement === comboInput,
    env.w.document.activeElement ? env.w.document.activeElement.tagName + '.' + (env.w.document.activeElement.className || '') : 'sin foco');
  check('carga inicial de candidatos',
    env.calls.gets.some(g => g.path === '/users'
      && g.options.params.estado === 'ACTIVO' && g.options.params.per_page === 50 && !g.options.params.search),
    JSON.stringify(env.calls.gets.filter(g => g.path === '/users')));
  check('sugerencias pintadas', root.querySelectorAll('.combo-option').length >= 1,
    String(root.querySelectorAll('.combo-option').length));

  comboInput.value = 'ana';
  comboInput.dispatchEvent(new env.w.Event('input', { bubbles: true }));
  await new Promise(r => setTimeout(r, 320));
  await tick(10);
  check('busqueda enviada con search (debounce)',
    env.calls.gets.some(g => g.path === '/users' && g.options.params.search === 'ana'),
    JSON.stringify(env.calls.gets.filter(g => g.path === '/users')));

  root = env.modalRoot();
  check('opciones tras buscar', root.querySelectorAll('.combo-option').length >= 1,
    String(root.querySelectorAll('.combo-option').length));
  env.click(root.querySelectorAll('.combo-option')[0]);
  await tick();
  root = env.modalRoot();
  check('chip de seleccion tras elegir',
    !!root.querySelector('[data-combo-chips] .chip'),
    root.querySelector('[data-combo-chips]') ? root.querySelector('[data-combo-chips]').textContent : 'sin chips');
  check('input limpio tras elegir', root.querySelector('[data-combo-input]').value === '');
  env.click(root.querySelector('[data-action="submit-add-member"]'));
  await tick(12);
  check('POST /groups/g1/members',
    env.calls.posts.some(p => p.path === '/groups/g1/members' && p.body.user_id === 'u3'),
    JSON.stringify(env.calls.posts.map(p => [p.path, p.body.user_id])));
  check('toast de miembro anadido', env.calls.toasts.some(t => t.type === 'success'));
  check('detalle recargado tras anadir',
    env.calls.gets.filter(g => g.path === '/groups/g1/members').length >= 3,
    String(env.calls.gets.filter(g => g.path === '/groups/g1/members').length));

  // --- Quitar miembro ---
  const removeBtn = env.modalRoot().querySelector('[data-action="remove-member"]');
  check('boton quitar presente tras recargar', !!removeBtn);
  env.click(removeBtn);
  await tick(12);
  check('DELETE /groups/g1/members/u1',
    env.calls.deletes.some(d => d.path === '/groups/g1/members/u1'),
    JSON.stringify(env.calls.deletes));

  // --- Cerrar detalle y CRUD ---
  env.click(env.modalRoot().querySelector('[data-action="close-modal"]'));
  await tick();
  check('detalle cerrado', !env.modalRoot().querySelector('.modal'));

  env.click(env.container.querySelector('[data-action="open-create"]'));
  root = env.modalRoot();
  check('modal de alta de grupo abierto', !!root && !!root.querySelector('[data-modal-field="nombre"]'));
  env.set(root, 'nombre', 'Recepcion');
  env.set(root, 'descripcion', 'Atencion al cliente');
  env.click(root.querySelector('[data-action="submit-create"]'));
  await tick();
  check('POST /groups con Idempotency-Key',
    env.calls.posts.some(p => p.path === '/groups' && p.body.nombre === 'Recepcion'
      && p.options.headers && p.options.headers['Idempotency-Key']),
    JSON.stringify(env.calls.posts.map(p => p.path)));

  env.click(env.container.querySelector('[data-action="open-edit"][data-id="g2"]'));
  root = env.modalRoot();
  check('modal de edicion abierto', !!root && root.querySelector('[data-modal-field="nombre"]').value === 'Ventas');
  env.set(root, 'nombre', 'Ventas y marketing');
  env.click(root.querySelector('[data-action="submit-edit"]'));
  await tick();
  check('PATCH /groups/g2',
    env.calls.patches.some(p => p.path === '/groups/g2' && p.body.nombre === 'Ventas y marketing'),
    JSON.stringify(env.calls.patches));

  env.click(env.container.querySelector('[data-action="open-delete"][data-id="g2"]'));
  root = env.modalRoot();
  check('modal de baja abierto', !!root && !!root.querySelector('[data-action="submit-delete"]'));
  env.click(root.querySelector('[data-action="submit-delete"]'));
  await tick();
  check('DELETE /groups/g2', env.calls.deletes.some(d => d.path === '/groups/g2'),
    JSON.stringify(env.calls.deletes));
  env.close();

  // --- Sin permiso ---
  const env2 = createEnv({ perms: ['permissions.permission.view'], roles: ['VIEWER'] });
  env2.loadGroups();
  await tick();
  check('sin groups.group.view: pantalla de sin permiso', !!env2.container.querySelector('h3'));
  check('sin groups.group.view: sin GET /groups',
    !env2.calls.gets.some(g => g.path === '/groups'), JSON.stringify(env2.calls.gets));
  env2.close();
}

// =====================================================================
// S5 - SIDEBAR
// =====================================================================
async function s5_sidebar() {
  console.log('S5 sidebar: items de control por permisos');

  const routesFor = async (perms, roles) => {
    const env = createEnv({ perms, roles });
    env.loadShell();
    env.w.AppSidebar.init();
    const items = [...env.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
    env.close();
    return items;
  };

  const admin = await routesFor(ADMIN_PERMS, ['ADMIN']);
  check('ADMIN ve Usuarios, Roles y Grupos',
    admin.includes('users') && admin.includes('roles') && admin.includes('groups'), JSON.stringify(admin));

  const viewer = await routesFor(['groups.group.view', 'permissions.permission.view'], ['VIEWER']);
  check('VIEWER ve Grupos', viewer.includes('groups'), JSON.stringify(viewer));
  check('VIEWER no ve Usuarios', !viewer.includes('users'), JSON.stringify(viewer));
  check('VIEWER no ve Roles', !viewer.includes('roles'), JSON.stringify(viewer));

  const manager = await routesFor(
    ['groups.group.view', 'groups.group.manage_members', 'users.user.status', 'users.password.reset'],
    ['MANAGER']);
  check('MANAGER sin users.user.view no ve Usuarios', !manager.includes('users'), JSON.stringify(manager));
  check('MANAGER ve Grupos', manager.includes('groups'), JSON.stringify(manager));
  check('MANAGER sin roles.role.view no ve Roles', !manager.includes('roles'), JSON.stringify(manager));
}

// =====================================================================
// S6 - CAMBIO DE CONTRASENA
// =====================================================================
async function s6_password() {
  console.log('S6 contrasena: boton de menu, modal y modo obligatorio');

  const env = createEnv({ perms: [], roles: ['ADMIN'], realEventBus: true });
  const w = env.w;
  env.evalUi();
  w.eval(FILES.eventBus);
  w.EventBus.on('toast:show', (data) => env.calls.toasts.push(data));
  w.eval(FILES.password);
  w.AuthPasswordModule.init();

  const logout = w.document.getElementById('logout-btn');
  const btn = w.document.getElementById('change-password-btn');
  check('boton montado en el menu de usuario', !!btn);
  check('boton justo antes de cerrar sesion',
    !!btn && logout.previousElementSibling === btn,
    btn ? 'posicion incorrecta' : 'sin boton');
  check('boton con data-i18n', !!btn && btn.getAttribute('data-i18n') === 'shell.change_password');
  check('texto traducido del boton', !!btn && btn.textContent === ES_DICT.shell.change_password, btn && btn.textContent);

  // --- Apertura normal ---
  env.click(btn);
  let root = w.document.getElementById('password-modal-root');
  check('modal abierto', w.AuthPasswordModule.isOpen() === true);
  check('modal normal con boton de cierre', !!root.querySelector('.modal-close'));
  check('modal normal con cancelar y guardar',
    !!root.querySelector('[data-action="pw-cancel"]') && !!root.querySelector('[data-action="pw-submit"]'));

  // --- Validacion de cliente ---
  env.set(root, 'password_actual', 'Old1234');
  env.set(root, 'password_nuevo', 'abc');
  env.set(root, 'password_confirm', 'abc');
  env.click(root.querySelector('[data-action="pw-submit"]'));
  await tick();
  check('clave debil no se envia', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo en password_nuevo', !!root.querySelector('[data-error="password_nuevo"]:not(.hidden)'));

  // 9 caracteres SIN especial: rechazado en cliente (v1.16.0)
  env.set(root, 'password_nuevo', 'NewPass12');
  env.set(root, 'password_confirm', 'NewPass12');
  env.click(root.querySelector('[data-action="pw-submit"]'));
  await tick();
  check('9 sin especial no se envia', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo por falta de especial', !!root.querySelector('[data-error="password_nuevo"]:not(.hidden)'));

  env.set(root, 'password_nuevo', 'NewPass123!');
  env.set(root, 'password_confirm', 'OtraClave1!');
  env.click(root.querySelector('[data-action="pw-submit"]'));
  await tick();
  check('confirmacion que no coincide no se envia', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo en password_confirm', !!root.querySelector('[data-error="password_confirm"]:not(.hidden)'));

  // --- Cancelar cierra el modal normal ---
  env.click(root.querySelector('[data-action="pw-cancel"]'));
  check('cancelar cierra el modal', w.AuthPasswordModule.isOpen() === false
    && !root.querySelector('.modal'));

  // --- http:error: solo la categoria adecuada abre el obligatorio ---
  w.EventBus.emit('http:error', { path: '/inventory/stock', status: 403, data: { data: { category: 'otra' } } });
  check('403 de otra categoria no abre nada', w.AuthPasswordModule.isOpen() === false);

  w.EventBus.emit('http:error', { path: '/auth/change-password', status: 403, data: { data: { category: 'password_change_required' } } });
  check('403 de change-password no abre el obligatorio', w.AuthPasswordModule.isOpen() === false);

  w.EventBus.emit('http:error', { path: '/api/users', status: 403, data: { data: { category: 'password_change_required' } } });
  check('403 con categoria adecuada abre el modal', w.AuthPasswordModule.isOpen() === true);
  root = w.document.getElementById('password-modal-root');
  check('obligatorio sin boton de cierre', !root.querySelector('.modal-close'));
  check('obligatorio marcado', w.AuthPasswordModule.isForced() === true);
  check('obligatorio sin cancelar', !root.querySelector('[data-action="pw-cancel"]'));
  check('obligatorio con guardar y cerrar sesion',
    !!root.querySelector('[data-action="pw-submit"]') && !!root.querySelector('[data-action="pw-logout"]'));

  // --- El cierre ignorado en modo obligatorio ---
  const ghost = w.document.createElement('button');
  ghost.setAttribute('data-action', 'close-modal');
  root.appendChild(ghost);
  env.click(ghost);
  check('el obligatorio ignora el cierre', w.AuthPasswordModule.isOpen() === true);

  // --- Cambio exitoso desde el modo obligatorio ---
  env.set(root, 'password_actual', 'Old1234');
  env.set(root, 'password_nuevo', 'NewPass123!');
  env.set(root, 'password_confirm', 'NewPass123!');
  env.click(root.querySelector('[data-action="pw-submit"]'));
  await tick();
  check('POST /auth/change-password con la clave actual y la nueva',
    env.calls.posts.some(p => p.path === '/auth/change-password'
      && p.body.password_actual === 'Old1234' && p.body.password_nuevo === 'NewPass123!'),
    JSON.stringify(env.calls.posts));
  check('toast de exito', env.calls.toasts.some(t => t.type === 'success'));
  check('modal cerrado tras el cambio', w.AuthPasswordModule.isOpen() === false
    && !w.document.getElementById('password-modal-root').querySelector('.modal'));
  check('modo obligatorio levantado tras el cambio', w.AuthPasswordModule.isForced() === false);

  // --- Disparador directo del login + cierre de sesion ---
  w.EventBus.emit('auth:password-required', { forced: true });
  check('evento auth:password-required abre el obligatorio',
    w.AuthPasswordModule.isOpen() === true && w.AuthPasswordModule.isForced() === true);
  w.EventBus.emit('auth:logout', {});
  check('logout retira el boton del menu', !w.document.getElementById('change-password-btn'));
  check('logout cierra el modal', w.AuthPasswordModule.isOpen() === false);
  env.close();
}

// =====================================================================
// S7 - PERMISO TEMPORAL: MINUTOS Y AUTO-REFRESCO (v1.17.6)
// =====================================================================
async function s7_temp_minutes_autorefresh() {
  console.log('S7 temporal: minutos, modo fecha en UTC y auto-refresco al expirar');

  // --- Modal de concesion: modo minutos envia duracion_minutos ---
  {
    const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
    env.loadUsers();
    await tick();
    env.click(env.container.querySelector('[data-action="open-grant"]'));
    await tick(12);
    const root = env.modalRoot();

    const minutosRadio = root.querySelector('[data-expiry-mode][value="minutos"]');
    check('radio minutos presente', !!minutosRadio);
    check('campo minutos oculto hasta elegir el modo',
      !!root.querySelector('[data-expiry-minutes].hidden'));

    env.click(minutosRadio);
    await tick();
    const minutosField = root.querySelector('[data-modal-field="duracion_minutos"]');
    check('campo duracion_minutos visible al elegir minutos', !!minutosField);
    check('campo horas oculto en modo minutos',
      !!root.querySelector('[data-expiry-hours].hidden'));

    env.set(root, 'user_id', 'u1');
    env.set(root, 'permission_id', 'p1');
    env.set(root, 'motivo', 'Prueba minutos');
    env.set(root, 'duracion_minutos', '2');
    env.click(root.querySelector('[data-action="submit-grant"]'));
    await tick();
    check('POST con duracion_minutos y sin horas',
      env.calls.posts.some(p => p.path === '/temporary-permissions'
        && p.body.duracion_minutos === 2 && !('duracion_horas' in p.body)),
      JSON.stringify(env.calls.posts.filter(p => p.path === '/temporary-permissions')));
    env.close();
  }

  // --- Modal de concesion: modo fecha envia hora local como UTC (v1.17.7) ---
  {
    const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
    env.loadUsers();
    await tick();
    env.click(env.container.querySelector('[data-action="open-grant"]'));
    await tick(12);
    const root = env.modalRoot();

    env.click(root.querySelector('[data-expiry-mode][value="fecha"]'));
    await tick();
    const fechaField = root.querySelector('[data-modal-field="fecha_expiracion"]');
    check('campo fecha visible al elegir el modo fecha', !!fechaField);

    // hora de pared local (lo que el admin ve en su reloj); el valor esperado
    // en el payload es el instante en UTC naive, no la hora local cruda.
    const elegidaLocal = '2030-05-20T18:30';
    const esperadoUtc = new Date(elegidaLocal).toISOString().slice(0, 19).replace('T', ' ');
    env.set(root, 'fecha_expiracion', elegidaLocal);
    env.set(root, 'user_id', 'u1');
    env.set(root, 'permission_id', 'p1');
    env.set(root, 'motivo', 'Prueba fecha');
    env.click(root.querySelector('[data-action="submit-grant"]'));
    await tick();
    const posts = env.calls.posts.filter(p => p.path === '/temporary-permissions');
    check('POST modo fecha con la expiracion en UTC naive',
      posts.some(p => p.body.fecha_expiracion === esperadoUtc),
      JSON.stringify(posts.map(p => p.body.fecha_expiracion)) + ' esperado ' + esperadoUtc);
    if (new Date(elegidaLocal).getTimezoneOffset() !== 0) {
      check('POST modo fecha no envia la hora local sin convertir',
        !posts.some(p => p.body.fecha_expiracion === elegidaLocal),
        JSON.stringify(posts.map(p => p.body.fecha_expiracion)));
    }
    env.close();
  }

  // --- Auto-refresco: la fila que caduca recarga la pestana sola ---
  {
    let servidos = 0;
    const env = createEnv({
      perms: ADMIN_PERMS,
      roles: ['ADMIN'],
      getHandlers: {
        '/temporary-permissions': () => {
          servidos++;
            // Primera carga: fila que caduca en ~2 s. El API escribe la fecha
            // en UTC naive ('Y-m-d H:i:s' sin Z); se replica con toISOString
            // (el sello se trunca a segundos, asi que hace falta >= 1 s de
            // margen). Recargas posteriores: lista ya sin la fila (vencida).
            const items = servidos === 1 ? [{
            id: 'tp-soon',
            user_email: 'mpinilla@acme.local',
            user_id: 'u1',
            permission_id: 'p1',
            permission_identificador: 'inventory.stock.view',
            permission_criticidad: 'MEDIUM',
            motivo: 'Corta',
            granted_by_email: 'admin@acme.local',
            fecha_expiracion: new Date(Date.now() + 2000).toISOString().slice(0, 19).replace('T', ' '),
            activo: 1
          }] : [];
          return { status: 200, data: { temporary_permissions: items, total: items.length } };
        }
      }
    });
    env.loadUsers();
    await tick();
    env.click(env.container.querySelector('[data-action="tab"][data-tab="temp"]'));
    await tick();
    const temporales = () => env.calls.gets.filter(g => g.path === '/temporary-permissions');
    check('primera carga de la pestana temporal', temporales().length >= 1,
      String(temporales().length));
    check('fila proxima a vencer visible', /inventory\.stock\.view/.test(env.container.textContent));

    // scheduleTempAutoRefresh programa expiracion + 1s de margen; esperamos 4.2s.
    await new Promise(r => setTimeout(r, 4200));
    check('recarga automatica al pasar la expiracion', temporales().length >= 2,
      String(temporales().length));
    check('la fila vencida ya no se pinta', !/inventory\.stock\.view/.test(env.container.textContent));
    env.close();
  }
}

// =====================================================================
async function main() {
  await s1_registration();
  await s2_users_screen();
  await s3_roles_screen();
  await s4_groups_screen();
  await s5_sidebar();
  await s6_password();
  await s7_temp_minutes_autorefresh();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
