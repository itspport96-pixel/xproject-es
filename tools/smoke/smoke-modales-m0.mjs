/**
 * Smoke test jsdom del estandar de modales (M0, docs/UI_STANDARD.md §6).
 * Uso: node tools/smoke/smoke-modales-m0.mjs   (desde la raiz del repo)
 *
 * Cubre: tamaños modalShell (sm/md/lg/xl/pp), UI.modalFooter, field/grid,
 * Esc cierra (con excepciones), Enter envía (y cuando no) y foco en el
 * primer campo al abrir sin re-enfocar en re-render.
 */
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/xampp/htdocs/xp/public/js';
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');
const ES_DICT = JSON.parse(readFileSync('C:/xampp/htdocs/xp/resources/locales/es.json', 'utf8'));

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

function createEnv() {
  const dom = new JSDOM(
    '<!DOCTYPE html><html><body><div id="module-container"><div data-modal-root></div></div></body></html>',
    { url: 'http://localhost/xp/public/', runScripts: 'outside-only', pretendToBeVisual: true }
  );
  const w = dom.window;
  const calls = { clicks: [], keys: [] };

  Object.defineProperty(w.Element.prototype, 'scrollIntoView', { value: function() {}, configurable: true });

  w.AppConfig = { apiBase: '/api', baseUrl: '/xp/public', appName: 'XP Platform' };
  w.EventBus = { on() {}, off() {}, emit() {} };

  const env = { w, calls, dom };
  w.HttpClient = {
    async get(p) {
      if (p.startsWith('/locales/')) {
        const loc = p.slice('/locales/'.length).replace(/\.json$/, '');
        return { status: 200, data: { locale: loc, version: 'smoke', dictionary: ES_DICT } };
      }
      throw Object.assign(new Error('GET no soportado: ' + p), { status: 404, data: null });
    },
    async post() { return { status: 201, data: { ok: true } }; }
  };

  const evalIn = (code) => w.eval(code);
  env.evalIn = evalIn;
  evalIn(read('core/i18n.js'));
  w.i18n.inject('es', ES_DICT);
  UI_FILES.forEach(evalIn);
  // jsdom entrega DOMContentLoaded de forma asíncrona; view.js y modal.js
  // difieren su binde global a ese evento si el documento aún está "loading".
  // Se dispara aquí para que los handlers existan desde el primer test (el
  // guard de cada módulo evita el doble binde con el evento real).
  if (w.document.readyState === 'loading') {
    w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  }

  env.root = w.document.getElementById('module-container').querySelector('[data-modal-root]');
  env.container = w.document.getElementById('module-container');
  env.UI = w.UI;

  // Delegacion de clics tipo modulo: cuenta el primario y cierra con close-modal.
  env.container.addEventListener('click', (e) => {
    const btn = e.target.closest('button');
    if (!btn) return;
    if (btn.dataset.action === 'close-modal') { env.root.innerHTML = ''; return; }
    if (btn.dataset.action && btn.dataset.action.startsWith('submit-')) {
      calls.clicks.push(btn.dataset.action);
    }
  });

  env.key = (key, target) => {
    const t = target || env.root;
    const ev = new w.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    return t.dispatchEvent(ev); // false si preventDefault()
  };
  env.focus = (el) => el && el.focus();
  env.close = () => dom.window.close();
  return env;
}

// =====================================================================
// S1 - modalShell: tamaños
// =====================================================================
function s1_sizes() {
  console.log('S1 modalShell: tamaños sm/md/lg/xl/pp y defecto');
  const env = createEnv();
  const { UI } = env;
  const has = (opts, cls) => UI.modalShell(Object.assign({ title: 'X', bodyHtml: 'b' }, opts)).includes(cls);

  check('defecto sin clase de tamaño', has({}, '<div class="modal">'));
  check('size sm -> modal-sm', has({ size: 'sm' }, 'class="modal modal-sm"'));
  check('size md -> modal-md', has({ size: 'md' }, 'class="modal modal-md"'));
  check('size lg -> modal-lg', has({ size: 'lg' }, 'class="modal modal-lg"'));
  check('size xl -> modal-xl', has({ size: 'xl' }, 'class="modal modal-xl"'));
  check('size pp -> modal-pp', has({ size: 'pp' }, 'class="modal modal-pp"'));
  check('tamaño desconocido se ignora', has({ size: 'zz' }, '<div class="modal">'));
  check('overlay con data-modal', UI.modalShell({ title: 'X' }).includes('<div class="modal-overlay" data-modal>'));
  check('cabecera con close-modal', UI.modalShell({ title: 'X' }).includes('data-action="close-modal"'));
  env.close();
}

