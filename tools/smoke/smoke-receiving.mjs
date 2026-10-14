/**
 * Smoke test jsdom para inventory/receiving.js (v1.13.32: recepción contra
 * documento + planificación; sin cegueza, sin reconteo, sin registro CU-001).
 * Uso: node tools/smoke/smoke-receiving.mjs   (desde C:/xampp/htdocs/xp)
 *
 * Cubre:
 *  - Pestañas por permisos: .plan+.create → ' receive,plan (2 pestañas);
 *    sólo .create → ' receive (usa GET /pending); sólo .plan → ' plan;
 *    ninguno → deniedState. NO existe pestaña register.
 *  - plan: listado con acciones (UI.rowActions), paginación
 *    (UI.renderPagination con pagination/shown/label), editor de líneas con
 *    cantidad_esperada, validaciÃ³n de nÃºmero/lÃ­neas, aviso de segundo receptor.
 *  - receive: fuente GET /inventory/receiving/documents/pending; la
 *    cantidad_esperada se PINTA bloqueada (readonly/disabled) y NO se envÃ­a
 *    en el payload; envÃ­o con Idempotency-Key al endpoint del documento;
 *    rechazo_total deshabilita las cantidades contadas; un receptor puro
 *    (sÃ³lo .create) SÃ puede recibir (endpoint pending con .create).
 *  - MÃ³dulo registrado en ModuleLoader/AppRouter.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

const FILES = {
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

const PRODUCTS = [
  { id: 'p1', sku: 'PROD2000', nombre: 'Producto Demo', activo: true },
  { id: 'p2', sku: 'PROD3000', nombre: 'Otro Producto', activo: true }
];

const LOCATIONS = [
  { id: 'loc1', codigo: 'HOUSEA23', nombre: 'A-2-3', tipo: 'ALMACENAMIENTO', capacidad: null, capacidad_usada: 0, activa: true },
  { id: 'loc2', codigo: 'DANOS01', nombre: 'Zona danados', tipo: 'ALMACENAMIENTO', capacidad: 10, capacidad_usada: 10, activa: true }
];

const DOCS_LIST = [
  { id: 'd1', numero_documento: 'DOC-001', proveedor: 'ACME', referencia: 'REF-1', estado: 'PLANIFICADA', creado_por: 'user-plan', created_at: '2026-10-10 09:00:00', updated_at: '2026-10-10 09:00:00', lineas_totales: 1, lineas_recibidas: 0 },
  { id: 'd2', numero_documento: 'DOC-002', proveedor: 'Globex', referencia: 'REF-2', estado: 'EN_RECEPCION', creado_por: 'user-plan', created_at: '2026-10-11 10:00:00', updated_at: '2026-10-11 10:00:00', lineas_totales: 1, lineas_recibidas: 0 }
];

const DOCS_PAYLOAD = {
  status: 200,
  data: { data: DOCS_LIST, pagination: { total: 2, page: 1, per_page: 20, has_next: false } }
};

const PENDING_LIST = [
  {
    id: 'd1', numero_documento: 'DOC-001', proveedor: 'ACME', referencia: 'REF-1', estado: 'PLANIFICADA',
    creado_por: 'user-plan', created_at: '2026-10-10 09:00:00', updated_at: '2026-10-10 09:00:00',
    lineas_totales: 1, lineas_recibidas: 0,
    lineas: [{ id: 'l1', producto_id: 'p1', sku: 'PROD2000', cantidad_esperada: 5, estado: 'PENDIENTE', unidad_recepcion_id: null }]
  },
  {
    id: 'd2', numero_documento: 'DOC-002', proveedor: 'Globex', referencia: 'REF-2', estado: 'EN_RECEPCION',
    creado_por: 'user-plan', created_at: '2026-10-11 10:00:00', updated_at: '2026-10-11 10:00:00',
    lineas_totales: 1, lineas_recibidas: 0,
    lineas: [{ id: 'l2', producto_id: 'p2', sku: 'PROD3000', cantidad_esperada: 3, estado: 'PENDIENTE', unidad_recepcion_id: null }]
  }
];

const PENDING_PAYLOAD = {
  status: 200,
  data: { data: PENDING_LIST, pagination: { total: 2, page: 1, per_page: 100, has_next: false } }
};

const PRODUCTS_PAYLOAD = { status: 200, data: { data: PRODUCTS, pagination: { total: 2, page: 1, per_page: 100 } } };
const LOCATIONS_PAYLOAD = { status: 200, data: { data: LOCATIONS, pagination: { total: 2, page: 1, per_page: 100 } } };

const RECEIVE_RESULT_OK = {
  status: 200,
  data: {
    mensaje: 'ok', documento_id: 'd1', numero_documento: 'DOC-001', estado_documento: 'CERRADA',
    resultado: 'RECIBIDO', rechazo_total: false,
    lineas: [{ line_id: 'l1', sku: 'PROD2000', estado_linea: 'RECIBIDA', cantidad_recibida: 5, unidad_fisica_id: 'u1', incidencia_id: null }]
  }
};

// =====================================================================
// ENTORNO
// =====================================================================

function createEnv({ perms = [], roles = ['ADMIN'], getHandlers = {}, patchImpl = null, postImpl = null, confirmResult = true } = {}) {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="sidebar"></div><div id="module-container"></div></body></html>',
    { url: 'http://localhost/xp/public/#/inventory/receiving', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;

  const calls = { gets: [], posts: [], patches: [], toasts: [], errors: [], confirms: [] };

  Object.defineProperty(w.Element.prototype, 'scrollIntoView', { value: function () {}, configurable: true });
  w.console = {
    log() {}, info() {}, debug() {},
    warn(...a) { calls.errors.push(a.map(String).join(' ')); },
    error(...a) { calls.errors.push(a.map(String).join(' ')); }
  };
  Object.defineProperty(w, 'crypto', {
    configurable: true, writable: true,
    value: { randomUUID: (() => { let n = 0; return () => `00000000-0000-4000-8000-${String(++n).padStart(12, '0')}`; })() }
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

  const flags = { confirm: confirmResult };
  w.confirm = () => { calls.confirms.push(true); return flags.confirm; };

  const env = { w, calls, patchImpl, postImpl, dom, flags };

  w.HttpClient = {
    async get(p, options = {}) {
      calls.gets.push({ path: p, options });
      if (getHandlers[p]) return getHandlers[p](options, env);
      if (p === '/inventory/receiving/documents') return DOCS_PAYLOAD;
      if (p === '/inventory/receiving/documents/pending') return PENDING_PAYLOAD;
      if (p === '/inventory/products') return PRODUCTS_PAYLOAD;
      if (p === '/inventory/locations') return LOCATIONS_PAYLOAD;
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
    },
    async patch(p, body, options = {}) {
      calls.patches.push({ path: p, body, options });
      if (env.patchImpl) return env.patchImpl(p, body, options, env);
      return { status: 200, data: { mensaje: 'ok' } };
    }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  evalIn(read('core/i18n.js'));
  w.i18n.inject('es', ES_DICT);
  const evalUi = () => UI_FILES.forEach(evalIn);
  env.evalUi = evalUi;

  env.loadReceiving = () => {
    evalUi();
    evalIn(FILES.receiving);
    w.InventoryReceivingModule.init({ tab: 'receive' }, w.document.getElementById('module-container'));
  };
  env.loadReceivingWithTab = (tab) => {
    evalUi();
    evalIn(FILES.receiving);
    w.InventoryReceivingModule.init({ tab }, w.document.getElementById('module-container'));
  };

  env.container = w.document.getElementById('module-container');
  env.click = (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  env.change = (el, value) => { el.value = value; el.dispatchEvent(new w.Event('change', { bubbles: true })); };
  env.input = (el, value) => { el.value = value; el.dispatchEvent(new w.Event('input', { bubbles: true })); };
  env.close = () => dom.window.close();

  return env;
}

const PLAN_PERMS = ['inventory.receiving.plan'];
const CREATE_PERMS = ['inventory.receiving.create'];
const BOTH_PERMS = ['inventory.receiving.plan', 'inventory.receiving.create'];

// =====================================================================
// S1 - PESTAÑAS POR PERMISOS (2 pestañas, sin register)
// =====================================================================
async function s1_tabs_by_perms() {
  console.log('S1 pestañas: gating por permisos (sin register)');

  {
    const env = createEnv({ perms: BOTH_PERMS, roles: ['ADMIN'] });
    env.loadReceiving();
    await tick();
    const tabs = [...env.container.querySelectorAll('[data-action="tab"]')].map(b => b.getAttribute('data-tab'));
    check('plan+create → 2 pestañas (receive,plan)', tabs.join(',') === 'receive,plan', tabs.join(','));
    check('panel receive activo por defecto', !!env.container.querySelector('[data-panel="receive"]:not(.hidden)'));
    check('sin panel register', !env.container.querySelector('[data-panel="register"]'));
    env.close();
  }

  {
    const env = createEnv({ perms: CREATE_PERMS, roles: ['USER'] });
    env.loadReceiving();
    await tick();
    const tabs = [...env.container.querySelectorAll('[data-action="tab"]')].map(b => b.getAttribute('data-tab'));
    check('sólo create → barra oculta (1 pestaña, sin botones)', tabs.length === 0, tabs.join(','));
    check('panel receive visible', !!env.container.querySelector('[data-panel="receive"]:not(.hidden)'));
    check('sin panel plan', !env.container.querySelector('[data-panel="plan"]'));
    check('sin panel register', !env.container.querySelector('[data-panel="register"]'));
    env.close();
  }

  {
    const env = createEnv({ perms: PLAN_PERMS, roles: ['MANAGER'] });
    env.loadReceivingWithTab('plan');
    await tick();
    const tabs = [...env.container.querySelectorAll('[data-action="tab"]')].map(b => b.getAttribute('data-tab'));
    check('sólo plan → sólo botón plan', tabs.length <= 1 && tabs.every(id => id === 'plan'), tabs.join(','));
    check('panel plan visible', !!env.container.querySelector('[data-panel="plan"]:not(.hidden)'));
    check('sin panel receive', !env.container.querySelector('[data-panel="receive"]'));
    env.close();
  }

  {
    const env = createEnv({ perms: [], roles: [] });
    env.loadReceiving();
    await tick();
    check('sin permisos ni roles → deniedState', /max-w-md/.test(env.container.innerHTML) && !env.container.querySelector('[data-panel="receive"]'), env.container.innerHTML.slice(0, 200));
    env.close();
  }
}

// =====================================================================
// S2 - PLAN: LISTADO + PAGINACIÓN + ROWACTIONS
// =====================================================================
async function s2_plan_list() {
  console.log('S2 plan: listado, paginación y rowActions');
  const env = createEnv({ perms: PLAN_PERMS, roles: ['MANAGER'] });
  env.loadReceivingWithTab('plan');
  await tick(12);

  check('GET documentos disparado', env.calls.gets.some(c => c.path === '/inventory/receiving/documents'), JSON.stringify(env.calls.gets.map(c => c.path)));
  check('tabla con datos', /DOC-001/.test(env.container.innerHTML) && /DOC-002/.test(env.container.innerHTML));
  check('badge estado PLANIFICADA', /badge/.test(env.container.innerHTML));

  const editBtn = env.container.querySelector('[data-action="plan-edit"]');
  check('rowActions: botón editar (primary)', !!editBtn && editBtn.getAttribute('data-id') === 'd1');
  const cancelBtn = env.container.querySelector('[data-action="plan-cancel"]');
  check('rowActions: menú con cancelar (danger)', !!cancelBtn);

  const pagRoot = env.container.querySelector('[data-plan-pagination]');
  check('raíz de paginación presente', !!pagRoot);
  check('sin paginación si total<=per_page (2<=20)', !pagRoot.querySelector('[data-page]'));

  env.close();
}

// =====================================================================
// S3 - PLAN: EDITOR + VALIDACIONES + GUARDADO + AVISO SEGUNDO RECEPTOR
// =====================================================================
async function s3_plan_editor() {
  console.log('S3 plan: editor de líneas con cantidad_esperada');
  const env = createEnv({ perms: PLAN_PERMS, roles: ['MANAGER'] });
  env.loadReceivingWithTab('plan');
  await tick(12);

  env.click(env.container.querySelector('[data-action="plan-new"]'));
  await tick(2);
  check('editor abierto', !!env.container.querySelector('[data-plan-form]'));
  check('campo numero_documento', !!env.container.querySelector('[name="numero_documento"]'));
  check('campo cantidad_esperada presente (plan conoce la esperada)', !!env.container.querySelector('[data-line-cantidad]'));

  env.click(env.container.querySelector('[data-action="plan-save"]'));
  await tick(2);
  const errNumero = env.container.querySelector('[data-error="numero_documento"]')?.textContent || '';
  check('valida numero_documento vacío', /documento|obligatorio/i.test(errNumero), errNumero);

  env.input(env.container.querySelector('[name="numero_documento"]'), 'DOC-NEW');
  const skuInput = env.container.querySelector('[data-line-sku]');
  const cantInput = env.container.querySelector('[data-line-cantidad]');
  check('inputs de línea presentes', !!skuInput && !!cantInput);
  env.input(skuInput, 'PROD2000');
  env.input(cantInput, '3');

  let saved = null;
  env.postImpl = (p, body, options) => { saved = { p, body, options }; return { status: 201, data: { mensaje: 'ok' } }; };
  env.click(env.container.querySelector('[data-action="plan-save"]'));
  await tick(6);

  check('POST /inventory/receiving/documents', saved && saved.p === '/inventory/receiving/documents', JSON.stringify(saved));
  check('body con numero_documento', saved && saved.body.numero_documento === 'DOC-NEW');
  check('body con lineas[0].cantidad_esperada', saved && saved.body.lineas[0].cantidad_esperada === 3, JSON.stringify(saved && saved.body));
  check('toast de guardado', env.calls.toasts.length > 0);

  // Aviso de separación de funciones: 409 con mensaje de segundo receptor.
  // Tras un guardado con éxito el editor se cierra: se reabre (plan-new).
  env.click(env.container.querySelector('[data-action="plan-new"]'));
  await tick(2);
  env.input(env.container.querySelector('[name="numero_documento"]'), 'DOC-NEW2');
  env.input(env.container.querySelector('[data-line-sku]'), 'PROD2000');
  env.input(env.container.querySelector('[data-line-cantidad]'), '3');
  env.postImpl = () => {
    throw Object.assign(new Error('Se requiere otro usuario con permiso de recepción: quien planifica no puede ser el único receptor del tenant.'), {
      status: 409, data: null
    });
  };
  env.click(env.container.querySelector('[data-action="plan-save"]'));
  await tick(8);
  const warned = env.calls.toasts.some(t => /único receptor|otro usuario/i.test(String(t.message || '')));
  check('409 segundo receptor → toast de aviso', warned, JSON.stringify(env.calls.toasts.map(t => t.message)));

  env.close();
}

// =====================================================================
// S4 - PLAN: CANCELAR (destructiva con confirmación)
// =====================================================================
async function s4_plan_cancel() {
  console.log('S4 plan: cancelar documento con confirmación');
  const env = createEnv({ perms: PLAN_PERMS, roles: ['MANAGER'], confirmResult: false });
  env.loadReceivingWithTab('plan');
  await tick(12);

  env.click(env.container.querySelector('[data-action="plan-cancel"]'));
  await tick(4);
  check('confirmación mostrada', env.calls.confirms.length === 1);
  check('cancel sin confirmar NO llama al backend', env.calls.posts.filter(c => c.path.endsWith('/cancel')).length === 0);

  env.flags.confirm = true;
  let cancelled = null;
  env.postImpl = (p, body, options) => { cancelled = { p, body, options }; return { status: 200, data: { mensaje: 'ok' } }; };
  env.click(env.container.querySelector('[data-action="plan-cancel"]'));
  await tick(6);
  check('cancel con confirmar → POST .../cancel', cancelled && cancelled.p === '/inventory/receiving/documents/d1/cancel', JSON.stringify(cancelled));

  env.close();
}

// =====================================================================
// S5 - RECEIVE: ESPERADA VISIBLE BLOQUEADA + PAYLOAD SIN ESPERADA
// =====================================================================
async function s5_receive_expected_visible() {
  console.log('S5 receive: esperada visible bloqueada, payload sin cantidad_esperada');
  const env = createEnv({ perms: BOTH_PERMS, roles: ['ADMIN'] });
  env.loadReceivingWithTab('receive');
  await tick(14);

  check('GET pending disparado', env.calls.gets.some(c => c.path === '/inventory/receiving/documents/pending'), JSON.stringify(env.calls.gets.map(c => c.path)));

  const sel = env.container.querySelector('[data-receive-doc-select]');
  check('selector de documentos presente', !!sel);

  env.change(sel, 'd1');
  await tick(4);

  const detail = env.container.querySelector('[data-receive-doc-detail]');
  const detailHtml = detail ? detail.innerHTML : '';
  check('detalle de documento cargado', /PROD2000/.test(detailHtml));
  const esperada = detail && detail.querySelector('[data-line-esperada]');
  check('cantidad esperada PINTADA (visible)', !!esperada && esperada.value === '5', esperada && esperada.value);
  check('cantidad esperada BLOQUEADA (readonly+disabled)', !!esperada && esperada.hasAttribute('readonly') && esperada.disabled);
  const recibida = detail && detail.querySelector('[data-line-recibida]');
  check('campo cantidad_recibida editable presente', !!recibida && !recibida.disabled);
  const ubicacion = env.container.querySelector('[data-receive-ubicacion]');
  check('campo ubicacion presente', !!ubicacion);
  const rechazo = detail && detail.querySelector('[data-rechazo-total]');
  check('checkbox rechazo_total presente', !!rechazo);

  env.input(recibida, '5');
  env.change(ubicacion, 'HOUSEA23');

  let received = null;
  env.postImpl = (p, body, options) => {
    received = { p, body, options };
    return RECEIVE_RESULT_OK;
  };
  const submitBtn = env.container.querySelector('[data-action="submit-doc-receive"]');
  check('botón de confirmar presente', !!submitBtn);
  env.click(submitBtn);
  await tick(8);

  check('POST .../receive', received && received.p === '/inventory/receiving/documents/d1/receive', JSON.stringify(received && received.p));
  check('Idempotency-Key enviada', received && !!received.options.headers && !!received.options.headers['Idempotency-Key'], JSON.stringify(received && received.options));
  check('body SIN cantidad_esperada', received && received.body.lineas[0].cantidad_esperada === undefined, JSON.stringify(received && received.body));
  check('body CON cantidad_recibida', received && received.body.lineas[0].cantidad_recibida === 5);
  check('body SIN rechazo_total (falso no se envía)', received && received.body.rechazo_total === undefined);
  check('resultado pintado con badge RECIBIDA', /badge/.test(env.container.querySelector('[data-receive-result]').innerHTML));

  env.close();
}

// =====================================================================
// S6 - RECEIVE: RECEPTOR PURO (sólo .create) SÍ RECIBE (endpoint pending)
// =====================================================================
async function s6_receive_pure_creator() {
  console.log('S6 receive: receptor puro (sólo .create) recibe vía /pending');
  const env = createEnv({ perms: CREATE_PERMS, roles: ['USER'] });
  env.loadReceivingWithTab('receive');
  await tick(14);

  check('GET pending disparado (no /documents)', env.calls.gets.some(c => c.path === '/inventory/receiving/documents/pending'));
  const sel = env.container.querySelector('[data-receive-doc-select]');
  check('selector de documentos presente', !!sel);

  env.change(sel, 'd1');
  await tick(4);
  const recibida = env.container.querySelector('[data-line-recibida]');
  check('campo cantidad_recibida presente para receptor puro', !!recibida);

  env.input(recibida, '5');
  env.change(env.container.querySelector('[data-receive-ubicacion]'), 'HOUSEA23');

  let received = null;
  env.postImpl = (p, body, options) => { received = { p, body, options }; return RECEIVE_RESULT_OK; };
  env.click(env.container.querySelector('[data-action="submit-doc-receive"]'));
  await tick(8);

  check('POST .../receive para receptor puro', received && received.p === '/inventory/receiving/documents/d1/receive', JSON.stringify(received && received.p));

  env.close();
}

// =====================================================================
// S7 - RECEIVE: RECHAZO TOTAL
// =====================================================================
async function s7_receive_rechazo_total() {
  console.log('S7 receive: rechazo total cierra sin cantidades');
  const env = createEnv({ perms: BOTH_PERMS, roles: ['ADMIN'] });
  env.loadReceivingWithTab('receive');
  await tick(14);

  env.change(env.container.querySelector('[data-receive-doc-select]'), 'd1');
  await tick(4);

  const rechazo = env.container.querySelector('[data-rechazo-total]');
  check('checkbox rechazo_total presente', !!rechazo);
  rechazo.checked = true;
  rechazo.dispatchEvent(new env.w.Event('change', { bubbles: true }));
  await tick(2);

  const recibida = env.container.querySelector('[data-line-recibida]');
  check('cantidad_recibida deshabilitada al marcar rechazo', recibida && recibida.disabled);

  env.change(env.container.querySelector('[data-receive-ubicacion]'), 'HOUSEA23');

  let received = null;
  env.postImpl = (p, body, options) => {
    received = { p, body, options };
    return {
      status: 200,
      data: {
        mensaje: 'Recepción procesada: entrega rechazada con incidencia de prioridad alta.',
        documento_id: 'd1', numero_documento: 'DOC-001', estado_documento: 'CERRADA',
        resultado: 'RECHAZADA', rechazo_total: true,
        lineas: [{ line_id: 'l1', sku: 'PROD2000', estado_linea: 'RECHAZADA', cantidad_recibida: 0, unidad_fisica_id: null, incidencia_id: 'i1' }]
      }
    };
  };
  env.click(env.container.querySelector('[data-action="submit-doc-receive"]'));
  await tick(8);

  check('body con rechazo_total: true', received && received.body.rechazo_total === true, JSON.stringify(received && received.body));
  check('body sin cantidad_recibida en rechazo', received && received.body.lineas[0].cantidad_recibida === undefined);
  check('resultado con badge RECHAZADA', /badge/.test(env.container.querySelector('[data-receive-result]').innerHTML));

  env.close();
}

// =====================================================================
// S8 - MÓDULO REGISTRADO EN LOADER/ROUTER
// =====================================================================
async function s8_loader_registration() {
  console.log('S8 loader: registro del módulo receiving');
  const env = createEnv({ perms: BOTH_PERMS, roles: ['ADMIN'] });
  env.evalUi();
  env.evalIn(FILES.loader);
  env.evalIn(FILES.router);
  env.evalIn(FILES.sidebar);

  check('módulo inventory/receiving registrado', env.w.ModuleLoader.has('inventory/receiving'));
  check('global key InventoryReceivingModule', env.w.ModuleLoader.resolveGlobalKey('inventory/receiving') === 'InventoryReceivingModule');
  check('ruta inventory/receiving → módulo', env.w.AppRouter.getRoutes()['inventory/receiving'] && env.w.AppRouter.getRoutes()['inventory/receiving'].module === 'inventory/receiving');
  check('isModuleAvailable receiving', env.w.AppRouter.isModuleAvailable('inventory/receiving') === true);

  env.close();
}

// =====================================================================
// MAIN
// =====================================================================
async function main() {
  console.log('== Smoke receiving v1.13.32 (receive + plan; sin cegueza/reconteo/register) ==');
  await s1_tabs_by_perms();
  await s2_plan_list();
  await s3_plan_editor();
  await s4_plan_cancel();
  await s5_receive_expected_visible();
  await s6_receive_pure_creator();
  await s7_receive_rechazo_total();
  await s8_loader_registration();

  console.log(`\nResultado: ${pass} ok, ${fail} FAIL`);
  process.exit(fail === 0 ? 0 : 1);
}

main().catch(err => {
  console.error('ERROR inesperado:', err);
  process.exit(1);
});
