/**
 * App Sidebar - Navegación lateral persistente
 *
 * Responsabilidades:
 * - Renderizar menú con secciones agrupadas
 * - Filtrar items según roles del usuario
 * - Marcar item activo según ruta
 * - Soporte mobile (colapsable con overlay)
 * - Escuchar eventos del router y auth
 *
 * Iconos: SVG inline (sin librería externa, funciona con currentColor).
 */
(function(global) {
  'use strict';

  // ============================================
  // ICONOS SVG (inline, escalables, currentColor)
  // ============================================

  const ICONS = {
    dashboard: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>',
    box: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    mapPin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    inbox: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
    layers: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
    users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    shield: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>',
    checkList: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5h11"/><path d="M9 12h11"/><path d="M9 19h11"/><polyline points="3 5 4.5 6.5 7 4"/><polyline points="3 12 4.5 13.5 7 11"/><polyline points="3 19 4.5 20.5 7 18"/></svg>',
    truck: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="6" width="14" height="11" rx="1"/><path d="M15 9h3.5l3.5 3.5V17h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="19" r="2"/></svg>',
    checkCircle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    undo: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
    settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    clipboard: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>',
    exchange: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
    chevron: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>',
    logOut: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>'
  };

  // ============================================
  // CONFIGURACIÓN DEL MENÚ
  // ============================================

  // labelKey/sectionKey: claves i18n resueltas con t() en cada render
  // (cambio de idioma sin recarga).
  const MENU_CONFIG = [
    {
      sectionKey: 'nav.section_principal',
      items: [
        { labelKey: 'nav.dashboard', route: 'dashboard', icon: 'dashboard', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] }
      ]
    },
    {
      sectionKey: 'nav.section_inventory',
      items: [
        { labelKey: 'nav.products', route: 'inventory', icon: 'box', permission: 'inventory.item.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.locations', route: 'inventory/locations', icon: 'mapPin', permission: 'inventory.location.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.receiving', route: 'inventory/receiving', icon: 'inbox', permission: 'inventory.receiving.create', roles: ['ADMIN', 'MANAGER', 'USER'] },
        { labelKey: 'nav.stock', route: 'inventory/stock', icon: 'layers', permission: 'inventory.stock.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.movements', route: 'inventory/movements', icon: 'exchange', permission: 'inventory.stock.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.reservations', route: 'inventory/reservations', icon: 'clock', permission: 'inventory.reservation.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.picking', route: 'inventory/picking', icon: 'checkList', permission: 'inventory.picking.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.shipments', route: 'inventory/shipments', icon: 'truck', permission: 'inventory.shipping.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.deliveries', route: 'inventory/deliveries', icon: 'checkCircle', permission: 'inventory.delivery.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.returns', route: 'inventory/returns', icon: 'undo', permission: 'inventory.return.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.incidents', route: 'inventory/incidents', icon: 'alert', permission: 'inventory.incident.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.counts', route: 'inventory/counts', icon: 'clipboard', permission: 'inventory.count.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] }
      ]
    },
    {
      sectionKey: 'nav.section_admin',
      items: [
        { labelKey: 'nav.users', route: 'users', icon: 'users', permission: 'users.user.view', roles: ['ADMIN', 'MANAGER'] },
        { labelKey: 'nav.roles', route: 'roles', icon: 'shield', permission: 'roles.role.view', roles: ['ADMIN'] },
        { labelKey: 'nav.groups', route: 'groups', icon: 'users', permission: 'groups.group.view', roles: ['ADMIN', 'MANAGER', 'USER', 'VIEWER'] },
        { labelKey: 'nav.audit', route: 'audit', icon: 'shield', permission: 'audit.log.view', roles: ['ADMIN', 'MANAGER'] }
      ]
    },
    {
      sectionKey: 'nav.section_system',
      items: [
        { labelKey: 'nav.settings', route: 'settings', icon: 'settings', roles: ['ADMIN'] }
      ]
    }
  ];

  // ============================================
  // ESTADO
  // ============================================

  let container = null;
  let currentRoute = '';
  let isOpen = false;
  let initialized = false;
  let userRoles = [];

  // Estado de colapso de los grupos: se guarda tras cada clic del usuario
  // mediante el wrapper Storage (namespace xp.) sobre localStorage. Lectura y
  // escritura con try/catch: si no hay storage disponible (modo privado,
  // cuota llenada, entorno sin Storage) el sidebar sigue funcionando y el
  // fallback de lectura es cerrado.
  const SECTION_STORAGE_PREFIX = 'sidebar.section.';

  function readSectionExpanded(sectionKey) {
    try {
      if (global.Storage && typeof global.Storage.get === 'function') {
        return global.Storage.get(SECTION_STORAGE_PREFIX + sectionKey, false) === true;
      }
    } catch (e) {
      // Sin storage legible: fallback a cerrado.
    }
    return false;
  }

  function writeSectionExpanded(sectionKey, expanded) {
    try {
      if (global.Storage && typeof global.Storage.set === 'function') {
        global.Storage.set(SECTION_STORAGE_PREFIX + sectionKey, expanded === true);
      }
    } catch (e) {
      // Sin storage escribible: la sesion actual sigue con su estado en el DOM.
    }
  }

  // Precedencia: la ruta activa dentro del grupo lo abre siempre; si no,
  // el estado guardado por el usuario; si no hay valor (o la lectura
  // falló), cerrado.
  function isSectionExpanded(sectionKey, items) {
    const activeInside = (items || []).some(item =>
      item.route === currentRoute ||
      (currentRoute && currentRoute.startsWith(item.route + '/'))
    );
    if (activeInside) return true;
    return readSectionExpanded(sectionKey);
  }

  // ============================================
  // RENDER
  // ============================================

  function render() {
    if (!container) return;

    const filteredMenu = filterMenuByRoles();

    container.innerHTML = `
      <div class="sidebar-header">
        ${renderBrand()}
        <button class="sidebar-toggle-mobile" aria-label="${escapeHtml(t('shell.sidebar_close'))}">${ICONS.close}</button>
      </div>
      <nav class="sidebar-nav" role="navigation" aria-label="${escapeHtml(t('shell.sidebar_menu'))}">
        ${renderSections(filteredMenu)}
      </nav>
      <div class="sidebar-footer">
        <div class="sidebar-user">
          <div class="avatar avatar-sm">${getUserInitials()}</div>
          <div class="sidebar-user-info">
            <div class="sidebar-user-name">${escapeHtml(getUserName())}</div>
            <div class="sidebar-user-role">${escapeHtml(getUserRole())}</div>
          </div>
          <button type="button" class="sidebar-logout" id="sidebar-logout-btn"
                  aria-label="${escapeHtml(t('shell.logout'))}" title="${escapeHtml(t('shell.logout'))}">${ICONS.logOut}</button>
        </div>
      </div>
    `;

    bindSidebarEvents();
    updateActiveItem();
  }

  // Marca del tenant (v1.14.0): logo subido o inicial sobre el color de
  // marca, nombre de la organización y, debajo, el nombre de la app.
  function renderBrand() {
    const appName = global.AppConfig?.appName || 'XP Platform';
    const branding = global.TenantBranding ? global.TenantBranding.get() : null;
    const tenantName = (branding && branding.nombre_publico) || '';
    const title = tenantName || appName;
    const logo = branding && global.TenantBranding.logoSrc(branding.logo_url);
    const mark = logo
      ? `<img class="sidebar-brand-logo" src="${escapeHtml(logo)}" alt="" data-testid="sidebar-brand-logo">`
      : `<span class="sidebar-brand-mark" aria-hidden="true">${escapeHtml(title.trim().charAt(0).toUpperCase() || 'X')}</span>`;
    const sub = tenantName ? `<span class="sidebar-brand-sub">${escapeHtml(appName)}</span>` : '';
    return `
        <div class="sidebar-brand" data-testid="sidebar-brand" title="${escapeHtml(title)}">
          ${mark}
          <span class="sidebar-brand-text"><span class="sidebar-brand-name">${escapeHtml(title)}</span>${sub}</span>
        </div>`;
  }

  function renderSections(menu) {
    return menu.map((section, idx) => {
      if (section.items.length === 0) return '';
      const title = section.sectionKey ? t(section.sectionKey) : section.section;
      const sectionKey = section.sectionKey || `section_${idx}`;
      const panelId = `sidebar-section-${idx}`;
      const expanded = isSectionExpanded(sectionKey, section.items);
      return `
        <div class="sidebar-section${expanded ? '' : ' is-collapsed'}">
          <button type="button" class="sidebar-section-title" data-section="${escapeHtml(sectionKey)}"
                  aria-expanded="${expanded}" aria-controls="${panelId}">
            <span class="sidebar-section-label">${escapeHtml(title)}</span>
            <span class="sidebar-section-chevron" aria-hidden="true">${ICONS.chevron}</span>
          </button>
          <div class="sidebar-section-items" id="${panelId}"${expanded ? '' : ' hidden'}>
            ${renderItems(section.items)}
          </div>
        </div>
      `;
    }).join('');
  }

  function renderItems(items) {
    // El estado activo se resuelve en updateActiveItem() (exacto > prefijo).
    return items.map(item => {
      const iconSvg = ICONS[item.icon] || ICONS.dashboard;
      const label = item.labelKey ? t(item.labelKey) : item.label;

      return `
        <button class="sidebar-item" data-route="${escapeHtml(item.route)}">
          <span class="sidebar-item-icon" aria-hidden="true">${iconSvg}</span>
          <span class="sidebar-item-label">${escapeHtml(label)}</span>
        </button>
      `;
    }).join('');
  }

  // ============================================
  // FILTRADO POR ROLES
  // ============================================

  function getUserPermissions() {
    if (global.AuthState && typeof global.AuthState.getPermissions === 'function') {
      return global.AuthState.getPermissions();
    }
    return [];
  }

  function filterMenuByRoles() {
    const isAdmin = userRoles.includes('ADMIN');
    const permissions = getUserPermissions();

    return MENU_CONFIG.map(section => {
      const filteredItems = section.items.filter(item => {
        // 1) Sólo enlazar rutas cuyo módulo JS exista (sin enlaces muertos).
        if (global.AppRouter && typeof global.AppRouter.isModuleAvailable === 'function') {
          if (!global.AppRouter.isModuleAvailable(item.route)) return false;
        }

        if (isAdmin) return true;

        // 2) Permiso real del backend cuando está declarado y disponible.
        if (item.permission && permissions.length > 0) {
          return permissions.includes(item.permission);
        }

        // 3) Fallback por rol (mismo contrato que la BD).
        if (item.roles && item.roles.length > 0) {
          return item.roles.some(role => userRoles.includes(role));
        }
        return true;
      });
      return { ...section, items: filteredItems };
    }).filter(section => section.items.length > 0);
  }

  // ============================================
  // EVENTOS
  // ============================================

  function handleNavClick(e) {
    // Clic en el título de una sección: colapsa/expande sin navegar.
    const sectionTitle = e.target.closest('.sidebar-section-title');
    if (sectionTitle) {
      toggleSection(sectionTitle);
      return;
    }

    const item = e.target.closest('.sidebar-item');
    if (!item) return;
    const route = item.dataset.route;
    if (route && global.AppRouter) {
      global.AppRouter.navigate(route);
      closeMobile();
    }
  }

  function toggleSection(sectionTitle) {
    const sectionKey = sectionTitle.dataset.section;
    const section = sectionTitle.closest('.sidebar-section');
    const panel = sectionTitle.getAttribute('aria-controls')
      ? document.getElementById(sectionTitle.getAttribute('aria-controls'))
      : null;
    if (!section || !panel) return;

    const expanded = sectionTitle.getAttribute('aria-expanded') === 'true';
    const next = !expanded;

    sectionTitle.setAttribute('aria-expanded', String(next));
    section.classList.toggle('is-collapsed', !next);
    if (next) {
      panel.removeAttribute('hidden');
    } else {
      panel.setAttribute('hidden', '');
    }
    writeSectionExpanded(sectionKey, next);
  }

  // Se bindea por render SOLO el botón de cierre (elemento nuevo cada render).
  // El click de navegación se bindea UNA sola vez en init() (el contenedor
  // #sidebar no se reemplaza, sólo su innerHTML → evita listeners duplicados).
  function bindSidebarEvents() {
    if (!container) return;

    const closeBtn = container.querySelector('.sidebar-toggle-mobile');
    if (closeBtn) {
      closeBtn.addEventListener('click', closeMobile);
    }

    // Logout del pie: mismo handler que #logout-btn del header (state.js):
    // preventDefault + AuthState.logout(). Se re-bindea en cada render porque
    // el innerHTML del sidebar se reemplaza (i18n, auth:user-updated).
    const logoutBtn = container.querySelector('#sidebar-logout-btn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (global.AuthState) global.AuthState.logout();
      });
    }
  }

  function bindGlobalEvents() {
    if (!global.EventBus) return;

    global.EventBus.on('route:loaded', (data) => {
      if (data && data.path) {
        currentRoute = data.path;
        updateActiveItem();
      }
    });

    global.EventBus.on('route:activated', (data) => {
      if (data && data.path) {
        currentRoute = data.path;
        updateActiveItem();
      }
    });

    global.EventBus.on('auth:login', (data) => {
      if (data && data.user && data.user.roles) {
        userRoles = data.user.roles.map(r => typeof r === 'string' ? r : r.nombre);
      }
      render();
      show();
    });

    global.EventBus.on('auth:logout', () => {
      hide();
      userRoles = [];
    });

    global.EventBus.on('auth:synced', (data) => {
      if (data && data.isAuthenticated && data.user) {
        userRoles = data.user.roles ? data.user.roles.map(r => typeof r === 'string' ? r : r.nombre) : [];
        render();
        show();
      } else {
        hide();
      }
    });

    // Roles/permisos sincronizados desde /auth/me (asíncrono tras el arranque)
    global.EventBus.on('auth:user-updated', () => {
      const user = global.AuthState ? global.AuthState.getUser() : null;
      userRoles = (user && user.roles)
        ? user.roles.map(r => typeof r === 'string' ? r : (r && r.nombre) || '').filter(Boolean)
        : [];
      render();
      if (global.AuthState && global.AuthState.isAuthenticated()) show();
    });

    // Cambio de idioma sin recarga: re-pinta etiquetas y secciones.
    global.EventBus.on('i18n:changed', () => {
      render();
    });

    // Logo/nombre del tenant cambiados (Configuración o carga inicial).
    global.EventBus.on('branding:changed', () => {
      if (global.AuthState && global.AuthState.isAuthenticated()) render();
    });
  }

  // ============================================
  // API PÚBLICA
  // ============================================

  function updateActiveItem() {
    if (!container) return;
    const items = Array.from(container.querySelectorAll('.sidebar-item'));

    // 1) Coincidencia exacta con la ruta actual.
    const exact = items.find(item => item.dataset.route === currentRoute);
    // 2) Si no hay exacta, el item cuyo prefijo contenga la ruta actual
    //    (subrutas: #/inventory/locations resalta "inventory" sólo si no
    //    existe un item para esa ruta concreta).
    const prefix = exact ? null
      : items.find(item => item.dataset.route
          && currentRoute
          && currentRoute.startsWith(item.dataset.route + '/'));

    items.forEach(item => {
      const isActive = item === exact || item === prefix;
      item.classList.toggle('is-active', isActive);
      if (isActive) {
        item.setAttribute('aria-current', 'page');
      } else {
        item.removeAttribute('aria-current');
      }
    });

    // El item activo nunca debe quedar dentro de una sección colapsada:
    // al navegar se expande la sección que lo contiene. No se persiste:
    // la apertura por ruta activa se recalcula en cada render (precedencia
    // ruta > estado guardado) y no es una acción del usuario.
    const activeItem = exact || prefix;
    if (activeItem) {
      const section = activeItem.closest('.sidebar-section');
      const sectionTitle = section && section.querySelector('.sidebar-section-title');
      if (sectionTitle && sectionTitle.getAttribute('aria-expanded') !== 'true') {
        const panel = section.querySelector('.sidebar-section-items');
        sectionTitle.setAttribute('aria-expanded', 'true');
        section.classList.remove('is-collapsed');
        if (panel) panel.removeAttribute('hidden');
      }
    }
  }

  function isMobile() {
    return window.innerWidth <= 768;
  }

  function toggle() {
    if (isMobile()) {
      // Mobile: panel deslizante + overlay
      isOpen = !isOpen;
      container?.classList.toggle('is-open', isOpen);
      if (global.EventBus) {
        global.EventBus.emit('sidebar:toggled', { isOpen });
      }
      return;
    }

    // Desktop: colapsar/expandir (el nombre de plataforma vive en el sidebar)
    const app = document.getElementById('app');
    const collapsed = app ? app.classList.toggle('sidebar-collapsed') : false;
    isOpen = !collapsed;
    if (global.EventBus) {
      global.EventBus.emit('sidebar:toggled', { isOpen });
    }
  }

  function open() {
    if (isMobile()) {
      isOpen = true;
      container?.classList.add('is-open');
      return;
    }
    document.getElementById('app')?.classList.remove('sidebar-collapsed');
    isOpen = true;
  }

  function close() {
    if (isMobile()) {
      isOpen = false;
      container?.classList.remove('is-open');
      return;
    }
    document.getElementById('app')?.classList.add('sidebar-collapsed');
    isOpen = false;
  }

  function closeMobile() {
    if (isMobile()) close();
  }

  function show() { container?.classList.remove('hidden'); }
  function hide() { container?.classList.add('hidden'); }

  function init() {
    if (initialized) return;
    initialized = true;

    container = document.getElementById('sidebar');
    if (!container) {
      console.warn('[Sidebar] Container #sidebar not found');
      initialized = false;
      return;
    }

    if (global.AuthState) {
      const user = global.AuthState.getUser();
      if (user && user.roles) {
        userRoles = user.roles.map(r => typeof r === 'string' ? r : r.nombre);
      }
    }

    if (global.AppRouter) {
      currentRoute = global.AppRouter.getCurrentRoute() || '';
    }

    container.addEventListener('click', handleNavClick);

    bindGlobalEvents();
    render();
    isOpen = !isMobile();

    if (global.AuthState && global.AuthState.isAuthenticated()) {
      show();
    } else {
      hide();
    }
  }

  // ============================================
  // HELPERS
  // ============================================

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getUserName() {
    if (!global.AuthState) return t('shell.guest');
    const user = global.AuthState.getUser();
    if (!user) return t('shell.guest');
    return user.nombre ? `${user.nombre} ${user.apellido || ''}`.trim() : user.email;
  }

  function getUserRole() {
    if (userRoles.length === 0) return t('shell.no_role');
    return userRoles.join(', ');
  }

  function getUserInitials() {
    const user = global.AuthState ? global.AuthState.getUser() : null;
    if (!user) return '?';
    const name = getUserName();
    const parts = name.split(' ');
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return name.substring(0, 2).toUpperCase();
  }

  // ============================================
  // EXPORT
  // ============================================

  const AppSidebar = {
    init,
    toggle,
    open,
    close,
    closeMobile,
    show,
    hide,
    setActive: (route) => {
      currentRoute = route;
      updateActiveItem();
    }
  };

  global.AppSidebar = AppSidebar;

  // El arranque lo hace public/index.php (DOMContentLoaded) en este orden:
  // AuthState.init() → AppSidebar.init() → AppRouter.init().
  // Un init automático aquí duplicaría listeners (AuthState aún sin cargar).

})(window);