// =====================================================================
// S2 - UI.modalFooter
// =====================================================================
function s2_footer() {
  console.log('S2 modalFooter: orden, tipos, data-modal-send, disabled, danger');
  const env = createEnv();
  const { UI } = env;

  const html = UI.modalFooter({ submitLabel: 'Guardar', submitAction: 'submit-save' });
  const iCancel = html.indexOf('data-action="close-modal"');
  const iSubmit = html.indexOf('data-action="submit-save"');
  check('hay Cancelar y primario', iCancel !== -1 && iSubmit !== -1, html);
  check('Cancelar antes que el primario', iCancel !== -1 && iSubmit !== -1 && iCancel < iSubmit);
  check('Cancelar type=button', /<button type="button" class="btn btn-secondary" data-action="close-modal">Cancelar<\/button>/.test(html), html);
  check('primario btn-primary con data-modal-send', /class="btn btn-primary" data-action="submit-save" data-modal-send/.test(html), html);
  check('primario sin atributo type', !/data-action="submit-save"[^>]*type=/.test(html), html);
  check('sin hint no pinta mr-auto', !html.includes('mr-auto'), html);

  const hint = UI.modalFooter({ hintHtml: '<span data-key-hint>Key</span>', submitLabel: 'Crear', submitAction: 'submit-create' });
  check('hint con mr-auto y delante', hint.indexOf('mr-auto') !== -1 && hint.indexOf('mr-auto') < hint.indexOf('close-modal'), hint);

  const danger = UI.modalFooter({ submitLabel: 'Borrar', submitAction: 'submit-del', danger: true });
  check('danger usa btn-danger', danger.includes('class="btn btn-danger"'), danger);

  const disabled = UI.modalFooter({ submitLabel: 'Guardar', submitAction: 'submit-save', disabled: true });
  check('disabled deshabilita el primario', /data-modal-send disabled/.test(disabled), disabled);

  const soloCancelar = UI.modalFooter({});
  check('sin submitLabel sólo Cancelar', soloCancelar.includes('close-modal') && !soloCancelar.includes('data-modal-send'), soloCancelar);
  env.close();
}

// =====================================================================
// S3 - field / grid
// =====================================================================
function s3_fields() {
  console.log('S3 field: label/asterisco/error/hint, textarea >= 4 filas, grid 1 columna');
  const env = createEnv();
  const { UI } = env;

  const f = UI.field({ label: 'Codigo', name: 'codigo', required: true, hint: 'Ayuda' });
  check('label con asterisco de obligatorio', f.includes('Codigo <span class="required">*</span>'), f);
  check('span de error con data-error', f.includes('<span class="form-error hidden" data-error="codigo"></span>'), f);
  const iCtrl = f.indexOf('<input');
  const iErr = f.indexOf('data-error="codigo"');
  const iHint = f.indexOf('form-hint');
  check('orden control -> error -> hint', iCtrl !== -1 && iErr > iCtrl && iHint > iErr, f);

  const taDefault = UI.field({ label: 'N', name: 'n', type: 'textarea' });
  check('textarea por defecto 4 filas', /<textarea[^>]*rows="4"/.test(taDefault), taDefault);
  const ta2 = UI.field({ label: 'N', name: 'n', type: 'textarea', rows: 2 });
  check('textarea respeta el minimo de 4 filas', /<textarea[^>]*rows="4"/.test(ta2), ta2);
  check('textarea con clase input textarea', /<textarea class="input textarea"/.test(ta2), ta2);

  const g1 = UI.grid('<span>x</span>');
  check('grid por defecto 1 columna', g1.includes('grid grid-cols-1 gap-4') && !g1.includes('md:grid-cols-2'), g1);
  const g2 = UI.grid('<span>x</span>', 2);
  check('grid con cols=2 pide 2 columnas', g2.includes('md:grid-cols-2'), g2);
  env.close();
}

