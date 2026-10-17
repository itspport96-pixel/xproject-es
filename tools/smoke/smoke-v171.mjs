/**
 * Smoke test jsdom para la UI v1.7.1 (Sprint 7 frontend: picking y envíos).
 * Uso: node smoke-v171.mjs   (desde .../Temp/opencode/jsdom-env)
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  picking: read('modules/inventory/picking.js'),
  shipments: read('modules/inventory/shipments.js'),
  loader: read('core/loader.js'),
  router: read('core/router.js'),
  sidebar: read('modules/dashboard/sidebar.js')
};

// Las cabeceras de pagina viajan en ui/render.js y los modulos hacen
// `const UI = global.UI` al evaluarse, asi que van siempre primero.
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
// FIXTURES
// =====================================================================

const PICKING_LOCATIONS = [
  { id: 'pk1', codigo: 'PICK-01', tipo: 'PICKING', capacidad: 50, capacidad_usada: 0, activa: true },
  { id: 'pk2', codigo: 'PICK-02', tipo: 'PICKING', capacidad: 20, capacidad_usada: 3, activa: true },
  { id: 'tr1', codigo: 'TRANS01', tipo: 'TRANSITO', capacidad: null, capacidad_usada: 0, activa: true },
  { id: 'al1', codigo: 'HOUSEA23', tipo: 'ALMACENAMIENTO', capacidad: 30, capacidad_usada: 2, activa: true },
  { id: 'in1', codigo: 'PICK-OLD', tipo: 'PICKING', capacidad: 10, capacidad_usada: 0, activa: false }
];

const ACTIVE_RESERVATIONS = [
  {
    reserva_id: 'r1', stock_unit_id: 'u1', barcode: 'SU-AAAAAA-11111111',
    producto: 'Producto Demo', ubicacion: 'HOUSEA23', cantidad: 3, estado: 'ACTIVA',
    fecha_expiracion: '2099-01-01 00:00:00', creado_por: 'user-1', fecha_creacion: '2026-10-01 10:00:00'
  },
  {
    reserva_id: 'r2', stock_unit_id: 'u2', barcode: 'SU-BBBBBB-22222222',
    producto: 'Otro Producto', ubicacion: 'HOUSEA23', cantidad: 1, estado: 'ACTIVA',
    fecha_expiracion: null, creado_por: 'user-1', fecha_creacion: '2026-10-01 11:00:00'
  }
];

const RESERVATIONS_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, activas: 2, con_expiracion: 1 },
    data: ACTIVE_RESERVATIONS,
    pagination: { total: 2, page: 1, per_page: 100, has_next: false }
  }
};

const PICKING_ROWS = [
  {
    picking_id: 'p1', id_lote_picking: 'LOTE-A', stock_unit_id: 'u1', barcode: 'SU-AAAAAA-11111111',
    producto: 'Producto Demo', cantidad: 3, estado: 'EN_CURSO', ubicacion_origen: 'HOUSEA23',
    ubicacion_preparacion: 'PICK-01', referencia_orden: 'OC-100', selector: 'user-1', supervisor: null,
    fecha_creacion: '2026-10-02 09:00:00', fecha_actualizacion: '2026-10-02 09:00:00'
  },
  {
    picking_id: 'p2', id_lote_picking: 'LOTE-A', stock_unit_id: 'u2', barcode: 'SU-BBBBBB-22222222',
    producto: 'Otro Producto', cantidad: 1, estado: 'EN_CURSO', ubicacion_origen: 'HOUSEA23',
    ubicacion_preparacion: 'PICK-01', referencia_orden: null, selector: 'user-1', supervisor: null,
    fecha_creacion: '2026-10-02 09:00:01', fecha_actualizacion: '2026-10-02 09:00:01'
  },
  {
    picking_id: 'p3', id_lote_picking: 'LOTE-B', stock_unit_id: 'u3', barcode: 'SU-CCCCCC-33333333',
    producto: 'Tercer Producto', cantidad: 2, estado: 'COMPLETADA', ubicacion_origen: 'HOUSEA23',
    ubicacion_preparacion: 'PICK-02', referencia_orden: 'OC-101', selector: 'user-1', supervisor: null,
    fecha_creacion: '2026-10-02 08:00:00', fecha_actualizacion: '2026-10-02 08:30:00'
  },
  {
    picking_id: 'p4', id_lote_picking: 'LOTE-C', stock_unit_id: 'u4', barcode: 'SU-DDDDDD-44444444',
    producto: 'Cuarto Producto', cantidad: 1, estado: 'VERIFICADA', ubicacion_origen: 'HOUSEA23',
    ubicacion_preparacion: 'PICK-02', referencia_orden: null, selector: 'user-1', supervisor: 'user-2',
    fecha_creacion: '2026-10-01 08:00:00', fecha_actualizacion: '2026-10-01 09:00:00'
  }
];

const PICKING_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 4, en_curso: 2, completadas: 1, verificadas: 1 },
    data: PICKING_ROWS,
    pagination: { total: 4, page: 1, per_page: 20, has_next: false }
  }
};

const PREPARED_UNITS = [
  {
    unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111', producto_nombre: 'Producto Demo',
    sku: 'PROD2000', ubicacion_codigo: 'PICK-01', cantidad_fisica: 3, estado: 'EN_PREPARACION'
  },
  {
    unidad_fisica_id: 'u2', codigo_barcode: 'SU-BBBBBB-22222222', producto_nombre: 'Otro Producto',
    sku: 'PROD3000', ubicacion_codigo: 'PICK-01', cantidad_fisica: 1, estado: 'EN_PREPARACION'
  }
];

const STOCK_PREPARED_PAYLOAD = {
  status: 200,
  data: {
    summary: { unidades: 2, cantidad_fisica: 4, cantidad_reservada: 0, cantidad_en_transito: 0, cantidad_disponible: 0 },
    data: PREPARED_UNITS,
    pagination: { total: 2, page: 1, per_page: 100, has_next: false }
  }
};

const SHIPMENTS = [
  {
    shipment_id: 'sh1', referencia_externa: 'EXP-1', tipo: 'PROPIO', ubicacion_origen: 'HOUSEA23',
    destino_direccion: 'Av. Siempreviva 742', destinatario_id: null, destinatario_nombre: 'Cliente Uno',
    destinatario_contacto: '123', conductor_nombre: 'Chofer Uno', vehiculo_referencia: 'ABC123',
    estado: 'PREPARACION', hora_salida: null, hora_entrega_esperada: null, hora_entrega_real: null,
    actor_id_autoriza_salida: null, total_items: 2, fecha_creacion: '2026-10-02 10:00:00',
    fecha_actualizacion: '2026-10-02 10:00:00'
  },
  {
    shipment_id: 'sh2', referencia_externa: null, tipo: 'MENSAJERIA', ubicacion_origen: null,
    destino_direccion: null, destinatario_id: null, destinatario_nombre: 'Cliente Dos',
    destinatario_contacto: null, conductor_nombre: null, vehiculo_referencia: null,
    estado: 'EN_RUTA', hora_salida: '2026-10-01 12:00:00', hora_entrega_esperada: null,
    hora_entrega_real: null, actor_id_autoriza_salida: 'user-2', total_items: 1,
    fecha_creacion: '2026-10-01 10:00:00', fecha_actualizacion: '2026-10-01 12:00:00'
  }
];

const SHIPMENTS_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, en_preparacion: 1, en_ruta: 1, entregados: 0 },
    data: SHIPMENTS,
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

const SHIPMENT_DETAIL = {
  status: 200,
  data: {
    shipment: { ...SHIPMENTS[0], observaciones: 'Fragil' },
    items: [
      {
        shipment_item_id: 'i1', stock_unit_id: 'u1', barcode: 'SU-AAAAAA-11111111',
        producto: 'Producto Demo', cantidad: 3, estado: 'CARGADA', fecha_creacion: '2026-10-02 10:05:00'
      },
      {
        shipment_item_id: 'i2', stock_unit_id: 'u2', barcode: 'SU-BBBBBB-22222222',
        producto: 'Otro Producto', cantidad: 1, estado: 'CARGADA', fecha_creacion: '2026-10-02 10:05:01'
      }
    ]
  }
};

// =====================================================================
// ENTORNO
// =====================================================================

function createEnv({ perms = [], roles = ['ADMIN'], getHandlers = {}, postImpl = null } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="sidebar"></div><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], posts: [], toasts: [], errors: [] };

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
  w.confirm = () => true;

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

      if (p === '/inventory/reservations') return RESERVATIONS_PAYLOAD;
      if (p === '/inventory/picking') return PICKING_PAYLOAD;
      if (p === '/inventory/shipments' && options.params && options.params.per_page === 20) return SHIPMENTS_PAYLOAD;
      if (p === '/inventory/shipments/sh1') return SHIPMENT_DETAIL;
      if (p === '/inventory/stock') return STOCK_PREPARED_PAYLOAD;
      if (p === '/inventory/locations') {
        const soloPicking = options.params && options.params.tipo === 'PICKING';
        const soloActivas = options.params && (options.params.activa === '1' || options.params.activa === true);
        const filas = PICKING_LOCATIONS.filter(l => {
          if (soloPicking && l.tipo !== 'PICKING') return false;
          if (soloActivas && !(l.activa === 1 || l.activa === true)) return false;
          return true;
        });
        return {
          status: 200,
          data: { data: filas, pagination: { total: filas.length, page: 1, per_page: 100 } }
        };
      }
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

  env.evalIn = (code) => w.eval(code);
  // i18n runtime + diccionario es inyectado (sin red).
  w.eval(read('core/i18n.js'));
  w.i18n.inject('es', ES_DICT);
  const evalUi = () => UI_FILES.forEach(env.evalIn);
  env.loadPicking = () => {
    evalUi();
    env.evalIn(FILES.picking);
    w.InventoryPickingModule.init({}, w.document.getElementById('module-container'));
  };
  env.loadShipments = () => {
    evalUi();
    env.evalIn(FILES.shipments);
    w.InventoryShipmentsModule.init({}, w.document.getElementById('module-container'));
  };
  env.loadShell = () => {
    evalUi();
    env.evalIn(FILES.loader);
    env.evalIn(FILES.router);
    env.evalIn(FILES.sidebar);
  };

  env.container = w.document.getElementById('module-container');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.change = (el) => el.dispatchEvent(new w.Event('change', { bubbles: true }));
  env.submit = (form) => form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  env.keydown = (el, key) => el.dispatchEvent(new w.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
  env.close = () => dom.window.close();

  return env;
}

const FULL_PERMS = [
  'inventory.stock.view', 'inventory.location.view', 'inventory.reservation.view',
  'inventory.picking.view', 'inventory.picking.create', 'inventory.picking.confirm',
  'inventory.picking.verify', 'inventory.shipping.view', 'inventory.shipping.create',
  'inventory.shipping.confirm'
];

const USER_PERMS = [
  'inventory.stock.view', 'inventory.location.view', 'inventory.reservation.view',
  'inventory.picking.view', 'inventory.picking.create', 'inventory.picking.confirm',
  'inventory.shipping.view', 'inventory.shipping.create'
];

const VIEWER_PERMS = [
  'inventory.stock.view', 'inventory.location.view', 'inventory.reservation.view',
  'inventory.picking.view', 'inventory.shipping.view'
];

// =====================================================================
// P1 - PANTALLA DE PICKING: reservas, escaneo y arranque del lote
// =====================================================================
async function p1_picking_screen() {
  console.log('P1 picking: reservas, escaneo, selección y start');

  const env = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env.loadPicking();
  await tick();

  check('sección de nuevo lote visible con create', !!env.container.querySelector('[data-start-form]'));
  check('GET de reservas ACTIVAS', env.calls.gets.some(g =>
    g.path === '/inventory/reservations' && g.options.params.estado === 'ACTIVA'),
    JSON.stringify(env.calls.gets.map(g => g.path)));
  check('GET de zonas PICKING activas', env.calls.gets.some(g =>
    g.path === '/inventory/locations' && g.options.params.tipo === 'PICKING'));
  check('GET de operaciones', env.calls.gets.some(g => g.path === '/inventory/picking'));

  const resRows = [...env.container.querySelectorAll('[data-res-table] tbody tr')];
  check('2 reservas pendientes en la tabla', resRows.length === 2, `got ${resRows.length}`);

  const locSelect = env.container.querySelector('select[name="ubicacion_preparacion_id"]');
  const locValues = [...locSelect.options].map(o => o.value);
  check('sólo zonas PICKING activas en el select',
    JSON.stringify(locValues) === JSON.stringify(['', 'pk1', 'pk2']), JSON.stringify(locValues));

  const hintText = env.container.querySelector('[data-key-hint]').textContent;
  const keyBefore = (hintText.match(/Idempotency-Key:\s*(\S+)/) || [])[1];
  check('muestra Idempotency-Key del formulario', /Idempotency-Key/.test(hintText) && !!keyBefore, hintText);

  // Escaneo por Enter con barcode desconocido
  const scan = env.container.querySelector('[data-scan]');
  scan.value = 'NO-EXISTE';
  env.keydown(scan, 'Enter');
  await tick();
  check('barcode desconocido no selecciona', env.container.querySelector('[data-selection-count]').textContent === '0');
  check('barcode desconocido avisa', env.calls.toasts.some(t => t.type === 'danger' && /Barcode no encontrado/.test(t.title)),
    JSON.stringify(env.calls.toasts));

  // Escaneo por Enter con barcode válido
  scan.value = 'su-bbbbbb-22222222 ';
  env.keydown(scan, 'Enter');
  await tick();
  const count1 = env.container.querySelector('[data-selection-count]').textContent;
  check('escaneo selecciona la reserva', count1 === '1', count1);
  check('el input se limpia tras el escaneo', scan.value === '');
  check('la fila quedó marcada', !!env.container.querySelector('[data-res-table] input[data-id="u2"]:checked'));

  // Selección múltiple: seleccionar todas
  env.click(env.container.querySelector('[data-action="select-all"]'));
  await tick();
  const count2 = env.container.querySelector('[data-selection-count]').textContent;
  check('seleccionar todas marca 2', count2 === '2', count2);

  // Validación sin ubicación (el lote ya está informado)
  env.container.querySelector('input[name="id_lote_picking"]').value = 'LOTE-SIN-ZONA';
  locSelect.value = '';
  env.submit(env.container.querySelector('[data-start-form]'));
  await tick();
  check('sin ubicación no hay POST', !env.calls.posts.some(p => p.path === '/inventory/picking/start'),
    JSON.stringify(env.calls.posts));
  check('muestra error de campo', !env.container.querySelector('[data-error="ubicacion_preparacion_id"]').classList.contains('hidden'));

  // Envío correcto
  locSelect.value = 'pk1';
  env.container.querySelector('input[name="id_lote_picking"]').value = 'LOTE-E2E';
  env.container.querySelector('input[name="referencia_orden"]').value = 'OC-900';
  env.submit(env.container.querySelector('[data-start-form]'));
  await tick();

  const startCall = env.calls.posts.find(p => p.path === '/inventory/picking/start');
  check('POST /inventory/picking/start', !!startCall, JSON.stringify(env.calls.posts));
  check('payload con lote, zona y unidades', !!startCall &&
    startCall.body.id_lote_picking === 'LOTE-E2E' &&
    startCall.body.ubicacion_preparacion_id === 'pk1' &&
    startCall.body.referencia_orden === 'OC-900' &&
    startCall.body.unidades.length === 2 &&
    startCall.body.unidades.every(u => typeof u.unidad_fisica_id === 'string'),
    JSON.stringify(startCall && startCall.body));
  check('cabecera Idempotency-Key', !!startCall && startCall.options.headers['Idempotency-Key'] === keyBefore,
    JSON.stringify(startCall && startCall.options.headers));
  check('toast de éxito', env.calls.toasts.some(t => t.type === 'success' && /iniciado/i.test(t.title)));
  check('la clave se renueva tras el éxito', (() => {
    const hint = env.container.querySelector('[data-key-hint]');
    const actual = hint && hint.textContent.match(/Idempotency-Key:\s*(\S+)/);
    return !!actual && actual[1] !== keyBefore;
  })());
  check('se recargan reservas y picking', env.calls.gets.filter(g => g.path === '/inventory/reservations').length >= 2 &&
    env.calls.gets.filter(g => g.path === '/inventory/picking').length >= 2);
  env.close();

  // 409: clave quemada con otra carga útil -> se renueva
  const env2 = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env2.postImpl = () => {
    throw Object.assign(new Error('Clave ya usada'), { status: 409, data: null });
  };
  env2.loadPicking();
  await tick();
  const k2before = env2.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/)[1];
  env2.container.querySelector('input[name="id_lote_picking"]').value = 'LOTE-409';
  env2.container.querySelector('select[name="ubicacion_preparacion_id"]').value = 'pk1';
  env2.click(env2.container.querySelector('[data-action="select-all"]'));
  env2.submit(env2.container.querySelector('[data-start-form]'));
  await tick();
  const k2after = env2.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/)[1];
  check('409 renueva la clave', k2before !== k2after, `${k2before} vs ${k2after}`);
  check('409 toast de conflicto', env2.calls.toasts.some(t => t.type === 'warning' && /Conflicto/.test(t.title)));
  env2.close();

  // 403 en el start
  const env3 = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env3.postImpl = () => {
    throw Object.assign(new Error('Permiso requerido'), { status: 403, data: null });
  };
  env3.loadPicking();
  await tick();
  env3.container.querySelector('input[name="id_lote_picking"]').value = 'LOTE-403';
  env3.container.querySelector('select[name="ubicacion_preparacion_id"]').value = 'pk1';
  env3.click(env3.container.querySelector('[data-action="select-all"]'));
  env3.submit(env3.container.querySelector('[data-start-form]'));
  await tick();
  check('403 toast de acceso denegado', env3.calls.toasts.some(t => t.type === 'warning' && /Acceso denegado/.test(t.title)),
    JSON.stringify(env3.calls.toasts));
  env3.close();

  // 422 con campo -> error de campo en línea
  const env4 = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env4.postImpl = () => {
    throw Object.assign(new Error('La cantidad no coincide con la unidad'), { status: 422, data: { field: 'cantidad' } });
  };
  env4.loadPicking();
  await tick();
  env4.container.querySelector('input[name="id_lote_picking"]').value = 'LOTE-422';
  env4.container.querySelector('select[name="ubicacion_preparacion_id"]').value = 'pk1';
  env4.click(env4.container.querySelector('[data-action="select-all"]'));
  env4.submit(env4.container.querySelector('[data-start-form]'));
  await tick();
  check('422 toast de operación rechazada', env4.calls.toasts.some(t => t.type === 'danger' && /rechazada/.test(t.title)),
    JSON.stringify(env4.calls.toasts));
  check('422 aviso en línea', !env4.container.querySelector('[data-form-error]').classList.contains('hidden'));
  env4.close();

  // Sin inventory.picking.create no se muestra el formulario de arranque
  const env5 = createEnv({ perms: VIEWER_PERMS, roles: ['VIEWER'] });
  env5.loadPicking();
  await tick();
  check('sin create no hay formulario de lote', !env5.container.querySelector('[data-start-form]'));
  check('sin create sigue viendo el listado', !!env5.container.querySelector('[data-picking-table]'));
  env5.close();

  // Sin inventory.picking.view -> pantalla de sin permiso y sin llamadas
  const env6 = createEnv({ perms: ['inventory.stock.view'], roles: ['VIEWER'] });
  env6.loadPicking();
  await tick();
  check('sin picking.view muestra aviso', /Sin permiso/.test(env6.container.textContent));
  check('sin picking.view no hay llamadas', env6.calls.gets.length === 0, JSON.stringify(env6.calls.gets));
  env6.close();
}

// =====================================================================
// P2 - OPERACIONES: agrupación por lote y acciones por estado/permiso
// =====================================================================
async function p2_picking_operations() {
  console.log('P2 picking: lotes, Completar y Verificar');

  const env = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env.loadPicking();
  await tick();

  const filas = [...env.container.querySelectorAll('[data-picking-table] tbody tr')];
  check('3 lotes agrupados (no 4 filas)', filas.length === 3, `got ${filas.length}`);
  check('resumen con operaciones y estados', /Operaciones/.test(env.container.querySelector('[data-picking-summary]').textContent));

  const rowFor = (lote) => [...env.container.querySelectorAll('[data-picking-table] tbody tr')]
    .find(r => r.querySelector('[data-lote]') && r.querySelector('[data-lote]').dataset.lote === lote);

  const accionesDe = (lote) => {
    const row = rowFor(lote);
    const btns = [...row.querySelectorAll('button[data-action]')].map(b => b.dataset.action);
    return btns;
  };

  check('LOTE EN_CURSO ofrece Completar y desplegar', accionesDe('LOTE-A').includes('complete-lote') && accionesDe('LOTE-A').includes('expand-lote'),
    JSON.stringify(accionesDe('LOTE-A')));
  check('LOTE EN_CURSO no ofrece Verificar', !accionesDe('LOTE-A').includes('verify-lote'));
  check('LOTE COMPLETADA ofrece Verificar', accionesDe('LOTE-B').includes('verify-lote'), JSON.stringify(accionesDe('LOTE-B')));
  check('LOTE COMPLETADA no ofrece Completar', !accionesDe('LOTE-B').includes('complete-lote'));
  check('LOTE VERIFICADA sin acciones de estado', !accionesDe('LOTE-C').includes('complete-lote') && !accionesDe('LOTE-C').includes('verify-lote'),
    JSON.stringify(accionesDe('LOTE-C')));

  // Expansión del lote
  env.click([...env.container.querySelectorAll('[data-action="expand-lote"]')][0]);
  await tick();
  check('expandir muestra las unidades del lote', /SU-AAAAAA-11111111/.test(env.container.querySelector('[data-picking-table]').textContent));

  // Completar
  env.click(rowFor('LOTE-A').querySelector('[data-action="complete-lote"]'));
  await tick();
  const complete = env.calls.posts.find(p => p.path === '/inventory/picking/LOTE-A/complete');
  check('POST /inventory/picking/LOTE-A/complete', !!complete, JSON.stringify(env.calls.posts));
  check('complete con Idempotency-Key', !!complete && !!complete.options.headers['Idempotency-Key'],
    JSON.stringify(complete && complete.options.headers));
  check('toast de lote completado', env.calls.toasts.some(t => t.type === 'success' && /completado/i.test(t.title)));
  env.close();

  // Verificar
  const env2 = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env2.loadPicking();
  await tick();
  env2.click([...env2.container.querySelectorAll('[data-action="verify-lote"]')][0]);
  await tick();
  const verify = env2.calls.posts.find(p => p.path === '/inventory/picking/LOTE-B/verify');
  check('POST /inventory/picking/LOTE-B/verify', !!verify, JSON.stringify(env2.calls.posts));
  check('verify con Idempotency-Key', !!verify && !!verify.options.headers['Idempotency-Key']);
  check('toast de lote verificado', env2.calls.toasts.some(t => t.type === 'success' && /verificado/i.test(t.title)));
  env2.close();

  // 409 en verify (ya verificado por otro usuario)
  const env3 = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env3.postImpl = () => {
    throw Object.assign(new Error('El lote ya no está COMPLETADA'), { status: 409, data: null });
  };
  env3.loadPicking();
  await tick();
  env3.click([...env3.container.querySelectorAll('[data-action="verify-lote"]')][0]);
  await tick();
  check('409 en verify avisa y refresca', env3.calls.toasts.some(t => t.type === 'warning' && /Conflicto/.test(t.title)) &&
    env3.calls.gets.filter(g => g.path === '/inventory/picking').length >= 2,
    JSON.stringify(env3.calls.toasts));
  env3.close();

  // Sin inventory.picking.verify el USER no ve Verificar
  const env4 = createEnv({ perms: USER_PERMS, roles: ['USER'] });
  env4.loadPicking();
  await tick();
  const btnsUSER = [...env4.container.querySelectorAll('[data-action="verify-lote"]')];
  check('USER sin verify no ve el botón Verificar', btnsUSER.length === 0, `got ${btnsUSER.length}`);
  check('USER con confirm ve Completar', env4.container.querySelectorAll('[data-action="complete-lote"]').length >= 1);
  env4.close();

  // Sin inventory.picking.confirm nadie ve Completar
  const env5 = createEnv({ perms: ['inventory.picking.view'], roles: ['VIEWER'] });
  env5.loadPicking();
  await tick();
  check('sin confirm no hay Completar', env5.container.querySelectorAll('[data-action="complete-lote"]').length === 0);
  check('sin verify no hay Verificar', env5.container.querySelectorAll('[data-action="verify-lote"]').length === 0);
  env5.close();
}

// =====================================================================
// S1 - ENVIOS: catálogos, modos de carga y POST /shipments/load
// =====================================================================
async function s1_shipments_load() {
  console.log('S1 envíos: catálogos, lote/manual y carga');

  const env = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env.loadShipments();
  await tick();

  check('formulario de nuevo envío con create', !!env.container.querySelector('[data-load-form]'));
  check('carga ubicaciones, stock y picking', env.calls.gets.some(g => g.path === '/inventory/locations') &&
    env.calls.gets.some(g => g.path === '/inventory/stock') &&
    env.calls.gets.some(g => g.path === '/inventory/picking'),
    JSON.stringify(env.calls.gets.map(g => g.path)));
  check('stock filtrado en EN_PREPARACION', env.calls.gets.some(g =>
    g.path === '/inventory/stock' && g.options.params.estado === 'EN_PREPARACION'));

  const loteSelect = env.container.querySelector('[data-lote-select]');
  check('sólo lotes COMPLETADA/VERIFICADA', !!loteSelect, 'falta select de lote');
  const lotes = [...loteSelect.options].map(o => o.value);
  check('lotes cargables LOTE-B y LOTE-C', JSON.stringify(lotes) === JSON.stringify(['', 'LOTE-B', 'LOTE-C']), JSON.stringify(lotes));

  // LOTE-B: u3 no está EN_PREPARACION -> sin unidades listas
  loteSelect.value = 'LOTE-B';
  env.change(loteSelect);
  await tick();
  check('LOTE-B sin unidades preparadas avisa', /Ninguna unidad del lote está en preparación/.test(env.container.querySelector('[data-mode-panel]').textContent));

  // Modo manual: 2 unidades preparadas
  env.click(env.container.querySelector('[data-action="mode-unidades"]'));
  await tick();
  const manualRows = [...env.container.querySelectorAll('[data-mode-panel] tbody tr')];
  check('modo manual lista las unidades EN_PREPARACION', manualRows.length === 2, `got ${manualRows.length}`);

  check('Idempotency-Key del formulario', /Idempotency-Key/.test(env.container.querySelector('[data-key-hint]').textContent));

  // Validación: sin destinatario
  env.click(env.container.querySelector('[data-action="select-all"]'));
  await tick();
  env.submit(env.container.querySelector('[data-load-form]'));
  await tick();
  check('sin destinatario no hay POST', !env.calls.posts.some(p => p.path === '/inventory/shipments/load'),
    JSON.stringify(env.calls.posts));
  check('error de campo destinatario', !env.container.querySelector('[data-error="destinatario_nombre"]').classList.contains('hidden'));

  // Envío correcto
  const keyBefore = env.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/)[1];
  const form = env.container.querySelector('[data-load-form]');
  form.querySelector('input[name="destinatario_nombre"]').value = 'Cliente Demo';
  form.querySelector('input[name="destino_direccion"]').value = 'Calle Falsa 123';
  form.querySelector('input[name="conductor_nombre"]').value = 'Chofer Demo';
  form.querySelector('input[name="vehiculo_referencia"]').value = 'XYZ999';
  form.querySelector('textarea[name="observaciones"]').value = 'Entregar en horario de oficina';
  // hora de pared local (v1.17.8): el backend normaliza el valor interpretandolo
  // como UTC, asi que el payload debe traer el instante convertido a UTC naive.
  const entregaLocal = '2030-05-20T18:30';
  const entregaUtc = new Date(entregaLocal).toISOString().slice(0, 19).replace('T', ' ');
  form.querySelector('input[name="hora_entrega_esperada"]').value = entregaLocal;
  env.submit(form);
  await tick();

  const load = env.calls.posts.find(p => p.path === '/inventory/shipments/load');
  check('POST /inventory/shipments/load', !!load, JSON.stringify(env.calls.posts));
  check('payload con 2 unidades', !!load && load.body.unidades.length === 2, JSON.stringify(load && load.body.unidades));
  check('payload con datos del envío', !!load &&
    load.body.shipment.tipo === 'PROPIO' &&
    load.body.shipment.destinatario_nombre === 'Cliente Demo' &&
    load.body.shipment.destino_direccion === 'Calle Falsa 123' &&
    load.body.shipment.observaciones === 'Entregar en horario de oficina',
    JSON.stringify(load && load.body.shipment));
  check('hora_entrega_esperada en UTC naive',
    !!load && load.body.shipment.hora_entrega_esperada === entregaUtc,
    JSON.stringify(load && load.body.shipment.hora_entrega_esperada) + ' esperado ' + entregaUtc);
  if (new Date(entregaLocal).getTimezoneOffset() !== 0) {
    check('hora_entrega_esperada nunca viaja como hora local cruda',
      !!load && load.body.shipment.hora_entrega_esperada !== entregaLocal,
      JSON.stringify(load && load.body.shipment.hora_entrega_esperada));
  }
  check('cabecera Idempotency-Key', !!load && load.options.headers['Idempotency-Key'] === keyBefore,
    JSON.stringify(load && load.options.headers));
  check('toast de carga exitosa', env.calls.toasts.some(t => t.type === 'success' && /cargadas/i.test(t.title)));
  check('la clave se renueva tras el éxito', (() => {
    const hint = env.container.querySelector('[data-key-hint]');
    const actual = hint && hint.textContent.match(/Idempotency-Key:\s*(\S+)/);
    return !!actual && actual[1] !== keyBefore;
  })());
  check('el listado se recarga', env.calls.gets.filter(g => g.path === '/inventory/shipments').length >= 2);
  env.close();

  // 409 renueva la clave
  const env2 = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env2.postImpl = () => {
    throw Object.assign(new Error('Clave ya usada'), { status: 409, data: null });
  };
  env2.loadShipments();
  await tick();
  const k2before = env2.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/)[1];
  const form2 = env2.container.querySelector('[data-load-form]');
  form2.querySelector('input[name="destinatario_nombre"]').value = 'Cliente 409';
  env2.click(env2.container.querySelector('[data-action="mode-unidades"]'));
  await tick();
  env2.click(env2.container.querySelector('[data-action="select-all"]'));
  env2.submit(env2.container.querySelector('[data-load-form]'));
  await tick();
  const k2after = env2.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/)[1];
  check('409 renueva la clave', k2before !== k2after, `${k2before} vs ${k2after}`);
  check('409 toast de conflicto', env2.calls.toasts.some(t => t.type === 'warning' && /Conflicto/.test(t.title)));
  env2.close();

  // Sin inventory.shipping.create no hay formulario de carga
  const env3 = createEnv({ perms: ['inventory.shipping.view', 'inventory.stock.view'], roles: ['VIEWER'] });
  env3.loadShipments();
  await tick();
  check('sin shipping.create no hay formulario', !env3.container.querySelector('[data-load-form]'));
  check('sin shipping.create sí hay listado', !!env3.container.querySelector('[data-ship-table]'));
  env3.close();

  // Sin inventory.shipping.view -> sin permiso
  const env4 = createEnv({ perms: ['inventory.stock.view'], roles: ['VIEWER'] });
  env4.loadShipments();
  await tick();
  check('sin shipping.view muestra aviso', /Sin permiso/.test(env4.container.textContent));
  check('sin shipping.view no hay llamadas', env4.calls.gets.length === 0, JSON.stringify(env4.calls.gets));
  env4.close();
}

// =====================================================================
// S2 - ENVIOS: detalle y autorización de salida
// =====================================================================
async function s2_shipments_detail_authorize() {
  console.log('S2 envíos: listado, detalle y autorizar salida');

  const env = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env.loadShipments();
  await tick();

  const filas = [...env.container.querySelectorAll('[data-ship-table] tbody tr')];
  check('2 envíos en el listado', filas.length === 2, `got ${filas.length}`);
  check('PREPARACION ofrece Autorizar salida',
    !!filas[0].querySelector('[data-action="authorize"]'));
  check('EN_RUTA no ofrece Autorizar salida',
    !filas[1].querySelector('[data-action="authorize"]'));

  env.click(filas[0].querySelector('[data-action="open-detail"]'));
  await tick();
  const modal = env.container.querySelector('[data-modal-root] .modal');
  check('detalle abierto', !!modal);
  check('detalle con destinatario y conductor', /Cliente Uno/.test(modal.textContent) && /Chofer Uno/.test(modal.textContent));
  check('detalle con 2 ítems', /Ítems \(2\)/.test(modal.textContent), modal.querySelector('.modal-body').textContent.slice(0, 120));
  check('detalle con barcode de los ítems', /SU-AAAAAA-11111111/.test(modal.textContent));
  check('detalle ofrece Autorizar salida', !!modal.querySelector('[data-action="authorize"]'));
  check('GET del detalle', env.calls.gets.some(g => g.path === '/inventory/shipments/sh1'));

  // Autorizar desde el listado con confirm() = false -> no POST
  const envNo = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  envNo.w.confirm = () => false;
  envNo.loadShipments();
  await tick();
  envNo.click([...envNo.container.querySelectorAll('[data-action="authorize"]')][0]);
  await tick();
  check('cancelar el confirm no envía', !envNo.calls.posts.some(p => p.path === '/inventory/shipments/authorize'),
    JSON.stringify(envNo.calls.posts));
  envNo.close();

  // Autorizar con confirm() = true
  env.w.confirm = () => true;
  env.click([...env.container.querySelectorAll('[data-action="authorize"]')][0]);
  await tick();

  const auth = env.calls.posts.find(p => p.path === '/inventory/shipments/authorize');
  check('POST /inventory/shipments/authorize', !!auth, JSON.stringify(env.calls.posts));
  check('payload con shipment_id', !!auth && auth.body.shipment_id === 'sh1', JSON.stringify(auth && auth.body));
  check('authorize con Idempotency-Key', !!auth && !!auth.options.headers['Idempotency-Key'],
    JSON.stringify(auth && auth.options.headers));
  check('toast de salida autorizada', env.calls.toasts.some(t => t.type === 'success' && /autorizada/i.test(t.title)));
  check('el modal se cierra tras autorizar', !env.container.querySelector('[data-modal-root] .modal'));
  check('el listado se recarga', env.calls.gets.filter(g => g.path === '/inventory/shipments').length >= 2);

  // Un segundo intento usa una clave NUEVA (la anterior se liberó al éxito)
  env.click([...env.container.querySelectorAll('[data-action="authorize"]')][0]);
  await tick();
  const authPosts = env.calls.posts.filter(p => p.path === '/inventory/shipments/authorize');
  check('segundo authorize reutiliza la clave SÓLO en el reintento fallido',
    authPosts.length === 2 &&
    !!authPosts[0].options.headers['Idempotency-Key'] &&
    !!authPosts[1].options.headers['Idempotency-Key'],
    JSON.stringify(authPosts.map(p => p.options.headers['Idempotency-Key'])));
  check('segundo éxito con clave distinta', authPosts.length === 2 &&
    authPosts[0].options.headers['Idempotency-Key'] !== authPosts[1].options.headers['Idempotency-Key'],
    JSON.stringify(authPosts.map(p => p.options.headers['Idempotency-Key'])));
  env.close();

  // 409 en authorize
  const env3 = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env3.postImpl = () => {
    throw Object.assign(new Error('El envío ya no está en preparación'), { status: 409, data: null });
  };
  env3.loadShipments();
  await tick();
  env3.click([...env3.container.querySelectorAll('[data-action="authorize"]')][0]);
  await tick();
  check('409 en authorize avisa', env3.calls.toasts.some(t => t.type === 'warning' && /Conflicto/.test(t.title)),
    JSON.stringify(env3.calls.toasts));
  env3.close();

  // 404 en authorize (envío borrado) -> toast y refresco
  const env4 = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env4.postImpl = () => {
    throw Object.assign(new Error('Envío no encontrado'), { status: 404, data: null });
  };
  env4.loadShipments();
  await tick();
  env4.click([...env4.container.querySelectorAll('[data-action="authorize"]')][0]);
  await tick();
  check('404 en authorize avisa', env4.calls.toasts.some(t => t.type === 'danger' && /No encontrado/.test(t.title)),
    JSON.stringify(env4.calls.toasts));
  env4.close();

  // 403 en authorize (sin confirm)
  const env5 = createEnv({ perms: ['inventory.shipping.view', 'inventory.shipping.create'], roles: ['USER'] });
  env5.postImpl = () => {
    throw Object.assign(new Error('Permiso requerido'), { status: 403, data: null });
  };
  env5.loadShipments();
  await tick();
  check('sin shipping.confirm no se ofrece Autorizar',
    env5.container.querySelectorAll('[data-action="authorize"]').length === 0,
    `got ${env5.container.querySelectorAll('[data-action="authorize"]').length}`);
  env5.close();
}

// =====================================================================
// S3 - SHELL: router, loader y sidebar (B7)
// =====================================================================
async function s3_shell() {
  console.log('S3 shell: rutas, registro de módulos y permisos del menú');

  const env = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  env.evalIn(FILES.loader);
  env.evalIn(FILES.router);
  const w = env.w;

  check('ruta inventory/picking declarada', w.AppRouter.getRoutes()['inventory/picking'] &&
    w.AppRouter.getRoutes()['inventory/picking'].module === 'inventory/picking');
  check('ruta inventory/shipments declarada', w.AppRouter.getRoutes()['inventory/shipments'] &&
    w.AppRouter.getRoutes()['inventory/shipments'].module === 'inventory/shipments');
  check('módulo inventory/picking registrado', w.ModuleLoader.has('inventory/picking'));
  check('módulo inventory/shipments registrado', w.ModuleLoader.has('inventory/shipments'));
  check('isModuleAvailable picking', w.AppRouter.isModuleAvailable('inventory/picking') === true);
  check('isModuleAvailable shipments', w.AppRouter.isModuleAvailable('inventory/shipments') === true);
  env.close();

  // ADMIN: ve ambos enlaces
  const envA = createEnv({ perms: FULL_PERMS, roles: ['ADMIN'] });
  envA.loadShell();
  envA.w.AppSidebar.init();
  const adminRoutes = [...envA.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('ADMIN ve Picking', adminRoutes.includes('inventory/picking'), JSON.stringify(adminRoutes));
  check('ADMIN ve Envíos', adminRoutes.includes('inventory/shipments'), JSON.stringify(adminRoutes));
  envA.close();

  // USER con los permisos de picking/shipping
  const envU = createEnv({ perms: USER_PERMS, roles: ['USER'] });
  envU.loadShell();
  envU.w.AppSidebar.init();
  const userRoutes = [...envU.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('USER con picking.view ve Picking', userRoutes.includes('inventory/picking'), JSON.stringify(userRoutes));
  check('USER con shipping.view ve Envíos', userRoutes.includes('inventory/shipments'), JSON.stringify(userRoutes));
  envU.close();

  // Sin los permisos nuevos: no aparecen
  const envX = createEnv({ perms: ['inventory.stock.view', 'inventory.location.view'], roles: ['VIEWER'] });
  envX.loadShell();
  envX.w.AppSidebar.init();
  const xRoutes = [...envX.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('sin picking.view no se muestra Picking', !xRoutes.includes('inventory/picking'), JSON.stringify(xRoutes));
  check('sin shipping.view no se muestra Envíos', !xRoutes.includes('inventory/shipments'), JSON.stringify(xRoutes));
  envX.close();
}

// =====================================================================
async function main() {
  await p1_picking_screen();
  await p2_picking_operations();
  await s1_shipments_load();
  await s2_shipments_detail_authorize();
  await s3_shell();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
