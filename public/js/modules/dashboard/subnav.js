/**
 * Subnav del módulo Inventory.
 *
 * Barra de pestañas bajo el header con TODAS las opciones de inventario
 * (los enlaces que antes estaban en la sidebar). Se muestra en el panel de
 * inventario (#/dashboard/inventory) y en todas las rutas de inventario
 * (#/inventory, #/inventory/*), de modo que desde cualquier opción se puede
 * saltar al resto sin volver al panel.
 *
 * Cada opción se pinta sólo si el usuario tiene el permiso correspondiente
 * (mismo contrato que la sidebar: permiso real del backend con fallback a
 * roles). No hay opción "Dashboard": esa vive en la sidebar.
 *
 * Responsive sin scroll horizontal:
 *   - Escritorio (>=768px): tira de pestañas (envuelve si no caben).
 *   - Móvil (<768px): desplegable (botón + listbox), nunca scroll lateral.
 *
 * Sticky: no se pierde con el scroll; se queda justo bajo el header
 * (top: var(--app-header-height), medida en init y al redimensionar).
 *
 * Accesibilidad: aria-haspopup/aria-expanded en el disparador, role=listbox
 * en el menú, role=option + aria-selected en cada opción, cierre con Escape y
 * con clic fuera, navegación con Enter/Espacio.
 */