// =====================================================================
// S4 - Esc cierra (y excepciones)
// =====================================================================
async function s4_escape() {
  console.log('S4 Esc: cierra el modal, respeta menú de fila y select');
  const env = createEnv();
  const { UI, root, w } = env;
  const openModal = () => {
    root.innerHTML = UI.modalShell({
      title: 'Editar',
      bodyHtml: UI.field({ label: 'Codigo', name: 'codigo' }),
      footerHtml: UI.modalFooter({ submitLabel: 'Guardar', submitAction: 'submit-save' })
    });
  };

  openModal();
  const input = root.querySelector('input[name="codigo"]');
  env.focus(input);
  const delivered = env.key('Escape', input);
  check('Esc cierra el modal', !root.querySelector('[data-modal]'));
  check('Esc cancela el evento', delivered === false);

  openModal();
  env.key('Escape');
  check('Esc desde fuera del campo también cierra', !root.querySelector('[data-modal]'));

  // menú de fila abierto: Esc no toca el modal (view.js cierra el menú)
  openModal();
  const holder = env.container.ownerDocument.createElement('div');
  holder.innerHTML = '<button data-row-menu-trigger></button><div class="dropdown-menu is-open" data-row-menu></div>';
  env.container.appendChild(holder);
  env.key('Escape', root.querySelector('input[name="codigo"]'));
  check('con menú de fila abierto el modal sigue abierto', !!root.querySelector('[data-modal]'));
  check('el menú de fila se cerró', !env.container.querySelector('[data-row-menu].is-open'));
  env.key('Escape', root.querySelector('input[name="codigo"]'));
  check('segunda Esc cierra el modal', !root.querySelector('[data-modal]'));
  holder.remove();

  // Escape en un <select> cierra sólo su desplegable
  root.innerHTML = UI.modalShell({
    title: 'X',
    bodyHtml: UI.field({ label: 'Tipo', name: 'tipo', type: 'select', options: [{ value: 'a', label: 'A' }] })
  });
  env.key('Escape', root.querySelector('select[name="tipo"]'));
  check('Esc en select no cierra el modal', !!root.querySelector('[data-modal]'));
  env.key('Escape', root.querySelector('select[name="tipo"]').closest('.modal-body'));
  check('Esc en otro punto sí cierra', !root.querySelector('[data-modal]'));

  // modal sin botón de cierre: se retira del DOM
  root.innerHTML = '<div class="modal-overlay" data-modal><div class="modal"><div class="modal-body">cargando</div></div></div>';
  env.key('Escape');
  check('modal sin close-modal se elimina', !root.querySelector('[data-modal]'));

  // sin modal abierto Esc no hace nada raro
  const deliveredNone = env.key('Escape', env.container);
  check('Esc sin modal no se cancela', deliveredNone === true);
  env.close();
}

// =====================================================================
// S5 - Enter envía (y cuando no)
// =====================================================================
async function s5_enter() {
  console.log('S5 Enter: envía desde campos, no desde textarea/search/form/disabled');
  const env = createEnv();
  const { UI, root } = env;
  const open = (footerHtml, bodyHtml) => {
    env.calls.clicks.length = 0;
    root.innerHTML = UI.modalShell({
      title: 'X',
      bodyHtml: bodyHtml || UI.field({ label: 'Codigo', name: 'codigo' }),
      footerHtml: footerHtml
    });
  };
  const footer = (extra) => UI.modalFooter(Object.assign({ submitLabel: 'Guardar', submitAction: 'submit-save' }, extra || {}));
  const enterOn = (sel) => env.key('Enter', root.querySelector(sel));

  open(footer());
  check('Enter en input dispara el primario', (enterOn('input[name="codigo"]'), env.calls.clicks.length === 1), JSON.stringify(env.calls.clicks));
  enterOn('input[name="codigo"]');
  check('Enter se repite sin doble clic inicial', env.calls.clicks.length === 2, String(env.calls.clicks.length));

  open(footer(), UI.field({ label: 'Notas', name: 'notas', type: 'textarea' }));
  enterOn('textarea[name="notas"]');
  check('Enter en textarea no envía', env.calls.clicks.length === 0, JSON.stringify(env.calls.clicks));

  open(footer());
  root.innerHTML = UI.modalShell({
    title: 'X',
    bodyHtml: '<input type="search" class="input" data-modal-field="q">',
    footerHtml: footer()
  });
  env.calls.clicks.length = 0;
  enterOn('input[type="search"]');
  check('Enter en input[type=search] no envía', env.calls.clicks.length === 0, JSON.stringify(env.calls.clicks));

  open(footer());
  const combo = root.querySelector('input[name="codigo"]');
  combo.setAttribute('aria-expanded', 'true');
  enterOn('input[name="codigo"]');
  check('Enter con aria-expanded=true no envía', env.calls.clicks.length === 0, JSON.stringify(env.calls.clicks));

  open(footer({ disabled: true }));
  enterOn('input[name="codigo"]');
  check('Enter con primario disabled no envía', env.calls.clicks.length === 0, JSON.stringify(env.calls.clicks));

  // dentro de <form> manda el submit nativo: nuestro handler no hace clic
  root.innerHTML = UI.modalShell({
    title: 'X',
    bodyHtml: `<form data-f>${UI.field({ label: 'Codigo', name: 'codigo' })}</form>`,
    footerHtml: footer()
  });
  env.calls.clicks.length = 0;
  enterOn('form[data-f] input[name="codigo"]');
  check('Enter dentro de form no dispara data-action', env.calls.clicks.length === 0, JSON.stringify(env.calls.clicks));

  // fuera de un modal no toca nada
  open(footer());
  const outside = env.container.ownerDocument.createElement('input');
  env.container.appendChild(outside);
  env.calls.clicks.length = 0;
  env.key('Enter', outside);
  check('Enter fuera del modal no envía', env.calls.clicks.length === 0, JSON.stringify(env.calls.clicks));

  // preventDefault de un componente previo bloquea el envío
  open(footer());
  const inp = root.querySelector('input[name="codigo"]');
  const stopper = (e) => e.preventDefault();
  inp.addEventListener('keydown', stopper);
  env.calls.clicks.length = 0;
  env.key('Enter', inp);
  check('Enter cancelado por el componente no envía', env.calls.clicks.length === 0, JSON.stringify(env.calls.clicks));
  inp.removeEventListener('keydown', stopper);
  env.close();
}

