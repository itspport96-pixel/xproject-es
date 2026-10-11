/**
 * Smoke test jsdom para la UI v1.6.2 (Sprint 6 frontend + endpoint de reservas).
 * Uso: node smoke-v162.mjs   (desde .../Temp/opencode/jsdom-env)
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  stock: read('modules/inventory/stock.js'),
  reservations: read('modules/inventory/reservations.js'),
  audit: read('modules/audit/logs.js'),
  loader: read('core/loader.js'),
  router: read('core/router.js'),
  sidebar: read('modules/dashboard/sidebar.js')
};

// Componentes compartidos v1.9.3: los módulos migrados (p. ej. reservas)
// hacen `const UI = global.UI` al evaluarse, así que van siempre primero.
const UI_FILES = ['ui/dom.js', 'ui/render.js', 'ui/view.js', 'ui/modal.js', 'ui/actions.js'].map(read);

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
// FIXTURES
// =====================================================================

const LOCATIONS = [
  { id: 'loc1', codigo: 'HOUSEA23', tipo: 'ALMACENAMIENTO', capacidad: 20, capacidad_usada: 5, activa: true },
  { id: 'loc2', codigo: 'LLENA01', tipo: 'ALMACENAMIENTO', capacidad: 10, capacidad_usada: 10, activa: true },
  { id: 'loc3', codigo: '312-EDIT', tipo: 'ALMACENAMIENTO', capacidad: 30, capacidad_usada: 2, activa: true },
  { id: 'loc4', codigo: 'TRANS01', tipo: 'TRANSITO', capacidad: null, capacidad_usada: 0, activa: true },
  { id: 'loc5', codigo: 'INACT01', tipo: 'ALMACENAMIENTO', capacidad: 5, capacidad_usada: 0, activa: false }
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
  },
  {
    unidad_fisica_id: 'u3', codigo_barcode: 'SU-CCCCCC-33333333', producto_id: 'p2', sku: 'PROD3000',
    producto_nombre: 'Otro Producto', ubicacion_id: 'loc3', ubicacion_codigo: '312-EDIT',
    estado: 'EN_TRANSITO', cantidad_fisica: 2, cantidad_reservada: 0, cantidad_en_transito: 2, cantidad_disponible: 0
  }
];

const STOCK_PAYLOAD = {
  status: 200,
  data: {
    summary: { unidades: 3, cantidad_fisica: 10, cantidad_reservada: 3, cantidad_en_transito: 2, cantidad_disponible: 5 },
    data: UNITS,
    pagination: { total: 3, page: 1, per_page: 20, has_next: false }
  }
};

const RESERVATIONS = [
  {
    reserva_id: 'r1', stock_unit_id: 'u2', barcode: 'SU-BBBBBB-22222222',
    producto: 'Producto Demo', ubicacion: 'HOUSEA23', cantidad: 3, estado: 'ACTIVA',
    fecha_expiracion: '2099-01-01 00:00:00', creado_por: 'user-1', fecha_creacion: '2026-10-01 10:00:00'
  },
  {
    reserva_id: 'r2', stock_unit_id: 'u4', barcode: 'SU-DDDDDD-44444444',
    producto: 'Otro Producto', ubicacion: '312-EDIT', cantidad: 1, estado: 'CANCELADA',
    fecha_expiracion: null, creado_por: 'user-1', fecha_creacion: '2026-10-02 10:00:00'
  }
];

const RESERVATIONS_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, activas: 1, con_expiracion: 1 },
    data: RESERVATIONS,
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

const AUDIT_ROWS = [
  {
    id: 'a1', tenant_id: 't1', usuario_id: 'user-1', operacion: 'product.update', modulo: 'inventory',
    recurso: 'products', entidad_id: 'p1', tipo_cambio: 'UPDATE',
    valores_anteriores: { nombre: 'Antes' }, valores_nuevos: { nombre: 'Despues' },
    resultado: 'SUCCESS', razon_fallo: null, ip_address: '127.0.0.1', user_agent: 'test',
    request_id: 'req-1', timestamp: '2026-10-01 21:00:00.123456', duracion_operacion: 12,
    detalles_adicionales: { clave: 'valor' }, prev_hash: 'prev1', hash_integridad: 'hash1'
  },
  {
    id: 'a2', tenant_id: 't1', usuario_id: null, operacion: 'POST /api/inventory/movements', modulo: 'inventory',
    recurso: null, entidad_id: null, tipo_cambio: 'EXECUTE',
    valores_anteriores: null, valores_nuevos: { unidad_fisica_id: 'u1' },
    resultado: 'FAILURE', razon_fallo: 'Capacidad excedida', ip_address: '127.0.0.1', user_agent: 'test',
    request_id: 'req-2', timestamp: '2026-10-01 21:05:00.654321', duracion_operacion: 8,
    detalles_adicionales: null, prev_hash: null, hash_integridad: 'hash2'
  }
];

const AUDIT_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, exitosos: 1, fallidos: 1 },
    data: AUDIT_ROWS,
    pagination: { page: 1, per_page: 25, total: 2, last_page: 1 }
  }
};

// =====================================================================
// ENTORNO (una ventana jsdom con stubs)
// =====================================================================

function createEnv({ perms = [], roles = ['ADMIN'], getHandlers = {}, postImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="sidebar"></div><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], posts: [], toasts: [], errors: [] };

  Object.defineProperty(w.Element.prototype, 'scrollIntoView', { value: function() {}, configurable: true });
  // Silencia los console.error internos de los módulos (van a `calls.errors`).
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

  w.EventBus = {
    on() {}, off() {},
    emit(event, data) { if (event === 'toast:show') calls.toasts.push(data); }
  };

  w.AuthState = {
    getUser: () => ({ nombre: 'Test', apellido: 'User', email: 'test@acme.local', roles: roles.map(nombre => ({ nombre, permisos: perms })) }),
    getPermissions: () => perms.slice(),
    getRoles: () => roles.slice(),
    hasPermission: (p) => perms.includes(p),
    isAuthenticated: () => true
  };

  const env = { w, calls, postImpl, dom };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (getHandlers[p]) return getHandlers[p](options, env);
      if (p === '/inventory/stock') return STOCK_PAYLOAD;
      if (p === '/inventory/reservations') return RESERVATIONS_PAYLOAD;
      if (p === '/inventory/locations') return { status: 200, data: { data: LOCATIONS, pagination: { total: LOCATIONS.length, page: 1, per_page: 100 } } };
      if (p === '/inventory/products') return { status: 200, data: { data: [], pagination: { total: 0, page: 1, per_page: 100 } } };
      if (p === '/audit/logs') return AUDIT_PAYLOAD;
      if (p === '/users') return { status: 200, data: { data: [{ id: 'user-1', nombre: 'Demo', apellido: 'Admin', email: 'demo@acme.local' }], pagination: { total: 1, page: 1, per_page: 100 } } };
      if (p.startsWith('/locales/')) {
        const loc = p.slice('/locales/'.length).replace(/\.json$/, '');
        return { status: 200, data: { locale: loc, version: 'smoke', dictionary: ES_DICT } };
      }
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    },
    async post(p, body, options = {}) {
      calls.posts.push({ path: p, body, options });
      if (env.postImpl) return env.postImpl(p, body, options, env);
      return { status: 201, data: { ok: true } };
    }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  // i18n runtime + diccionario es inyectado (sin red): los módulos migrados
  // llaman t() al renderizar.
  evalIn(read('core/i18n.js'));
  w.i18n.inject('es', ES_DICT);
  const evalUi = () => UI_FILES.forEach(evalIn);

  env.loadStock = () => { evalUi(); evalIn(FILES.stock); w.InventoryStockModule.init({}, w.document.getElementById('module-container')); };
  env.loadReservations = () => { evalUi(); evalIn(FILES.reservations); w.InventoryReservationsModule.init({}, w.document.getElementById('module-container')); };
  env.loadAudit = () => { evalUi(); evalIn(FILES.audit); w.AuditLogsModule.init({}, w.document.getElementById('module-container')); };
  env.loadShell = () => { evalUi(); evalIn(FILES.loader); evalIn(FILES.router); evalIn(FILES.sidebar); };

  env.container = w.document.getElementById('module-container');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.submit = (form) => form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  env.close = () => dom.window.close();

  return env;
}

const ADMIN_PERMS = [
  'audit.log.view', 'inventory.item.view', 'inventory.stock.view', 'inventory.location.view',
  'inventory.movement.create', 'inventory.reservation.view', 'inventory.reservation.create',
  'inventory.reservation.cancel', 'users.user.view'
];
const VIEWER_PERMS = ['inventory.item.view', 'inventory.stock.view', 'inventory.location.view'];

// =====================================================================
// S1 - STOCK: acciones por estado y permiso
// =====================================================================
async function s1_stock_actions() {
  console.log('S1 stock: acciones Mover/Reservar por estado y permiso');
  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadStock();
  await tick();

  const rows = [...env.container.querySelectorAll('tbody tr')];
  check('3 filas renderizadas', rows.length === 3, `got ${rows.length}`);

  const actionsOf = (row) => row.querySelector('.table-actions').textContent;
  check('DISPONIBLE muestra Mover y Reservar', /Mover/.test(actionsOf(rows[0])) && /Reservar/.test(actionsOf(rows[0])), actionsOf(rows[0]));
  check('RESERVADO muestra sólo Mover', /Mover/.test(actionsOf(rows[1])) && !/Reservar/.test(actionsOf(rows[1])), actionsOf(rows[1]));
  // v1.10.0 (R3): todas las filas muestran "Historial"; EN_TRANSITO sigue sin
  // Mover/Reservar (y ADMIN_PERMS no incluye inventory.stock.block).
  check('EN_TRANSITO sin Mover/Reservar (sólo Historial)',
    !/Mover/.test(actionsOf(rows[2])) && !/Reservar/.test(actionsOf(rows[2]))
    && !/Bloquear/.test(actionsOf(rows[2])) && /Historial/.test(actionsOf(rows[2])),
    actionsOf(rows[2]));
  env.close();

  const env2 = createEnv({ perms: VIEWER_PERMS, roles: ['VIEWER'] });
  env2.loadStock();
  await tick();
  const row = env2.container.querySelector('tbody tr');
  const rowActions = row.querySelector('.table-actions').textContent;
  check('sin permiso de escritura sólo Historial',
    !/Mover/.test(rowActions) && !/Reservar/.test(rowActions)
    && !/Bloquear/.test(rowActions) && /Historial/.test(rowActions),
    rowActions);
  env2.close();
}

// =====================================================================
// S2 - MODAL MOVER
// =====================================================================
async function s2_move_modal() {
  console.log('S2 mover: modal, destinos, idempotencia y errores');
  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadStock();
  await tick();

  env.click([...env.container.querySelectorAll('[data-action="move"]')][0]);
  const form = env.container.querySelector('[data-move-form]');
  check('modal de movimiento abierto', !!form);

  const select = form.querySelector('select[name="ubicacion_destino_id"]');
  const values = [...select.options].map(o => o.value);
  check('sólo destino válido (loc3)', JSON.stringify(values) === JSON.stringify(['', 'loc3']), JSON.stringify(values));

  const keyHint = form.querySelector('[data-key-hint]').textContent;
  check('muestra Idempotency-Key', /Idempotency-Key/.test(keyHint) && /00000000-0000-4000-8000-000000000001/.test(keyHint), keyHint);

  select.value = 'loc3';
  env.submit(form);
  await tick();

  check('POST /inventory/movements', env.calls.posts[0] && env.calls.posts[0].path === '/inventory/movements', JSON.stringify(env.calls.posts[0] || null));
  check('payload con unidad y destino', env.calls.posts[0].body.unidad_fisica_id === 'u1' && env.calls.posts[0].body.ubicacion_destino_id === 'loc3');
  check('cabecera Idempotency-Key', env.calls.posts[0].options.headers['Idempotency-Key'] === '00000000-0000-4000-8000-000000000001');
  check('modal cerrado tras éxito', !env.container.querySelector('[data-move-form]'));
  check('stock recargado tras éxito', env.calls.gets.filter(g => g.path === '/inventory/stock').length >= 2);
  check('catálogos recargados (capacidad_usada)', env.calls.gets.some(g => g.path === '/inventory/locations'));
  check('toast de éxito', env.calls.toasts.some(t => t.type === 'success'));
  env.close();

  // 422 capacidad: la clave NO se renueva en el reintento del mismo envío
  const env3 = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env3.postImpl = () => {
    throw Object.assign(new Error('Capacidad excedida'), { status: 422, data: { field: 'capacidad' } });
  };
  env3.loadStock();
  await tick();
  env3.click(env3.container.querySelector('[data-action="move"]'));
  const form3 = env3.container.querySelector('[data-move-form]');
  form3.querySelector('select[name="ubicacion_destino_id"]').value = 'loc3';
  env3.submit(form3);
  await tick();
  check('422 toast de capacidad', env3.calls.toasts.some(t => t.type === 'danger' && /Capacidad/.test(t.title)), JSON.stringify(env3.calls.toasts));
  check('422 el modal sigue abierto', !!env3.container.querySelector('[data-move-form]'));
  const key1 = env3.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/)[1];

  // reintento del mismo envío con la misma clave
  env3.postImpl = () => ({ status: 201, data: { ok: true } });
  env3.submit(env3.container.querySelector('[data-move-form]'));
  await tick();
  check('reintento reutiliza la misma clave', env3.calls.posts[env3.calls.posts.length - 1].options.headers['Idempotency-Key'] === key1,
    JSON.stringify(env3.calls.posts.map(p => p.options.headers['Idempotency-Key'])));
  env3.close();

  // 409: clave quemada con otra carga útil -> se renueva
  const env4 = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env4.postImpl = () => {
    throw Object.assign(new Error('Clave ya usada'), { status: 409, data: null });
  };
  env4.loadStock();
  await tick();
  env4.click(env4.container.querySelector('[data-action="move"]'));
  const keyBefore = env4.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/)[1];
  const form4 = env4.container.querySelector('[data-move-form]');
  form4.querySelector('select[name="ubicacion_destino_id"]').value = 'loc3';
  env4.submit(form4);
  await tick();
  const keyAfter = env4.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/)[1];
  check('409 renueva la clave', keyBefore !== keyAfter, `${keyBefore} vs ${keyAfter}`);
  check('409 toast de conflicto', env4.calls.toasts.some(t => t.type === 'warning' && /Conflicto/.test(t.title)));
  env4.close();

  // 403
  const env5 = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env5.postImpl = () => {
    throw Object.assign(new Error('Acceso denegado: permiso'), { status: 403, data: null });
  };
  env5.loadStock();
  await tick();
  env5.click(env5.container.querySelector('[data-action="move"]'));
  const form5 = env5.container.querySelector('[data-move-form]');
  form5.querySelector('select[name="ubicacion_destino_id"]').value = 'loc3';
  env5.submit(form5);
  await tick();
  check('403 toast de acceso denegado', env5.calls.toasts.some(t => t.type === 'warning' && /Acceso denegado/.test(t.title)), JSON.stringify(env5.calls.toasts));
  env5.close();

  // 404
  const env6 = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env6.postImpl = () => {
    throw Object.assign(new Error('Unidad no encontrada'), { status: 404, data: null });
  };
  env6.loadStock();
  await tick();
  env6.click(env6.container.querySelector('[data-action="move"]'));
  const form6 = env6.container.querySelector('[data-move-form]');
  form6.querySelector('select[name="ubicacion_destino_id"]').value = 'loc3';
  env6.submit(form6);
  await tick();
  check('404 toast de no encontrado', env6.calls.toasts.some(t => t.type === 'danger' && /No encontrado/.test(t.title)));
  env6.close();

  // 401
  const env7 = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env7.postImpl = () => {
    throw Object.assign(new Error('Sesión expirada'), { status: 401, data: null });
  };
  env7.loadStock();
  await tick();
  env7.click(env7.container.querySelector('[data-action="move"]'));
  const form7 = env7.container.querySelector('[data-move-form]');
  form7.querySelector('select[name="ubicacion_destino_id"]').value = 'loc3';
  env7.submit(form7);
  await tick();
  check('401 toast de sesión expirada', env7.calls.toasts.some(t => t.type === 'warning' && /Sesión expirada/.test(t.title)));
  env7.close();

  // 400 (validación de cliente: destino vacío no envía)
  const env8 = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env8.loadStock();
  await tick();
  env8.click(env8.container.querySelector('[data-action="move"]'));
  env8.submit(env8.container.querySelector('[data-move-form]'));
  await tick();
  check('sin destino no se envía nada', env8.calls.posts.length === 0);
  check('error de campo visible', !!env8.container.querySelector('[data-error="ubicacion_destino_id"]:not(.hidden)'));
  env8.close();
}

// =====================================================================
// S3 - MODAL RESERVAR
// =====================================================================
async function s3_reserve_modal() {
  console.log('S3 reservar: modal, validaciones y envío');
  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadStock();
  await tick();

  env.click([...env.container.querySelectorAll('[data-action="reserve"]')][0]);
  const form = env.container.querySelector('[data-reserve-form]');
  check('modal de reserva abierto', !!form);
  check('muestra disponible', /Disponible/.test(form.textContent));

  form.querySelector('input[name="cantidad"]').value = '3';
  env.submit(form);
  await tick();

  check('POST /inventory/reservations', env.calls.posts[0] && env.calls.posts[0].path === '/inventory/reservations', JSON.stringify(env.calls.posts[0] || null));
  check('payload con cantidad', env.calls.posts[0].body.cantidad === '3' && env.calls.posts[0].body.unidad_fisica_id === 'u1');
  check('cabecera Idempotency-Key', !!env.calls.posts[0].options.headers['Idempotency-Key']);
  check('toast de éxito', env.calls.toasts.some(t => t.type === 'success'));
  env.close();

  // cantidad mayor que lo disponible: validación local, sin envío
  const env2 = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env2.loadStock();
  await tick();
  env2.click(env2.container.querySelector('[data-action="reserve"]'));
  const form2 = env2.container.querySelector('[data-reserve-form]');
  form2.querySelector('input[name="cantidad"]').value = '99';
  env2.submit(form2);
  await tick();
  check('cantidad inválida no envía', env2.calls.posts.length === 0);
  check('error de campo cantidad', !!env2.container.querySelector('[data-error="cantidad"]:not(.hidden)'));

  // expiración en el pasado: validación local
  form2.querySelector('input[name="cantidad"]').value = '2';
  form2.querySelector('input[name="fecha_expiracion"]').value = '2020-01-01T10:00';
  env2.submit(form2);
  await tick();
  check('expiración pasada no envía', env2.calls.posts.length === 0);
  check('error de campo fecha_expiracion', !!env2.container.querySelector('[data-error="fecha_expiracion"]:not(.hidden)'));
  env2.close();
}

// =====================================================================
// S4 - PANTALLA RESERVAS
// =====================================================================
async function s4_reservations_screen() {
  console.log('S4 reservas: listado desde GET /inventory/reservations y Liberar');
  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadReservations();
  await tick();

  check('consulta GET /inventory/reservations',
    env.calls.gets.some(g => g.path === '/inventory/reservations' && g.options.params.page === 1),
    JSON.stringify(env.calls.gets));
  check('ya no consulta /inventory/stock',
    !env.calls.gets.some(g => g.path === '/inventory/stock'),
    JSON.stringify(env.calls.gets));

  const table = env.container.querySelector('[data-res-table] table');
  check('tabla renderizada', !!table);
  const headers = [...table.querySelectorAll('th')].map(th => th.textContent.trim());
  check('columna Vencimiento presente', headers.includes('Vencimiento'), JSON.stringify(headers));
  check('sin alerta de hueco', !/Hueco de endpoint/.test(env.container.textContent));

  const rows = table.querySelectorAll('tbody tr');
  check('2 filas renderizadas', rows.length === 2, `got ${rows.length}`);

  const cells = rows[0].querySelectorAll('td');
  check('vencimiento con el dato real', cells[4].textContent.includes('2099-01-01'), cells[4].textContent);
  check('estado ACTIVA en la fila', /ACTIVA/.test(cells[3].textContent), cells[3].textContent);
  const cells2 = rows[1].querySelectorAll('td');
  check('reserva sin vencimiento muestra guion', cells2[4].textContent.trim() === '—', cells2[4].textContent);

  const summary = env.container.querySelector('[data-res-summary]').textContent;
  check('resumen con total/activas/vencimiento', /Reservas/.test(summary) && /Activas/.test(summary) && /Con vencimiento/.test(summary), summary);

  const releaseBtn = env.container.querySelector('[data-action="release"]');
  check('botón Liberar habilitado', !!releaseBtn && !releaseBtn.disabled);
  check('Liberar apunta a la reserva r1', !!releaseBtn && releaseBtn.dataset.id === 'r1', releaseBtn && releaseBtn.dataset.id);

  // Liberar con confirmación aceptada
  env.w.confirm = () => true;
  env.click(releaseBtn);
  await tick();
  check('POST /inventory/reservations/r1/release',
    env.calls.posts.some(p => p.path === '/inventory/reservations/r1/release'),
    JSON.stringify(env.calls.posts));
  check('liberar dispara toast de éxito',
    env.calls.toasts.some(t => t.type === 'success'),
    JSON.stringify(env.calls.toasts));
  check('refresca el listado tras liberar',
    env.calls.gets.filter(g => g.path === '/inventory/reservations').length >= 2,
    JSON.stringify(env.calls.gets));
  env.close();

  // confirm() rechazado -> no se llama al backend
  const env2 = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env2.loadReservations();
  await tick();
  env2.w.confirm = () => false;
  env2.click(env2.container.querySelector('[data-action="release"]'));
  await tick();
  check('confirm rechazado no llama al backend', env2.calls.posts.length === 0, JSON.stringify(env2.calls.posts));
  env2.close();

  // 409 (reserva ya no activa) -> toast de aviso + refresco
  const env3 = createEnv({
    perms: ADMIN_PERMS,
    roles: ['ADMIN'],
    postImpl: async () => {
      throw Object.assign(new Error('La reserva ya no está activa.'), { status: 409, data: null });
    }
  });
  env3.loadReservations();
  await tick();
  env3.w.confirm = () => true;
  env3.click(env3.container.querySelector('[data-action="release"]'));
  await tick();
  check('toast de aviso en el 409', env3.calls.toasts.some(t => t.type === 'warning'), JSON.stringify(env3.calls.toasts));
  check('refresca tras el 409',
    env3.calls.gets.filter(g => g.path === '/inventory/reservations').length >= 2,
    JSON.stringify(env3.calls.gets));
  env3.close();

  // 403 al liberar (el backend rechaza el permiso) -> toast "Sin permiso"
  const env4 = createEnv({
    perms: ['inventory.reservation.view', 'inventory.reservation.cancel'],
    roles: ['VIEWER'],
    postImpl: async () => {
      throw Object.assign(new Error('Acceso denegado'), { status: 403, data: null });
    }
  });
  env4.loadReservations();
  await tick();
  check('con reservation.view el listado se ve', !!env4.container.querySelector('tbody tr'));
  const btn4 = env4.container.querySelector('[data-action="release"]');
  check('con cancel hay botón Liberar', !!btn4);
  env4.w.confirm = () => true;
  env4.click(btn4);
  await tick();
  check('toast de sin permiso en el 403', env4.calls.toasts.some(t => t.type === 'danger' && /permiso/i.test(t.title)), JSON.stringify(env4.calls.toasts));
  env4.close();

  // VIEWER sin reservation.view -> pantalla de sin permiso y sin llamadas
  const env5 = createEnv({ perms: VIEWER_PERMS, roles: ['VIEWER'] });
  env5.loadReservations();
  await tick();
  check('sin permiso muestra aviso', /Sin permiso/.test(env5.container.textContent));
  check('sin permiso no pide el listado', env5.calls.gets.length === 0, JSON.stringify(env5.calls.gets));
  env5.close();
}

// =====================================================================
// S5 - VISOR DE AUDITORÍA
// =====================================================================
async function s5_audit_viewer() {
  console.log('S5 auditoría: filtros, listado y detalle');
  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadAudit();
  await tick();

  check('GET /audit/logs', env.calls.gets.some(g => g.path === '/audit/logs'));
  const headers = [...env.container.querySelectorAll('thead th')].map(th => th.textContent.trim());
  check('columnas del listado', headers.includes('Fecha') && headers.includes('Resultado') && headers.includes('Detalle'), JSON.stringify(headers));
  check('resumen renderizado', /Registros/.test(env.container.querySelector('[data-audit-summary]').textContent));
  check('2 filas', env.container.querySelectorAll('tbody tr').length === 2);

  // filtro de resultado
  env.container.querySelector('[data-filter="resultado"]').value = 'FAILURE';
  env.click(env.container.querySelector('[data-action="apply-filters"]'));
  await tick();
  const last = env.calls.gets[env.calls.gets.length - 1];
  check('filtro resultado enviado', last.path === '/audit/logs' && last.options.params.resultado === 'FAILURE', JSON.stringify(last.options.params));

  // detalle con valores antes/después
  env.click(env.container.querySelector('[data-action="detail"]'));
  const modal = env.container.querySelector('[data-modal-root] .modal');
  check('modal de detalle abierto', !!modal);
  check('muestra valores antes', /Valores antes/.test(modal.textContent));
  check('muestra valores después', /Valores después/.test(modal.textContent));
  check('JSON de antes', /"nombre": "Antes"/.test(modal.innerHTML));
  check('JSON de después', /"nombre": "Despues"/.test(modal.innerHTML));
  check('muestra hash e integridad', /hash_integridad/.test(modal.textContent) && /hash1/.test(modal.textContent));
  check('SUCCESS sin alerta de fallo', !modal.querySelector('.alert-danger'));

  env.click(modal.querySelector('[data-action="close-modal"]'));
  check('modal cerrado', !env.container.querySelector('[data-modal-root] .modal'));
  env.close();

  // segundo detalle (FAILURE) para verificar razon_fallo y prev_hash ausente
  const env2 = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env2.loadAudit();
  await tick();
  const detailBtns = [...env2.container.querySelectorAll('[data-action="detail"]')];
  env2.click(detailBtns[1]);
  const modal2 = env2.container.querySelector('[data-modal-root] .modal');
  check('detalle FAILURE con razon', /Capacidad excedida/.test(modal2.textContent));
  check('sin valores antes muestra aviso', /sin cambios/.test(modal2.textContent));
  env2.close();

  // sin permiso
  const env3 = createEnv({ perms: VIEWER_PERMS, roles: ['VIEWER'] });
  env3.loadAudit();
  await tick();
  check('sin audit.log.view no carga datos',
    !env3.calls.gets.some(g => g.path === '/audit/logs') && /Sin permiso/.test(env3.container.textContent),
    JSON.stringify(env3.calls.gets));
  env3.close();
}

// =====================================================================
// S6 - ROUTER / LOADER / SIDEBAR
// =====================================================================
async function s6_shell_registration() {
  console.log('S6 shell: router, loader y sidebar');

  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.evalIn(FILES.loader);
  env.evalIn(FILES.router);
  const w = env.w;

  check('ruta audit declarada en router', w.AppRouter.getRoutes()['audit'] && w.AppRouter.getRoutes()['audit'].module === 'audit/logs');
  check('ruta inventory/reservations declarada', !!w.AppRouter.getRoutes()['inventory/reservations']);
  check('módulo audit/logs registrado', w.ModuleLoader.has('audit/logs'));
  check('módulo inventory/reservations registrado', w.ModuleLoader.has('inventory/reservations'));
  check('isModuleAvailable audit', w.AppRouter.isModuleAvailable('audit') === true);
  check('isModuleAvailable reservations', w.AppRouter.isModuleAvailable('inventory/reservations') === true);
  check('stock sigue disponible', w.AppRouter.isModuleAvailable('inventory/stock') === true);
  env.close();

  // sidebar ADMIN
  const envA = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  envA.evalIn(FILES.loader);
  envA.evalIn(FILES.router);
  envA.evalIn(FILES.sidebar);
  envA.w.AppSidebar.init();
  const adminRoutes = [...envA.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('ADMIN ve Auditoría', adminRoutes.includes('audit'), JSON.stringify(adminRoutes));
  check('ADMIN ve Reservas', adminRoutes.includes('inventory/reservations'), JSON.stringify(adminRoutes));
  check('ADMIN ve Stock', adminRoutes.includes('inventory/stock'));
  envA.close();

  // sidebar VIEWER
  const envV = createEnv({ perms: VIEWER_PERMS, roles: ['VIEWER'] });
  envV.evalIn(FILES.loader);
  envV.evalIn(FILES.router);
  envV.evalIn(FILES.sidebar);
  envV.w.AppSidebar.init();
  const viewerRoutes = [...envV.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('VIEWER no ve Auditoría', !viewerRoutes.includes('audit'), JSON.stringify(viewerRoutes));
  check('VIEWER no ve Reservas', !viewerRoutes.includes('inventory/reservations'), JSON.stringify(viewerRoutes));
  check('VIEWER ve Stock', viewerRoutes.includes('inventory/stock'), JSON.stringify(viewerRoutes));
  envV.close();

  // sidebar USER (movement/reservation sin cancel ni audit)
  const USER_PERMS = ['inventory.item.view', 'inventory.stock.view', 'inventory.location.view', 'inventory.movement.create', 'inventory.reservation.view', 'inventory.reservation.create'];
  const envU = createEnv({ perms: USER_PERMS, roles: ['USER'] });
  envU.evalIn(FILES.loader);
  envU.evalIn(FILES.router);
  envU.evalIn(FILES.sidebar);
  envU.w.AppSidebar.init();
  const userRoutes = [...envU.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('USER ve Reservas', userRoutes.includes('inventory/reservations'), JSON.stringify(userRoutes));
  check('USER no ve Auditoría', !userRoutes.includes('audit'), JSON.stringify(userRoutes));
  envU.close();
}

// =====================================================================
async function main() {
  await s1_stock_actions();
  await s2_move_modal();
  await s3_reserve_modal();
  await s4_reservations_screen();
  await s5_audit_viewer();
  await s6_shell_registration();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
