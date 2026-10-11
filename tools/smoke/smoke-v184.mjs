/**
 * Smoke test jsdom para la UI v1.8.4 (Sprint 8 frontend: entregas y devoluciones).
 * Uso: node tools/smoke/smoke-v184.mjs   (desde la raíz del repo)
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
  deliveries: read('modules/inventory/deliveries.js'),
  returns: read('modules/inventory/returns.js'),
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

const SHIPMENT_DETAIL = {
  status: 200,
  data: {
    shipment: EN_RUTA_SHIPMENTS[0],
    items: [
      {
        shipment_item_id: 'i1', stock_unit_id: 'u1', barcode: 'SU-AAAAAA-11111111',
        producto: 'Producto Demo', cantidad: 3, estado: 'EN_TRANSITO', fecha_creacion: '2026-10-03 08:05:00'
      },
      {
        shipment_item_id: 'i2', stock_unit_id: 'u2', barcode: 'SU-BBBBBB-22222222',
        producto: 'Otro Producto', cantidad: 1, estado: 'EN_TRANSITO', fecha_creacion: '2026-10-03 08:05:01'
      },
      {
        shipment_item_id: 'i3', stock_unit_id: 'u9', barcode: 'SU-EEEEEE-99999999',
        producto: 'Ya entregado', cantidad: 1, estado: 'ENTREGADA', fecha_creacion: '2026-10-03 08:05:02'
      }
    ]
  }
};

const DELIVERY_ROWS = [
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
];

const DELIVERIES_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, pendientes: 1, entregadas: 1, rechazadas: 0, parciales: 0 },
    data: DELIVERY_ROWS,
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

const RETURN_ROWS = [
  {
    return_id: 'ret1', delivery_id: 'd1', shipment_id: 'sh9', referencia_externa: 'EXP-9',
    unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111', cantidad: 3, motivo: 'DAÑO',
    descripcion_motivo: 'Llegó roto', estado_al_devolver: 'DAÑADO', ubicacion_destino_id: 'z1',
    ubicacion_destino: 'ZDANADOS', actor_id_recibe_devolucion: 'user-1',
    timestamp_devolucion: '2026-10-04 08:00:00', fecha_creacion: '2026-10-04 08:00:00'
  },
  {
    return_id: 'ret2', delivery_id: 'd1', shipment_id: 'sh9', referencia_externa: 'EXP-9',
    unidad_fisica_id: 'u2', codigo_barcode: 'SU-BBBBBB-22222222', cantidad: 1, motivo: 'CAMBIO_CLIENTE',
    descripcion_motivo: null, estado_al_devolver: 'OK', ubicacion_destino_id: 'al1',
    ubicacion_destino: 'HOUSEA23', actor_id_recibe_devolucion: 'user-1',
    timestamp_devolucion: '2026-10-04 09:00:00', fecha_creacion: '2026-10-04 09:00:00'
  }
];

const RETURNS_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2, ok: 1, danadas: 1, deterioradas: 0, perdidas: 0 },
    data: RETURN_ROWS,
    pagination: { total: 2, page: 1, per_page: 20, has_next: false }
  }
};

const STOCK_ENTREGADO_PAYLOAD = {
  status: 200,
  data: {
    summary: { unidades: 2, cantidad_fisica: 4, cantidad_reservada: 0, cantidad_en_transito: 0, cantidad_disponible: 0 },
    data: [
      {
        unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111', producto_id: 'p1', sku: 'PROD2000',
        producto_nombre: 'Producto Demo', lote: null, numero_serie: null, ubicacion_id: null,
        ubicacion_codigo: null, estado: 'ENTREGADO', estado_actual: 'ENTREGADO', cantidad_fisica: 3,
        cantidad_reservada: 0, cantidad_en_transito: 0, cantidad_disponible: 0,
        ultima_actualizacion: '2026-10-03 12:00:00'
      },
      {
        unidad_fisica_id: 'u2', codigo_barcode: 'SU-BBBBBB-22222222', producto_id: 'p2', sku: 'PROD3000',
        producto_nombre: 'Otro Producto', lote: null, numero_serie: null, ubicacion_id: null,
        ubicacion_codigo: null, estado: 'ENTREGADO', estado_actual: 'ENTREGADO', cantidad_fisica: 1,
        cantidad_reservada: 0, cantidad_en_transito: 0, cantidad_disponible: 0,
        ultima_actualizacion: '2026-10-03 12:00:00'
      },
      {
        unidad_fisica_id: 'u3', codigo_barcode: 'SU-CCCC-3333', producto_id: 'p3', sku: 'PROD4000',
        producto_nombre: 'No devolvible', lote: null, numero_serie: null, ubicacion_id: 'al1',
        ubicacion_codigo: 'HOUSEA23', estado: 'DISPONIBLE', estado_actual: 'DISPONIBLE', cantidad_fisica: 1,
        cantidad_reservada: 0, cantidad_en_transito: 0, cantidad_disponible: 1,
        ultima_actualizacion: '2026-10-03 12:00:00'
      }
    ],
    pagination: { total: 3, page: 1, per_page: 100, has_next: false }
  }
};

const LOCATIONS_PAYLOAD = {
  status: 200,
  data: {
    summary: { total: 2 },
    data: [
      { id: 'al1', codigo: 'HOUSEA23', nombre: 'Almacén A', tipo: 'ALMACENAMIENTO', activa: true },
      { id: 'z1', codigo: 'ZDANADOS', nombre: 'Zona dañados', tipo: 'DAÑADOS', activa: true }
    ],
    pagination: { total: 2, page: 1, per_page: 100, has_next: false }
  }
};

// =====================================================================
// ENTORNO
// =====================================================================

function createEnv({ perms = [], roles = ['ADMIN'], getHandlers = {} } = {}) {
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

  const env = { w, calls, postImpl: null, dom };

  const defaults = {
    '/inventory/shipments': (options) => {
      if (options.params && options.params.estado === 'EN_RUTA') return EN_RUTA_PAYLOAD;
      return { status: 200, data: { summary: {}, data: [], pagination: { total: 0, page: 1, per_page: 20, has_next: false } } };
    },
    '/inventory/shipments/sh2': () => SHIPMENT_DETAIL,
    '/inventory/deliveries': () => DELIVERIES_PAYLOAD,
    '/inventory/returns': () => RETURNS_PAYLOAD,
    '/inventory/stock': () => STOCK_ENTREGADO_PAYLOAD,
    '/inventory/locations': () => LOCATIONS_PAYLOAD
  };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (p.startsWith('/locales/')) {
        const loc = p.slice('/locales/'.length).replace(/\.json$/, '');
        return { status: 200, data: { locale: loc, version: 'smoke', dictionary: ES_DICT } };
      }
      const handler = getHandlers[p] || defaults[p];
      if (!handler) throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
      return handler(options, env);
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
  env.loadDeliveries = () => {
    evalUi();
    env.evalIn(FILES.deliveries);
    w.InventoryDeliveriesModule.init({}, w.document.getElementById('module-container'));
  };
  env.loadReturns = () => {
    evalUi();
    env.evalIn(FILES.returns);
    w.InventoryReturnsModule.init({}, w.document.getElementById('module-container'));
  };
  env.loadShell = () => {
    evalUi();
    env.evalIn(FILES.loader);
    env.evalIn(FILES.router);
    env.evalIn(FILES.sidebar);
  };

  env.container = w.document.getElementById('module-container');
  env.sidebar = w.document.getElementById('sidebar');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.change = (el) => el.dispatchEvent(new w.Event('change', { bubbles: true }));
  env.input = (el) => el.dispatchEvent(new w.Event('input', { bubbles: true }));
  env.close = () => dom.window.close();

  return env;
}

const ADMIN_PERMS = [
  'inventory.item.view', 'inventory.stock.view', 'inventory.location.view',
  'inventory.reservation.view', 'inventory.picking.view', 'inventory.shipping.view',
  'inventory.delivery.view', 'inventory.delivery.confirm',
  'inventory.return.view', 'inventory.return.create'
];

// =====================================================================
// D1 - PANTALLA DE ENTREGAS: listado, filtros y confirmación
// =====================================================================
async function d1_deliveries_screen() {
  console.log('D1 entregas: listado, filtros y confirmación');

  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadDeliveries();
  await tick();

  check('módulo de entregas global', !!env.w.InventoryDeliveriesModule);
  check('GET envíos EN_RUTA', env.calls.gets.some(g =>
    g.path === '/inventory/shipments' && g.options.params.estado === 'EN_RUTA'),
    JSON.stringify(env.calls.gets.map(g => g.path)));
  check('GET historial de entregas', env.calls.gets.some(g => g.path === '/inventory/deliveries'));
  check('2 envíos EN_RUTA en la tabla', env.container.querySelectorAll('[data-en-ruta-table] tbody tr').length === 2);
  check('acciones de entrega visibles', !!env.container.querySelector('[data-action="open-confirm"]')
    && !!env.container.querySelector('[data-action="open-failed"]'));
  check('2 filas en el historial', env.container.querySelectorAll('[data-del-table] tbody tr').length === 2);
  check('4 tarjetas de resumen', env.container.querySelectorAll('[data-del-summary] .card').length === 4);
  check('badge de estado', /Entregada/.test(env.container.querySelector('[data-del-table]').textContent));

  // filtros
  env.container.querySelector('[data-filter="estado"]').value = 'ENTREGADA';
  env.click(env.container.querySelector('[data-action="apply-filters"]'));
  await tick();
  const last = env.calls.gets[env.calls.gets.length - 1];
  check('filtro de estado enviado',
    last.path === '/inventory/deliveries' && last.options.params.estado === 'ENTREGADA',
    JSON.stringify(last.options.params));

  env.click(env.container.querySelector('[data-action="clear-filters"]'));
  await tick();
  const afterClear = env.calls.gets[env.calls.gets.length - 1];
  check('limpiar filtros reenvía sin estado', afterClear.path === '/inventory/deliveries'
    && !('estado' in afterClear.options.params), JSON.stringify(afterClear.options.params));

  // modal de confirmación
  env.click(env.container.querySelector('[data-action="open-confirm"]'));
  await tick();
  const modal = env.container.querySelector('[data-modal-root] .modal');
  check('modal de confirmación abierto', !!modal);
  check('detalle del envío cargado', env.calls.gets.some(g => g.path === '/inventory/shipments/sh2'));
  check('sólo unidades pendientes (2)', modal.querySelectorAll('[data-action="toggle-unit"]').length === 2,
    String(modal.querySelectorAll('[data-action="toggle-unit"]').length));
  check('todas marcadas por defecto', modal.querySelectorAll('input[data-action="toggle-unit"]:checked').length === 2);
  check('Idempotency-Key visible', /Idempotency-Key/.test(modal.querySelector('[data-key-hint]').textContent));
  check('aviso de unidades marcadas', /2 unidad\(es\) están marcadas/.test(modal.querySelector('[data-confirm-notice]').textContent),
    modal.querySelector('[data-confirm-notice]').textContent.trim());

  // sin unidades marcadas no envía
  env.click(env.container.querySelector('[data-action="unmark-all"]'));
  await tick();
  env.click(env.container.querySelector('[data-action="submit-confirm"]'));
  await tick();
  check('sin unidades marcadas no envía', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  const errUnidades = env.container.querySelector('[data-error="unidades"]');
  check('error de campo unidades', !!errUnidades && !errUnidades.classList.contains('hidden'));

  // confirmar con todas las marcadas
  env.click(env.container.querySelector('[data-action="mark-all"]'));
  await tick();
  env.click(env.container.querySelector('[data-action="submit-confirm"]'));
  await tick();

  const post = env.calls.posts.find(p => p.path === '/inventory/deliveries/confirm');
  check('POST confirmar entrega', !!post, JSON.stringify(env.calls.posts));
  check('payload con shipment_id', !!post && post.body.shipment_id === 'sh2');
  check('payload con 2 unidades', !!post && post.body.unidades.length === 2, JSON.stringify(post && post.body));
  check('Idempotency-Key en la cabecera', !!post && !!post.options.headers['Idempotency-Key']);
  check('motivo_fallo por defecto', !!post && post.body.motivo_fallo === 'OTRO');
  check('modal cerrado tras el éxito', !env.container.querySelector('[data-modal-root] .modal'));
  check('toast de éxito', env.calls.toasts.some(t => t.type === 'success' && /Entrega confirmada/.test(t.title)),
    JSON.stringify(env.calls.toasts));
  check('historial recargado tras el éxito',
    env.calls.gets.filter(g => g.path === '/inventory/deliveries').length >= 2);
  env.close();
}

// =====================================================================
// D2 - ERRORES DE LA CONFIRMACIÓN: 422, 409 y 401
// =====================================================================
async function d2_confirm_errors() {
  console.log('D2 entregas: 422, 409 y 401 en la confirmación');

  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.postImpl = () => Promise.reject(
    Object.assign(new Error('La firma debe ser una URL http:// o https://'),
      { status: 422, data: { field: 'firma_comprobante:u1' } })
  );
  env.loadDeliveries();
  await tick();
  env.click(env.container.querySelector('[data-action="open-confirm"]'));
  await tick();
  env.click(env.container.querySelector('[data-action="submit-confirm"]'));
  await tick();

  check('toast de 422', env.calls.toasts.some(t => t.type === 'danger' && t.title === 'Operación rechazada'),
    JSON.stringify(env.calls.toasts));
  const errFirma = env.container.querySelector('[data-error="firma_comprobante:u1"]');
  check('error de campo en la firma', !!errFirma && !errFirma.classList.contains('hidden'));
  check('alerta inline del modal', !env.container.querySelector('[data-form-error]').classList.contains('hidden'));
  check('el modal sigue abierto', !!env.container.querySelector('[data-modal-root] .modal'));

  const key1 = (env.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/) || [])[1];
  env.postImpl = () => Promise.reject(
    Object.assign(new Error('Idempotency-Key ya utilizada'), { status: 409, data: null })
  );
  env.click(env.container.querySelector('[data-action="submit-confirm"]'));
  await tick();
  const key2 = (env.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/) || [])[1];
  check('409 renueva la Idempotency-Key', !!key1 && !!key2 && key1 !== key2, `${key1} vs ${key2}`);
  check('toast de 409', env.calls.toasts.some(t => t.type === 'warning' && t.title === 'Conflicto'),
    JSON.stringify(env.calls.toasts));

  env.postImpl = () => Promise.reject(
    Object.assign(new Error('Token inválido o expirado'), { status: 401, data: null })
  );
  env.click(env.container.querySelector('[data-action="submit-confirm"]'));
  await tick();
  check('toast de 401', env.calls.toasts.some(t => t.type === 'warning' && t.title === 'Sesión expirada'),
    JSON.stringify(env.calls.toasts));
  env.close();
}

// =====================================================================
// D3 - MODAL DE FALLO DE ENTREGA
// =====================================================================
async function d3_failure_modal() {
  console.log('D3 entregas: modal de fallo');

  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadDeliveries();
  await tick();

  env.click(env.container.querySelector('[data-action="open-failed"]'));
  await tick();
  check('modal de fallo abierto', !!env.container.querySelector('[data-modal-root] .modal'));
  check('select de motivo con 4 opciones',
    env.container.querySelectorAll('[data-field="motivo"] option').length === 5,
    String(env.container.querySelectorAll('[data-field="motivo"] option').length));

  env.click(env.container.querySelector('[data-action="submit-failed"]'));
  await tick();
  check('sin motivo no envía', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo motivo', !env.container.querySelector('[data-error="motivo"]').classList.contains('hidden'));

  const motivo = env.container.querySelector('[data-field="motivo"]');
  motivo.value = 'RECHAZO';
  env.change(motivo);
  const obs = env.container.querySelector('[data-field="observaciones"]');
  obs.value = 'No había nadie para recibir';
  env.input(obs);

  const boxes = [...env.container.querySelectorAll('input[data-action="toggle-unit"]')];
  boxes[0].checked = false;
  env.change(boxes[0]);
  await tick();
  check('una unidad desmarcada reduce el contador',
    /Unidades afectadas \(1 de 2\)/.test(env.container.querySelector('.modal-body').textContent),
    env.container.querySelector('.modal-body').textContent.slice(0, 200));

  env.click(env.container.querySelector('[data-action="submit-failed"]'));
  await tick();

  const post = env.calls.posts.find(p => p.path === '/inventory/deliveries/failed');
  check('POST registrar fallo', !!post, JSON.stringify(env.calls.posts));
  check('payload con motivo', !!post && post.body.motivo === 'RECHAZO');
  check('payload con una sola unidad', !!post && post.body.unidades.length === 1, JSON.stringify(post && post.body.unidades));
  check('payload con observaciones', !!post && post.body.observaciones === 'No había nadie para recibir');
  check('Idempotency-Key en el fallo', !!post && !!post.options.headers['Idempotency-Key']);
  check('modal cerrado tras el fallo', !env.container.querySelector('[data-modal-root] .modal'));
  check('toast de éxito del fallo', env.calls.toasts.some(t => t.type === 'success' && /Fallo registrado/.test(t.title)),
    JSON.stringify(env.calls.toasts));
  env.close();
}

// =====================================================================
// D4 - PERMISOS DE LA PANTALLA DE ENTREGAS
// =====================================================================
async function d4_delivery_permissions() {
  console.log('D4 entregas: permisos');

  const env = createEnv({ perms: ['inventory.stock.view'], roles: ['VIEWER'] });
  env.loadDeliveries();
  await tick();
  check('sin delivery.view se muestra el aviso',
    /inventory.delivery.view/.test(env.container.textContent), env.container.textContent.slice(0, 120));
  check('sin delivery.view no hay llamadas al API', env.calls.gets.length === 0,
    JSON.stringify(env.calls.gets.map(g => g.path)));
  env.close();

  // sin inventory.shipping.view el backend devuelve 403 para los envíos
  const env2 = createEnv({
    perms: ['inventory.delivery.view', 'inventory.delivery.confirm'], roles: ['USER'],
    getHandlers: {
      '/inventory/shipments': () => Promise.reject(
        Object.assign(new Error('Acceso denegado'), { status: 403, data: null })
      )
    }
  });
  env2.loadDeliveries();
  await tick();
  check('aviso de envíos sin shipping.view',
    /Sin acceso a los envíos/.test(env2.container.querySelector('[data-en-ruta-table]').textContent),
    env2.container.querySelector('[data-en-ruta-table]').textContent.trim());
  check('el historial se sigue cargando', env2.calls.gets.some(g => g.path === '/inventory/deliveries'));
  env2.close();

  // con delivery.view pero sin delivery.confirm no hay acciones de escritura
  const env3 = createEnv({ perms: ['inventory.delivery.view', 'inventory.shipping.view'], roles: ['USER'] });
  env3.loadDeliveries();
  await tick();
  check('sin delivery.confirm no hay botones de acción',
    !env3.container.querySelector('[data-action="open-confirm"]')
    && !env3.container.querySelector('[data-action="open-failed"]'));
  check('el listado EN_RUTA sí se pinta', env3.container.querySelectorAll('[data-en-ruta-table] tbody tr').length === 2);
  env3.close();
}

// =====================================================================
// R1 - PANTALLA DE DEVOLUCIONES: listado, filtros y alta
// =====================================================================
async function d5_returns_screen() {
  console.log('R1 devoluciones: listado, filtros y alta');

  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadReturns();
  await tick();

  check('módulo de devoluciones global', !!env.w.InventoryReturnsModule);
  check('GET devoluciones', env.calls.gets.some(g => g.path === '/inventory/returns'));
  check('5 tarjetas de resumen', env.container.querySelectorAll('[data-ret-summary] .card').length === 5);
  check('2 filas en el listado', env.container.querySelectorAll('[data-ret-table] tbody tr').length === 2);
  check('motivo DAÑO legible', /Daño/.test(env.container.querySelector('[data-ret-table]').textContent));

  env.container.querySelector('[data-filter="estado"]').value = 'OK';
  env.click(env.container.querySelector('[data-action="apply-filters"]'));
  await tick();
  const last = env.calls.gets[env.calls.gets.length - 1];
  check('filtro de estado enviado',
    last.path === '/inventory/returns' && last.options.params.estado === 'OK',
    JSON.stringify(last.options.params));

  // alta
  env.click(env.container.querySelector('[data-action="open-register"]'));
  await tick();
  check('modal de alta abierto', !!env.container.querySelector('[data-modal-root] .modal'));
  check('GET de stock ENTREGADO', env.calls.gets.some(g =>
    g.path === '/inventory/stock' && g.options.params.estado === 'ENTREGADO'),
    JSON.stringify(env.calls.gets.map(g => g.path)));
  check('GET de ubicaciones activas', env.calls.gets.some(g =>
    g.path === '/inventory/locations' && g.options.params.activa === 'true'));
  const unitValues = [...env.container.querySelectorAll('[data-modal-field="unidad_fisica_id"] option')]
    .map(o => o.value).filter(Boolean);
  check('sólo unidades devolvibles en el selector', unitValues.length === 2, JSON.stringify(unitValues));

  const setModal = (field, value) => {
    const el = env.container.querySelector(`[data-modal-field="${field}"]`);
    el.value = value;
    env.change(el);
    return el;
  };

  setModal('unidad_fisica_id', 'u1');
  setModal('motivo', 'FALLO_ENTREGA');
  setModal('estado_al_devolver', 'OK');
  await tick();
  check('destino habilitado con OK',
    !env.container.querySelector('[data-modal-field="ubicacion_destino_id"]').disabled);

  env.click(env.container.querySelector('[data-action="submit-register"]'));
  await tick();
  check('OK sin destino no envía', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo en el destino',
    !env.container.querySelector('[data-error="ubicacion_destino_id"]').classList.contains('hidden'));

  setModal('ubicacion_destino_id', 'al1');
  const obs = env.container.querySelector('[data-modal-field="observaciones"]');
  obs.value = 'Cliente devolvió la caja';
  env.input(obs);
  env.click(env.container.querySelector('[data-action="submit-register"]'));
  await tick();

  const post = env.calls.posts.find(p => p.path === '/inventory/returns/register');
  check('POST registrar devolución', !!post, JSON.stringify(env.calls.posts));
  check('payload con la unidad', !!post && post.body.unidad_fisica_id === 'u1');
  check('payload con motivo y estado', !!post && post.body.motivo === 'FALLO_ENTREGA'
    && post.body.estado_al_devolver === 'OK');
  check('payload con destino', !!post && post.body.ubicacion_destino_id === 'al1');
  check('payload con observaciones', !!post && post.body.observaciones === 'Cliente devolvió la caja');
  check('Idempotency-Key en el alta', !!post && !!post.options.headers['Idempotency-Key']);
  check('modal cerrado tras el alta', !env.container.querySelector('[data-modal-root] .modal'));
  check('toast de éxito del alta',
    env.calls.toasts.some(t => t.type === 'success' && /Devolución registrada/.test(t.title)),
    JSON.stringify(env.calls.toasts));
  check('listado recargado tras el alta',
    env.calls.gets.filter(g => g.path === '/inventory/returns').length >= 2);
  env.close();
}

// =====================================================================
// R2 - DEVOLUCIONES: DAÑADO sin destino, 409 y permisos
// =====================================================================
async function d6_returns_errors() {
  console.log('R2 devoluciones: DAÑADO, 409 y permisos');

  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadReturns();
  await tick();
  env.click(env.container.querySelector('[data-action="open-register"]'));
  await tick();

  const setModal = (field, value) => {
    const el = env.container.querySelector(`[data-modal-field="${field}"]`);
    el.value = value;
    env.change(el);
    return el;
  };

  setModal('unidad_fisica_id', 'u2');
  setModal('motivo', 'DAÑO');
  setModal('estado_al_devolver', 'DAÑADO');
  await tick();
  check('destino deshabilitado con DAÑADO',
    env.container.querySelector('[data-modal-field="ubicacion_destino_id"]').disabled);
  check('el select de estados usa el valor DAÑADO',
    env.container.querySelector('[data-modal-field="estado_al_devolver"]').value === 'DAÑADO');

  // 409 antes del alta (el modal sigue abierto)
  const key1 = (env.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/) || [])[1];
  env.postImpl = () => Promise.reject(
    Object.assign(new Error('Idempotency-Key ya utilizada'), { status: 409, data: null })
  );
  env.click(env.container.querySelector('[data-action="submit-register"]'));
  await tick();
  const key2 = (env.container.querySelector('[data-key-hint]').textContent.match(/Idempotency-Key:\s*(\S+)/) || [])[1];
  check('409 renueva la clave del alta', !!key1 && !!key2 && key1 !== key2, `${key1} vs ${key2}`);
  check('el modal de alta sigue abierto en el 409', !!env.container.querySelector('[data-modal-root] .modal'));

  env.postImpl = null;
  env.click(env.container.querySelector('[data-action="submit-register"]'));
  await tick();
  const post = env.calls.posts.find(p => p.path === '/inventory/returns/register');
  check('alta con DAÑADO sin destino', !!post, JSON.stringify(env.calls.posts));
  check('DAÑADO no envía ubicacion_destino_id', !!post && !('ubicacion_destino_id' in post.body),
    JSON.stringify(post && post.body));
  check('modal cerrado tras el alta de DAÑADO',
    !env.container.querySelector('[data-modal-root] .modal'));
  env.close();

  // sin inventory.return.create el botón queda oculto
  const env2 = createEnv({
    perms: ['inventory.return.view', 'inventory.stock.view', 'inventory.location.view'],
    roles: ['USER']
  });
  env2.loadReturns();
  await tick();
  const btn = env2.container.querySelector('[data-action="open-register"]');
  check('sin return.create el botón está oculto', !!btn && btn.classList.contains('hidden'));
  env2.click(btn);
  await tick();
  check('sin return.create no abre el modal',
    !env2.container.querySelector('[data-modal-root] .modal') && env2.calls.posts.length === 0);
  check('el listado sí se pinta con return.view',
    env2.container.querySelectorAll('[data-ret-table] tbody tr').length === 2);
  env2.close();

  // sin inventory.return.view
  const env3 = createEnv({ perms: ['inventory.stock.view'], roles: ['VIEWER'] });
  env3.loadReturns();
  await tick();
  check('sin return.view se muestra el aviso',
    /inventory.return.view/.test(env3.container.textContent), env3.container.textContent.slice(0, 120));
  check('sin return.view no hay llamadas al API', env3.calls.gets.length === 0,
    JSON.stringify(env3.calls.gets.map(g => g.path)));
  env3.close();
}

// =====================================================================
// S1 - SHELL: loader, router y sidebar
// =====================================================================
async function d7_shell_registration() {
  console.log('S1 shell: loader, router y sidebar');

  const env = createEnv({ perms: ADMIN_PERMS, roles: ['ADMIN'] });
  env.loadShell();
  const w = env.w;

  check('módulo inventory/deliveries registrado', w.ModuleLoader.has('inventory/deliveries'));
  check('módulo inventory/returns registrado', w.ModuleLoader.has('inventory/returns'));
  check('ruta inventory/deliveries declarada',
    !!w.AppRouter.getRoutes()['inventory/deliveries']
    && w.AppRouter.getRoutes()['inventory/deliveries'].module === 'inventory/deliveries');
  check('ruta inventory/returns declarada',
    !!w.AppRouter.getRoutes()['inventory/returns']
    && w.AppRouter.getRoutes()['inventory/returns'].module === 'inventory/returns');
  check('isModuleAvailable deliveries', w.AppRouter.isModuleAvailable('inventory/deliveries') === true);
  check('isModuleAvailable returns', w.AppRouter.isModuleAvailable('inventory/returns') === true);

  w.AppSidebar.init();
  const adminRoutes = [...env.sidebar.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('ADMIN ve Entregas', adminRoutes.includes('inventory/deliveries'), JSON.stringify(adminRoutes));
  check('ADMIN ve Devoluciones', adminRoutes.includes('inventory/returns'), JSON.stringify(adminRoutes));
  check('ADMIN ve Reservas', adminRoutes.includes('inventory/reservations'));
  env.close();

  const envU = createEnv({
    perms: ['inventory.stock.view', 'inventory.reservation.view', 'inventory.delivery.view'],
    roles: ['USER']
  });
  envU.loadShell();
  envU.w.AppSidebar.init();
  const userRoutes = [...envU.sidebar.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('USER con delivery.view ve Entregas', userRoutes.includes('inventory/deliveries'),
    JSON.stringify(userRoutes));
  check('USER sin return.view no ve Devoluciones', !userRoutes.includes('inventory/returns'),
    JSON.stringify(userRoutes));
  check('USER con reservation.view ve Reservas', userRoutes.includes('inventory/reservations'));
  envU.close();

  const envV = createEnv({ perms: ['inventory.stock.view'], roles: ['VIEWER'] });
  envV.loadShell();
  envV.w.AppSidebar.init();
  const viewerRoutes = [...envV.sidebar.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('VIEWER sin delivery.view no ve Entregas', !viewerRoutes.includes('inventory/deliveries'),
    JSON.stringify(viewerRoutes));
  check('VIEWER sin return.view no ve Devoluciones', !viewerRoutes.includes('inventory/returns'));
  check('VIEWER sin reservation.view no ve Reservas', !viewerRoutes.includes('inventory/reservations'));
  envV.close();
}

// =====================================================================
async function main() {
  await d1_deliveries_screen();
  await d2_confirm_errors();
  await d3_failure_modal();
  await d4_delivery_permissions();
  await d5_returns_screen();
  await d6_returns_errors();
  await d7_shell_registration();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});


