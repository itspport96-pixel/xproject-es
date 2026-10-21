/**
 * Dashboard Module - Vista principal post-login (dashboard GENERAL del tenant)
 *
 * Responsabilidades:
 * - Mostrar métricas generales del tenant (productos, ubicaciones, usuarios,
 *   unidades físicas) vía GET /tenant/stats
 * - Accesos rápidos a módulos
 * - Lazy-loaded via ModuleLoader (key: DashboardDashboardModule)
 *
 * Endpoints usados:
 * - GET /api/tenant/stats                    (TenantController)
 *
 * Los widgets propios de Inventario (resumen de stock y contadores
 * operativos) viven en el dashboard del módulo (inventory/dashboard).
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
  let container = null;
  let state = {
    stats: null,
    scope: 'inventory',
    loading: true,
    error: null
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

      <div data-dashboard-content>${UI.cardsSkeleton(4)}
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
      content.innerHTML = UI.cardsSkeleton(4);
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
    const inventoryScope = state.scope === 'inventory';

    const statCards = [];
    if (canItemView()) {
      statCards.push(renderStatCard(t('nav.products'), stats.productos || 0, ICONS.box, 'inventory'));
    }
    if (canLocationView()) {
      statCards.push(renderStatCard(t('nav.locations'), stats.ubicaciones || 0, ICONS.mapPin, 'inventory/locations'));
    }
    if (tenantScope) {
      statCards.push(renderStatCard(t('dashboard.cards.active_users'), stats.usuarios_activos || 0, ICONS.users,
        isRouteAvailable('users') && canView('users.user.view', ['ADMIN', 'MANAGER']) ? 'users' : null));
      statCards.push(renderStatCard(t('dashboard.cards.stock_units'), stats.unidades_fisicas || 0, ICONS.package, null));
    }
    const gridClass = statCards.length > 2 ? 'lg:grid-cols-4' : 'lg:grid-cols-2';

    const quickAccess = [
      { title: t('dashboard.quick.products_title'), route: 'inventory', description: t('dashboard.quick.products_desc'), permission: 'inventory.item.view' },
      { title: t('nav.locations'), route: 'inventory/locations', description: t('dashboard.quick.locations_desc'), permission: 'inventory.location.view' },
      { title: t('nav.users'), route: 'users', description: t('dashboard.quick.users_desc'), permission: 'users.user.view' }
    ].filter(item => isRouteAvailable(item.route) && canView(item.permission, item.permission === 'users.user.view' ? ['ADMIN', 'MANAGER'] : ['ADMIN', 'MANAGER', 'USER', 'VIEWER']));

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

  function formatNumber(value) {
        return typeof value === 'number' ? value.toLocaleString(i18n.getLocale()) : '0';
  }

  // ============================================
  // DATA
  // ============================================

  function canView(permission, fallbackRoles) {
    if (global.UI && typeof global.UI.can === 'function') {
      return global.UI.can(permission, fallbackRoles);
    }
    return false;
  }

  const canTenantStats = () => canView('tenants.tenant.view', ['ADMIN']);
  const canItemView = () => canView('inventory.item.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
  const canLocationView = () => canView('inventory.location.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);

  async function loadStats() {
    state.error = null;
    const gate = UI.skeletonGate(state, 'loading', render);

    if (!global.HttpClient) {
      gate.done();
      state.error = t('dashboard.messages.http_unavailable');
      render();
      return;
    }

    // 1) Resumen del tenant (GET /tenant/stats requiere tenants.tenant.view)
    if (canTenantStats()) {
      try {
        const response = await global.HttpClient.get('/tenant/stats');
        state.stats = (response && response.data && response.data.stats) || response.stats || {};
        state.scope = 'tenant';
        gate.done();
        render();
        return;
      } catch (err) {
        if (err.status !== 403) {
          console.error('[Dashboard] loadStats error:', err);
          gate.done();
          state.error = err.message || t('dashboard.messages.stats_load_error');
          render();
          return;
        }
      }
    }

    // 2) Sin permiso de tenant → resumen de inventario (sólo si hay view)
    const wantsItems = canItemView();
    const wantsLocations = canLocationView();
    if (!wantsItems && !wantsLocations) {
      state.stats = null;
      state.scope = 'none';
      gate.done();
      render();
      return;
    }

    try {
      const requests = [];
      if (wantsItems) {
        requests.push(global.HttpClient.get('/inventory/products', { params: { page: 1, per_page: 1 } }));
      }
      if (wantsLocations) {
        requests.push(global.HttpClient.get('/inventory/locations', { params: { page: 1, per_page: 1 } }));
      }
      const results = await Promise.all(requests);

      state.stats = {
        productos: wantsItems ? totalOf(results[0]) : 0,
        ubicaciones: wantsLocations ? totalOf(results[wantsItems ? 1 : 0]) : 0
      };
      state.scope = 'inventory';
      gate.done();
      render();
    } catch (err) {
      console.error('[Dashboard] loadStats (inventory) error:', err);
      gate.done();
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
      if (container) {
        container.innerHTML = '';
      }
      container = null;
      state = {
        stats: null,
        scope: 'inventory',
        loading: true,
        error: null
      };
    }
  };

  global.DashboardDashboardModule = DashboardDashboardModule;

})(window);
