/**
 * BLOQUE M1: capturas y comprobaciones Playwright del modal "Anadir
 * miembro" de Grupos (combobox con sugerencias y chips, UI_STANDARD §6.6).
 * Uso: node tools/smoke/verify-modal-add-member.mjs
 *
 * Comprueba a 1366x768: modal-md (~640px), foco en el combobox, lista de
 * sugerencias con role=listbox y aria-expanded, seleccion con chip, quita
 * del chip y Esc cerrando primero la lista. Capturas en
 * tools/smoke/capturas-modales/.
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

async function openAddMember(page) {
  await page.goto(BASE + '/#/groups', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-action="open-detail"]', { timeout: 15000 });
  await page.locator('[data-action="open-detail"]').first().click();
  await page.waitForSelector('.modal [data-action="open-add-member"]', { timeout: 15000 });
  await page.locator('.modal [data-action="open-add-member"]').click();
  await page.waitForSelector('.modal-md [data-combo-input]', { timeout: 15000 });
  // la carga inicial de sugerencias es inmediata al enfocar
  await page.waitForSelector('.combo-list .combo-option, .combo-list .combo-msg', { timeout: 15000 });
  await page.waitForTimeout(300);
}

async function metrics(page) {
  return page.evaluate(() => {
    const modal = document.querySelector('.modal-md');
    const input = modal ? modal.querySelector('[data-combo-input]') : null;
    const list = modal ? modal.querySelector('.combo-list') : null;
    const chips = modal ? modal.querySelector('[data-combo-chips]') : null;
    const rect = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { width: Math.round(r.width), height: Math.round(r.height), top: Math.round(r.top) };
    };
    return {
      modal: rect(modal),
      focused: !!(input && document.activeElement === input),
      role: input ? input.getAttribute('role') : null,
      expanded: input ? input.getAttribute('aria-expanded') : null,
      listRole: list ? list.getAttribute('role') : null,
      listVisible: list ? getComputedStyle(list).display !== 'none' : false,
      options: modal ? modal.querySelectorAll('.combo-option').length : 0,
      chipCount: chips ? chips.querySelectorAll('.chip').length : 0,
      // Regresion v1.13.55: con la lista abierta el modal-body no debe
      // crecer su scrollHeight (apareceria una segunda barra de scroll).
      bodyScrollable: modal ? (() => {
        const body = modal.querySelector('.modal-body');
        return !!body && body.scrollHeight > body.clientHeight + 2;
      })() : null
    };
  });
}

async function main() {
  const browser = await chromium.launch({ headless: true });

  try {
    // ================= TEMA CLARO @1366 =================
    console.log('ADMIN: modal anadir miembro en tema claro @1366');
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await ctx.addInitScript(themeScript('light'));
    const page = await ctx.newPage();
    await login(page, 'aleon@acme.local', 'Admin123!');
    await openAddMember(page);

    const m1 = await metrics(page);
    check('modal-md presente (~640px)', m1.modal && m1.modal.width >= 560 && m1.modal.width <= 700,
      m1.modal && String(m1.modal.width));
    check('combobox con role=combobox', m1.role === 'combobox', m1.role);
    check('foco en el combobox', m1.focused === true);
    check('aria-expanded=true con lista visible',
      m1.expanded === 'true' && m1.listVisible === true, `${m1.expanded}/${m1.listVisible}`);
    check('lista role=listbox', m1.listRole === 'listbox', m1.listRole);
    check('sugerencias pintadas', m1.options >= 1, String(m1.options));
    check('modal-body sin scroll con la lista abierta (sin doble barra)', m1.bodyScrollable === false,
      String(m1.bodyScrollable));
    await page.screenshot({ path: path.join(OUT, 'modal-anadir-miembro-1366-light.png'), fullPage: false });

    // Seleccionar la primera sugerencia -> chip, lista reabierta
    await page.locator('.combo-option').first().click();
    await page.waitForTimeout(400);
    const m2 = await metrics(page);
    check('chip tras elegir', m2.chipCount >= 1, String(m2.chipCount));
    check('la lista sigue abierta con los restantes', m2.expanded === 'true', m2.expanded);
    check('modal-body sin scroll tras anadir chip', m2.bodyScrollable === false, String(m2.bodyScrollable));
    await page.screenshot({ path: path.join(OUT, 'modal-anadir-miembro-1366-seleccion.png'), fullPage: false });

    // Quitar el chip
    await page.locator('[data-action="combo-remove"]').first().click();
    await page.waitForTimeout(400);
    const m3 = await metrics(page);
    check('chip retirado', m3.chipCount === 0, String(m3.chipCount));

    // Esc cierra la lista, no el modal
    await page.keyboard.press('Escape');
    const m4 = await metrics(page);
    check('Esc cierra la lista y mantiene el modal',
      m4.expanded === 'false' && !!m4.modal, `${m4.expanded}/${!!m4.modal}`);
    await ctx.close();

    // ================= TEMA OSCURO @1366 =================
    console.log('ADMIN: modal anadir miembro en tema oscuro @1366');
    const ctxDark = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await ctxDark.addInitScript(themeScript('dark'));
    const page2 = await ctxDark.newPage();
    await login(page2, 'aleon@acme.local', 'Admin123!');
    await openAddMember(page2);
    const md = await metrics(page2);
    check('dark: html.dark activo',
      await page2.evaluate(() => document.documentElement.classList.contains('dark')));
    check('dark: modal-md presente', !!md.modal, JSON.stringify(md.modal));
    check('dark: sugerencias pintadas', md.options >= 1, String(md.options));
    await page2.screenshot({ path: path.join(OUT, 'modal-anadir-miembro-1366-dark.png'), fullPage: false });
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