(function(global) {
  'use strict';

  // Opciones de inventario (route, labelKey, permission, roles) — mismo
  // contrato de permisos que tenían en la sidebar.
  const ITEMS = [
    { route: 'inventory', labelKey: 'nav.products', permission: 'inventory.item.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/locations', labelKey: 'nav.locations', permission: 'inventory.location.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/receiving', labelKey: 'nav.receiving', permission: 'inventory.receiving.create', roles: ['ADMIN', 'MANAGER', 'USER'] },
    { route: 'inventory/stock', labelKey: 'nav.stock', permission: 'inventory.stock.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/movements', labelKey: 'nav.movements', permission: 'inventory.stock.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/reservations', labelKey: 'nav.reservations', permission: 'inventory.reservation.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/picking', labelKey: 'nav.picking', permission: 'inventory.picking.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/shipments', labelKey: 'nav.shipments', permission: 'inventory.shipping.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/deliveries', labelKey: 'nav.deliveries', permission: 'inventory.delivery.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/returns', labelKey: 'nav.returns', permission: 'inventory.return.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/incidents', labelKey: 'nav.incidents', permission: 'inventory.incident.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
    { route: 'inventory/counts', labelKey: 'nav.counts', permission: 'inventory.count.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] }
  ];

  let el = null;
  let tabsEl = null;
  let btnEl = null;
  let btnLabelEl = null;
  let menuEl = null;
  let clickOutHandler = null;
  let escHandler = null;
  let headerOffsetHandler = null;
  let headerObserver = null;
  let unsubscribers = [];

  // ============================================
  // HELPERS
  // ============================================

  function tOr(key, fallback) {
    if (global.t) {
      const v = global.t(key);
      if (v && v !== key) return v;
    }
    return fallback;
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function can(permission, fallbackRoles) {
    return global.UI && typeof global.UI.can === 'function'
      ? global.UI.can(permission, fallbackRoles)
      : false;
  }

  function currentRoute() {
    return global.AppRouter && typeof global.AppRouter.getCurrentRoute === 'function'
      ? global.AppRouter.getCurrentRoute()
      : null;
  }

  // Altura real del header: la barra queda sticky justo debajo con
  // top: var(--app-header-height). Se recalcula en cada render (rutas),
  // al redimensionar y con ResizeObserver: al init el shell puede estar
  // en modo público (header con display:none) y mediria 0.
  function syncHeaderOffset() {
    const doc = global.document;
    if (!doc || !doc.documentElement) return;
    const header = doc.querySelector('.app-header');
    const height = header ? header.offsetHeight : 0;
    doc.documentElement.style.setProperty('--app-header-height', height + 'px');
  }

  function observeHeaderOffset() {
    if (typeof global.ResizeObserver !== 'function') return;
    const header = global.document && global.document.querySelector('.app-header');
    if (!header) return;
    if (headerObserver) headerObserver.disconnect();
    headerObserver = new global.ResizeObserver(() => syncHeaderOffset());
    headerObserver.observe(header);
  }

  function isInventoryRoute(route) {
    if (typeof route !== 'string') return false;
    return route === 'dashboard/inventory' || route === 'inventory' || route.indexOf('inventory/') === 0;
  }

  function visibleItems() {
    return ITEMS.filter(item => {
      // Sin enlaces muertos: sólo si el módulo JS de la ruta existe.
      if (global.AppRouter && typeof global.AppRouter.isModuleAvailable === 'function') {
        if (!global.AppRouter.isModuleAvailable(item.route)) return false;
      }
      return can(item.permission, item.roles);
    });
  }

  function labelFor(item) {
    return tOr(item.labelKey, item.route);
  }

  // ============================================
  // RENDER
  // ============================================

  function render() {
    syncHeaderOffset();

    if (!el) return;

    const route = currentRoute();
    const items = visibleItems();

    if (!isInventoryRoute(route) || items.length === 0) {
      el.hidden = true;
      return;
    }

    el.hidden = false;

    // Pestañas (escritorio)
    tabsEl.innerHTML = items.map(item => {
      const active = route === item.route;
      return `<a class="subnav-tab${active ? ' is-active' : ''}" data-route="${escapeHtml(item.route)}" href="#/${escapeHtml(item.route)}"${active ? ' aria-current="page"' : ''}>${escapeHtml(labelFor(item))}</a>`;
    }).join('');

    // Desplegable (móvil): label del item activo + opciones
    const activeItem = items.find(item => route === item.route) || items[0];
    if (btnLabelEl) btnLabelEl.textContent = labelFor(activeItem);

    menuEl.innerHTML = items.map(item => {
      const active = route === item.route;
      return `<li role="option" aria-selected="${active ? 'true' : 'false'}" class="subnav-select-option${active ? ' is-active' : ''}" data-route="${escapeHtml(item.route)}" tabindex="0">${escapeHtml(labelFor(item))}</li>`;
    }).join('');
  }

  function openMenu(open) {
    if (!menuEl || !btnEl) return;
    menuEl.hidden = !open;
    btnEl.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function navigateTo(route) {
    if (route && global.AppRouter) {
      global.AppRouter.navigate(route);
    }
  }

  // ============================================
  // EVENTS
  // ============================================

  function handleClick(e) {
    if (!el) return;

    // Disparador del desplegable (móvil)
    if (e.target.closest('#subnav-select-btn')) {
      openMenu(menuEl.hidden);
      return;
    }

    // Pestaña (escritorio) u opción del menú (móvil)
    const item = e.target.closest('[data-route]');
    if (item && el.contains(item)) {
      e.preventDefault();
      openMenu(false);
      navigateTo(item.dataset.route);
    }
  }

  function handleKeydown(e) {
    if (!el) return;

    if (e.key === 'Escape') {
      openMenu(false);
      return;
    }

    if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('.subnav-select-option')) {
      e.preventDefault();
      const item = e.target.closest('[data-route]');
      if (item) {
        openMenu(false);
        navigateTo(item.dataset.route);
      }
    }
  }

  // ============================================
  // PUBLIC API
  // ============================================

  const InventorySubnav = {
    init() {
      el = global.document && global.document.getElementById('inventory-subnav');
      if (!el) return;

      syncHeaderOffset();
      observeHeaderOffset();
      headerOffsetHandler = () => syncHeaderOffset();
      global.addEventListener('resize', headerOffsetHandler);

      // data-i18n-aria: applyDom() de i18n.js reescribe el aria-label en cada
      // aplicacion de diccionario (arranque, login y cambio de idioma). El
      // valor inline es solo el estado inicial mientras carga la traduccion.
      const navLabel = escapeHtml(tOr('nav.inventory', 'Inventory'));

      el.innerHTML = `
        <nav class="subnav" aria-label="${navLabel}" data-i18n-aria="nav.inventory">
          <div class="subnav-tabs" data-subnav-tabs></div>
          <div class="subnav-select">
            <button id="subnav-select-btn" class="subnav-select-btn" type="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="subnav-select-menu">
              <span data-subnav-btn-label></span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="subnav-select-chevron" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <ul id="subnav-select-menu" class="subnav-select-menu" role="listbox" aria-label="${navLabel}" data-i18n-aria="nav.inventory" hidden data-subnav-menu></ul>
          </div>
        </nav>`;

      tabsEl = el.querySelector('[data-subnav-tabs]');
      btnEl = el.querySelector('#subnav-select-btn');
      btnLabelEl = el.querySelector('[data-subnav-btn-label]');
      menuEl = el.querySelector('[data-subnav-menu]');

      el.addEventListener('click', handleClick);
      el.addEventListener('keydown', handleKeydown);

      // Cerrar el desplegable al pulsar fuera o con Escape (patrón del shell).
      clickOutHandler = (e) => { if (!el.contains(e.target)) openMenu(false); };
      global.document.addEventListener('click', clickOutHandler);
      escHandler = (e) => { if (e.key === 'Escape') openMenu(false); };
      global.document.addEventListener('keydown', escHandler);

      // Re-render en cambios de ruta (visibilidad y pestaña activa).
      if (global.EventBus && typeof global.EventBus.on === 'function') {
        unsubscribers.push(global.EventBus.on('route:loaded', render));
        unsubscribers.push(global.EventBus.on('route:activated', render));
      }

      render();
    },

    render,

    destroy() {
      if (el) {
        el.removeEventListener('click', handleClick);
        el.removeEventListener('keydown', handleKeydown);
        el.innerHTML = '';
        el.hidden = true;
      }
      if (clickOutHandler) global.document.removeEventListener('click', clickOutHandler);
      if (escHandler) global.document.removeEventListener('keydown', escHandler);
      if (headerOffsetHandler) global.removeEventListener('resize', headerOffsetHandler);
      headerOffsetHandler = null;
      if (headerObserver) headerObserver.disconnect();
      headerObserver = null;
      unsubscribers.forEach(off => { if (typeof off === 'function') off(); });
      unsubscribers = [];
      el = null;
      tabsEl = null;
      btnEl = null;
      btnLabelEl = null;
      menuEl = null;
      clickOutHandler = null;
      escHandler = null;
    }
  };

  global.InventorySubnav = InventorySubnav;
})(window);
