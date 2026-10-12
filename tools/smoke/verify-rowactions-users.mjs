/**
 * CIERRE rowActions Users: capturas y comprobaciones Playwright.
 * Uso: node tools/smoke/verify-rowactions-users.mjs
 *
 * Capturas en tools/smoke/capturas-rowactions/ de la vista Users:
 *  - admin (aleon@acme.local, rol ADMIN) @1920 y @1366
 *  - manager (manager@acme.local, rol MANAGER) @1920 y @1366
 * Comprueba: sin scroll horizontal de la tabla, columna de acciones a la
 * derecha, chips sin partir, menu de tres puntos accesible (aria), opciones
 * por grupos con Eliminar al final en rojo, menu dentro del viewport, sin
 * Eliminar/Estado sobre el propio usuario, escalada sobre ADMIN para un
 * actor no ADMIN y ?tab= en la URL.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const BASE = 'http://localhost/xp/public';
const OUT = path.resolve('C:/xampp/htdocs/xp/tools/smoke/capturas-rowactions');
mkdirSync(OUT, { recursive: true });

let pass = 0;
let fail = 0;
function check(name, cond, extra) {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${extra ? ' :: ' + extra : ''}`); }
}

async function login(page, email, pass) {
  await page.goto(BASE + '/#login', { waitUntil: 'networkidle' });
  await page.waitForSelector('input[type="email"], input[name="email"], #email', { timeout: 15000 });
  await page.locator('input[type="email"], input[name="email"], #email').first().fill(email);
  await page.locator('input[type="password"], input[name="password"], #password').first().fill(pass);
  await page.locator('button[type="submit"], form button').first().click();
  await page.waitForURL(/dashboard|\/#\/dashboard/, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1200);
}

async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: false });
}

function rowByEmail(page, email) {
  return page.evaluate((mail) => {
    const rows = Array.from(document.querySelectorAll('tbody tr'));
    const row = rows.find(r => (r.textContent || '').includes(mail));
    if (!row) return null;
    const acts = Array.from(row.querySelectorAll('[data-action]')).map(b => ({
      action: b.dataset.action,
      danger: b.classList.contains('danger'),
      label: (b.textContent || '').trim()
    }));
    const last = acts.length ? acts[acts.length - 1] : null;
    return { acts, last };
  }, email);
}

async function tableMetrics(page) {
  return page.evaluate(() => {
    const wrap = document.querySelector('[data-users-table]');
    const wrapper = wrap ? wrap.closest('.table-wrapper') : null;
    const col = document.querySelector('.table th.col-actions');
    const badge = document.querySelector('.table td .badge');
    const clip = document.querySelector('.table td .cell-clip');
    return {
      hasTable: !!(wrap && wrap.querySelector('table')),
      hScroll: wrapper ? (wrapper.scrollWidth > wrapper.clientWidth + 1) : null,
      colAlign: col ? window.getComputedStyle(col).textAlign : null,
      badgeNowrap: badge ? window.getComputedStyle(badge).whiteSpace : null,
      clipOverflow: clip ? window.getComputedStyle(clip).overflow : null,
      triggers: document.querySelectorAll('[data-row-menu-trigger]').length
    };
  });
}

async function openFirstMenu(page) {
  const trigger = page.locator('[data-row-menu-trigger]').first();
  await trigger.click();
  await page.waitForTimeout(250);
  return page.evaluate(() => {
    const menu = document.querySelector('[data-row-menu].is-open');
    if (!menu) return null;
    const items = Array.from(menu.querySelectorAll('.dropdown-item')).map(b => ({
      action: b.dataset.action,
      danger: b.classList.contains('danger'),
      label: (b.textContent || '').trim()
    }));
    const dividers = menu.querySelectorAll('.dropdown-divider').length;
    const r = menu.getBoundingClientRect();
    return {
      items, dividers,
      ariaExpanded: menu.parentElement.querySelector('[data-row-menu-trigger]').getAttribute('aria-expanded'),
      ariaHaspopup: menu.parentElement.querySelector('[data-row-menu-trigger]').getAttribute('aria-haspopup'),
      insideViewport: r.top >= 0 && r.left >= 0 &&
        r.bottom <= (document.documentElement.clientHeight || window.innerHeight) + 1 &&
        r.right <= (document.documentElement.clientWidth || window.innerWidth) + 1,
      rect: { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right) }
    };
  });
}

async function closeMenu(page) {
  await page.keyboard.press('Escape');
  await page.waitForTimeout(150);
  return page.evaluate(() => ({
    open: !!document.querySelector('[data-row-menu].is-open'),
    aria: (() => {
      const t = document.querySelector('[data-row-menu-trigger]');
      return t ? t.getAttribute('aria-expanded') : null;
    })()
  }));
}

const SIZES = [
  { label: '1920', width: 1920, height: 1080 },
  { label: '1366', width: 1366, height: 768 }
];

async function main() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();

  try {
    // ================= ADMIN (rol ADMIN) =================
    console.log('ADMIN (aleon@acme.local): Users a 1920 y 1366');
    await login(page, 'aleon@acme.local', 'Admin123!');

    for (const size of SIZES) {
      await page.setViewportSize({ width: size.width, height: size.height });
      await page.goto(BASE + '/#/users', { waitUntil: 'networkidle' });
      await page.waitForTimeout(1500);

      await shot(page, `users-admin-${size.label}`);

      const m = await tableMetrics(page);
      check(`admin@${size.label} tabla renderizada`, m.hasTable);
      check(`admin@${size.label} sin scroll horizontal`, m.hScroll === false, `hScroll=${m.hScroll}`);
      check(`admin@${size.label} columna acciones a la derecha`, m.colAlign === 'right', `align=${m.colAlign}`);
      check(`admin@${size.label} chips sin partir (nowrap)`, m.badgeNowrap === 'nowrap', `ws=${m.badgeNowrap}`);
      check(`admin@${size.label} celda roles recorta`, m.clipOverflow === 'hidden', `ov=${m.clipOverflow}`);
      check(`admin@${size.label} menues de fila presentes`, m.triggers > 0, `triggers=${m.triggers}`);

      const menu = await openFirstMenu(page);
      check(`admin@${size.label} menu abre (aria-expanded)`,
        !!menu && menu.ariaExpanded === 'true' && menu.ariaHaspopup === 'menu',
        JSON.stringify(menu && { a: menu.ariaExpanded, h: menu.ariaHaspopup }));
      if (menu) {
        const actions = menu.items.map(i => i.action);
        check(`admin@${size.label} principal Acciones: Roles, Ver permisos temporales`,
          actions.includes('open-roles') && actions.includes('open-temp-user'), JSON.stringify(actions));
        check(`admin@${size.label} menu: Cambiar estado y Restablecer contrasena`,
          actions.includes('open-status') && actions.includes('open-reset'), JSON.stringify(actions));
        check(`admin@${size.label} menu: Eliminar al final y en rojo`,
          actions[actions.length - 1] === 'open-delete' && menu.items[menu.items.length - 1].danger,
          JSON.stringify(menu.items[menu.items.length - 1]));
        check(`admin@${size.label} grupos separados por linea`, menu.dividers >= 2, `dividers=${menu.dividers}`);
        check(`admin@${size.label} menu dentro del viewport`, menu.insideViewport, JSON.stringify(menu.rect));
        await shot(page, `users-admin-${size.label}-menu`);
      }

      const closed = await closeMenu(page);
      check(`admin@${size.label} Esc cierra el menu`,
        closed.open === false && closed.aria === 'false', JSON.stringify(closed));

      // Escalada: sobre el propio usuario (aleon) sin Eliminar ni Estado.
      const self = await rowByEmail(page, 'aleon@acme.local');
      check(`admin@${size.label} sin Eliminar ni Estado sobre si mismo`,
        !!self && !self.acts.some(a => a.action === 'open-delete' || a.action === 'open-status'),
        JSON.stringify(self && self.acts));
      // Sobre otro usuario no-ADMIN sí hay ambas.
      const otro = await rowByEmail(page, 'fgal@acme.local');
      check(`admin@${size.label} sobre otro usuario: Estado y Eliminar`,
        !!otro && otro.acts.some(a => a.action === 'open-status') && otro.acts.some(a => a.action === 'open-delete'),
        JSON.stringify(otro && otro.acts));

      // ?tab= en la URL.
      await page.locator('[data-action="tab"][data-tab="temp"]').click();
      await page.waitForTimeout(800);
      const hash = await page.evaluate(() => window.location.hash);
      check(`admin@${size.label} pestana Temporales reflejada en la URL`, /tab=temp/.test(hash), hash);
      await page.goto(BASE + '/#/users?tab=users', { waitUntil: 'networkidle' });
      await page.waitForTimeout(800);
    }

    // ================= MANAGER =================
    console.log('MANAGER (manager@acme.local): Users a 1920 y 1366');
    await page.goto(BASE + '/#login', { waitUntil: 'networkidle' });
    await page.evaluate(() => { try { window.AuthState && window.AuthState.logout(); } catch (e) {} });
    await page.goto(BASE + '/#login', { waitUntil: 'networkidle' });
    await login(page, 'manager@acme.local', 'Admin123!');

    for (const size of SIZES) {
      await page.setViewportSize({ width: size.width, height: size.height });
      await page.goto(BASE + '/#/users', { waitUntil: 'networkidle' });
      await page.waitForTimeout(1500);

      await shot(page, `users-manager-${size.label}`);

      const m = await tableMetrics(page);
      check(`manager@${size.label} sin scroll horizontal`, m.hScroll === false, `hScroll=${m.hScroll}`);
      check(`manager@${size.label} columna acciones a la derecha`, m.colAlign === 'right', `align=${m.colAlign}`);

      if (m.hasTable) {
        // Escalada: actor no-ADMIN no actua sobre ADMIN (aleon).
        const adminRow = await rowByEmail(page, 'aleon@acme.local');
        check(`manager@${size.label} sin acciones sobre ADMIN`,
          !!adminRow && !adminRow.acts.some(a => a.action === 'open-delete' || a.action === 'open-status'),
          JSON.stringify(adminRow && adminRow.acts));

        // Escalada: sin Eliminar/Estado sobre si mismo.
        const self = await rowByEmail(page, 'manager@acme.local');
        check(`manager@${size.label} sin Eliminar ni Estado sobre si mismo`,
          !!self && !self.acts.some(a => a.action === 'open-delete' || a.action === 'open-status'),
          JSON.stringify(self && self.acts));

        // Menú: sin Eliminar (sin permiso) y sin Roles (sin users.user.view).
        const menu = await openFirstMenu(page);
        if (menu) {
          const actions = menu.items.map(i => i.action);
          check(`manager@${size.label} menu sin Eliminar (sin permiso)`,
            !actions.includes('open-delete'), JSON.stringify(actions));
          check(`manager@${size.label} menu dentro del viewport`, menu.insideViewport, JSON.stringify(menu.rect));
          await shot(page, `users-manager-${size.label}-menu`);
          await closeMenu(page);
        } else {
          check(`manager@${size.label} menu abre`, false, 'sin menu abierto');
        }
      }
    }
  } finally {
    await browser.close();
  }

  console.log(`RESULTADO: ${pass} OK, ${fail} FAIL`);
  console.log('Capturas en ' + OUT);
  if (fail > 0) process.exit(1);
}

main().catch(err => { console.error(err); process.exit(1); });
