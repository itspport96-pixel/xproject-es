/**
 * Subnav del módulo Inventory.
 *
 * Barra bajo el header con las opciones de inventario AGRUPADAS por flujo
 * de trabajo (Catálogo, Entradas, Stock, Salidas, Incidencias). Se muestra
 * en el panel de inventario (#/dashboard/inventory) y en todas las rutas de
 * inventario (#/inventory, #/inventory/*), de modo que desde cualquier opción
 * se puede saltar al resto sin volver al panel.
 *
 * La agrupación (12 rutas -> 5 elementos) resuelve el desbordamiento a
 * segunda fila en idiomas largos: con las 12 pestañas planas, el francés a
 * 1024px con la sidebar abierta ocupaba 1041px contra 748px disponibles y se
 * partía en 2 filas (36px -> 75px de alto). Agrupado son ~530px en francés.
 *
 * Cada opción se pinta sólo si el usuario tiene el permiso correspondiente
 * (mismo contrato que la sidebar: permiso real del backend con fallback a
 * roles). No hay opción "Dashboard": esa vive en la sidebar.
 *
 * Reglas de agrupación:
 *   - Grupo con 2+ opciones visibles: disparador de menú (SÓLO abre el menú,
 *     no navega) + menú con las opciones.
 *   - Grupo con UNA opción visible: colapsa a pestaña directa.
 *   - Grupo con 0 opciones visibles: no se pinta.
 *
 * El menú de grupo reutiliza el patrón dropdown ya sancionado
 * (.dropdown / .dropdown-menu / .dropdown-item de components.css, el mismo
 * que UI.rowActions): no se reimplementa (regla 13).
 *
 * Responsive sin scroll horizontal:
 *   - Escritorio (>=768px): tira de pestañas + menús de grupo.
 *   - Móvil (<768px): desplegable (botón + listbox) con los grupos como
 *     encabezados no clicables, nunca scroll lateral.
 *
 * Sticky: no se pierde con el scroll; se queda justo bajo el header
 * (top: var(--app-header-height), medida en init y al redimensionar).
 *
 * Accesibilidad: aria-haspopup="menu" + aria-expanded en cada disparador de
 * grupo, role=menu en el menú y role=menuitem en cada opción; el menú se
 * cierra con Escape (devolviendo el foco a su disparador) y con clic fuera;
 * en el desplegable móvil, role=listbox + role=option + aria-selected.
 */
