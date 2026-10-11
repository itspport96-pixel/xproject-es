/**
 * Smoke test jsdom para la UI v1.10.0 (Inventory: R3, E4, E1, CU-002, E8).
 * Uso: node tools/smoke/smoke-v1100.mjs   (desde C:/xampp/htdocs/xp)
 *
 * Cubre:
 *  - inventory/movements.js: listado GET /inventory/movements, filtros
 *    (unidad, origen, destino, motivo, es_correccion, fechas), summary
 *    (total/correcciones), tabla con badge de corrección (E8), paginación y
 *    permiso inventory.stock.view.
 *  - inventory/stock.js: línea de tiempo "Historial"
 *    (GET /inventory/stock/{id}/history, paginación en modal, 403),
 *    Bloquear/Desbloquear (E4: confirm, POST con accion + Idempotency-Key) y
 *    warnings de mover (CU-002: data.warnings → toast).
 *  - inventory/incidents.js: resolver con resultado (E4: select sólo para
 *    UBICACION_DESCONOCIDA/INVENTARIO_DESAPARECIDO, destino sólo con
 *    ENCONTRADA) y badge de prioridad (E1).
 *  - inventory/receiving.js: rechazo_total (E1: checkbox, payload, resultado
 *    con discrepancia 100 % y prioridad ALTA).
 *  - Registro de inventory/movements en loader/router/sidebar.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  movements: read('modules/inventory/movements.js'),
  stock: read('modules/inventory/stock.js'),
  incidents: read('modules/inventory/incidents.js'),
  receiving: read('modules/inventory/receiving.js'),
  loader: read('core/loader.js'),
  router: read('core/router.js'),
  sidebar: read('modules/dashboard/sidebar.js')
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
// FIXTURES
// =====================================================================

const LOCATIONS = [
  { id: 'loc1', codigo: 'HOUSEA23', nombre: 'A-2-3', tipo: 'ALMACENAMIENTO', capacidad: 20, capacidad_usada: 5, activa: true },
  { id: 'loc2', codigo: 'DAÑOS01', nombre: 'Zona dañados', tipo: 'ALMACENAMIENTO', capacidad: 10, capacidad_usada: 1, activa: true },
  { id: 'loc3', codigo: '312-EDIT', tipo: 'ALMACENAMIENTO', capacidad: 30, capacidad_usada: 2, activa: true }
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
    unidad_fisica_id: 'u4', codigo_barcode: 'SU-EEEEEE-55555555', producto_id: 'p1', sku: 'PROD2000',
    producto_nombre: 'Producto Demo', ubicacion_id: 'loc3', ubicacion_codigo: '312-EDIT',
    estado: 'BLOQUEADO', cantidad_fisica: 2, cantidad_reservada: 0, cantidad_en_transito: 0, cantidad_disponible: 0
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
    summary: { unidades: 4, cantidad_fisica: 12, cantidad_reservada: 3, cantidad_en_transito: 2, cantidad_disponible: 5 },
    data: UNITS,
    pagination: { total: 4, page: 1, per_page: 20, has_next: false }
  }
};

const HISTORY_PAGE1 = {
  status: 200,
  data: {
    unidad: { ...UNITS[0] },
    data: [
      {
        fuente: 'movimiento', id: 'm2', tipo: 'MOVIDO', timestamp: '2026-10-04 12:00:00',
        descripcion: 'Unidad movida a HOUSEA23', hash_integridad: 'abc123def456',
        es_correccion: true, movimiento_referencia_id: 'm1',
        ubicacion_origen_id: 'loc3', ubicacion_origen_codigo: '312-EDIT',
        ubicacion_destino_id: 'loc1', ubicacion_destino_codigo: 'HOUSEA23',
        estado_anterior: 'DISPONIBLE', estado_posterior: 'DISPONIBLE',
        cantidad_anterior: 5, cantidad_posterior: 5
      },
      {
        fuente: 'evento', id: 'e1', tipo: 'RECIBIDO', timestamp: '2026-10-04 10:00:00',
        descripcion: 'Recepción registrada', hash_integridad: 'hash-evento-1',
        estado_anterior: null, estado_posterior: 'DISPONIBLE'
      }
    ],
    pagination: { total: 3, page: 1, per_page: 50, has_next: true }
  }
};

const HISTORY_PAGE2 = {
  status: 200,
  data: {
    unidad: { ...UNITS[0] },
    data: [
      {
        fuente: 'evento', id: 'e0', tipo: 'BLOQUEADO', timestamp: '2026-10-03 09:00:00',
        descripcion: 'Unidad bloqueada por incidencia', hash_integridad: 'hash-bloqueo-1',
        estado_anterior: 'DISPONIBLE', estado_posterior: 'BLOQUEADO'
      }
    ],
    pagination: { total: 3, page: 2, per_page: 50, has_next: false }
  }
};

const MOVEMENTS_ROWS = [
  {
    movimiento_id: 'mv1', unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111',
    cantidad: 5, ubicacion_origen_id: 'loc3', ubicacion_origen_codigo: '312-EDIT',
    ubicacion_destino_id: 'loc1', ubicacion_destino_codigo: 'HOUSEA23',
    motivo: 'REUBICACION', actor_id: 'user-1', referencia_externa: null,
    timestamp_movimiento: '2026-10-04 12:00:00', hash_integridad: 'hash-m1',
    es_correccion: false, movimiento_referencia_id: null
  },
  {
    movimiento_id: 'mv2', unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111',
    cantidad: 5, ubicacion_origen_id: 'loc1', ubicacion_origen_codigo: 'HOUSEA23',
    ubicacion_destino_id: 'loc3', ubicacion_destino_codigo: '312-EDIT',
    motivo: 'REUBICACION', actor_id: 'user-1', referencia_externa: 'ORD-77',
    timestamp_movimiento: '2026-10-04 13:00:00', hash_integridad: 'hash-m2',
    es_correccion: true, movimiento_referencia_id: 'mv1'
  }
];

const MOVEMENTS_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, correcciones: 1 },
    data: MOVEMENTS_ROWS,
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

const INCIDENTS_LIST = [
  {
    incidencia_id: 'i1', tipo: 'UBICACION_DESCONOCIDA', descripcion: 'Unidad no está en su ubicación',
    observaciones: null, estado: 'ABIERTA', prioridad: 'ALTA',
    unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111',
    ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23', inventory_count_id: null,
    actor_id_reporta: 'user-1', fecha_deteccion: '2026-10-05 09:00:00', fecha_creacion: '2026-10-05 09:00:00'
  },
  {
    incidencia_id: 'i2', tipo: 'DAÑO', descripcion: 'Paquete golpeado',
    observaciones: null, estado: 'ABIERTA', prioridad: 'NORMAL',
    unidad_fisica_id: 'u2', codigo_barcode: 'SU-BBBBBB-22222222',
    ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23', inventory_count_id: null,
    actor_id_reporta: 'user-1', fecha_deteccion: '2026-10-05 10:00:00', fecha_creacion: '2026-10-05 10:00:00'
  }
];

const INCIDENTS_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, abiertas: 2, investigacion: 0, resueltas: 0, no_resueltas: 0 },
    data: INCIDENTS_LIST,
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

const RECEIVE_RECHAZO = {
  status: 201,
  data: {
    mensaje: 'Entrega rechazada: no se registró stock.',
    unidad_fisica_id: null,
    codigo_barcode: null,
    producto_id: 'p1',
    sku: 'PROD2000',
    ubicacion_id: 'loc1',
    ubicacion_codigo: 'HOUSEA23',
    cantidad_esperada: 10,
    cantidad_recibida: 0,
    estado: null,
    ledger: null,
    evento: null,
    incidencia: {
      incidencia_id: 'ir1', tipo: 'ENTREGA_RECHAZADA', estado: 'ABIERTA', prioridad: 'ALTA',
      descripcion: 'Entrega rechazada al 100 %.'
    },
    recepcion_parcial: false,
    rechazo_total: true,
    discrepancia_porcentaje: 100
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

  const calls = { gets: [], posts: [], toasts: [], errors: [], confirms: [] };

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

  const env = { w, calls, postImpl, dom, confirmResult: true };

  w.confirm = (msg) => { calls.confirms.push(String(msg)); return env.confirmResult; };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (getHandlers[p]) return getHandlers[p](options, env);
      if (p === '/inventory/stock') return STOCK_PAYLOAD;
      if (p === '/inventory/movements') return MOVEMENTS_PAYLOAD;
      if (p === '/inventory/incidents') return INCIDENTS_PAYLOAD;
      if (p === '/inventory/locations') return { status: 200, data: { data: LOCATIONS, pagination: { total: LOCATIONS.length, page: 1, per_page: 100 } } };
      if (p === '/inventory/products') return { status: 200, data: { data: [{ sku: 'PROD2000', nombre: 'Producto Demo', activo: true }], pagination: { total: 1, page: 1, per_page: 100 } } };
      if (p.startsWith('/inventory/stock/') && p.endsWith('/history')) {
        const page = options.params && options.params.page === 2 ? 2 : 1;
        return page === 2 ? HISTORY_PAGE2 : HISTORY_PAGE1;
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

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  // i18n runtime + diccionario es inyectado (sin red): los módulos migrados
  // llaman t() al renderizar.
  evalIn(read('core/i18n.js'));
  w.i18n.inject('es', ES_DICT);
  const evalUi = () => UI_FILES.forEach(evalIn);

  env.loadMovements = () => { evalUi(); evalIn(FILES.movements); w.InventoryMovementsModule.init({}, w.document.getElementById('module-container')); };
  env.loadStock = () => { evalUi(); evalIn(FILES.stock); w.InventoryStockModule.init({}, w.document.getElementById('module-container')); };
  env.loadIncidents = () => { evalUi(); evalIn(FILES.incidents); w.InventoryIncidentsModule.init({}, w.document.getElementById('module-container')); };
  env.loadReceiving = () => { evalUi(); evalIn(FILES.receiving); w.InventoryReceivingModule.init({}, w.document.getElementById('module-container')); };
  env.loadShell = () => { evalUi(); evalIn(FILES.loader); evalIn(FILES.router); evalIn(FILES.sidebar); };

  env.container = w.document.getElementById('module-container');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.submit = (form) => form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  env.change = (el) => el.dispatchEvent(new w.Event('change', { bubbles: true }));
  env.input = (el) => el.dispatchEvent(new w.Event('input', { bubbles: true }));
  env.close = () => dom.window.close();

  return env;
}

const STOCK_PERMS = [
  'inventory.stock.view', 'inventory.stock.block', 'inventory.movement.create',
  'inventory.reservation.create', 'inventory.reservation.view',
  'inventory.incident.view', 'inventory.incident.resolve', 'inventory.incident.report',
  'inventory.receiving.create', 'inventory.location.view', 'inventory.item.view'
];
const NO_BLOCK_PERMS = STOCK_PERMS.filter(p => p !== 'inventory.stock.block');

// =====================================================================
// S1 - MOVIMENTS: listado, filtros y permiso
// =====================================================================
async function s1_movements() {
  console.log('S1 movimientos: listado, filtros, correcciones y permiso');

  const env = createEnv({ perms: STOCK_PERMS, roles: ['ADMIN'] });
  env.loadMovements();
  await tick();

  const first = env.calls.gets.find(g => g.path === '/inventory/movements');
  check('GET /inventory/movements con page 1',
    !!first && first.options.params.page === 1 && first.options.params.per_page === 20,
    JSON.stringify(first && first.options.params));

  const cards = env.container.querySelector('[data-mov-summary]').textContent;
  check('summary con total y correcciones', /Movimientos/.test(cards) && /2/.test(cards) && /Correcciones/.test(cards) && /1/.test(cards), cards);

  const headers = [...env.container.querySelectorAll('thead th')].map(th => th.textContent.trim());
  check('columnas de la tabla',
    headers.includes('Movimiento') && headers.includes('Ruta') && headers.includes('Corrección'),
    JSON.stringify(headers));

  const rows = [...env.container.querySelectorAll('tbody tr')];
  check('2 movimientos renderizados', rows.length === 2, `got ${rows.length}`);
  check('badge Corrección en la fila E8', /Corrección/.test(rows[1].textContent), rows[1].textContent);

  // Filtros
  env.container.querySelector('[data-filter="unidad_fisica_id"]').value = 'u1';
  env.container.querySelector('[data-filter="es_correccion"]').value = '1';
  env.container.querySelector('[data-filter="motivo"]').value = 'REUBICACION';
  env.click(env.container.querySelector('[data-action="apply-filters"]'));
  await tick();

  const filtered = env.calls.gets.filter(g => g.path === '/inventory/movements').pop();
  check('filtros enviados al backend',
    filtered.options.params.unidad_fisica_id === 'u1'
    && filtered.options.params.es_correccion === '1'
    && filtered.options.params.motivo === 'REUBICACION',
    JSON.stringify(filtered.options.params));

  env.click(env.container.querySelector('[data-action="clear-filters"]'));
  await tick();
  const cleared = env.calls.gets.filter(g => g.path === '/inventory/movements').pop();
  check('limpiar filtros no envía filtros',
    cleared.options.params.unidad_fisica_id === undefined && cleared.options.params.es_correccion === undefined,
    JSON.stringify(cleared.options.params));

  env.close();

  // Sin inventory.stock.view
  const env2 = createEnv({ perms: ['inventory.item.view'], roles: ['VIEWER'] });
  env2.loadMovements();
  await tick();
  check('sin permiso muestra aviso', /inventory\.stock\.view/.test(env2.container.textContent));
  check('sin permiso no pide el listado',
    !env2.calls.gets.some(g => g.path === '/inventory/movements'),
    JSON.stringify(env2.calls.gets));
  env2.close();
}

// =====================================================================
// S2 - STOCK: historial (R3) y bloqueo (E4)
// =====================================================================
async function s2_stock_history_block() {
  console.log('S2 stock: historial R3 y bloquear/desbloquear E4');

  const env = createEnv({ perms: STOCK_PERMS, roles: ['ADMIN'] });
  env.loadStock();
  await tick();

  const actionsOf = (row) => row.querySelector('.table-actions').textContent;
  const rows = [...env.container.querySelectorAll('tbody tr')];
  check('DISPONIBLE muestra Bloquear e Historial',
    /Bloquear/.test(actionsOf(rows[0])) && /Historial/.test(actionsOf(rows[0])), actionsOf(rows[0]));
  check('BLOQUEADO muestra Desbloquear', /Desbloquear/.test(actionsOf(rows[2])), actionsOf(rows[2]));
  check('EN_TRANSITO admite Bloquear (matriz)', /Bloquear/.test(actionsOf(rows[3])), actionsOf(rows[3]));

  // ---- Historial ----
  env.click(rows[0].querySelector('[data-action="history"]'));
  await tick();

  const hist = env.calls.gets.find(g => g.path === '/inventory/stock/u1/history');
  check('GET /inventory/stock/u1/history page 1',
    !!hist && hist.options.params.page === 1 && hist.options.params.per_page === 50,
    JSON.stringify(hist && hist.options.params));

  const modal = env.container.querySelector('[data-modal-root] .modal');
  check('modal de historial abierto', !!modal);
  const modalText = modal ? modal.textContent : '';
  check('entradas evento y movimiento visibles',
    /RECIBIDO/.test(modalText) && /MOVIDO/.test(modalText), modalText.slice(0, 200));
  check('badge Corrección en la línea de tiempo', /Corrección/.test(modalText));
  check('hash truncado visible', /hash abc123def456/.test(modalText));

  const next = modal.querySelector('[data-action="history-next"]');
  check('paginación siguiente habilitada', next && !next.disabled);
  env.click(next);
  await tick();

  const hist2 = env.calls.gets.filter(g => g.path === '/inventory/stock/u1/history').pop();
  check('página 2 del historial', hist2 && hist2.options.params.page === 2, JSON.stringify(hist2 && hist2.options.params));
  check('evento BLOQUEADO de la página 2', /BLOQUEADO/.test(env.container.querySelector('[data-modal-root]').textContent));

  env.click(env.container.querySelector('[data-action="close-modal"]'));
  await tick();
  check('modal de historial cerrado', !env.container.querySelector('[data-modal-root] .modal'));

  // ---- Bloquear ----
  env.confirmResult = true;
  env.click(rows[0].querySelector('[data-action="block"]'));
  await tick();

  const blockCall = env.calls.posts.find(p => p.path === '/inventory/stock/u1/block');
  check('POST /inventory/stock/u1/block con accion BLOQUEAR',
    !!blockCall && blockCall.body && blockCall.body.accion === 'BLOQUEAR',
    JSON.stringify(blockCall && blockCall.body));
  check('block con Idempotency-Key',
    !!(blockCall && blockCall.options.headers && blockCall.options.headers['Idempotency-Key']),
    JSON.stringify(blockCall && blockCall.options.headers));
  check('toast de bloqueo', env.calls.toasts.some(t => /bloqueada/i.test(t.title)));
  check('recarga stock tras bloquear',
    env.calls.gets.filter(g => g.path === '/inventory/stock').length >= 2);

  // Confirm rechazado
  const env2 = createEnv({ perms: STOCK_PERMS, roles: ['ADMIN'] });
  env2.loadStock();
  await tick();
  env2.confirmResult = false;
  env2.click(env2.container.querySelector('tbody tr [data-action="block"]'));
  await tick();
  check('confirm rechazado no llama al backend', env2.calls.posts.length === 0, JSON.stringify(env2.calls.posts));
  env2.close();

  // Sin inventory.stock.block
  const env3 = createEnv({ perms: NO_BLOCK_PERMS, roles: ['ADMIN'] });
  env3.loadStock();
  await tick();
  const row3 = env3.container.querySelector('tbody tr');
  check('sin inventory.stock.block no hay Bloquear/Desbloquear',
    !/Bloquear/.test(row3.querySelector('.table-actions').textContent)
    && !/Desbloquear/.test(row3.querySelector('.table-actions').textContent),
    row3.querySelector('.table-actions').textContent);
  env3.close();

  // Desbloquear (la tabla se re-renderizó tras el bloqueo: re-consultar filas)
  const rowsAfter = [...env.container.querySelectorAll('tbody tr')];
  env.click(rowsAfter[2].querySelector('[data-action="unblock"]'));
  await tick();
  const unblockCall = env.calls.posts.find(p => p.path === '/inventory/stock/u4/unblock');
  check('POST /inventory/stock/u4/unblock con accion DESBLOQUEAR',
    !!unblockCall && unblockCall.body && unblockCall.body.accion === 'DESBLOQUEAR',
    JSON.stringify(unblockCall && unblockCall.body));

  env.close();
}

// =====================================================================
// S3 - STOCK: warnings al mover (CU-002)
// =====================================================================
async function s3_move_warnings() {
  console.log('S3 mover: data.warnings (CU-002)');

  const env = createEnv({ perms: STOCK_PERMS, roles: ['ADMIN'] });
  env.postImpl = (p) => {
    if (p === '/inventory/movements') {
      return {
        status: 200,
        data: {
          mensaje: 'Unidad movida',
          warnings: [{ codigo: 'UNIDAD_RESERVADA', mensaje: 'La unidad tiene reservas activas de la orden ORD-77.' }],
          movimiento: { movimiento_id: 'mv9', es_correccion: false, movimiento_referencia_id: null }
        }
      };
    }
    return { status: 201, data: { ok: true } };
  };
  env.loadStock();
  await tick();

  env.click(env.container.querySelector('tbody tr [data-action="move"]'));
  await tick();
  const form = env.container.querySelector('[data-move-form]');
  check('modal de movimiento abierto', !!form);

  form.querySelector('select[name="ubicacion_destino_id"]').value = 'loc3';
  env.submit(form);
  await tick();

  const post = env.calls.posts.find(p => p.path === '/inventory/movements');
  check('POST /inventory/movements', !!post, JSON.stringify(env.calls.posts));
  check('warning UNIDAD_RESERVADA como toast',
    env.calls.toasts.some(t => t.type === 'warning' && /ORD-77/.test(t.message || '')),
    JSON.stringify(env.calls.toasts));
  check('toast de éxito también visible',
    env.calls.toasts.some(t => t.type === 'success' && /movida/i.test(t.title || '')),
    JSON.stringify(env.calls.toasts));

  env.close();
}

// =====================================================================
// S4 - INCIDENCIAS: resultado al resolver (E4) + prioridad (E1)
// =====================================================================
async function s4_incidents_resolve() {
  console.log('S4 incidencias: resultado E4 y prioridad E1');

  const env = createEnv({ perms: STOCK_PERMS, roles: ['ADMIN'] });
  env.loadIncidents();
  await tick();

  const rows = [...env.container.querySelectorAll('tbody tr')];
  check('2 incidencias renderizadas', rows.length === 2, `got ${rows.length}`);
  check('prioridad ALTA visible en la tabla', /Alta/.test(rows[0].textContent), rows[0].textContent);
  check('prioridad NORMAL no es Alta', !/Alta/.test(rows[1].textContent), rows[1].textContent);

  // --- UBICACION_DESCONOCIDA exige resultado ---
  env.click(rows[0].querySelector('[data-action="open-resolve"]'));
  await tick();

  let modal = env.container.querySelector('[data-modal-root] .modal');
  check('modal resolver abierto', !!modal);
  const resultadoSel = modal.querySelector('[data-modal-field="resultado"]');
  check('select de resultado para UBICACION_DESCONOCIDA', !!resultadoSel);
  check('sin destino hasta elegir ENCONTRADA', !modal.querySelector('[data-modal-field="ubicacion_destino_id"]'));

  // Enviar sin resultado → validación, sin POST
  const fillResolve = (m) => {
    const desc = m.querySelector('[data-modal-field="descripcion_resolucion"]');
    desc.value = 'Unidad reapareció en el muelle';
    env.input(desc);
    const acc = m.querySelector('[data-modal-field="acciones_tomadas"]');
    acc.value = 'Revisado con operario y devuelto a estantería';
    env.input(acc);
  };
  env.click(modal.querySelector('[data-action="submit-resolve"]'));
  await tick();
  check('sin resultado no se envía', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo resultado',
    /encontrada/i.test(modal.querySelector('[data-error="resultado"]').textContent),
    modal.querySelector('[data-error="resultado"]').textContent);

  // Elegir ENCONTRADA → aparece destino
  resultadoSel.value = 'ENCONTRADA';
  env.change(resultadoSel);
  await tick();
  modal = env.container.querySelector('[data-modal-root] .modal');
  const destinoSel = modal.querySelector('[data-modal-field="ubicacion_destino_id"]');
  check('destino visible con ENCONTRADA', !!destinoSel);
  check('catálogo de ubicaciones cargado', destinoSel && destinoSel.options.length > 1,
    destinoSel ? String(destinoSel.options.length) : 'sin select');

  destinoSel.value = 'loc3';
  env.change(destinoSel);
  fillResolve(modal);
  env.click(modal.querySelector('[data-action="submit-resolve"]'));
  await tick();

  const resolvePost = env.calls.posts.find(p => p.path === '/inventory/incidents/i1/resolve');
  check('POST resolve con resultado',
    !!resolvePost && resolvePost.body && resolvePost.body.resultado === 'ENCONTRADA',
    JSON.stringify(resolvePost && resolvePost.body));
  check('POST resolve con ubicacion_destino_id',
    !!resolvePost && resolvePost.body.ubicacion_destino_id === 'loc3',
    JSON.stringify(resolvePost && resolvePost.body));
  check('idempotency-key en resolve',
    !!(resolvePost && resolvePost.options.headers && resolvePost.options.headers['Idempotency-Key']),
    JSON.stringify(resolvePost && resolvePost.options.headers));

  // --- DAÑO no admite resultado ---
  await tick();
  const rows2 = [...env.container.querySelectorAll('tbody tr')];
  env.click(rows2[1].querySelector('[data-action="open-resolve"]'));
  await tick();

  modal = env.container.querySelector('[data-modal-root] .modal');
  check('sin select de resultado para DAÑO', !modal.querySelector('[data-modal-field="resultado"]'));

  fillResolve(modal);
  env.click(modal.querySelector('[data-action="submit-resolve"]'));
  await tick();

  const danoPost = env.calls.posts.find(p => p.path === '/inventory/incidents/i2/resolve');
  check('resolve de DAÑO no envía resultado',
    !!danoPost && danoPost.body && !('resultado' in danoPost.body),
    JSON.stringify(danoPost && danoPost.body));

  env.close();
}

// =====================================================================
// S5 - RECEPCIÓN: rechazo total (E1)
// =====================================================================
async function s5_receiving_rechazo() {
  console.log('S5 recepción: rechazo_total E1');

  const env = createEnv({ perms: STOCK_PERMS, roles: ['ADMIN'] });
  env.postImpl = (p) => {
    if (p === '/inventory/receive') return RECEIVE_RECHAZO;
    return { status: 201, data: { ok: true } };
  };
  env.loadReceiving();
  await tick();

  const form = env.container.querySelector('[data-receive-form]');
  check('formulario de recepción', !!form);

  form.querySelector('select[name="sku"]').value = 'PROD2000';
  form.querySelector('select[name="ubicacion_id"]').value = 'loc1';
  form.querySelector('input[name="cantidad_esperada"]').value = '10';

  const chk = form.querySelector('[data-rechazo-total]');
  check('checkbox de rechazo total', !!chk);

  const recibida = form.querySelector('input[name="cantidad_recibida"]');
  chk.checked = true;
  env.change(chk);
  check('rechazo deshabilita cantidad recibida', recibida.disabled === true);
  check('rechazo limpia cantidad recibida', recibida.value === '', recibida.value);

  recibida.value = '4'; // sucio: no debe viajar al backend
  env.submit(form);
  await tick();

  const post = env.calls.posts.find(p => p.path === '/inventory/receive');
  check('POST /inventory/receive con rechazo_total',
    !!post && post.body && post.body.rechazo_total === '1',
    JSON.stringify(post && post.body));
  check('rechazo no envía cantidad_recibida',
    !!post && !('cantidad_recibida' in post.body),
    JSON.stringify(post && post.body));
  check('esperada viaja igual', !!post && post.body.cantidad_esperada === '10',
    JSON.stringify(post && post.body));

  const resultText = env.container.querySelector('[data-result-root]').textContent;
  check('resultado Rechazada', /Rechazada/.test(resultText), resultText.slice(0, 160));
  check('prioridad Alta en el resultado', /Alta/.test(resultText));
  check('discrepancia 100 % en el resultado', /100/.test(resultText));
  check('toast de entrega rechazada',
    env.calls.toasts.some(t => t.type === 'warning' && /rechazada/i.test(t.title || '')),
    JSON.stringify(env.calls.toasts));

  const chkAfter = form.querySelector('[data-rechazo-total]');
  check('checkbox se limpia tras el envío', chkAfter.checked === false);
  check('cantidad recibida vuelve a habilitarse', form.querySelector('input[name="cantidad_recibida"]').disabled === false);

  // La clave se regenera tras un 201
  const keyHintAfter = form.querySelector('[data-key-hint]').textContent;
  check('idempotency-key regenerada tras 201', /Idempotency-Key/.test(keyHintAfter));

  env.close();
}

// =====================================================================
// S6 - SHELL: loader / router / sidebar
// =====================================================================
async function s6_shell() {
  console.log('S6 shell: registro de inventory/movements');

  check('loader registra inventory/movements',
    FILES.loader.includes("'inventory/movements'"));
  check('router declara la ruta inventory/movements',
    FILES.router.includes("inventory/movements"));

  const env = createEnv({ perms: STOCK_PERMS, roles: ['ADMIN'] });
  env.loadShell();
  const w = env.w;

  const routes = w.AppRouter.getRoutes();
  check('ruta inventory/movements con módulo correcto',
    routes['inventory/movements'] && routes['inventory/movements'].module === 'inventory/movements',
    JSON.stringify(routes['inventory/movements']));

  w.AppSidebar.init();
  const adminRoutes = [...w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('ADMIN con stock.view ve Movimientos', adminRoutes.includes('inventory/movements'), JSON.stringify(adminRoutes));
  env.close();

  const env2 = createEnv({ perms: ['inventory.item.view'], roles: ['VIEWER'] });
  env2.loadShell();
  env2.w.AppSidebar.init();
  const viewerRoutes = [...env2.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('sin stock.view no ve Movimientos', !viewerRoutes.includes('inventory/movements'), JSON.stringify(viewerRoutes));
  env2.close();
}

// =====================================================================
// RUN
// =====================================================================

const t0 = Date.now();
await s1_movements();
await s2_stock_history_block();
await s3_move_warnings();
await s4_incidents_resolve();
await s5_receiving_rechazo();
await s6_shell();
const ms = Date.now() - t0;

console.log('');
console.log(`RESULTADO: ${pass} OK, ${fail} FAIL  (${ms} ms)`);
process.exit(fail > 0 ? 1 : 0);
