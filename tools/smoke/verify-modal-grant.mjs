/**
 * BLOQUE M2: capturas y comprobaciones Playwright del layout del modal
 * "Otorgar permiso temporal" (UI_STANDARD §6.4: sin columnas fantasma,
 * ritmo uniforme y segmentado Duración|Fecha, v1.13.53).
 * Uso: node tools/smoke/verify-modal-grant.mjs
 *
 * Comprueba a 1366x768: ancho md (~640px), rejilla de beneficiario con las
 * dos celdas iguales y etiquetas alineadas, permiso y motivo a ancho
 * completo (sin media pantalla vacía), .segmented con 2 segmentos y cambio
 * de panel por el handler change nativo, sin solapes y hint visible.
 * Capturas en tools/smoke/capturas-modales/.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const BASE = 'http://localhost/xp/public';
const OUT = path.resolve('C:/xampp/htdocs/xp/tools/smoke/capturas-modales');
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

async function openGrant(page) {
  await page.goto(BASE + '/#/users', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-action="tab"][data-tab="temp"]', { timeout: 15000 });
  await page.locator('[data-action="tab"][data-tab="temp"]').click();
  await page.waitForSelector('[data-action="open-grant"]', { timeout: 15000 });
  await page.locator('[data-action="open-grant"]').click();
  await page.waitForSelector('.modal [data-modal-field="permission_id"]', { timeout: 15000 });
  await page.waitForTimeout(400);
}

async function metrics(page) {
  return page.evaluate(() => {
    const modal = document.querySelector('.modal:not([hidden])');
    const body = modal ? modal.querySelector('.modal-body') : null;
    const search = modal ? modal.querySelector('[data-grant-user-search]') : null;
    const grid = search ? search.closest('.grid') : null;
    const colSearch = grid ? grid.children[0] : null;
    const colSelect = grid ? grid.children[1] : null;
    const selLabel = colSelect ? colSelect.querySelector('label') : null;
    const searchLabel = colSearch ? colSearch.querySelector('label') : null;
    const permission = modal ? modal.querySelector('[data-modal-field="permission_id"]') : null;
    const motivo = modal ? modal.querySelector('[data-modal-field="motivo"]') : null;
    const segmented = modal ? modal.querySelector('.segmented') : null;
    const segments = segmented ? segmented.querySelectorAll('.segment').length : 0;
    const radioHoras = modal ? modal.querySelector('input[data-expiry-mode][value="horas"]') : null;
    const radioFecha = modal ? modal.querySelector('input[data-expiry-mode][value="fecha"]') : null;
    const panelHoras = modal ? modal.querySelector('[data-expiry-hours]') : null;
    const panelFecha = modal ? modal.querySelector('[data-expiry-date]') : null;
    const hint = modal ? modal.querySelector('.text-xs.text-muted') : null;
    const vis = (el) => !!el && getComputedStyle(el).display !== 'none';
    const rect = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), bottom: Math.round(r.bottom) };
    };
    return {
      modal: rect(modal),
      body: rect(body),
      colSearch: rect(colSearch),
      colSelect: rect(colSelect),
      labelTops: { search: rect(searchLabel) && rect(searchLabel).top, sel: rect(selLabel) && rect(selLabel).top },
      permission: rect(permission),
      motivo: rect(motivo),
      segmented: rect(segmented),
      segmentedVisible: vis(segmented),
      segments,
      radioHoras: radioHoras ? radioHoras.checked : null,
      radioFecha: radioFecha ? radioFecha.checked : null,
      panelHoras: rect(panelHoras),
      panelFecha: rect(panelFecha),
      panelHorasVisible: vis(panelHoras),
      panelFechaVisible: vis(panelFecha),
      hintVisible: vis(hint)
    };
  });
}

async function main() {
  const browser = await chromium.launch({ headless: true });

  try {
    // ================= TEMA CLARO @1366 =================
    console.log('ADMIN: modal otorgar permiso (layout) en tema claro @1366');
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await ctx.addInitScript(themeScript('light'));
    const page = await ctx.newPage();
    await login(page, 'aleon@acme.local', 'Admin123!');
    await openGrant(page);

    const m1 = await metrics(page);
    check('modal de ancho md (~640px)', m1.modal && m1.modal.w >= 560 && m1.modal.w <= 700,
      m1.modal && String(m1.modal.w));
    check('rejilla de beneficiario: dos celdas iguales',
      m1.colSearch && m1.colSelect && Math.abs(m1.colSearch.w - m1.colSelect.w) <= 4,
      JSON.stringify([m1.colSearch && m1.colSearch.w, m1.colSelect && m1.colSelect.w]));
    check('etiquetas de ambas celdas alineadas al tope',
      m1.labelTops.search !== null && m1.labelTops.sel !== null &&
      Math.abs(m1.labelTops.search - m1.labelTops.sel) <= 2,
      JSON.stringify(m1.labelTops));
    check('permiso a ancho completo (sin columna fantasma)',
      m1.permission && m1.body && m1.permission.w >= m1.body.w * 0.75,
      JSON.stringify([m1.permission && m1.permission.w, m1.body && m1.body.w]));
    check('motivo al mismo ancho que el permiso',
      m1.permission && m1.motivo && Math.abs(m1.permission.w - m1.motivo.w) <= 4,
      JSON.stringify([m1.permission && m1.permission.w, m1.motivo && m1.motivo.w]));
    check('segmentado visible con 2 segmentos',
      m1.segmentedVisible === true && m1.segments === 2,
      `${m1.segmentedVisible}/${m1.segments}`);
    check('por defecto Duración (horas) activo',
      m1.radioHoras === true && m1.panelHorasVisible === true && m1.panelFechaVisible === false,
      JSON.stringify([m1.radioHoras, m1.panelHorasVisible, m1.panelFechaVisible]));
    check('segmentado sin solape con el panel de horas',
      m1.segmented && m1.panelHoras &&
      m1.panelHoras.top - m1.segmented.bottom >= 4 && m1.panelHoras.top > m1.segmented.bottom,
      JSON.stringify([m1.segmented && m1.segmented.bottom, m1.panelHoras && m1.panelHoras.top]));
    check('hint de duración visible', m1.hintVisible === true, String(m1.hintVisible));
    await page.screenshot({ path: path.join(OUT, 'modal-otorgar-permiso-1366-light.png'), fullPage: false });

    // Cambio a "Fecha y hora de caducidad" (handler change nativo)
    await page.locator('.segment', { hasText: 'Fecha' }).click();
    await page.waitForTimeout(300);
    const m2 = await metrics(page);
    check('segmentar a Fecha conmuta el panel',
      m2.radioFecha === true && m2.panelFechaVisible === true && m2.panelHorasVisible === false,
      JSON.stringify([m2.radioFecha, m2.panelFechaVisible, m2.panelHorasVisible]));
    check('el panel de fecha queda por debajo del segmentado sin solape',
      m2.segmented && m2.panelFecha &&
      m2.panelFecha.top - m2.segmented.bottom >= 4 && m2.panelFecha.top > m2.segmented.bottom,
      JSON.stringify([m2.segmented && m2.segmented.bottom, m2.panelFecha && m2.panelFecha.top]));
    await page.screenshot({ path: path.join(OUT, 'modal-otorgar-permiso-1366-fecha.png'), fullPage: false });
    await ctx.close();

    // ================= TEMA OSCURO @1366 =================
    console.log('ADMIN: modal otorgar permiso (layout) en tema oscuro @1366');
    const ctxDark = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await ctxDark.addInitScript(themeScript('dark'));
    const page2 = await ctxDark.newPage();
    await login(page2, 'aleon@acme.local', 'Admin123!');
    await openGrant(page2);
    const md = await metrics(page2);
    check('dark: html.dark activo',
      await page2.evaluate(() => document.documentElement.classList.contains('dark')));
    check('dark: modal presente', !!md.modal, JSON.stringify(md.modal));
    check('dark: segmentado visible con 2 segmentos',
      md.segmentedVisible === true && md.segments === 2,
      `${md.segmentedVisible}/${md.segments}`);
    await page2.screenshot({ path: path.join(OUT, 'modal-otorgar-permiso-1366-dark.png'), fullPage: false });
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