// =====================================================================
// S6 - foco en el primer campo
// =====================================================================
async function s6_focus() {
  console.log('S6 foco: primer campo al abrir, sin re-enfocar en re-render');
  const env = createEnv();
  const { UI, root, w } = env;
  const html = (n) => UI.modalShell({
    title: 'X',
    bodyHtml: UI.field({ label: 'Uno', name: 'uno' }) + UI.field({ label: 'Dos', name: 'dos' })
  }) + '<!--r' + n + '-->';

  UI.mountModal(root, html(1));
  await tick();
  check('foco en el primer campo al abrir', w.document.activeElement && w.document.activeElement.name === 'uno',
    w.document.activeElement && w.document.activeElement.tagName + ':' + (w.document.activeElement.name || ''));

  // reapertura con el módulo enfocando algo en el mismo task: no se le roba
  UI.closeModal(root);
  await tick();
  UI.mountModal(root, html(1));
  root.querySelector('input[name="dos"]').focus();
  await tick();
  check('no se roba el foco si el módulo enfocó tras montar',
    w.document.activeElement && w.document.activeElement.name === 'dos',
    w.document.activeElement && (w.document.activeElement.name || w.document.activeElement.tagName));

  // re-render: el foco no salta al primer campo (aunque el elemento
  // enfocado haya desaparecido con el cambio de DOM)
  UI.mountModal(root, html(2));
  await tick();
  check('re-render no re-enfoca el primer campo',
    !w.document.activeElement || w.document.activeElement.name !== 'uno',
    w.document.activeElement && (w.document.activeElement.name || w.document.activeElement.tagName));

  // re-render sucesivo: sigue sin re-enfocar
  UI.mountModal(root, html(3));
  await tick();
  check('re-render sucesivo tampoco re-enfoca',
    !w.document.activeElement || w.document.activeElement.name !== 'uno',
    w.document.activeElement && (w.document.activeElement.name || w.document.activeElement.tagName));

  // cierre y reapertura: vuelve a enfocar
  UI.closeModal(root);
  await tick();
  UI.mountModal(root, html(4));
  await tick();
  check('al reabrir vuelve el foco al primer campo', w.document.activeElement && w.document.activeElement.name === 'uno',
    w.document.activeElement && (w.document.activeElement.name || w.document.activeElement.tagName));

  // modal heredado sin data-modal-field: primer input del body
  UI.closeModal(root);
  await tick();
  root.innerHTML = '<div class="modal-overlay" data-modal><div class="modal"><div class="modal-body"><input type="text" name="legacy"></div></div></div>';
  await tick();
  check('modal sin data-modal-field enfoca el primer input del body', w.document.activeElement && w.document.activeElement.name === 'legacy',
    w.document.activeElement && (w.document.activeElement.name || w.document.activeElement.tagName));
  env.close();
}

// =====================================================================
async function main() {
  s1_sizes();
  s2_footer();
  s3_fields();
  await s4_escape();
  await s5_enter();
  await s6_focus();

  console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
  if (fail > 0) process.exitCode = 1;
}

main().catch(err => {
  console.error('ERROR del smoke test:', err);
  process.exitCode = 1;
});
