/**
 * BLOQUE M3: capturas y comprobaciones Playwright del layout de los modales
 * "Nuevo rol" y "Editar rol" (UI_STANDARD §6.4, v1.13.54).
 * Uso: node tools/smoke/verify-modal-roles.mjs
 *
 * Comprueba a 1366x768 la línea base de la cabecera Nombre|Basar en
 * (etiquetas arriba e inputs abajo alineados con el toolbar), campos a
 * ancho completo con la misma línea izquierda, picker y resumen del pie
 * visibles. Para capturar "Editar rol" crea un rol temporal y lo borra al
 * final (la BD viva queda con sus 4 roles BUILT_IN). Capturas en
 * tools/smoke/capturas-modales/.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const BASE = 'http://localhost/xp/public';
const OUT = path.resolve('C:/xampp/htdocs/xp/tools/smoke/capturas-modales');
mkdirSync(OUT, { recursive: true });

const TMP_RE = /tmp m3 \d+/i;
const TMP = 'TMP M3 ' + Date.now();

let pass = 0;
let fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${extra ? ' :: ' + extra : ''}`); }
}

function themeScript(mode) {
  return `localStorage.setItem('xp.theme', JSON.stringify({ v: ${JSON.stringify(mode)}, t: Date.now() }));`;
}

async function login(page) {
  await page.goto(BASE + '/#login', { waitUntil: 'networkidle' });
  await page.waitForSelector('input[type="email"], input[name="email"], #email', { timeout: 15000 });
  await page.locator('input[type="email"], input[name="email"], #email').first().fill('aleon@acme.local');
  await page.locator('input[type="password"], input[name="password"], #password').first().fill('Admin123!');
  await page.locator('button[type="submit"], form button').first().click();
  await page.waitForURL(/dashboard|\/#\/dashboard/, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(800);
}

async function gotoRoles(page) {
  await page.goto(BASE + '/#/roles', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-action="open-create"]', { timeout: 15000 });
}

async function openCreate(page) {
  await gotoRoles(page);
  await page.locator('[data-action="open-create"]').click();
  await page.waitForSelector('.modal [data-modal-field="nombre"]', { timeout: 15000 });
  await page.waitForTimeout(800);
}

function headMetrics(page) {
  return page.evaluate(() => {
    const modal = document.querySelector('.modal');
    const nombre = modal ? modal.querySelector('[data-modal-field="nombre"]') : null;
    const head = modal ? modal.querySelector('.pp-head-row') : null;
    const nombreGroup = head ? head.querySelector('.form-group') : null;
    const nombreLabel = nombreGroup ? nombreGroup.querySelector('.form-label') : null;
    const base = modal ? modal.querySelector('.pp-base') : null;
    const baseLabel = base; // .pp-base ES el <label> que envuelve texto + select
    const baseSelect = base ? base.querySelector('select') : null;
    const btns = modal ? modal.querySelectorAll('.pp-toolbar .btn') : [];
    const lastBtn = btns.length ? btns[btns.length - 1] : null;
    const rect = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), w: Math.round(r.width) };
    };
    return {
      modal: rect(modal),
      nombre: rect(nombre),
      nombreLabel: rect(nombreLabel),
      baseLabel: rect(baseLabel),
      baseSelect: rect(baseSelect),
      lastBtn: rect(lastBtn),
      descripcion: rect(modal ? modal.querySelector('[data-modal-field="descripcion"]') : null),
      permsLabel: rect(modal ? modal.querySelector('.form-label.mt-4') : null),
      summary: !!(modal && modal.querySelector('.pp-summary') &&
        modal.querySelector('.pp-summary').textContent.trim().length > 0),
      pickerSearch: !!(modal && modal.querySelector('[data-pp-search]'))
    };
  });
}

async function waitRolesRendered(page) {
  // El tbody del esqueleto de carga tambien contiene <tr> (vacios): hay que
  // esperar a que aparezca texto real (los 4 roles BUILT_IN siempre estan).
  await page.waitForFunction(() => {
    const tb = document.querySelector('tbody');
    return !!tb && tb.textContent.replace(/\s+/g, '').length > 0;
  }, { timeout: 15000 });
  await page.waitForTimeout(150);
}

async function deleteTmpLeftovers(page) {
  await gotoRoles(page);
  await waitRolesRendered(page);
  for (let i = 0; i < 5; i++) {
    if ((await page.locator('.modal').count()) > 0) {
      const close = page.locator('.modal .modal-close');
      if ((await close.count()) > 0) await close.click().catch(() => {});
      await page.waitForTimeout(400);
    }
    const n = await page.locator('tbody tr', { hasText: TMP_RE }).count();
    if (n === 0) break;
    await page.locator('tbody tr', { hasText: TMP_RE }).first().locator('[data-row-menu-trigger]').click();
    await page.waitForSelector('[data-row-menu].is-open [data-action="open-delete"]', { timeout: 15000 });
    await page.locator('[data-row-menu].is-open [data-action="open-delete"]').click();
    await page.waitForSelector('.modal [data-action="submit-delete"]', { timeout: 15000 });
    await page.locator('.modal [data-action="submit-delete"]').click();
    await page.waitForTimeout(1200);
    await waitRolesRendered(page);
  }
}

async function main() {
  const browser = await chromium.launch({ headless: true });

  try {
    // ================= TEMA CLARO @1366 =================
    console.log('ADMIN: modal Nuevo rol (layout) en tema claro @1366');
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await ctx.addInitScript(themeScript('light'));
    const page = await ctx.newPage();
    await login(page);
    await openCreate(page);

    const m1 = await headMetrics(page);
    check('head: etiquetas Nombre y Basar en a la misma altura',
      m1.nombreLabel && m1.baseLabel && Math.abs(m1.nombreLabel.top - m1.baseLabel.top) <= 2,
      JSON.stringify([m1.nombreLabel && m1.nombreLabel.top, m1.baseLabel && m1.baseLabel.top]));
    check('head: input Nombre y select Basar en en la misma linea base',
      m1.nombre && m1.baseSelect && Math.abs(m1.nombre.bottom - m1.baseSelect.bottom) <= 2,
      JSON.stringify([m1.nombre && m1.nombre.bottom, m1.baseSelect && m1.baseSelect.bottom]));
    check('head: botones del toolbar en la misma linea base',
      m1.nombre && m1.lastBtn && Math.abs(m1.nombre.bottom - m1.lastBtn.bottom) <= 2,
      JSON.stringify([m1.nombre && m1.nombre.bottom, m1.lastBtn && m1.lastBtn.bottom]));
    const leftGap = m1.descripcion && m1.modal ? m1.descripcion.left - m1.modal.left : -1;
    const rightGap = m1.descripcion && m1.modal ? m1.modal.right - m1.descripcion.right : -1;
    check('descripcion centrada a ancho completo (margenes izq/der iguales)',
      leftGap >= 0 && rightGap >= 0 && Math.abs(leftGap - rightGap) <= 6,
      JSON.stringify([leftGap, rightGap]));
    check('Permisos del rol y Descripcion con la misma linea izquierda',
      m1.permsLabel && m1.descripcion && Math.abs(m1.permsLabel.left - m1.descripcion.left) <= 2,
      JSON.stringify([m1.permsLabel && m1.permsLabel.left, m1.descripcion && m1.descripcion.left]));
    check('picker con buscador presente', m1.pickerSearch === true);
    check('resumen del pie visible', m1.summary === true);
    await page.screenshot({ path: path.join(OUT, 'modal-nuevo-rol-1366-light.png'), fullPage: false });

    // --- Alta de rol TMP para capturar Editar ---
    await page.locator('.modal [data-modal-field="nombre"]').fill(TMP);
    await page.locator('.modal [data-modal-field="descripcion"]').fill('rol temporal de captura M3');
    await page.locator('.modal [data-action="submit-create"]').click();
    await page.waitForTimeout(1500);
    const row = page.locator('tbody tr', { hasText: TMP_RE }).first();
    await row.waitFor({ timeout: 15000 });
    check('rol TMP creado en la tabla', (await row.count()) === 1);

    await row.locator('[data-row-menu-trigger]').click();
    await page.waitForSelector('[data-row-menu].is-open [data-action="open-edit"]', { timeout: 15000 });
    await page.locator('[data-row-menu].is-open [data-action="open-edit"]').click();
    await page.waitForSelector('.modal [data-modal-field="nombre"]', { timeout: 15000 });
    await page.waitForTimeout(800);
    const m2 = await headMetrics(page);
    check('editar: etiquetas Nombre y Basar en a la misma altura',
      m2.nombreLabel && m2.baseLabel && Math.abs(m2.nombreLabel.top - m2.baseLabel.top) <= 2,
      JSON.stringify([m2.nombreLabel && m2.nombreLabel.top, m2.baseLabel && m2.baseLabel.top]));
    check('editar: input Nombre y select Basar en en la misma linea base',
      m2.nombre && m2.baseSelect && Math.abs(m2.nombre.bottom - m2.baseSelect.bottom) <= 2,
      JSON.stringify([m2.nombre && m2.nombre.bottom, m2.baseSelect && m2.baseSelect.bottom]));
    check('editar: casilla Rol activo presente',
      await page.evaluate(() => !!document.querySelector('.modal [data-modal-field="activo"]')));
    await page.screenshot({ path: path.join(OUT, 'modal-editar-rol-1366-light.png'), fullPage: false });

    const closeBtn = page.locator('.modal .modal-footer [data-action="close-modal"]');
    if ((await closeBtn.count()) > 0) await closeBtn.click();
    await page.waitForTimeout(500);
    await deleteTmpLeftovers(page);
    await waitRolesRendered(page);
    const finalCount = await page.locator('tbody tr', { hasText: TMP_RE }).count();
    check('sin restos de roles TMP en la BD', finalCount === 0);
    await ctx.close();

    // ================= TEMA OSCURO @1366 =================
    console.log('ADMIN: modal Nuevo rol (layout) en tema oscuro @1366');
    const ctxDark = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await ctxDark.addInitScript(themeScript('dark'));
    const page2 = await ctxDark.newPage();
    await login(page2);
    await openCreate(page2);
    const md = await headMetrics(page2);
    check('dark: html.dark activo',
      await page2.evaluate(() => document.documentElement.classList.contains('dark')));
    check('dark: linea base de la cabecera alineada',
      md.nombre && md.baseSelect && Math.abs(md.nombre.bottom - md.baseSelect.bottom) <= 2,
      JSON.stringify([md.nombre && md.nombre.bottom, md.baseSelect && md.baseSelect.bottom]));
    check('dark: picker y resumen visibles', md.pickerSearch === true && md.summary === true);
    await page2.screenshot({ path: path.join(OUT, 'modal-nuevo-rol-1366-dark.png'), fullPage: false });
    await ctxDark.close();

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
