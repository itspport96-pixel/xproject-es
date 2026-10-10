/**
 * Dashboard Module - Vista principal post-login
 *
 * Responsabilidades:
 * - Mostrar stats del tenant (productos, ubicaciones, usuarios)
 * - Accesos rápidos a módulos
 * - Lazy-loaded via ModuleLoader (key: DashboardDashboardModule)
 *
 * Endpoints usados:
 * - GET /api/tenant/stats                    (TenantController)
 * - GET /api/inventory/dashboard/critical    (CAPA 1, spec §9: primer render)
 * - GET /api/inventory/dashboard/secondary   (CAPA 2, spec §9: ~5s después)
 *
 * No inventa endpoints. Si alguno falla, el resto del dashboard sigue
 * funcionando (estado degradado).
 */
(function(global) {
  'use strict';

  const UI = global.UI;

  // ============================================
  // ICONOS SVG (inline, currentColor)
  // ============================================
  const ICONS = {
    box: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    mapPin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    package: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    arrowRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>'
  };

  // ============================================
  // ESTADO
  // ============================================
  // CAPA 2 (spec §9): se pide después del primer render para no frenarlo.
  const SECONDARY_DELAY_MS = 5000;

  let container = null;
  let state = {
    stats: null,
    scope: 'inventory',
    loading: true,
    error: null,
    critical: null,
    secondary: null,
    secondaryTimer: null
  };

  // ============================================
  // TEMPLATE
  // ============================================
  const TEMPLATE = `
    <div class="max-w-7xl mx-auto">
      ${UI.pageHeader({
        title: t('dashboard.title'),
        subtitle: t('dashboard.subtitle'),
        titleAttrs: 'data-dashboard-title',
        subtitleAttrs: 'data-dashboard-subtitle',
        wrapperClass: 'mb-6'
      })}

      <div data-dashboard-content>
        <div class="flex items-center justify-center py-20">
          <div class="spinner"></div>
        </div>
      </div>
    </div>
  `;

  // ============================================
  // RENDER
  // ============================================

  function render() {
    if (!container) return;

    const content = container.querySelector('[data-dashboard-content]');
    if (!content) return;

    // Actualizar título con nombre del usuario
    const titleEl = container.querySelector('[data-dashboard-title]');
    if (titleEl && global.AuthState) {
      const user = global.AuthState.getUser();
      if (user) {
        const name = user.nombre ? user.nombre : (user.email || '');
        titleEl.textContent = name ? t('dashboard.greeting', { name: name }) : t('dashboard.title');
      }
    }

    if (state.loading) {
      content.innerHTML = `
        <div class="flex items-center justify-center py-20">
          <div class="spinner"></div>
        </div>
      `;
      return;
    }

    if (state.error) {
      content.innerHTML = `
        <div class="card max-w-md">
          <div class="card-header">
            <h3 class="card-title text-danger">${t('dashboard.messages.error_title')}</h3>
          </div>
          <div class="card-body">
            <p class="text-sm">${escapeHtml(state.error)}</p>
          </div>
          <div class="mt-4 flex justify-end gap-2">
            <button class="btn btn-secondary btn-sm" data-action="retry">${t('dashboard.messages.retry')}</button>
          </div>
        </div>
      `;
      return;
    }

    const stats = state.stats || {};
    const tenantScope = state.scope === 'tenant';

    const statCards = [
      renderStatCard(t('nav.products'), stats.productos || 0, ICONS.box, 'inventory'),
      renderStatCard(t('nav.locations'), stats.ubicaciones || 0, ICONS.mapPin, 'inventory/locations')
    ];
    if (tenantScope) {
      statCards.push(renderStatCard(t('dashboard.cards.active_users'), stats.usuarios_activos || 0, ICONS.users,
        isRouteAvailable('users') ? 'users' : null));
      statCards.push(renderStatCard(t('dashboard.cards.stock_units'), stats.unidades_fisicas || 0, ICONS.package, null));
    }
    const gridClass = statCards.length > 2 ? 'lg:grid-cols-4' : 'lg:grid-cols-2';

    const quickAccess = [
      { title: t('dashboard.quick.products_title'), route: 'inventory', description: t('dashboard.quick.products_desc') },
      { title: t('nav.locations'), route: 'inventory/locations', description: t('dashboard.quick.locations_desc') },
      { title: t('nav.users'), route: 'users', description: t('dashboard.quick.users_desc') }
    ].filter(item => isRouteAvailable(item.route));

    content.innerHTML = `
      <div class="grid grid-cols-1 md:grid-cols-2 ${gridClass} gap-4 mb-6">
        ${statCards.join('')}
      </div>

      ${quickAccess.length ? `
      <div class="card mb-6">
        <div class="card-header">
          <h3 class="card-title">${t('dashboard.cards.quick_access')}</h3>
        </div>
        <div class="card-body">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            ${quickAccess.map(item => renderQuickAccess(item.title, item.route, item.description)).join('')}
          </div>
        </div>
      </div>` : ''}

      ${renderStockSummary(state.critical)}
      ${renderOperationSummary(state.secondary)}
    `;
  }

  function isRouteAvailable(route) {
    if (!global.AppRouter || typeof global.AppRouter.isModuleAvailable !== 'function') return true;
    return global.AppRouter.isModuleAvailable(route);
  }

  function renderStatCard(label, value, icon, route) {
    const formattedValue = typeof value === 'number' ? value.toLocaleString(i18n.getLocale()) : '—';
    const clickable = route ? `data-action="navigate" data-route="${escapeHtml(route)}" role="button" tabindex="0"` : '';
    const hoverClass = route ? 'cursor-pointer hover:border-strong' : '';

    return `
      <div class="stat-card ${hoverClass}" ${clickable}>
        <div class="flex items-start justify-between mb-3">
          <div class="text-muted" style="width:1.5rem;height:1.5rem;">${icon}</div>
        </div>
        <div class="stat-label">${escapeHtml(label)}</div>
        <div class="stat-value">${formattedValue}</div>
      </div>
    `;
  }

  function renderQuickAccess(title, route, description) {
    return `
      <button class="flex items-center justify-between p-3 rounded border border-default hover:bg-surface-alt text-left transition-colors" data-action="navigate" data-route="${escapeHtml(route)}">
        <div>
          <div class="font-medium text-sm">${escapeHtml(title)}</div>
          <div class="text-xs text-muted mt-0.5">${escapeHtml(description)}</div>
        </div>
        <span class="text-muted" style="width:1rem;height:1rem;">${ICONS.arrowRight}</span>
      </button>
    `;
  }

  /**
   * CAPA 1 (GET /inventory/dashboard/critical): tabla de stock resumida
   * (máx. 20 filas, sin stock primero) + alertas del catálogo.
   */
  function renderStockSummary(critical) {
    if (!critical) return '';

    const counts = critical.counts || {};
    const rows = Array.isArray(critical.stock) ? critical.stock : [];
    const tenant = critical.tenant && critical.tenant.nombre ? critical.tenant.nombre : '';

    const table = rows.length ? `
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>SKU</th>
              <th>${t('dashboard.stock.columns.product')}</th>
              <th class="text-right">${t('dashboard.stock.columns.units')}</th>
              <th class="text-right">${t('dashboard.stock.columns.quantity')}</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(row => `
            <tr>
              <td>${escapeHtml(row.sku)}</td>
              <td>${escapeHtml(row.nombre)}</td>
              <td class="text-right">${formatNumber(row.unidades)}</td>
              <td class="text-right">${formatNumber(row.cantidad)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`
      : `<p class="table-empty">${t('dashboard.stock.empty')}</p>`;

    return `
      <div class="card mb-6">
        <div class="card-header">
          <h3 class="card-title">${t('dashboard.cards.stock_summary')}</h3>
          ${tenant ? `<span class="text-muted text-sm">${escapeHtml(tenant)}</span>` : ''}
        </div>
        <div class="card-body">
          <div class="flex flex-wrap gap-4 mb-4 text-sm">
            <span class="text-muted">${t('dashboard.stock.counts.products_without_stock')}: <strong class="text-danger">${formatNumber(counts.productos_sin_stock)}</strong></span>
            <span class="text-muted">${t('dashboard.stock.counts.critical_locations')}: <strong>${formatNumber(counts.ubicaciones_criticas)}</strong></span>
            <span class="text-muted">${t('dashboard.stock.counts.units_in_stock')}: <strong>${formatNumber(counts.unidades_total)}</strong></span>
          </div>
          ${table}
        </div>
      </div>
    `;
  }

  /**
   * CAPA 2 (GET /inventory/dashboard/secondary): contadores operativos que
   * se pintan cuando la respuesta llega (~5s después del primer render).
   */
  function renderOperationSummary(secondary) {
    if (!secondary || !secondary.counts) return '';

    const counts = secondary.counts;
    const items = [
      [t('dashboard.metrics.locations_available'), counts.ubicaciones_disponibles],
      [t('dashboard.metrics.active_reservations'), counts.reservas_activas],
      [t('dashboard.metrics.in_transit'), counts.transporte_transito],
      [t('dashboard.metrics.open_incidents'), counts.incidencias_abiertas]
    ];

    return `
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">${t('dashboard.cards.operation')}</h3>
        </div>
        <div class="card-body">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            ${items.map(([label, value]) => `
            <div>
              <div class="text-muted text-xs">${escapeHtml(label)}</div>
              <div class="text-xl font-bold">${formatNumber(value)}</div>
            </div>`).join('')}
          </div>
        </div>
      </div>
    `;
  }

  function formatNumber(value) {
        return typeof value === 'number' ? value.toLocaleString(i18n.getLocale()) : '0';
  }

  // ============================================
  // DATA
  // ============================================

  async function loadStats() {
    state.loading = true;
    state.error = null;
    render();

    // Capas del dashboard (spec §9): fuera del camino crítico de las stats.
    // Cada una degrada por su cuenta si el endpoint no responde.
    loadDashboardLayers();

    if (!global.HttpClient) {
      state.loading = false;
      state.error = t('dashboard.messages.http_unavailable');
      render();
      return;
    }

    // 1) Resumen del tenant (GET /tenant/stats requiere tenants.tenant.view)
    try {
      const response = await global.HttpClient.get('/tenant/stats');
      state.stats = (response && response.data && response.data.stats) || response.stats || {};
      state.scope = 'tenant';
      state.loading = false;
      render();
      return;
    } catch (err) {
      if (err.status !== 403) {
        console.error('[Dashboard] loadStats error:', err);
        state.loading = false;
        state.error = err.message || t('dashboard.messages.stats_load_error');
        render();
        return;
      }
    }

    // 2) Sin permiso de tenant → resumen de inventario (lectura básica de todos los roles)
    try {
      const [products, locations] = await Promise.all([
        global.HttpClient.get('/inventory/products', { params: { page: 1, per_page: 1 } }),
        global.HttpClient.get('/inventory/locations', { params: { page: 1, per_page: 1 } })
      ]);

      state.stats = {
        productos: totalOf(products),
        ubicaciones: totalOf(locations)
      };
      state.scope = 'inventory';
      state.loading = false;
      render();
    } catch (err) {
      console.error('[Dashboard] loadStats (inventory) error:', err);
      state.loading = false;
      state.error = err.message || t('dashboard.messages.stats_load_error');
      render();
    }
  }

  function totalOf(response) {
    const pagination = response && response.data && response.data.pagination;
    if (pagination && typeof pagination.total === 'number') return pagination.total;
    if (response && Array.isArray(response.data)) return response.data.length;
    return 0;
  }

  /**
   * CAPA 1 (crítica) inmediata + programa la CAPA 2 (secundaria) ~5s después,
   * tal como define la spec §9 de Lazy Loading.
   */
  async function loadDashboardLayers() {
    if (!global.HttpClient) return;

    try {
      const response = await global.HttpClient.get('/inventory/dashboard/critical');
      state.critical = (response && response.data) || null;
      render();
    } catch (err) {
      // Sin permiso (inventory.stock.view) o backend caído: el resto del
      // dashboard sigue funcionando sin esta tarjeta.
      console.error('[Dashboard] critical error:', err);
      state.critical = null;
    }

    if (state.secondaryTimer) {
      clearTimeout(state.secondaryTimer);
    }

    state.secondaryTimer = setTimeout(() => {
      state.secondaryTimer = null;
      loadSecondary();
    }, SECONDARY_DELAY_MS);
  }

  async function loadSecondary() {
    if (!global.HttpClient) return;

    try {
      const response = await global.HttpClient.get('/inventory/dashboard/secondary');
      state.secondary = (response && response.data) || null;
      render();
    } catch (err) {
      console.error('[Dashboard] secondary error:', err);
      state.secondary = null;
    }
  }

  // ============================================
  // EVENTS
  // ============================================

  function handleClick(e) {
    const target = e.target.closest('[data-action]');
    if (!target) return;

    const action = target.dataset.action;
    if (action === 'navigate') {
      const route = target.dataset.route;
      if (route && global.AppRouter) {
        global.AppRouter.navigate(route);
      }
    } else if (action === 'retry') {
      loadStats();
    }
  }

  function handleKeydown(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const target = e.target.closest('[data-action="navigate"]');
    if (!target) return;
    e.preventDefault();
    target.click();
  }

  function bindEvents() {
    if (!container) return;
    container.addEventListener('click', handleClick);
    container.addEventListener('keydown', handleKeydown);
  }

  function unbindEvents() {
    if (!container) return;
    container.removeEventListener('click', handleClick);
    container.removeEventListener('keydown', handleKeydown);
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

  // ============================================
  // PUBLIC API
  // ============================================

  const DashboardDashboardModule = {
    init(params, targetContainer) {
      container = targetContainer;
      if (!container) return;
      container.innerHTML = TEMPLATE;
      bindEvents();
      loadStats();
    },

    onActivate() {
      if (container && !container.querySelector('[data-dashboard-content]')) {
        container.innerHTML = TEMPLATE;
        bindEvents();
      }
      loadStats();
    },

    destroy() {
      unbindEvents();
      if (state.secondaryTimer) {
        clearTimeout(state.secondaryTimer);
      }
      if (container) {
        container.innerHTML = '';
      }
      container = null;
      state = {
        stats: null,
        scope: 'inventory',
        loading: true,
        error: null,
        critical: null,
        secondary: null,
        secondaryTimer: null
      };
    }
  };

  global.DashboardDashboardModule = DashboardDashboardModule;

})(window);
