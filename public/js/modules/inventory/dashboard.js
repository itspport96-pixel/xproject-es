/**
 * Dashboard del módulo Inventory (ruta: dashboard/inventory).
 *
 * Landing del módulo Inventario: métricas del inventario (conteo de
 * productos y ubicaciones) + los widgets operativos que antes vivían en el
 * dashboard general (resumen de stock y contadores de operación).
 *
 * Endpoints usados:
 * - GET /inventory/products (page 1, per_page 1)  → total de productos
 * - GET /inventory/locations (page 1, per_page 1) → total de ubicaciones
 * - GET /inventory/dashboard/critical             → CAPA 1 (stock resumido)
 * - GET /inventory/dashboard/secondary            → CAPA 2 (operación)
 *
 * Las cuatro peticiones salen EN PARALELO a la entrada del panel y todo se
 * pinta en UNA sola actualización: sin timer de 5s ni pintados encadenados
 * (la carga escalonada de la spec §9 se retiró: el usuario ve las tarjetas
 * de conteo, Stock resumido y Operación a la vez). Un fallo de capa
 * (permiso o backend) deja su tarjeta en null sin tumbar el resto.
 *
 * No inventa endpoints. Cada petición se hace sólo si el usuario tiene el
 * permiso correspondiente (evita 403 y pantallas de "Acceso denegado").
 */
(function(global) {
  'use strict';

  const UI = global.UI;

  // ============================================
  // ICONOS SVG (inline, currentColor)
  // ============================================
  const ICONS = {
    box: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    mapPin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'
  };

  // ============================================
  // ESTADO
  // ============================================
  let container = null;
  let state = {
    counts: null,
    critical: null,
    secondary: null,
    loading: true,
    error: null
  };

  // ============================================
  // TEMPLATE
  // ============================================
  const TEMPLATE = `
    <div class="max-w-7xl mx-auto">
      ${UI.pageHeader({
        title: t('dashboard.inventory.title'),
        subtitle: t('dashboard.inventory.subtitle'),
        titleAttrs: 'data-inventory-dashboard-title',
        wrapperClass: 'mb-6'
      })}

      <div data-inventory-dashboard-content>${UI.cardsSkeleton(2)}
      </div>
    </div>
  `;

  // ============================================
  // RENDER
  // ============================================

  function render() {
    if (!container) return;

    const content = container.querySelector('[data-inventory-dashboard-content]');
    if (!content) return;

    if (state.loading) {
      content.innerHTML = UI.cardsSkeleton(2);
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

    const counts = state.counts || {};
    const statCards = [];
    if (canItemView()) {
      statCards.push(renderStatCard(t('nav.products'), counts.productos || 0, ICONS.box, 'inventory'));
    }
    if (canLocationView()) {
      statCards.push(renderStatCard(t('nav.locations'), counts.ubicaciones || 0, ICONS.mapPin, 'inventory/locations'));
    }
    const gridClass = statCards.length > 2 ? 'lg:grid-cols-4' : 'lg:grid-cols-2';

    content.innerHTML = `
      ${statCards.length ? `
      <div class="grid grid-cols-1 md:grid-cols-2 ${gridClass} gap-4 mb-6">
        ${statCards.join('')}
      </div>` : ''}

      ${renderStockSummary(state.critical)}
      ${renderOperationSummary(state.secondary)}
    `;
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
   * CAPA 2 (GET /inventory/dashboard/secondary): contadores operativos,
   * pedidos en paralelo con el resto y pintados en la misma actualización.
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

  function canView(permission, fallbackRoles) {
    if (global.UI && typeof global.UI.can === 'function') {
      return global.UI.can(permission, fallbackRoles);
    }
    return false;
  }

  const canStockLayers = () => canView('inventory.stock.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
  const canItemView = () => canView('inventory.item.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
  const canLocationView = () => canView('inventory.location.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);

  async function loadData() {
    state.error = null;
    const gate = UI.skeletonGate(state, 'loading', render);

    if (!global.HttpClient) {
      gate.done();
      state.error = t('dashboard.messages.http_unavailable');
      render();
      return;
    }

    const wantsItems = canItemView();
    const wantsLocations = canLocationView();
    const wantsStock = canStockLayers();

    if (!wantsItems && !wantsLocations && !wantsStock) {
      // Sin ningún permiso del módulo: no hay conteos ni widgets.
      state.counts = null;
      state.critical = null;
      state.secondary = null;
      gate.done();
      render();
      return;
    }

    try {
      // Todo en paralelo y UN solo render al final: las tarjetas de conteo,
      // Stock resumido y Operación aparecen a la vez (sin pop-in escalonado).
      // Las capas se envuelven para que un 403/caída deje su tarjeta en
      // null sin tumbar los conteos (mismo criterio que antes).
      const [prodRes, locRes, critical, secondary] = await Promise.all([
        wantsItems
          ? global.HttpClient.get('/inventory/products', { params: { page: 1, per_page: 1 } })
          : Promise.resolve(null),
        wantsLocations
          ? global.HttpClient.get('/inventory/locations', { params: { page: 1, per_page: 1 } })
          : Promise.resolve(null),
        wantsStock ? loadLayer('/inventory/dashboard/critical') : Promise.resolve(null),
        wantsStock ? loadLayer('/inventory/dashboard/secondary') : Promise.resolve(null)
      ]);

      state.counts = (wantsItems || wantsLocations)
        ? {
            productos: wantsItems ? totalOf(prodRes) : 0,
            ubicaciones: wantsLocations ? totalOf(locRes) : 0
          }
        : null;
      state.critical = critical;
      state.secondary = secondary;
      gate.done();
      render();
    } catch (err) {
      console.error('[InventoryDashboard] loadData error:', err);
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

  /**
   * CAPA 1 (critical) o CAPA 2 (secondary): se piden junto con los conteos.
   * Un fallo (permiso o backend) devuelve null y sólo falta esa tarjeta.
   */
  async function loadLayer(url) {
    try {
      const response = await global.HttpClient.get(url);
      return (response && response.data) || null;
    } catch (err) {
      console.error('[InventoryDashboard] ' + url + ' error:', err);
      return null;
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
      loadData();
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

  const InventoryDashboardModule = {
    init(params, targetContainer) {
      container = targetContainer;
      if (!container) return;
      container.innerHTML = TEMPLATE;
      bindEvents();
      loadData();
    },

    onActivate() {
      if (container && !container.querySelector('[data-inventory-dashboard-content]')) {
        container.innerHTML = TEMPLATE;
        bindEvents();
      }
      loadData();
    },

    destroy() {
      unbindEvents();
      if (container) {
        container.innerHTML = '';
      }
      container = null;
      state = {
        counts: null,
        critical: null,
        secondary: null,
        loading: true,
        error: null
      };
    }
  };

  global.InventoryDashboardModule = InventoryDashboardModule;
})(window);