(function(global) {
  'use strict';

  // Grupos de inventario por flujo de trabajo. Cada item conserva EXACTAMENTE
  // el permission/roles que tenia en la tira plana anterior (mismo gateo).
  const GROUPS = [
    {
      id: 'catalog',
      labelKey: 'nav.group.catalog',
      items: [
        { route: 'inventory', labelKey: 'nav.products', permission: 'inventory.item.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { route: 'inventory/locations', labelKey: 'nav.locations', permission: 'inventory.location.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] }
      ]
    },
    {
      id: 'intake',
      labelKey: 'nav.group.intake',
      items: [
        { route: 'inventory/receiving', labelKey: 'nav.receiving', permission: 'inventory.receiving.create', roles: ['ADMIN', 'MANAGER', 'USER'] },
        { route: 'inventory/returns', labelKey: 'nav.returns', permission: 'inventory.return.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] }
      ]
    },
    {
      id: 'stock',
      labelKey: 'nav.group.stock',
      items: [
        { route: 'inventory/stock', labelKey: 'nav.stock', permission: 'inventory.stock.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { route: 'inventory/movements', labelKey: 'nav.movements', permission: 'inventory.stock.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { route: 'inventory/reservations', labelKey: 'nav.reservations', permission: 'inventory.reservation.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { route: 'inventory/counts', labelKey: 'nav.counts', permission: 'inventory.count.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] }
      ]
    },
    {
      id: 'outbound',
      labelKey: 'nav.group.outbound',
      items: [
        { route: 'inventory/picking', labelKey: 'nav.picking', permission: 'inventory.picking.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { route: 'inventory/shipments', labelKey: 'nav.shipments', permission: 'inventory.shipping.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { route: 'inventory/deliveries', labelKey: 'nav.deliveries', permission: 'inventory.delivery.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] }
      ]
    },
    {
      id: 'incidents',
      // Sin labelKey: siempre colapsa a pestaña directa (un solo item), asi
      // que la etiqueta del grupo no se usa nunca.
      items: [
        { route: 'inventory/incidents', labelKey: 'nav.incidents', permission: 'inventory.incident.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] }
      ]
    }
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

  // Menú de grupo abierto en escritorio (patrón de UI.rowActions).
  let openGroupMenu = null;
  let openGroupTrigger = null;

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

  function itemVisible(item) {
    // Sin enlaces muertos: sólo si el módulo JS de la ruta existe.
    if (global.AppRouter && typeof global.AppRouter.isModuleAvailable === 'function') {
      if (!global.AppRouter.isModuleAvailable(item.route)) return false;
    }
    return can(item.permission, item.roles);
  }

  // Grupos con sus items ya filtrados; los grupos sin ninguno visible se
  // descartan por completo.
  function visibleGroups() {
    return GROUPS
      .map(group => ({
        id: group.id,
        labelKey: group.labelKey,
        items: (group.items || []).filter(itemVisible)
      }))
      .filter(group => group.items.length > 0);
  }

  function labelFor(item) {
    return tOr(item.labelKey, item.route);
  }

  // ============================================
  // RENDER
  // ============================================

  function renderTab(item, route) {
    const active = route === item.route;
    return `<a class="subnav-tab${active ? ' is-active' : ''}" data-route="${escapeHtml(item.route)}" href="#/${escapeHtml(item.route)}"${active ? ' aria-current="page"' : ''}>${escapeHtml(labelFor(item))}</a>`;
  }

  function renderGroup(group, route) {
    // Un solo hijo visible: colapsa a pestaña directa (sin menú).
    if (group.items.length === 1) return renderTab(group.items[0], route);

    const active = group.items.some(item => route === item.route);
    const menuId = 'subnav-group-' + group.id;
    const menuHtml = group.items.map(item => {
      const isActive = route === item.route;
      return `<a role="menuitem" class="dropdown-item${isActive ? ' is-active' : ''}" data-route="${escapeHtml(item.route)}" href="#/${escapeHtml(item.route)}"${isActive ? ' aria-current="page"' : ''}>${escapeHtml(labelFor(item))}</a>`;
    }).join('');

    // El disparador SÓLO abre el menú: no lleva data-route ni href, de modo
    // que nunca navega por accidente.
    return `
      <div class="dropdown subnav-group">
        <button type="button" class="subnav-tab subnav-group-btn${active ? ' is-active' : ''}" data-group-toggle
                aria-haspopup="menu" aria-expanded="false" aria-controls="${menuId}">
          ${escapeHtml(tOr(group.labelKey, group.id))}
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="subnav-chevron" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div id="${menuId}" class="dropdown-menu" data-group-menu role="menu" aria-hidden="true" hidden>${menuHtml}</div>
      </div>`;
  }

  function render() {
    syncHeaderOffset();

    if (!el) return;

    const route = currentRoute();
    const groups = visibleGroups();

    if (!isInventoryRoute(route) || groups.length === 0) {
      el.hidden = true;
      return;
    }

    el.hidden = false;
    closeGroupMenu(false);
    openMobileMenu(false);

    // Pestañas (escritorio): directas o con menú de grupo.
    tabsEl.innerHTML = groups.map(group => renderGroup(group, route)).join('');

    // Desplegable (móvil): label del item activo + opciones con encabezados
    const flat = groups.reduce((acc, group) => acc.concat(group.items), []);
    const activeItem = flat.find(item => route === item.route) || flat[0];
    if (btnLabelEl) btnLabelEl.textContent = labelFor(activeItem);

    menuEl.innerHTML = groups.map(group => {
      const header = group.labelKey
        ? `<li role="presentation" class="subnav-select-group" aria-hidden="true">${escapeHtml(tOr(group.labelKey, group.id))}</li>`
        : '';
      const opts = group.items.map(item => {
        const active = route === item.route;
        return `<li role="option" aria-selected="${active ? 'true' : 'false'}" class="subnav-select-option${active ? ' is-active' : ''}" data-route="${escapeHtml(item.route)}" tabindex="0">${escapeHtml(labelFor(item))}</li>`;
      }).join('');
      return header + opts;
    }).join('');
  }

  // Desplegable móvil (botón + listbox).
  function openMobileMenu(open) {
    if (!menuEl || !btnEl) return;
    menuEl.hidden = !open;
    btnEl.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  // Menú de grupo (escritorio): una sola instancia abierta a la vez.
  function closeGroupMenu(restoreFocus) {
    if (!openGroupMenu || !openGroupTrigger) return;
    openGroupMenu.hidden = true;
    openGroupMenu.setAttribute('aria-hidden', 'true');
    openGroupTrigger.setAttribute('aria-expanded', 'false');
    if (restoreFocus && typeof openGroupTrigger.focus === 'function') openGroupTrigger.focus();
    openGroupMenu = null;
    openGroupTrigger = null;
  }

  function toggleGroupMenu(trigger) {
    const menu = trigger.parentElement && trigger.parentElement.querySelector('[data-group-menu]');
    if (!menu) return;
    const wasOpen = openGroupTrigger === trigger;
    closeGroupMenu(false);
    if (wasOpen) return;
    openGroupMenu = menu;
    openGroupTrigger = trigger;
    menu.hidden = false;
    menu.setAttribute('aria-hidden', 'false');
    trigger.setAttribute('aria-expanded', 'true');
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
      openMobileMenu(menuEl.hidden);
      return;
    }

    // Disparador de grupo (escritorio): sólo abre/cierra su menú.
    const groupBtn = e.target.closest('[data-group-toggle]');
    if (groupBtn && el.contains(groupBtn)) {
      e.preventDefault();
      toggleGroupMenu(groupBtn);
      return;
    }

    // Pestaña directa (escritorio), opción de menú de grupo u opción del
    // desplegable móvil: todas llevan data-route.
    const item = e.target.closest('[data-route]');
    if (item && el.contains(item)) {
      e.preventDefault();
      closeGroupMenu(false);
      openMobileMenu(false);
      navigateTo(item.dataset.route);
    }
  }

  function handleKeydown(e) {
    if (!el) return;

    if (e.key === 'Escape') {
      // Con un menú de grupo abierto, Escape lo cierra y devuelve el foco a
      // su disparador; si no, cierra el desplegable móvil.
      if (openGroupMenu) {
        closeGroupMenu(true);
        return;
      }
      openMobileMenu(false);
      return;
    }

    if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('.subnav-select-option')) {
      e.preventDefault();
      const item = e.target.closest('[data-route]');
      if (item) {
        openMobileMenu(false);
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

      // Cerrar el desplegable móvil y el menú de grupo al pulsar fuera o con
      // Escape (patrón del shell / UI.rowActions).
      clickOutHandler = (e) => {
        if (!el.contains(e.target)) {
          closeGroupMenu(false);
          openMobileMenu(false);
        }
      };
      global.document.addEventListener('click', clickOutHandler);
      escHandler = (e) => {
        if (e.key === 'Escape') {
          closeGroupMenu(false);
          openMobileMenu(false);
        }
      };
      global.document.addEventListener('keydown', escHandler);

      // Re-render en cambios de ruta (visibilidad y pestaña activa). El
      // repintado al cambiar de idioma llega via route:loaded: i18n:changed
      // -> AppRouter.refresh() anula currentRoute y vuelve a enrutar.
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
      openGroupMenu = null;
      openGroupTrigger = null;
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
