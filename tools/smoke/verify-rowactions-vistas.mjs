/**
 * Smoke jsdom: estructura de UI.rowActions en las 12 vistas migradas (BLOQUE B).
 * Verifica por vista: celda .col-actions, primary correcto, menu con aria-haspopup,
 * orden de items, opcion danger al final y ausencia de acciones sin permiso.
 * Uso: node verify-rowactions-vistas.mjs   (desde .../Temp/opencode/jsdom-env)
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  i18n: read('core/i18n.js'),
  groups: read('modules/groups/groups.js'),
  roles: read('modules/roles/roles.js'),
  stock: read('modules/inventory/stock.js'),
  products: read('modules/inventory/products.js'),
  locations: read('modules/inventory/locations.js'),
  shipments: read('modules/inventory/shipments.js'),
  incidents: read('modules/inventory/incidents.js'),
  counts: read('modules/inventory/counts.js'),
  picking: read('modules/inventory/picking.js'),
  deliveries: read('modules/inventory/deliveries.js'),
  reservations: read('modules/inventory/reservations.js'),
  audit: read('modules/audit/logs.js')
};

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
const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// =====================================================================
// FIXTURES (reutilizadas de los smokes que ya las renderizan)
// =====================================================================

// smoke-v1120 (grupos/roles) + conteo `miembros` para evitar el N+1.
const GROUPS = [
  { id: 'g1', nombre: 'Almacen Central', descripcion: 'Operarios', activo: true, miembros: 2 },
  { id: 'g2', nombre: 'Ventas', descripcion: '', activo: false, miembros: 0 }
];

const ROLES = [
  { id: 'r1', nombre: 'Administrador', tipo: 'BUILT_IN', descripcion: 'Rol base', activo: true, permisos: [] },
  { id: 'r2', nombre: 'Analista', tipo: 'CUSTOM', descripcion: 'Lectura', activo: true, permisos: ['inventory.stock.view'] }
];

const PRODUCTS = [
  { id: 'p1', sku: 'SKU-1', nombre: 'Prod', categoria: 'General', unidad_medida: 'UNIDAD', activo: true, created_at: '2026-01-01' },
  { id: 'p2', sku: 'SKU-2', nombre: 'Prod Inactivo', categoria: 'General', unidad_medida: 'CAJA', activo: false, created_at: '2026-01-02' }
];
const PRODUCTS_PAYLOAD = {
  status: 200,
  data: { data: PRODUCTS, pagination: { total: 2, page: 1, per_page: 20, has_next: false } }
};

const LOCATIONS = [
  { id: 'l1', codigo: 'A-01', nombre: 'Pasillo', tipo: 'PASILLO', activa: true, created_at: '2026-01-01', capacidad_maxima: 100, capacidad_ocupada: 10 },
  { id: 'l2', codigo: 'A-02', nombre: 'Pasillo 2', tipo: 'PASILLO', activa: false, created_at: '2026-01-02', capacidad_maxima: 100, capacidad_ocupada: 0 }
];
const LOCATIONS_PAYLOAD = {
  status: 200,
  data: { data: LOCATIONS, pagination: { total: 2, page: 1, per_page: 20, has_next: false } }
};

// smoke-v162 (stock/reservas/auditoria).
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

// smoke-v193 (incidencias/conteos).
const INCIDENTS_LIST = [
  {
    incidencia_id: 'i1', tipo: 'DANO', descripcion: 'Unidad dana en estanteria', observaciones: null,
    estado: 'ABIERTA', unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111',
    ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23', inventory_count_id: null,
    actor_id_reporta: 'user-1', fecha_deteccion: '2026-10-01 10:00:00', fecha_creacion: '2026-10-01 10:00:00'
  },
  {
    incidencia_id: 'i2', tipo: 'PERDIDA', descripcion: 'Unidad extraviada', observaciones: null,
    estado: 'RESUELTA', unidad_fisica_id: 'u3', codigo_barcode: 'SU-CCCCCC-33333333',
    ubicacion_id: 'loc2', ubicacion_codigo: 'DA-OS01', inventory_count_id: null,
    actor_id_reporta: 'user-1', fecha_deteccion: '2026-09-30 08:00:00', fecha_creacion: '2026-09-30 08:00:00'
  }
];
const INCIDENTS_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, abiertas: 1, investigacion: 0, resueltas: 1, no_resueltas: 0 },
    data: INCIDENTS_LIST,
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

const COUNTS_LIST = [
  {
    conteo_id: 'c1', ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23', estado: 'PENDIENTE',
    usuario_responsable_id: 'user-1', fecha_conteo: '2026-10-02 09:00:00',
    total_discrepancias: 0, discrepancias_pendientes: 0, reconciliado: false,
    fecha_creacion: '2026-10-02 09:00:00', fecha_actualizacion: '2026-10-02 09:00:00'
  },
  {
    conteo_id: 'c2', ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23', estado: 'COMPLETADO',
    usuario_responsable_id: 'user-1', fecha_conteo: '2026-10-01 15:00:00',
    total_discrepancias: 1, discrepancias_pendientes: 1, reconciliado: false,
    fecha_creacion: '2026-10-01 15:00:00', fecha_actualizacion: '2026-10-01 16:00:00'
  }
];
const COUNTS_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, pendientes: 1, en_curso: 0, completados: 1, reconciliados: 0 },
    data: COUNTS_LIST,
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

// smoke-v171 (picking/envios).
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

// smoke-v184 (entregas/envios EN_RUTA).
const EN_RUTA_SHIPMENTS = [
  {
    shipment_id: 'sh2', referencia_externa: 'EXP-2', tipo: 'PROPIO', ubicacion_origen: 'HOUSEA23',
    destino_direccion: 'Av. Siempreviva 742', destinatario_id: null, destinatario_nombre: 'Cliente Dos',
    destinatario_contacto: '123', conductor_nombre: 'Chofer Dos', vehiculo_referencia: 'ABC123',
    estado: 'EN_RUTA', hora_salida: '2026-10-03 09:00:00', hora_entrega_esperada: null,
    hora_entrega_real: null, actor_id_autoriza_salida: 'user-2', total_items: 2,
    fecha_creacion: '2026-10-03 08:00:00', fecha_actualizacion: '2026-10-03 09:00:00'
  },
  {
    shipment_id: 'sh3', referencia_externa: null, tipo: 'MENSAJERIA', ubicacion_origen: null,
    destino_direccion: null, destinatario_id: null, destinatario_nombre: 'Cliente Tres',
    destinatario_contacto: null, conductor_nombre: null, vehiculo_referencia: null,
    estado: 'EN_RUTA', hora_salida: '2026-10-03 10:00:00', hora_entrega_esperada: null,
    hora_entrega_real: null, actor_id_autoriza_salida: 'user-2', total_items: 1,
    fecha_creacion: '2026-10-03 09:00:00', fecha_actualizacion: '2026-10-03 10:00:00'
  }
];
const EN_RUTA_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, en_preparacion: 0, en_ruta: 2, entregados: 0 },
    data: EN_RUTA_SHIPMENTS,
    pagination: { total: 2, page: 1, per_page: 100, has_next: false }
  }
};

const DELIVERIES_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, pendientes: 1, entregadas: 1, rechazadas: 0, parciales: 0 },
    data: [
      {
        delivery_id: 'd1', shipment_id: 'sh9', referencia_externa: 'EXP-9', shipment_estado: 'ENTREGADO',
        unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111', cantidad: 3, estado: 'ENTREGADA',
        destinatario_real: 'Cliente Real', firma_comprobante: 'https://firma.ejemplo/acta-1',
        observaciones: null, actor_id_entrega: 'user-1', timestamp_entrega: '2026-10-03 12:00:00',
        fecha_creacion: '2026-10-03 12:00:00'
      },
      {
        delivery_id: 'd2', shipment_id: 'sh8', referencia_externa: 'EXP-8', shipment_estado: 'EN_RUTA',
        unidad_fisica_id: 'u2', codigo_barcode: 'SU-BBBBBB-22222222', cantidad: 1, estado: 'PENDIENTE',
        destinatario_real: null, firma_comprobante: null, observaciones: null, actor_id_entrega: null,
        timestamp_entrega: null, fecha_creacion: '2026-10-03 11:00:00'
      }
    ],
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

// =====================================================================
// PERMISOS
// =====================================================================

const FULL = [
  'groups.group.view', 'groups.group.edit', 'groups.group.delete',
  'roles.role.view', 'roles.role.edit', 'roles.role.delete',
  'audit.log.view',
  'inventory.item.view',
  'inventory.location.view', 'inventory.location.manage',
  'inventory.stock.view', 'inventory.movement.create', 'inventory.stock.block',
  'inventory.reservation.view', 'inventory.reservation.create', 'inventory.reservation.cancel',
  'inventory.shipping.view', 'inventory.shipping.create', 'inventory.shipping.confirm',
  'inventory.delivery.view', 'inventory.delivery.confirm',
  'inventory.incident.view', 'inventory.incident.resolve',
  'inventory.count.view', 'inventory.count.create', 'inventory.count.reconcile',
  'inventory.picking.view', 'inventory.picking.create', 'inventory.picking.confirm', 'inventory.picking.verify'
];
const without = (...ps) => FULL.filter(p => !ps.includes(p));

// =====================================================================
// ENTORNO (ventana jsdom con stubs; mismo harness que los smokes v1.x)
// =====================================================================

function createEnv({ perms = [], roles = ['ADMIN'], getHandlers = {} } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="sidebar"></div><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], toasts: [], errors: [] };

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
    isAuthenticated: () => true,
    logout() {}
  };

  const env = { w, calls, dom, container: w.document.getElementById('module-container') };
  env.evalIn = (code) => w.eval(code);
  env.close = () => dom.window.close();

  const resolveGet = (p) => {
    if (getHandlers[p]) return getHandlers[p];
    if (p === '/groups') return { status: 200, data: { groups: GROUPS } };
    if (p === '/roles') return { status: 200, data: { roles: ROLES } };
    if (p === '/users') return { status: 200, data: { data: [{ id: 'user-1', nombre: 'Demo', apellido: 'Admin', email: 'demo@acme.local' }], pagination: { total: 1, page: 1, per_page: 100 } } };
    if (p === '/inventory/products') return PRODUCTS_PAYLOAD;
    if (p === '/inventory/locations') return LOCATIONS_PAYLOAD;
    if (p === '/inventory/stock') return STOCK_PAYLOAD;
    if (p === '/inventory/reservations') return RESERVATIONS_PAYLOAD;
    if (p === '/inventory/shipments') return SHIPMENTS_PAYLOAD;
    if (p === '/inventory/incidents') return INCIDENTS_PAYLOAD;
    if (p === '/inventory/counts') return COUNTS_PAYLOAD;
    if (p === '/inventory/picking') return PICKING_PAYLOAD;
    if (p === '/inventory/deliveries') return DELIVERIES_PAYLOAD;
    if (p === '/audit/logs') return AUDIT_PAYLOAD;
    if (p.startsWith('/locales/')) {
      const loc = p.slice('/locales/'.length).replace(/\.json$/, '');
      return { status: 200, data: { locale: loc, version: 'smoke', dictionary: ES_DICT } };
    }
    throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
  };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      return resolveGet(p, options);
    },
    async post(p, body) { return { status: 201, data: { ok: true } }; },
    async patch() { return { status: 200, data: { ok: true } }; },
    async delete() { return { status: 200, data: { ok: true } }; }
  };

  env.evalIn(FILES.i18n);
  w.i18n.inject('es', ES_DICT);
  UI_FILES.forEach(env.evalIn);

  return env;
}

function info(cell) {
  const wrap = cell ? cell.querySelector('.row-actions') : null;
  const primary = wrap ? wrap.querySelector(':scope > button[data-action]') : null;
  const trigger = wrap ? wrap.querySelector('.row-actions-trigger') : null;
  const menu = wrap ? [...wrap.querySelectorAll('.dropdown-menu .dropdown-item')] : [];
  return {
    wrap, primary, trigger, menu,
    actions: menu.map(m => m.dataset.action)
  };
}

const cellsOf = (env) => [...env.container.querySelectorAll('td.col-actions')];
const cellWith = (env, action) =>
  cellsOf(env).find(c => c.querySelector(`button[data-action="${action}"]`)) || null;
const noErrors = (env, tag) =>
  check(`${tag} sin errores de consola`, env.calls.errors.length === 0, JSON.stringify(env.calls.errors));

// =====================================================================
// RA-1 GRUPOS
// =====================================================================
async function ra1_groups() {
  console.log('RA-1 grupos: open-detail + edit + delete(danger)');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.groups);
  env.w.GroupsGroupsModule.init({}, env.container);
  await tick(12);

  check('RA-1.1 cabecera col-actions', env.container.querySelectorAll('th.col-actions').length >= 1);
  check('RA-1.2 dos celdas de acciones', cellsOf(env).length === 2, `cells=${cellsOf(env).length}`);
  const g1 = info(cellsOf(env)[0]);
  check('RA-1.3 primary open-detail', g1.primary && g1.primary.dataset.action === 'open-detail', g1.primary && g1.primary.dataset.action);
  check('RA-1.4 data-id del grupo', g1.primary && g1.primary.dataset.id === 'g1', g1.primary && g1.primary.dataset.id);
  check('RA-1.5 menu [open-edit, open-delete]', eq(g1.actions, ['open-edit', 'open-delete']), JSON.stringify(g1.actions));
  check('RA-1.6 trigger aria-haspopup=menu', !!g1.trigger && g1.trigger.getAttribute('aria-haspopup') === 'menu');
  check('RA-1.7 trigger aria-expanded=false', !!g1.trigger && g1.trigger.getAttribute('aria-expanded') === 'false');
  const last = g1.menu[g1.menu.length - 1];
  check('RA-1.8 delete al final en rojo', !!last && last.dataset.action === 'open-delete' && last.classList.contains('danger'));
  const g2 = info(cellsOf(env)[1]);
  check('RA-1.9 grupo inactivo conserva acciones', eq(g2.actions, ['open-edit', 'open-delete']), JSON.stringify(g2.actions));
  noErrors(env, 'RA-1.10');
  env.close();

  const e2 = createEnv({ perms: without('groups.group.delete') });
  e2.evalIn(FILES.groups);
  e2.w.GroupsGroupsModule.init({}, e2.container);
  await tick(12);
  const c2 = info(cellsOf(e2)[0]);
  check('RA-1.11 sin permission.delete no hay open-delete', eq(c2.actions, ['open-edit']), JSON.stringify(c2.actions));
  e2.close();

  const e3 = createEnv({ perms: without('groups.group.edit', 'groups.group.delete') });
  e3.evalIn(FILES.groups);
  e3.w.GroupsGroupsModule.init({}, e3.container);
  await tick(12);
  const c3 = info(cellsOf(e3)[0]);
  check('RA-1.12 sin edit/delete no hay menu', !!c3.wrap && !c3.trigger && c3.menu.length === 0, JSON.stringify(c3.actions));
  check('RA-1.13 primary sigue visible', !!c3.primary && c3.primary.dataset.action === 'open-detail');
  e3.close();
}

// =====================================================================
// RA-2 ROLES
// =====================================================================
async function ra2_roles() {
  console.log('RA-2 roles: open-permissions + edit + delete(danger) y guardas BUILT_IN');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.roles);
  env.w.RolesRolesModule.init({}, env.container);
  await tick(12);

  check('RA-2.1 dos celdas de acciones', cellsOf(env).length === 2, `cells=${cellsOf(env).length}`);
  const r1 = info(cellsOf(env)[0]);
  check('RA-2.2 BUILT_IN sin edit/delete', eq(r1.actions, []) && !!r1.primary && r1.primary.dataset.action === 'open-permissions', JSON.stringify(r1.actions));
  check('RA-2.3 BUILT_IN sin trigger', !r1.trigger);
  const r2 = info(cellsOf(env)[1]);
  check('RA-2.4 CUSTOM primary open-permissions', !!r2.primary && r2.primary.dataset.action === 'open-permissions');
  check('RA-2.5 CUSTOM menu [open-edit, open-delete]', eq(r2.actions, ['open-edit', 'open-delete']), JSON.stringify(r2.actions));
  check('RA-2.6 delete danger al final', r2.menu[r2.menu.length - 1].classList.contains('danger'));
  check('RA-2.7 trigger aria-haspopup=menu', !!r2.trigger && r2.trigger.getAttribute('aria-haspopup') === 'menu');
  noErrors(env, 'RA-2.8');
  env.close();

  const e2 = createEnv({ perms: without('roles.role.edit', 'roles.role.delete') });
  e2.evalIn(FILES.roles);
  e2.w.RolesRolesModule.init({}, e2.container);
  await tick(12);
  const c2 = info(cellsOf(e2)[1]);
  check('RA-2.9 sin edit/delete no hay menu', !!c2.primary && !c2.trigger && c2.menu.length === 0);
  e2.close();

  const e3 = createEnv({ perms: without('roles.role.view') });
  e3.evalIn(FILES.roles);
  e3.w.RolesRolesModule.init({}, e3.container);
  await tick(12);
  check('RA-2.10 sin roles.role.view el modulo entra en denied',
    cellsOf(e3).length === 0 && e3.container.querySelectorAll('.row-actions').length === 0,
    `cells=${cellsOf(e3).length}`);
  e3.close();
}

// =====================================================================
// RA-3 PRODUCTOS
// =====================================================================
async function ra3_products() {
  console.log('RA-3 productos: edit + stock + activate/deactivate + delete(danger)');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.products);
  env.w.InventoryProductsModule.init({}, env.container);
  await tick(15);

  check('RA-3.1 cabecera col-actions', env.container.querySelectorAll('th.col-actions').length >= 1);
  check('RA-3.2 dos celdas de acciones', cellsOf(env).length === 2, `cells=${cellsOf(env).length}`);
  const p1 = info(cellsOf(env)[0]);
  check('RA-3.3 primary edit', !!p1.primary && p1.primary.dataset.action === 'edit');
  check('RA-3.4 menu [stock, deactivate, delete]', eq(p1.actions, ['stock', 'deactivate', 'delete']), JSON.stringify(p1.actions));
  check('RA-3.5 delete danger al final', p1.menu[p1.menu.length - 1].classList.contains('danger'));
  check('RA-3.6 trigger aria-haspopup=menu', !!p1.trigger && p1.trigger.getAttribute('aria-haspopup') === 'menu');
  const p2 = info(cellsOf(env)[1]);
  check('RA-3.7 producto inactivo usa activate', eq(p2.actions, ['stock', 'activate', 'delete']), JSON.stringify(p2.actions));
  noErrors(env, 'RA-3.8');
  env.close();

  const e2 = createEnv({ perms: without('inventory.stock.view') });
  e2.evalIn(FILES.products);
  e2.w.InventoryProductsModule.init({}, e2.container);
  await tick(15);
  const c2 = info(cellsOf(e2)[0]);
  check('RA-3.9 sin stock.view no hay item stock', eq(c2.actions, ['deactivate', 'delete']), JSON.stringify(c2.actions));
  e2.close();
}

// =====================================================================
// RA-4 UBICACIONES
// =====================================================================
async function ra4_locations() {
  console.log('RA-4 ubicaciones: edit + units + activate/deactivate + delete(danger)');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.locations);
  env.w.InventoryLocationsModule.init({}, env.container);
  await tick(12);

  check('RA-4.1 dos celdas de acciones', cellsOf(env).length === 2, `cells=${cellsOf(env).length}`);
  const l1 = info(cellsOf(env)[0]);
  check('RA-4.2 primary edit', !!l1.primary && l1.primary.dataset.action === 'edit');
  check('RA-4.3 menu [units, deactivate, delete]', eq(l1.actions, ['units', 'deactivate', 'delete']), JSON.stringify(l1.actions));
  check('RA-4.4 delete danger al final', l1.menu[l1.menu.length - 1].classList.contains('danger'));
  const l2 = info(cellsOf(env)[1]);
  check('RA-4.5 ubicacion inactiva usa activate', eq(l2.actions, ['units', 'activate', 'delete']), JSON.stringify(l2.actions));
  noErrors(env, 'RA-4.6');
  env.close();

  const e2 = createEnv({ perms: without('inventory.stock.view') });
  e2.evalIn(FILES.locations);
  e2.w.InventoryLocationsModule.init({}, e2.container);
  await tick(12);
  const c2 = info(cellsOf(e2)[0]);
  check('RA-4.7 sin stock.view no hay item units', eq(c2.actions, ['deactivate', 'delete']), JSON.stringify(c2.actions));
  e2.close();
}

// =====================================================================
// RA-5 STOCK
// =====================================================================
async function ra5_stock() {
  console.log('RA-5 stock: move + reserve + block/unblock + history');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.stock);
  env.w.InventoryStockModule.init({}, env.container);
  await tick(15);

  const u1 = info(cellWith(env, 'move'));
  check('RA-5.1 celda u1 con primary move', !!u1.primary && u1.primary.dataset.id === 'u1');
  check('RA-5.2 menu [reserve, block, history]', eq(u1.actions, ['reserve', 'block', 'history']), JSON.stringify(u1.actions));
  check('RA-5.3 history sin permiso siempre presente', u1.actions.includes('history'));
  check('RA-5.4 trigger aria-haspopup=menu', !!u1.trigger && u1.trigger.getAttribute('aria-haspopup') === 'menu');
  const u3cell = cellsOf(env).find(c => c.querySelector('button[data-id="u3"]'));
  const u3 = info(u3cell);
  check('RA-5.5 EN_TRANSITO sin move (primary nulo)', !u3.primary);
  check('RA-5.6 EN_TRANSITO menu [block, history]', eq(u3.actions, ['block', 'history']), JSON.stringify(u3.actions));
  noErrors(env, 'RA-5.7');
  env.close();

  const e2 = createEnv({ perms: without('inventory.movement.create') });
  e2.evalIn(FILES.stock);
  e2.w.InventoryStockModule.init({}, e2.container);
  await tick(15);
  const c2 = info(cellWith(e2, 'reserve'));
  check('RA-5.8 sin movement.create no hay primary', !c2.primary);
  check('RA-5.9 menu sigue con reserve/history', eq(c2.actions, ['reserve', 'block', 'history']), JSON.stringify(c2.actions));
  e2.close();

  const e3 = createEnv({ perms: without('inventory.stock.block') });
  e3.evalIn(FILES.stock);
  e3.w.InventoryStockModule.init({}, e3.container);
  await tick(15);
  const c3 = info(cellWith(e3, 'reserve'));
  check('RA-5.10 sin stock.block no hay block/unblock', eq(c3.actions, ['reserve', 'history']), JSON.stringify(c3.actions));
  e3.close();
}

// =====================================================================
// RA-6 ENVIOS
// =====================================================================
async function ra6_shipments() {
  console.log('RA-6 envios: open-detail + authorize solo en PREPARACION');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.shipments);
  env.w.InventoryShipmentsModule.init({}, env.container);
  await tick(15);

  const sh1 = info(cellWith(env, 'authorize'));
  check('RA-6.1 sh1 (PREPARACION) con authorize', !!sh1.primary && sh1.primary.dataset.action === 'open-detail', sh1.primary && sh1.primary.dataset.action);
  check('RA-6.2 menu [authorize]', eq(sh1.actions, ['authorize']), JSON.stringify(sh1.actions));
  check('RA-6.3 trigger aria-haspopup=menu', !!sh1.trigger && sh1.trigger.getAttribute('aria-haspopup') === 'menu');
  const sh2cell = cellsOf(env).find(c => c.querySelector('button[data-action="open-detail"][data-id="sh2"]'));
  const sh2 = info(sh2cell);
  check('RA-6.4 sh2 (EN_RUTA) sin authorize', eq(sh2.actions, []), JSON.stringify(sh2.actions));
  check('RA-6.5 sh2 sin trigger', !sh2.trigger);
  noErrors(env, 'RA-6.6');
  env.close();

  const e2 = createEnv({ perms: without('inventory.shipping.confirm') });
  e2.evalIn(FILES.shipments);
  e2.w.InventoryShipmentsModule.init({}, e2.container);
  await tick(15);
  const c2 = info(cellWith(e2, 'open-detail'));
  check('RA-6.7 sin shipping.confirm no hay authorize', eq(c2.actions, []), JSON.stringify(c2.actions));
  e2.close();
}

// =====================================================================
// RA-7 INCIDENCIAS
// =====================================================================
async function ra7_incidents() {
  console.log('RA-7 incidencias: open-detail + investigate + open-resolve');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.incidents);
  env.w.InventoryIncidentsModule.init({}, env.container);
  await tick(15);

  const i1 = info(cellWith(env, 'investigate'));
  check('RA-7.1 i1 (ABIERTA) primary open-detail', !!i1.primary && i1.primary.dataset.action === 'open-detail');
  check('RA-7.2 menu [investigate, open-resolve]', eq(i1.actions, ['investigate', 'open-resolve']), JSON.stringify(i1.actions));
  check('RA-7.3 trigger aria-haspopup=menu', !!i1.trigger && i1.trigger.getAttribute('aria-haspopup') === 'menu');
  const i2cell = cellsOf(env).find(c => c.querySelector('button[data-action="open-detail"][data-id="i2"]'));
  const i2 = info(i2cell);
  check('RA-7.4 i2 (RESUELTA) sin acciones de gestion', eq(i2.actions, []), JSON.stringify(i2.actions));
  check('RA-7.5 i2 sin trigger', !i2.trigger);
  noErrors(env, 'RA-7.6');
  env.close();

  const e2 = createEnv({ perms: without('inventory.incident.resolve') });
  e2.evalIn(FILES.incidents);
  e2.w.InventoryIncidentsModule.init({}, e2.container);
  await tick(15);
  const c2 = info(cellWith(e2, 'open-detail'));
  check('RA-7.7 sin incident.resolve no hay investigate/resolve', eq(c2.actions, []), JSON.stringify(c2.actions));
  e2.close();
}

// =====================================================================
// RA-8 CONTEOS
// =====================================================================
async function ra8_counts() {
  console.log('RA-8 conteos: primary unico con etiqueta contextual');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.counts);
  env.w.InventoryCountsModule.init({}, env.container);
  await tick(15);

  check('RA-8.1 dos celdas de acciones', cellsOf(env).length === 2, `cells=${cellsOf(env).length}`);
  const c1 = info(cellsOf(env)[0]);
  const c2 = info(cellsOf(env)[1]);
  check('RA-8.2 c1 primary open-detail', !!c1.primary && c1.primary.dataset.action === 'open-detail');
  check('RA-8.3 c1 sin menu', !c1.trigger && c1.menu.length === 0);
  const label1 = c1.primary.textContent.trim();
  const label2 = c2.primary.textContent.trim();
  check('RA-8.4 etiquetas contextuales distintas (count vs reconcile)', label1 !== label2 && label1.length > 0 && label2.length > 0, `${label1} / ${label2}`);
  noErrors(env, 'RA-8.5');
  env.close();

  const e2 = createEnv({ perms: without('inventory.count.create', 'inventory.count.reconcile') });
  e2.evalIn(FILES.counts);
  e2.w.InventoryCountsModule.init({}, e2.container);
  await tick(15);
  const d1 = info(cellsOf(e2)[0]);
  const d2 = info(cellsOf(e2)[1]);
  check('RA-8.6 sin create/reconcile ambas etiquetas son view',
    d1.primary.textContent.trim() === d2.primary.textContent.trim(),
    `${d1.primary.textContent.trim()} / ${d2.primary.textContent.trim()}`);
  e2.close();
}

// =====================================================================
// RA-9 PICKING
// =====================================================================
async function ra9_picking() {
  console.log('RA-9 picking: expand-lote + complete-lote/verify-lote con data-lote');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.picking);
  env.w.InventoryPickingModule.init({}, env.container);
  await tick(15);

  const loteCells = cellsOf(env).filter(c => c.querySelector('[data-action="expand-lote"]'));
  check('RA-9.1 tres lotes con acciones', loteCells.length === 3, `cells=${loteCells.length}`);
  const a = info(loteCells[0]);
  check('RA-9.2 LOTE-A primary expand-lote', !!a.primary && a.primary.dataset.action === 'expand-lote');
  check('RA-9.3 primary conserva data-lote', !!a.primary && a.primary.getAttribute('data-lote') === 'LOTE-A', a.primary && a.primary.getAttribute('data-lote'));
  check('RA-9.4 LOTE-A (EN_CURSO) menu [complete-lote]', eq(a.actions, ['complete-lote']), JSON.stringify(a.actions));
  check('RA-9.5 complete-lote conserva data-lote', !!a.menu[0] && a.menu[0].getAttribute('data-lote') === 'LOTE-A', a.menu[0] && a.menu[0].getAttribute('data-lote'));
  check('RA-9.6 trigger aria-haspopup=menu', !!a.trigger && a.trigger.getAttribute('aria-haspopup') === 'menu');
  const b = info(loteCells[1]);
  check('RA-9.7 LOTE-B (COMPLETADA) menu [verify-lote]', eq(b.actions, ['verify-lote']), JSON.stringify(b.actions));
  const c = info(loteCells[2]);
  check('RA-9.8 LOTE-C (VERIFICADA) sin menu', !c.trigger && c.menu.length === 0, JSON.stringify(c.actions));
  noErrors(env, 'RA-9.9');
  env.close();

  const e2 = createEnv({ perms: without('inventory.picking.confirm') });
  e2.evalIn(FILES.picking);
  e2.w.InventoryPickingModule.init({}, e2.container);
  await tick(15);
  const cells2 = cellsOf(e2).filter(c => c.querySelector('[data-action="expand-lote"]'));
  const a2 = info(cells2[0]);
  check('RA-9.10 sin picking.confirm no hay complete-lote', eq(a2.actions, []), JSON.stringify(a2.actions));
  check('RA-9.11 expand-lote sigue visible', !!a2.primary && a2.primary.dataset.action === 'expand-lote');
  e2.close();
}

// =====================================================================
// RA-10 ENTREGAS
// =====================================================================
async function ra10_deliveries() {
  console.log('RA-10 entregas: open-confirm + open-failed en envios EN_RUTA');
  const env = createEnv({ perms: FULL, getHandlers: { '/inventory/shipments': EN_RUTA_PAYLOAD } });
  env.evalIn(FILES.deliveries);
  env.w.InventoryDeliveriesModule.init({}, env.container);
  await tick(15);

  const enRuta = env.container.querySelector('[data-en-ruta-table]');
  check('RA-10.1 tabla EN_RUTA renderizada', !!enRuta);
  const cells = enRuta ? [...enRuta.querySelectorAll('td.col-actions')] : [];
  check('RA-10.2 dos celdas EN_RUTA', cells.length === 2, `cells=${cells.length}`);
  const s = info(cells[0]);
  check('RA-10.3 primary open-confirm', !!s.primary && s.primary.dataset.action === 'open-confirm');
  check('RA-10.4 menu [open-failed]', eq(s.actions, ['open-failed']), JSON.stringify(s.actions));
  check('RA-10.5 trigger aria-haspopup=menu', !!s.trigger && s.trigger.getAttribute('aria-haspopup') === 'menu');
  noErrors(env, 'RA-10.6');
  env.close();

  const e2 = createEnv({ perms: without('inventory.delivery.confirm'), getHandlers: { '/inventory/shipments': EN_RUTA_PAYLOAD } });
  e2.evalIn(FILES.deliveries);
  e2.w.InventoryDeliveriesModule.init({}, e2.container);
  await tick(15);
  const enRuta2 = e2.container.querySelector('[data-en-ruta-table]');
  const cells2 = enRuta2 ? [...enRuta2.querySelectorAll('td.col-actions')] : [];
  check('RA-10.7 sin delivery.confirm no se pintan acciones',
    cells2.length === 2 && cells2.every(c => !c.querySelector('.row-actions')),
    JSON.stringify(cells2.map(c => !!c.querySelector('.row-actions'))));
  e2.close();
}

// =====================================================================
// RA-11 RESERVAS
// =====================================================================
async function ra11_reservations() {
  console.log('RA-11 reservas: menu solo con release(danger)');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.reservations);
  env.w.InventoryReservationsModule.init({}, env.container);
  await tick(12);

  check('RA-11.1 dos celdas de acciones', cellsOf(env).length === 2, `cells=${cellsOf(env).length}`);
  const r1 = info(cellsOf(env)[0]);
  check('RA-11.2 sin primary (solo menu)', !r1.primary);
  check('RA-11.3 menu [release]', eq(r1.actions, ['release']), JSON.stringify(r1.actions));
  check('RA-11.4 release danger', !!r1.menu[0] && r1.menu[0].classList.contains('danger'));
  check('RA-11.5 trigger aria-haspopup=menu', !!r1.trigger && r1.trigger.getAttribute('aria-haspopup') === 'menu');
  noErrors(env, 'RA-11.6');
  env.close();

  const e2 = createEnv({ perms: without('inventory.reservation.cancel') });
  e2.evalIn(FILES.reservations);
  e2.w.InventoryReservationsModule.init({}, e2.container);
  await tick(12);
  check('RA-11.7 sin reservation.cancel no hay acciones',
    cellsOf(e2).length === 2 && cellsOf(e2).every(c => !c.querySelector('.row-actions')),
    JSON.stringify(cellsOf(e2).map(c => !!c.querySelector('.row-actions'))));
  e2.close();
}

// =====================================================================
// RA-12 AUDITORIA
// =====================================================================
async function ra12_audit() {
  console.log('RA-12 auditoria: primary detail sin menu');
  const env = createEnv({ perms: FULL });
  env.evalIn(FILES.audit);
  env.w.AuditLogsModule.init({}, env.container);
  await tick(12);

  check('RA-12.1 dos celdas de acciones', cellsOf(env).length === 2, `cells=${cellsOf(env).length}`);
  const a1 = info(cellsOf(env)[0]);
  check('RA-12.2 primary detail', !!a1.primary && a1.primary.dataset.action === 'detail');
  check('RA-12.3 data-id es el indice', !!a1.primary && a1.primary.dataset.id === '0', a1.primary && a1.primary.dataset.id);
  check('RA-12.4 sin menu/trigger', !a1.trigger && a1.menu.length === 0);
  const a2 = info(cellsOf(env)[1]);
  check('RA-12.5 data-id 0,1 por fila', !!a2.primary && a2.primary.dataset.id === '1', a2.primary && a2.primary.dataset.id);
  noErrors(env, 'RA-12.6');
  env.close();
}

// =====================================================================
async function main() {
  await ra1_groups();
  await ra2_roles();
  await ra3_products();
  await ra4_locations();
  await ra5_stock();
  await ra6_shipments();
  await ra7_incidents();
  await ra8_counts();
  await ra9_picking();
  await ra10_deliveries();
  await ra11_reservations();
  await ra12_audit();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
