/**
 * CIERRE BLOQUE F: capturas y comprobaciones Playwright del picker de
 * permisos (modal de roles) a 1366px, en tema oscuro y claro.
 * Uso: node tools/smoke/verify-permission-picker.mjs
 *
 * Comprueba: modal-pp de dimensiones correctas (~1100x85vh), descripcion en
 * su propia fila a ancho completo sin scrollbars, paneles internos con el
 * unico scroll, buscador enfocado, tema oscuro aplicado al modal y
 * maestro-detalle responsivo (select de modulos en pantalla estrecha).
 * Capturas en tools/smoke/capturas-permissions/.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const BASE = 'http://localhost/xp/public';
const OUT = path.resolve('C:/xampp/htdocs/xp/tools/smoke/capturas-permissions');
mkdirSync(OUT, { recursive: true });

let pass = 0;
let fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${extra ? ' :: ' + extra : ''}`); }
}

function themeScript(mode) {
  return `localStorage.setItem('xp.theme', JSON.stringify({ v: ${JSON.stringify(mode)}, t: Date.now() }));`;
}

async function login(page, email, pass) {
  await page.goto(BASE + '/#login', { waitUntil: 'networkidle' });
  await page.waitForSelector('input[type="email"], input[name="email"], #email', { timeout: 15000 });
  await page.locator('input[type="email"], input[name="email"], #email').first().fill(email);
  await page.locator('input[type="password"], input[name="password"], #password').first().fill(pass);
  await page.locator('button[type="submit"], form button').first().click();
  await page.waitForURL(/dashboard|\/#\/dashboard/, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1000);
}

async function openCreateModal(page) {
  await page.goto(BASE + '/#/roles', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-action="open-create"]', { timeout: 15000 });
  await page.locator('[data-action="open-create"]').first().click();
  await page.waitForSelector('.modal-pp [data-pp-search]', { timeout: 15000 });
  await page.waitForTimeout(400);
}

function luminance(rgb) {
  const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(rgb || '');
  if (!m) return null;
  return (0.2126 * Number(m[1]) + 0.7152 * Number(m[2]) + 0.0722 * Number(m[3])) / 255;
}

async function metrics(page) {
  return page.evaluate(() => {
    const modal = document.querySelector('.modal-pp');
    const body = modal ? modal.querySelector('.modal-body') : null;
    const nombre = modal ? modal.querySelector('[data-modal-field="nombre"]') : null;
    const desc = modal ? modal.querySelector('[data-modal-field="descripcion"]') : null;
    const panel = modal ? modal.querySelector('.pp-panel') : null;
    const modules = modal ? modal.querySelector('.pp-modules') : null;
    const search = modal ? modal.querySelector('[data-pp-search]') : null;
    const headRow = modal ? modal.querySelector('.pp-head-row') : null;
    const modalBody = body;
    const rect = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), width: Math.round(r.width), height: Math.round(r.height) };
    };
    return {
      viewport: { w: window.innerWidth, h: window.innerHeight },
      modal: rect(modal),
      modalSurface: modal ? window.getComputedStyle(modal).backgroundColor : null,
      bodyScroll: modalBody ? { scrollH: modalBody.scrollHeight, clientH: modalBody.clientHeight } : null,
      desc: rect(desc),
      descOwnRow: !!(nombre && desc && headRow && !headRow.contains(desc) && desc.getBoundingClientRect().top >= nombre.getBoundingClientRect().bottom - 2),
      descScroll: desc ? { scrollH: desc.scrollHeight, clientH: desc.clientHeight, overflowY: window.getComputedStyle(desc).overflowY, resize: window.getComputedStyle(desc).resize } : null,
      panelScroll: panel ? { overflowY: window.getComputedStyle(panel).overflowY, scrollH: panel.scrollHeight, clientH: panel.clientHeight } : null,
      modulesVisible: modules ? window.getComputedStyle(modules).display !== 'none' : null,
      searchFocused: search ? document.activeElement === search : null,
      activeClass: document.documentElement.className
    };
  });
}

async function main() {
  const browser = await chromium.launch({ headless: true });

  try {
    // ================= TEMA OSCURO @1366 =================
    console.log('ADMIN: modal de roles en tema oscuro @1366');
    const ctxDark = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await ctxDark.addInitScript(themeScript('dark'));
    const page = await ctxDark.newPage();
    await login(page, 'aleon@acme.local', 'Admin123!');
    check('dark: html.dark activo', (await page.evaluate(() => document.documentElement.classList.contains('dark'))));

    await openCreateModal(page);
    const md = await metrics(page);
    check('dark: modal-pp presente', !!md.modal, JSON.stringify(md.modal));
    check('dark: ancho ~1100px', md.modal && md.modal.width >= 900 && md.modal.width <= 1101, md.modal && String(md.modal.width));
    check('dark: alto 85vh', md.modal && Math.abs(md.modal.height - Math.round(md.viewport.h * 0.85)) <= 3,
      md.modal && `${md.modal.height} vs ${Math.round(md.viewport.h * 0.85)}`);
    check('dark: body del modal sin scroll', md.bodyScroll && md.bodyScroll.scrollH <= md.bodyScroll.clientH + 2,
      JSON.stringify(md.bodyScroll));
    check('dark: descripcion en su propia fila', md.descOwnRow);
    check('dark: descripcion a ancho completo', md.desc && md.modal && md.desc.width >= md.modal.width * 0.85,
      md.desc && `${md.desc.width}/${md.modal.width}`);
    check('dark: descripcion sin scrollbars', md.descScroll && md.descScroll.scrollH <= md.descScroll.clientH + 2,
      JSON.stringify(md.descScroll));
    check('dark: descripcion sin resize manual', md.descScroll && md.descScroll.resize === 'none', md.descScroll && md.descScroll.resize);
    check('dark: panel con scroll interno', md.panelScroll && md.panelScroll.overflowY === 'auto', JSON.stringify(md.panelScroll));
    check('dark: buscador enfocado', md.searchFocused === true);
    const darkLum = luminance(md.modalSurface);
    check('dark: superficie del modal oscura', darkLum !== null && darkLum < 0.35, `${md.modalSurface} lum=${darkLum}`);
    await page.screenshot({ path: path.join(OUT, 'modal-crear-rol-1366-dark.png'), fullPage: false });

    // Interaccion: buscar y marcar un permiso.
    await page.fill('[data-pp-search]', 'inventory');
    await page.waitForTimeout(250);
    const invCheck = page.locator('input[data-perm-check]').first();
    check('dark: la busqueda deja permisos', await invCheck.count() > 0);
    await invCheck.click();
    await page.waitForTimeout(200);
    const summary = await page.locator('[data-pp-summary]').textContent();
    const n = /(\d+)\s+permisos/.exec(summary || '');
    check('dark: resumen refleja la seleccion', !!n && Number(n[1]) >= 1, summary);
    await page.screenshot({ path: path.join(OUT, 'modal-crear-rol-1366-dark-seleccion.png'), fullPage: false });
    await ctxDark.close();

    // ================= TEMA CLARO @1366 =================
    console.log('ADMIN: modal de roles en tema claro @1366');
    const ctxLight = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await ctxLight.addInitScript(themeScript('light'));
    const page2 = await ctxLight.newPage();
    await login(page2, 'aleon@acme.local', 'Admin123!');
    await openCreateModal(page2);
    const ml = await metrics(page2);
    const lightLum = luminance(ml.modalSurface);
    check('light: html.light activo', ml.activeClass.indexOf('light') !== -1 || ml.activeClass.indexOf('dark') === -1, ml.activeClass);
    check('light: superficie del modal clara', lightLum !== null && lightLum > 0.6, `${ml.modalSurface} lum=${lightLum}`);
    check('light: buscador enfocado', ml.searchFocused === true);
    await page2.screenshot({ path: path.join(OUT, 'modal-crear-rol-1366-light.png'), fullPage: false });
    await ctxLight.close();

    // ================= RESPONSIVE @700 (select de modulos) =================
    console.log('ADMIN: maestro-detalle responsivo @700');
    const ctxNarrow = await browser.newContext({ viewport: { width: 700, height: 900 } });
    await ctxNarrow.addInitScript(themeScript('light'));
    const page3 = await ctxNarrow.newPage();
    await login(page3, 'aleon@acme.local', 'Admin123!');
    await openCreateModal(page3);
    const mn = await metrics(page3);
    check('narrow: lista de modulos oculta', mn.modulesVisible === false);
    check('narrow: select de modulos visible',
      await page3.locator('[data-pp-modules-select]').isVisible());
    await page3.screenshot({ path: path.join(OUT, 'modal-crear-rol-700-light.png'), fullPage: false });
    await ctxNarrow.close();

    console.log(`\nRESULTADO: ${pass} OK, ${fail} FAIL`);
    if (fail > 0) process.exitCode = 1;
  } finally {
    await browser.close();
  }
}

main().catch(err => {
  console.error('ERROR del smoke Playwright:', err);
  process.exitCode = 1;
});
