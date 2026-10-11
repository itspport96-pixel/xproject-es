/**
 * Smoke test jsdom para la UI v1.9.3 (componentes compartidos + incidencias
 * y conteos).
 * Uso: node tools/smoke/smoke-v193.mjs   (desde C:/xampp/htdocs/xp)
 *
 * Cubre:
 *  - public/js/ui/* (render, modal, acciones) y su contrato con los módulos.
 *  - inventory/incidents.js: listado, filtros, permisos, modal reportar
 *    (Idempotency-Key), detalle, investigar y resolver.
 *  - inventory/counts.js: listado, detalle, registrar resultados, completar
 *    (422 con ids de faltantes), reconciliar (validaciones, clave y 422 de
 *    cantidad vencida), hoja CSV y modal iniciar.
 *  - Registro en loader/router/sidebar y carga de ui/* en index.php.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));
const readPublic = (p) => readFileSync(path.join('C:/xampp/htdocs/xp/public', p), 'utf8');

const FILES = {
  incidents: read('modules/inventory/incidents.js'),
  counts: read('modules/inventory/counts.js'),
  reservations: read('modules/inventory/reservations.js'),
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
async function tick(n = 6) {
  for (let i = 0; i < n; i++) await new Promise(r => setTimeout(r, 0));
}

// =====================================================================
// FIXTURES
// =====================================================================

const LOCATIONS = [
  { id: 'loc1', codigo: 'HOUSEA23', nombre: 'A-2-3', tipo: 'ALMACENAMIENTO', activa: true },
  { id: 'loc2', codigo: 'DAÑOS01', nombre: 'Zona dañados', tipo: 'ALMACENAMIENTO', activa: true }
];

const UNITS = [
  {
    unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111', producto_id: 'p1', sku: 'PROD2000',
    producto_nombre: 'Producto Demo', ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23',
    estado: 'DISPONIBLE', cantidad_fisica: 5
  },
  {
    unidad_fisica_id: 'u2', codigo_barcode: 'SU-BBBBBB-22222222', producto_id: 'p1', sku: 'PROD2000',
    producto_nombre: 'Producto Demo', ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23',
    estado: 'RESERVADO', cantidad_fisica: 3
  },
  {
    unidad_fisica_id: 'u3', codigo_barcode: 'SU-CCCCCC-33333333', producto_id: 'p2', sku: 'PROD3000',
    producto_nombre: 'Otro Producto', ubicacion_id: 'loc2', ubicacion_codigo: 'DAÑOS01',
    estado: 'DAÑADO', cantidad_fisica: 2
  }
];

const INCIDENTS_LIST = [
  {
    incidencia_id: 'i1', tipo: 'DAÑO', descripcion: 'Unidad dañada en estantería', observaciones: null,
    estado: 'ABIERTA', unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111',
    ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23', inventory_count_id: null,
    actor_id_reporta: 'user-1', fecha_deteccion: '2026-10-01 10:00:00', fecha_creacion: '2026-10-01 10:00:00'
  },
  {
    incidencia_id: 'i2', tipo: 'PERDIDA', descripcion: 'Unidad extraviada', observaciones: null,
    estado: 'RESUELTA', unidad_fisica_id: 'u3', codigo_barcode: 'SU-CCCCCC-33333333',
    ubicacion_id: 'loc2', ubicacion_codigo: 'DAÑOS01', inventory_count_id: null,
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

const INCIDENT_DETAIL = {
  ...INCIDENTS_LIST[0],
  observaciones: 'Revisado en muelle',
  fecha_actualizacion: '2026-10-01 11:00:00',
  resolucion: null
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

const SHEET_ROWS = [
  { unidad_fisica_id: 'u1', codigo_barcode: 'SU-AAAAAA-11111111', producto_id: 'p1', ubicacion_codigo: 'HOUSEA23', cantidad_esperada: 5 },
  { unidad_fisica_id: 'u2', codigo_barcode: 'SU-BBBBBB-22222222', producto_id: 'p1', ubicacion_codigo: 'HOUSEA23', cantidad_esperada: 3 }
];

const COUNT_DETAIL_PENDIENTE = {
  conteo_id: 'c1', ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23', estado: 'PENDIENTE',
  usuario_responsable_id: 'user-1', fecha_conteo: '2026-10-02 09:00:00',
  unidades_lista: 2, unidades_contadas: 1, discrepancias: 0, discrepancias_pendientes: 0,
  reconciliado: false, created_at: '2026-10-02 09:00:00', updated_at: '2026-10-02 09:00:00',
  lista_teorica: SHEET_ROWS,
  resultados: [{ unidad_fisica_id: 'u1', cantidad_encontrada: 5, notas: null }],
  detalle_discrepancias: [],
  ajustes_aplicados: []
};

const COUNT_DETAIL_COMPLETADO = {
  conteo_id: 'c2', ubicacion_id: 'loc1', ubicacion_codigo: 'HOUSEA23', estado: 'COMPLETADO',
  usuario_responsable_id: 'user-1', fecha_conteo: '2026-10-01 15:00:00',
  unidades_lista: 2, unidades_contadas: 2, discrepancias: 1, discrepancias_pendientes: 1,
  reconciliado: false, created_at: '2026-10-01 15:00:00', updated_at: '2026-10-01 16:00:00',
  lista_teorica: SHEET_ROWS,
  resultados: [
    { unidad_fisica_id: 'u1', cantidad_encontrada: 5, notas: null },
    { unidad_fisica_id: 'u2', cantidad_encontrada: 1, notas: 'Faltan 2' }
  ],
  detalle_discrepancias: [
    {
      unidad_fisica_id: 'u2', codigo_barcode: 'SU-BBBBBB-22222222',
      cantidad_esperada: 3, cantidad_encontrada: 1, diferencia: -2, notas: 'Faltan 2'
    }
  ],
  ajustes_aplicados: []
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

  const calls = { gets: [], posts: [], toasts: [], errors: [], downloads: [] };

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

  // Descarga CSV: Blob + object URL + clic sintético, registrada en calls.downloads.
  w.URL.createObjectURL = () => 'blob:fake';
  w.URL.revokeObjectURL = () => {};
  w.HTMLAnchorElement.prototype.click = function () { calls.downloads.push(this.download); };

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
      if (p === '/inventory/incidents') return INCIDENTS_PAYLOAD;
      if (p === '/inventory/incidents/i1') return { status: 200, data: INCIDENT_DETAIL };
      if (p === '/inventory/counts') return COUNTS_PAYLOAD;
      if (p === '/inventory/counts/c1') return { status: 200, data: COUNT_DETAIL_PENDIENTE };
      if (p === '/inventory/counts/c2') return { status: 200, data: COUNT_DETAIL_COMPLETADO };
      if (p === '/inventory/counts/c3') return { status: 200, data: { ...COUNT_DETAIL_PENDIENTE, conteo_id: 'c3' } };
      if (p.startsWith('/inventory/counts/') && p.endsWith('/sheet?format=csv')) {
        return { status: 200, data: 'unidad_fisica_id,codigo_barcode\r\nu1,SU-AAAAAA-11111111\r\n' };
      }
      if (p === '/inventory/stock') return { status: 200, data: { data: UNITS, pagination: { total: UNITS.length, page: 1, per_page: 100 } } };
      if (p === '/inventory/locations') return { status: 200, data: { data: LOCATIONS, pagination: { total: LOCATIONS.length, page: 1, per_page: 100 } } };
      if (p.startsWith('/locales/')) {
        const loc = p.slice('/locales/'.length).replace(/\.json$/, '');
        return { status: 200, data: { locale: loc, version: 'smoke', dictionary: ES_DICT } };
      }
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    },
    async post(p, body, options = {}) {
      calls.posts.push({ path: p, body, options });
      if (env.postImpl) return env.postImpl(p, body, options, env);
      return { status: 200, data: { mensaje: 'ok' } };
    }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  // i18n runtime + diccionario es inyectado (sin red): los módulos migrados
  // llaman t() al renderizar.
  evalIn(read('core/i18n.js'));
  w.i18n.inject('es', ES_DICT);
  const evalUi = () => UI_FILES.forEach(evalIn);
  env.evalUi = evalUi;

  env.loadIncidents = () => { evalUi(); evalIn(FILES.incidents); w.InventoryIncidentsModule.init({}, w.document.getElementById('module-container')); };
  env.loadCounts = () => { evalUi(); evalIn(FILES.counts); w.InventoryCountsModule.init({}, w.document.getElementById('module-container')); };
  env.loadShell = () => { evalUi(); evalIn(FILES.loader); evalIn(FILES.router); evalIn(FILES.sidebar); };

  env.container = w.document.getElementById('module-container');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.change = (el, value) => { el.value = value; el.dispatchEvent(new w.Event('change', { bubbles: true })); };
  env.input = (el, value) => { el.value = value; el.dispatchEvent(new w.Event('input', { bubbles: true })); };
  env.close = () => dom.window.close();

  return env;
}

const INCIDENT_PERMS = [
  'inventory.incident.view', 'inventory.incident.report', 'inventory.incident.resolve',
  'inventory.stock.view', 'inventory.location.view'
];
const REPORT_PERMS = ['inventory.incident.view', 'inventory.incident.report', 'inventory.stock.view'];
const VIEW_ONLY_INCIDENT_PERMS = ['inventory.incident.view', 'inventory.stock.view'];

const COUNT_PERMS = [
  'inventory.count.view', 'inventory.count.create', 'inventory.count.reconcile',
  'inventory.location.view'
];

// =====================================================================
// S1 - COMPONENTES UI COMPARTIDOS
// =====================================================================
async function s1_ui_components() {
  console.log('S1 ui: contrato de public/js/ui/*');
  const env = createEnv({ perms: INCIDENT_PERMS, roles: ['ADMIN'] });
  env.evalUi();
  const w = env.w;

  const fns = ['escapeHtml', 'formatNumber', 'toast', 'pageHeader', 'estadoBadge', 'emptyState',
    'loadingState', 'tableShell', 'renderPagination', 'summaryCards', 'modalShell', 'mountModal',
    'closeModal', 'field', 'grid', 'showFieldError', 'showFormError', 'clearErrors', 'can',
    'newIdempotencyKey', 'handleActionError', 'downloadCsv'];
  const missing = fns.filter(fn => typeof w.UI[fn] !== 'function');
  check('UI expone las 22 funciones', missing.length === 0, JSON.stringify(missing));

  check('escapeHtml escapa', w.UI.escapeHtml('<b>"x"\'</b>') === '&lt;b&gt;&quot;x&quot;&#039;&lt;/b&gt;');
  check('formatNumber entero', w.UI.formatNumber(5) === '5');
  check('formatNumber decimal', w.UI.formatNumber(1.5) === '1.5');

  const cat = [{ value: 'ABIERTA', label: 'Abierta', color: 'danger' }];
  check('estadoBadge con catálogo', /badge-danger/.test(w.UI.estadoBadge('ABIERTA', cat)) && /Abierta/.test(w.UI.estadoBadge('ABIERTA', cat)));
  check('estadoBadge default', /badge-warning/.test(w.UI.estadoBadge('X', cat, 'warning')));

  const root = w.document.createElement('div');
  root.innerHTML = `
    <div class="form-error hidden" data-form-error></div>
    <span class="form-error hidden" data-error="campo1"></span>`;
  w.document.body.appendChild(root);

  w.UI.showFieldError(root, 'campo1', 'mal');
  check('showFieldError visible', root.querySelector('[data-error="campo1"]').textContent === 'mal'
    && !root.querySelector('[data-error="campo1"]').classList.contains('hidden'));
  w.UI.showFormError(root, 'falló');
  check('showFormError visible', !root.querySelector('[data-form-error]').classList.contains('hidden'));
  w.UI.clearErrors(root);
  check('clearErrors oculta', root.querySelector('[data-error="campo1"]').classList.contains('hidden')
    && root.querySelector('[data-form-error]').classList.contains('hidden'));
  root.insertAdjacentHTML('beforeend', '<span class="form-error hidden" data-error="campo[x]"></span>');
  w.UI.showFieldError(root, 'campo["x"]', 'x');
  const sanitized = root.querySelector('[data-error="campo[x]"]');
  check('showFieldError sanea comillas', !!sanitized && sanitized.textContent === 'x'
    && !sanitized.classList.contains('hidden'));

  const k1 = w.UI.newIdempotencyKey();
  const k2 = w.UI.newIdempotencyKey();
  check('claves de idempotencia no vacías y distintas', !!k1 && !!k2 && k1 !== k2, `${k1} / ${k2}`);

  check('can con permiso real', w.UI.can('inventory.incident.view', ['VIEWER']) === true);
  check('can sin permiso', w.UI.can('no.existe', ['VIEWER']) === false);

  let conflict = 0;
  w.UI.handleActionError(
    Object.assign(new Error('payload distinto'), { status: 409, data: null }),
    { root, inline: true, onConflict: () => conflict++ }
  );
  check('handleActionError 409 llama onConflict', conflict === 1);
  check('handleActionError 409 toast', env.calls.toasts.some(t => t.type === 'warning' && t.title === 'Conflicto'));
  w.UI.handleActionError(Object.assign(new Error('no'), { status: 404, data: null }), {});
  check('handleActionError 404 toast', env.calls.toasts.some(t => t.type === 'danger' && t.title === 'No encontrado'));
  w.UI.handleActionError(Object.assign(new Error('deny'), { status: 403, data: null }), { root, inline: true });
  check('handleActionError 403 toast', env.calls.toasts.some(t => t.type === 'warning' && t.title === 'Acceso denegado'));
  check('handleActionError 403 error en línea', !root.querySelector('[data-form-error]').classList.contains('hidden'));

  env.close();
}

// =====================================================================
// S2 - INCIDENCIAS: LISTADO, FILTROS Y PERMISOS
// =====================================================================
async function s2_incidents_list() {
  console.log('S2 incidencias: listado, filtros y permisos');
  const env = createEnv({ perms: INCIDENT_PERMS, roles: ['ADMIN'] });
  env.loadIncidents();
  await tick();

  check('GET /inventory/incidents',
    env.calls.gets.some(g => g.path === '/inventory/incidents' && g.options.params.page === 1),
    JSON.stringify(env.calls.gets));

  const headers = [...env.container.querySelectorAll('thead th')].map(th => th.textContent.trim());
  check('columnas del listado', headers.includes('Incidencia') && headers.includes('Tipo')
    && headers.includes('Estado') && headers.includes('Acciones'), JSON.stringify(headers));

  const rows = env.container.querySelectorAll('tbody tr');
  check('2 filas renderizadas', rows.length === 2, `got ${rows.length}`);
  check('badge de estado en fila', /badge-danger/.test(rows[0].innerHTML), rows[0].innerHTML);
  check('tipo mostrado como label', /Daño/.test(rows[0].textContent), rows[0].textContent);

  const summary = env.container.querySelector('[data-inc-summary]').textContent;
  check('resumen con total/abiertas/resueltas', /Incidencias/.test(summary) && /Abiertas/.test(summary)
    && /Resueltas/.test(summary), summary);

  // filtro de estado
  env.change(env.container.querySelector('[data-filter="estado"]'), 'ABIERTA');
  env.click(env.container.querySelector('[data-action="apply-filters"]'));
  await tick();
  const last = env.calls.gets[env.calls.gets.length - 1];
  check('filtro estado enviado', last.path === '/inventory/incidents' && last.options.params.estado === 'ABIERTA',
    JSON.stringify(last.options.params));

  // limpiar filtros
  env.click(env.container.querySelector('[data-action="clear-filters"]'));
  await tick();
  const last2 = env.calls.gets[env.calls.gets.length - 1];
  check('limpiar filtros reenvía sin estado', last2.options.params.estado === undefined, JSON.stringify(last2.options.params));

  // selector de unidad desde /inventory/stock
  const unitFilter = env.container.querySelector('[data-filter="unidad_fisica_id"]');
  check('filtro de unidad es select con catálogo', !!unitFilter && unitFilter.tagName === 'SELECT'
    && unitFilter.options.length === UNITS.length + 1, unitFilter ? String(unitFilter.options.length) : 'none');

  // botones de alta
  check('botón reportar visible con permiso', !!env.container.querySelector('[data-action="open-report"]'));
  check('acciones por fila', /Ver/.test(rows[0].querySelector('.table-actions').textContent)
    && /Investigar/.test(rows[0].querySelector('.table-actions').textContent)
    && /Resolver/.test(rows[0].querySelector('.table-actions').textContent));
  check('fila RESUELTA sin acciones de cierre', !/Resolver/.test(rows[1].querySelector('.table-actions').textContent),
    rows[1].querySelector('.table-actions').textContent);
  env.close();

  // sin report: no hay botón de alta
  const env2 = createEnv({ perms: VIEW_ONLY_INCIDENT_PERMS, roles: ['VIEWER'] });
  env2.loadIncidents();
  await tick();
  check('sin incident.report no hay botón de alta', !env2.container.querySelector('[data-action="open-report"]'));
  check('sin resolve no hay acciones de cierre',
    !/Resolver/.test(env2.container.querySelector('tbody tr .table-actions').textContent));
  env2.close();

  // sin view: pantalla de sin permiso y sin llamadas
  const env3 = createEnv({ perms: ['inventory.stock.view'], roles: ['USER'] });
  env3.loadIncidents();
  await tick();
  check('sin incident.view muestra aviso', /Sin permiso/.test(env3.container.textContent));
  check('sin incident.view no pide datos', env3.calls.gets.length === 0, JSON.stringify(env3.calls.gets));
  env3.close();

  // sin stock.view: el filtro de unidad cae a input de id
  const env4 = createEnv({
    perms: ['inventory.incident.view'], roles: ['VIEWER'],
    getHandlers: {
      '/inventory/stock': () => { throw Object.assign(new Error('Acceso denegado'), { status: 403, data: null }); }
    }
  });
  env4.loadIncidents();
  await tick();
  const filter4 = env4.container.querySelector('[data-filter="unidad_fisica_id"]');
  check('sin stock.view el filtro es input libre', !!filter4 && filter4.tagName === 'INPUT',
    filter4 ? filter4.tagName : 'none');
  env4.close();
}

// =====================================================================
// S3 - INCIDENCIAS: MODAL REPORTAR
// =====================================================================
async function s3_incidents_report() {
  console.log('S3 incidencias: modal reportar (clave, validación, 409)');
  const env = createEnv({ perms: INCIDENT_PERMS, roles: ['ADMIN'] });
  env.loadIncidents();
  await tick();

  env.click(env.container.querySelector('[data-action="open-report"]'));
  await tick();
  let modal = env.container.querySelector('[data-modal-root] .modal');
  check('modal de reporte abierto', !!modal);
  check('muestra Idempotency-Key', /Idempotency-Key/.test(modal.textContent)
    && /00000000-0000-4000-8000-00000000000/.test(modal.textContent));
  check('selector de tipo con 8 tipos', modal.querySelectorAll('[data-modal-field="tipo"] option').length === 9,
    String(modal.querySelectorAll('[data-modal-field="tipo"] option').length));
  check('selector de unidad poblado desde stock',
    modal.querySelectorAll('[data-modal-field="unidad_fisica_id"] option').length === UNITS.length + 1,
    String(modal.querySelectorAll('[data-modal-field="unidad_fisica_id"] option').length));

  // validación local: sin tipo ni descripción no se envía
  env.click(modal.querySelector('[data-action="submit-report"]'));
  await tick();
  modal = env.container.querySelector('[data-modal-root] .modal');
  check('reporte sin datos no envía', env.calls.posts.length === 0, JSON.stringify(env.calls.posts));
  check('error de campo tipo visible', !!modal.querySelector('[data-error="tipo"]:not(.hidden)'));

  // cargar tipo + descripción
  env.change(modal.querySelector('[data-modal-field="tipo"]'), 'DAÑO');
  env.input(modal.querySelector('[data-modal-field="descripcion"]'), 'Raspon en la caja 12');
  env.click(modal.querySelector('[data-action="submit-report"]'));
  await tick();

  const post = env.calls.posts[0];
  check('POST /inventory/incidents/report', post && post.path === '/inventory/incidents/report',
    JSON.stringify(post || null));
  check('payload del reporte', post.body.tipo === 'DAÑO' && post.body.descripcion === 'Raspon en la caja 12',
    JSON.stringify(post.body));
  check('cabecera Idempotency-Key', !!post.options.headers['Idempotency-Key']);
  check('toast de éxito', env.calls.toasts.some(t => t.type === 'success'));
  check('modal cerrado tras éxito', !env.container.querySelector('[data-modal-root] .modal'));
  check('listado recargado', env.calls.gets.filter(g => g.path === '/inventory/incidents').length >= 2);
  env.close();

  // 409: se renueva la clave
  const env2 = createEnv({
    perms: INCIDENT_PERMS, roles: ['ADMIN'],
    postImpl: async () => { throw Object.assign(new Error('Clave ya usada'), { status: 409, data: null }); }
  });
  env2.loadIncidents();
  await tick();
  env2.click(env2.container.querySelector('[data-action="open-report"]'));
  const modal2 = env2.container.querySelector('[data-modal-root] .modal');
  const keyBefore = modal2.querySelector('[data-key-hint]').textContent.trim();
  env2.change(modal2.querySelector('[data-modal-field="tipo"]'), 'OTRO');
  env2.input(modal2.querySelector('[data-modal-field="descripcion"]'), 'Prueba');
  env2.click(modal2.querySelector('[data-action="submit-report"]'));
  await tick();
  const modal2b = env2.container.querySelector('[data-modal-root] .modal');
  const keyAfter = modal2b.querySelector('[data-key-hint]').textContent.trim();
  check('409 renueva la clave del reporte', keyBefore !== keyAfter, `${keyBefore} vs ${keyAfter}`);
  check('409 toast de conflicto', env2.calls.toasts.some(t => t.type === 'warning' && t.title === 'Conflicto'));
  env2.close();

  // 404 de unidad inexistente: toast + error en línea
  const env3 = createEnv({
    perms: INCIDENT_PERMS, roles: ['ADMIN'],
    postImpl: async () => { throw Object.assign(new Error('La unidad física no existe.'), { status: 404, data: null }); }
  });
  env3.loadIncidents();
  await tick();
  env3.click(env3.container.querySelector('[data-action="open-report"]'));
  const modal3 = env3.container.querySelector('[data-modal-root] .modal');
  env3.change(modal3.querySelector('[data-modal-field="tipo"]'), 'PERDIDA');
  env3.input(modal3.querySelector('[data-modal-field="descripcion"]'), 'Sin unidad');
  env3.click(modal3.querySelector('[data-action="submit-report"]'));
  await tick();
  check('404 toast de no encontrado', env3.calls.toasts.some(t => t.type === 'danger' && t.title === 'No encontrado'));
  check('404 el modal sigue abierto', !!env3.container.querySelector('[data-modal-root] .modal'));
  env3.close();
}

// =====================================================================
// S4 - INCIDENCIAS: DETALLE, INVESTIGAR Y RESOLVER
// =====================================================================
async function s4_incidents_detail() {
  console.log('S4 incidencias: detalle, investigar y resolver');
  const env = createEnv({ perms: INCIDENT_PERMS, roles: ['ADMIN'] });
  env.loadIncidents();
  await tick();

  env.click(env.container.querySelector('[data-action="open-detail"]'));
  await tick();
  const modal = env.container.querySelector('[data-modal-root] .modal');
  check('detalle cargado por GET /incidents/i1',
    env.calls.gets.some(g => g.path === '/inventory/incidents/i1'), JSON.stringify(env.calls.gets));
  check('detalle con datos', !!modal && /i1/.test(modal.textContent) && /Revisado en muelle/.test(modal.textContent));
  check('detalle sin bloque de resolución', !/Resolución/.test(modal.textContent));
  check('pie con Resolver e Investigar', !!modal.querySelector('[data-action="open-resolve"]')
    && !!modal.querySelector('[data-action="investigate"]'));

  // investigar con confirm aceptada
  env.w.confirm = () => true;
  env.click(modal.querySelector('[data-action="investigate"]'));
  await tick();
  check('POST /inventory/incidents/i1/investigate',
    env.calls.posts.some(p => p.path === '/inventory/incidents/i1/investigate'), JSON.stringify(env.calls.posts));
  check('toast de investigación', env.calls.toasts.some(t => t.type === 'success'
    && /investigación/.test(t.title + t.message)));

  // resolver: validación local
  env.click(env.container.querySelector('[data-action="open-resolve"]'));
  const rModal = env.container.querySelector('[data-modal-root] .modal');
  check('modal resolver abierto', !!rModal && !!rModal.querySelector('[data-modal-field="descripcion_resolucion"]'));
  env.click(rModal.querySelector('[data-action="submit-resolve"]'));
  await tick();
  check('resolver sin datos no envía', !env.calls.posts.some(p => p.path.endsWith('/resolve')),
    JSON.stringify(env.calls.posts));
  check('error de campo resolución', !!rModal.querySelector('[data-error="descripcion_resolucion"]:not(.hidden)'));

  // resolver completo
  env.input(rModal.querySelector('[data-modal-field="descripcion_resolucion"]'), 'Unidad reubicada');
  env.input(rModal.querySelector('[data-modal-field="acciones_tomadas"]'), 'Se movió a zona de cuarentena');
  env.click(rModal.querySelector('[data-action="submit-resolve"]'));
  await tick();
  const resolve = env.calls.posts.find(p => p.path === '/inventory/incidents/i1/resolve');
  check('POST resolve', !!resolve, JSON.stringify(env.calls.posts));
  check('payload resolve', resolve && resolve.body.estado === 'RESUELTA'
    && resolve.body.descripcion_resolucion === 'Unidad reubicada'
    && resolve.body.acciones_tomadas === 'Se movió a zona de cuarentena', JSON.stringify(resolve && resolve.body));
  check('resolve con Idempotency-Key', resolve && !!resolve.options.headers['Idempotency-Key']);
  check('toast de cierre', env.calls.toasts.some(t => t.type === 'success' && /cerrada/.test(t.title + t.message)));
  check('modal cerrado tras resolver', !env.container.querySelector('[data-modal-root] .modal'));
  env.close();

  // 422 de transición de estado: recarga para reflejar el estado real
  const env2 = createEnv({
    perms: INCIDENT_PERMS, roles: ['ADMIN'],
    postImpl: async (p) => {
      if (p.endsWith('/investigate')) {
        throw Object.assign(
          new Error("La incidencia 'i1' no admite esta operación en el estado 'INVESTIGACION' (se esperaba 'ABIERTA')."),
          { status: 422, data: { field: 'estado', category: 'business' } }
        );
      }
      return { status: 200, data: { mensaje: 'ok' } };
    }
  });
  env2.loadIncidents();
  await tick();
  env2.w.confirm = () => true;
  env2.click(env2.container.querySelector('[data-action="investigate"]'));
  await tick();
  check('422 de estado toast', env2.calls.toasts.some(t => t.type === 'danger' && t.title === 'Operación rechazada'),
    JSON.stringify(env2.calls.toasts));
  check('422 de estado recarga el listado',
    env2.calls.gets.filter(g => g.path === '/inventory/incidents').length >= 2);
  env2.close();

  // sin resolve: no se ofrecen acciones de cierre
  const env3 = createEnv({ perms: REPORT_PERMS, roles: ['USER'] });
  env3.loadIncidents();
  await tick();
  const actions3 = env3.container.querySelector('tbody tr .table-actions').textContent;
  check('sin incident.resolve no hay Investigar/Resolver',
    !/Investigar/.test(actions3) && !/Resolver/.test(actions3), actions3);
  env3.close();
}

// =====================================================================
// S5 - CONTEOS: LISTADO, DETALLE Y RESULTADOS
// =====================================================================
async function s5_counts_results() {
  console.log('S5 conteos: listado, detalle, resultados y completar');
  const env = createEnv({ perms: COUNT_PERMS, roles: ['ADMIN'] });
  env.loadCounts();
  await tick();

  check('GET /inventory/counts',
    env.calls.gets.some(g => g.path === '/inventory/counts' && g.options.params.page === 1),
    JSON.stringify(env.calls.gets));
  const headers = [...env.container.querySelectorAll('thead th')].map(th => th.textContent.trim());
  check('columnas del listado', headers.includes('Conteo') && headers.includes('Estado')
    && headers.includes('Discrepancias') && headers.includes('Acciones'), JSON.stringify(headers));
  check('2 filas renderizadas', env.container.querySelectorAll('tbody tr').length === 2);
  check('resumen con total/pendientes/completados', /Conteos/.test(env.container.querySelector('[data-cnt-summary]').textContent));
  check('filtro de ubicación desde catálogo',
    !!env.container.querySelector('[data-filter="ubicacion_id"]')
    && env.container.querySelector('[data-filter="ubicacion_id"]').tagName === 'SELECT');

  // detalle PENDIENTE
  env.click(env.container.querySelector('[data-action="open-detail"]'));
  await tick();
  const modal = env.container.querySelector('[data-modal-root] .modal');
  check('detalle cargado por GET /counts/c1',
    env.calls.gets.some(g => g.path === '/inventory/counts/c1'), JSON.stringify(env.calls.gets));
  check('hoja teórica con 2 unidades', modal.querySelectorAll('[data-result]').length === 2,
    String(modal.querySelectorAll('[data-result]').length));
  check('resultado existente precargado', modal.querySelector('[data-result="u1"]').value === '5',
    modal.querySelector('[data-result="u1"]').value);
  check('pie con Registrar/Completar/Descargar', !!modal.querySelector('[data-action="submit-results"]')
    && !!modal.querySelector('[data-action="complete"]') && !!modal.querySelector('[data-action="download-sheet"]'));

  // resultados vacíos: no envía (se limpia la fila precargada)
  env.input(modal.querySelector('[data-result="u1"]'), '');
  env.click(modal.querySelector('[data-action="submit-results"]'));
  await tick();
  check('sin cantidades no envía', !env.calls.posts.some(p => p.path.endsWith('/results')),
    JSON.stringify(env.calls.posts));
  check('error en línea sin cantidades', !modal.querySelector('[data-form-error]').classList.contains('hidden'));

  // registrar resultados con las dos filas
  env.input(modal.querySelector('[data-result="u1"]'), '5');
  env.input(modal.querySelector('[data-result="u2"]'), '2');
  env.click(modal.querySelector('[data-action="submit-results"]'));
  await tick();
  const results = env.calls.posts.find(p => p.path === '/inventory/counts/c1/results');
  check('POST /inventory/counts/c1/results', !!results, JSON.stringify(env.calls.posts));
  check('payload de resultados', results && results.body.resultados.length === 2
    && results.body.resultados.some(r => r.unidad_fisica_id === 'u2' && r.cantidad_encontrada === 2),
    JSON.stringify(results && results.body));
  check('toast de resultados', env.calls.toasts.some(t => t.type === 'success' && /registrados/.test(t.title)));
  check('detalle recargado tras registrar',
    env.calls.gets.filter(g => g.path === '/inventory/counts/c1').length >= 2);
  env.close();

  // completar con confirm y 422 de faltantes
  const env2 = createEnv({
    perms: COUNT_PERMS, roles: ['ADMIN'],
    postImpl: async (p) => {
      if (p === '/inventory/counts/c1/complete') {
        throw Object.assign(new Error('Faltan resultados para 2 unidad(es): u1, u2.'),
          { status: 422, data: { field: 'resultados', category: 'business' } });
      }
      return { status: 200, data: { mensaje: 'ok' } };
    }
  });
  env2.loadCounts();
  await tick();
  env2.click(env2.container.querySelector('[data-action="open-detail"]'));
  await tick();
  env2.w.confirm = () => true;
  const modal2 = env2.container.querySelector('[data-modal-root] .modal');
  env2.click(modal2.querySelector('[data-action="complete"]'));
  await tick();
  check('422 de faltantes marca u1', !!env2.container.querySelector('[data-error="resultados__u1"]:not(.hidden)'));
  check('422 de faltantes marca u2', !!env2.container.querySelector('[data-error="resultados__u2"]:not(.hidden)'));
  check('422 de faltantes muestra el mensaje',
    /Faltan resultados para 2 unidad/.test(env2.container.querySelector('[data-form-error]').textContent));
  check('422 de faltantes toast', env2.calls.toasts.some(t => t.type === 'danger' && /Faltan resultados/.test(t.message)));
  check('el modal sigue abierto', !!env2.container.querySelector('[data-modal-root] .modal'));
  env2.close();

  // completar exitoso
  const env3 = createEnv({ perms: COUNT_PERMS, roles: ['ADMIN'] });
  env3.loadCounts();
  await tick();
  env3.click(env3.container.querySelector('[data-action="open-detail"]'));
  await tick();
  env3.w.confirm = () => true;
  env3.click(env3.container.querySelector('[data-modal-root] [data-action="complete"]'));
  await tick();
  check('POST /inventory/counts/c1/complete', env3.calls.posts.some(p => p.path === '/inventory/counts/c1/complete'),
    JSON.stringify(env3.calls.posts));
  check('toast de conteo completado', env3.calls.toasts.some(t => t.type === 'success' && /completado/i.test(t.title)));
  env3.close();

  // sin count.create: el detalle es de sólo lectura
  const env4 = createEnv({ perms: ['inventory.count.view'], roles: ['VIEWER'] });
  env4.loadCounts();
  await tick();
  env4.click(env4.container.querySelector('[data-action="open-detail"]'));
  await tick();
  const modal4 = env4.container.querySelector('[data-modal-root] .modal');
  check('sin count.create no hay inputs de conteo', modal4.querySelectorAll('[data-result]').length === 0);
  check('sin count.create no hay botones de envío', !modal4.querySelector('[data-action="submit-results"]'));
  env4.close();
}

// =====================================================================
// S6 - CONTEOS: RECONCILIAR, INICIAR Y HOJA CSV
// =====================================================================
async function s6_counts_reconcile() {
  console.log('S6 conteos: reconciliar, iniciar conteo y hoja CSV');
  const env = createEnv({ perms: COUNT_PERMS, roles: ['ADMIN'] });
  env.loadCounts();
  await tick();

  // detalle COMPLETADO con 1 discrepancia pendiente
  env.change(env.container.querySelector('[data-filter="estado"]'), 'COMPLETADO');
  env.click(env.container.querySelector('[data-action="apply-filters"]'));
  await tick();
  const rows = env.container.querySelectorAll('tbody tr');
  const reconciliarBtn = [...rows].map(r => r.querySelector('.table-actions')).find(td => /Reconciliar/.test(td.textContent));
  check('fila COMPLETADO ofrece Reconciliar', !!reconciliarBtn);

  env.click(reconciliarBtn.querySelector('[data-action="open-detail"]'));
  await tick();
  const modal = env.container.querySelector('[data-modal-root] .modal');
  check('detalle c2 cargado', env.calls.gets.some(g => g.path === '/inventory/counts/c2'));
  check('tabla de discrepancias con select', !!modal.querySelector('[data-accion="u2"]'));
  check('pie con Reconciliar', !!modal.querySelector('[data-action="submit-reconcile"]'));

  // sin acción seleccionada
  env.click(modal.querySelector('[data-action="submit-reconcile"]'));
  await tick();
  check('reconciliar sin acciones no envía',
    !env.calls.posts.some(p => p.path.endsWith('/reconcile')), JSON.stringify(env.calls.posts));
  check('error en línea sin acciones', !modal.querySelector('[data-form-error]').classList.contains('hidden'));

  // JUSTIFICAR sin justificación
  env.change(modal.querySelector('[data-accion="u2"]'), 'JUSTIFICAR');
  env.click(modal.querySelector('[data-action="submit-reconcile"]'));
  await tick();
  check('JUSTIFICAR sin justificación no envía',
    !env.calls.posts.some(p => p.path.endsWith('/reconcile')));
  check('error de campo justificación',
    !!env.container.querySelector('[data-error="justificacion__u2"]:not(.hidden)'));

  // JUSTIFICAR completo
  env.input(env.container.querySelector('[data-justificacion="u2"]'), 'Diferencia autorizada en almacén');
  env.click(env.container.querySelector('[data-action="submit-reconcile"]'));
  await tick();
  const rec = env.calls.posts.find(p => p.path === '/inventory/counts/c2/reconcile');
  check('POST /inventory/counts/c2/reconcile', !!rec, JSON.stringify(env.calls.posts));
  check('payload de reconciliación', rec && rec.body.acciones.length === 1
    && rec.body.acciones[0].unidad_fisica_id === 'u2'
    && rec.body.acciones[0].accion === 'JUSTIFICAR'
    && rec.body.acciones[0].justificacion === 'Diferencia autorizada en almacén',
    JSON.stringify(rec && rec.body));
  check('reconcile con Idempotency-Key', rec && !!rec.options.headers['Idempotency-Key']);
  check('toast de reconciliación', env.calls.toasts.some(t => t.type === 'success' && /reconciliadas/.test(t.title)));
  env.close();

  // 422 de cantidad vencida: la fila pasa a JUSTIFICAR
  const env2 = createEnv({
    perms: COUNT_PERMS, roles: ['ADMIN'],
    postImpl: async (p) => {
      if (p === '/inventory/counts/c2/reconcile') {
        throw Object.assign(new Error(
          "La unidad física 'u2' ya no coincide con la hoja teórica del conteo: cantidad_esperada 3.0000, cantidad actual 5.0000. No se ajusta sobre datos obsoletos: registre la acción JUSTIFICAR con justificación o inicie un conteo nuevo."
        ), { status: 422, data: { field: 'cantidad', category: 'business' } });
      }
      return { status: 200, data: { mensaje: 'ok' } };
    }
  });
  env2.loadCounts();
  await tick();
  env2.click([...env2.container.querySelectorAll('[data-action="open-detail"]')].pop());
  await tick();
  const modal2 = env2.container.querySelector('[data-modal-root] .modal');
  env2.change(modal2.querySelector('[data-accion="u2"]'), 'AJUSTAR');
  env2.click(modal2.querySelector('[data-action="submit-reconcile"]'));
  await tick();
  check('422 cantidad vencida cambia a JUSTIFICAR',
    env2.container.querySelector('[data-accion="u2"]').value === 'JUSTIFICAR',
    env2.container.querySelector('[data-accion="u2"]').value);
  check('422 cantidad vencida toast de hoja vencida',
    env2.calls.toasts.some(t => t.type === 'warning' && /Hoja teórica vencida/.test(t.title)),
    JSON.stringify(env2.calls.toasts));
  check('422 cantidad vencida marca la justificación',
    !!env2.container.querySelector('[data-error="justificacion__u2"]:not(.hidden)'));
  check('422 cantidad vencida el modal sigue abierto',
    !!env2.container.querySelector('[data-modal-root] .modal'));
  env2.close();

  // modal iniciar conteo
  const env3 = createEnv({ perms: COUNT_PERMS, roles: ['ADMIN'] });
  env3.postImpl = async () => ({
    status: 201,
    data: { mensaje: 'Conteo físico iniciado.', conteo: { conteo_id: 'c3' }, unidades_en_lista: 2 }
  });
  env3.loadCounts();
  await tick();
  env3.click(env3.container.querySelector('[data-action="open-start"]'));
  const sModal = env3.container.querySelector('[data-modal-root] .modal');
  check('modal de inicio abierto', !!sModal && !!sModal.querySelector('[data-modal-field="ubicacion_id"]'));
  check('inicio con Idempotency-Key', /Idempotency-Key/.test(sModal.textContent));
  env3.change(sModal.querySelector('[data-modal-field="ubicacion_id"]'), 'loc1');
  env3.click(sModal.querySelector('[data-action="submit-start"]'));
  await tick();
  const start = env3.calls.posts.find(p => p.path === '/inventory/counts/start');
  check('POST /inventory/counts/start', !!start, JSON.stringify(env3.calls.posts));
  check('payload de inicio', start && start.body.ubicacion_id === 'loc1', JSON.stringify(start && start.body));
  check('start con Idempotency-Key', start && !!start.options.headers['Idempotency-Key']);
  check('abre el detalle del conteo nuevo',
    env3.calls.gets.some(g => g.path === '/inventory/counts/c3'), JSON.stringify(env3.calls.gets));
  env3.close();

  // hoja CSV
  const env4 = createEnv({ perms: COUNT_PERMS, roles: ['ADMIN'] });
  env4.loadCounts();
  await tick();
  env4.click(env4.container.querySelector('[data-action="open-detail"]'));
  await tick();
  env4.click(env4.container.querySelector('[data-modal-root] [data-action="download-sheet"]'));
  await tick();
  const csv = env4.calls.gets.find(g => g.path === '/inventory/counts/c1/sheet?format=csv');
  check('GET de la hoja CSV', !!csv, JSON.stringify(env4.calls.gets.map(g => g.path)));
  check('hoja CSV con rawResponse', csv && csv.options.rawResponse === true, JSON.stringify(csv && csv.options));
  check('nombre del archivo descargado', env4.calls.downloads.includes('conteo-c1.csv'),
    JSON.stringify(env4.calls.downloads));
  env4.close();

  // sin count.view: pantalla de sin permiso
  const env5 = createEnv({ perms: ['inventory.stock.view'], roles: ['USER'] });
  env5.loadCounts();
  await tick();
  check('sin count.view muestra aviso', /Sin permiso/.test(env5.container.textContent));
  check('sin count.view no pide datos', env5.calls.gets.length === 0, JSON.stringify(env5.calls.gets));
  env5.close();
}

// =====================================================================
// S7 - SHELL: LOADER, ROUTER, SIDEBAR E INDEX.PHP
// =====================================================================
async function s7_shell() {
  console.log('S7 shell: loader, router, sidebar y index.php');

  const env = createEnv({ perms: INCIDENT_PERMS, roles: ['ADMIN'] });
  env.evalIn(FILES.loader);
  env.evalIn(FILES.router);
  const w = env.w;

  check('módulo inventory/incidents registrado', w.ModuleLoader.has('inventory/incidents'));
  check('módulo inventory/counts registrado', w.ModuleLoader.has('inventory/counts'));
  check('global key incidencias', w.ModuleLoader.resolveGlobalKey('inventory/incidents') === 'InventoryIncidentsModule',
    w.ModuleLoader.resolveGlobalKey('inventory/incidents'));
  check('global key conteos', w.ModuleLoader.resolveGlobalKey('inventory/counts') === 'InventoryCountsModule',
    w.ModuleLoader.resolveGlobalKey('inventory/counts'));
  check('ruta incidents en router', w.AppRouter.getRoutes()['inventory/incidents']
    && w.AppRouter.getRoutes()['inventory/incidents'].module === 'inventory/incidents');
  check('ruta counts en router', w.AppRouter.getRoutes()['inventory/counts']
    && w.AppRouter.getRoutes()['inventory/counts'].module === 'inventory/counts');
  check('isModuleAvailable incidents', w.AppRouter.isModuleAvailable('inventory/incidents') === true);
  check('isModuleAvailable counts', w.AppRouter.isModuleAvailable('inventory/counts') === true);
  env.close();

  // sidebar con permisos reales de incidencias y conteos
  const envA = createEnv({
    perms: ['inventory.incident.view', 'inventory.count.view', 'inventory.stock.view'],
    roles: ['USER']
  });
  envA.evalIn(FILES.loader);
  envA.evalIn(FILES.router);
  envA.evalIn(FILES.sidebar);
  envA.w.AppSidebar.init();
  const items = [...envA.w.document.querySelectorAll('.sidebar-item')];
  const routes = items.map(i => i.dataset.route);
  check('ve Incidencias', routes.includes('inventory/incidents'), JSON.stringify(routes));
  check('ve Conteos', routes.includes('inventory/counts'), JSON.stringify(routes));
  const incItem = items.find(i => i.dataset.route === 'inventory/incidents');
  check('icono SVG de Incidencias', !!incItem && incItem.innerHTML.includes('<svg'));
  const cntItem = items.find(i => i.dataset.route === 'inventory/counts');
  check('icono SVG de Conteos', !!cntItem && cntItem.innerHTML.includes('<svg'));
  envA.close();

  // sin permisos de vista no aparecen en el menú
  const envB = createEnv({ perms: ['inventory.stock.view'], roles: ['USER'] });
  envB.evalIn(FILES.loader);
  envB.evalIn(FILES.router);
  envB.evalIn(FILES.sidebar);
  envB.w.AppSidebar.init();
  const routesB = [...envB.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('sin permiso no ve Incidencias', !routesB.includes('inventory/incidents'), JSON.stringify(routesB));
  check('sin permiso no ve Conteos', !routesB.includes('inventory/counts'), JSON.stringify(routesB));
  envB.close();

  // ADMIN lo ve todo
  const envC = createEnv({ perms: [], roles: ['ADMIN'] });
  envC.evalIn(FILES.loader);
  envC.evalIn(FILES.router);
  envC.evalIn(FILES.sidebar);
  envC.w.AppSidebar.init();
  const routesC = [...envC.w.document.querySelectorAll('.sidebar-item')].map(i => i.dataset.route);
  check('ADMIN ve Incidencias y Conteos',
    routesC.includes('inventory/incidents') && routesC.includes('inventory/counts'), JSON.stringify(routesC));
  envC.close();

  // index.php carga ui/* antes de los scripts del shell (sidebar)
  const index = readPublic('index.php');
  const uiOrder = ['js/ui/dom.js', 'js/ui/render.js', 'js/ui/modal.js', 'js/ui/actions.js']
    .map(s => index.indexOf(s));
  check('index.php carga los 4 ficheros de ui/', uiOrder.every(i => i >= 0), JSON.stringify(uiOrder));
  check('index.php los carga después de core/idle.js y antes del shell',
    uiOrder.every(i => i > index.indexOf('js/core/idle.js'))
    && uiOrder.every(i => i < index.indexOf('js/modules/dashboard/sidebar.js')),
    JSON.stringify(uiOrder) + ' idle=' + index.indexOf('js/core/idle.js')
    + ' sidebar=' + index.indexOf('js/modules/dashboard/sidebar.js'));
}

// =====================================================================
async function main() {
  await s1_ui_components();
  await s2_incidents_list();
  await s3_incidents_report();
  await s4_incidents_detail();
  await s5_counts_results();
  await s6_counts_reconcile();
  await s7_shell();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
