/**
 * Dashboard del módulo Inventory (ruta: dashboard/inventory).
 *
 * Landing del módulo Inventario con el layout del adjunto de referencia
 * (dashboard_inventory.html), adaptado a los contratos REALES del repo:
 *
 *   1. Acciones de cabecera (alta de producto / recepción) por permiso.
 *   2. Chips de alerta: sólo se pintan con valor > 0 y con permiso para
 *      navegar al destino; si no hay ninguna alerta visible, "Todo en orden".
 *   3. Tira de 7 días: una sola línea con movimientos, correcciones y
 *      devoluciones (UI.summaryCards en modo inline), con fecha_desde =
 *      hoy - 7 calculado en UTC.
 *   4. Stock (2/3): tabla con las filas que devuelve CAPA 1 (máx. 20,
 *      STOCK_LIMIT) y conmutador Sin stock / Todos (filtro en cliente).
 *   5. Flujo operativo (1/3): total de productos del catálogo, reservas,
 *      picking, envíos, entregas, conteos y unidades en tránsito; cada fila
 *      con su permiso real.
 *   6. Ocupación de almacenes (2/3, bajo la tabla de stock): gráfico de barras
 *      con las ubicaciones activas que tienen capacidad definida, de más a
 *      menos llena. Las virtuales (sin capacidad, p. ej. TRANSITO) no se
 *      pintan.
 *   7. Onboarding de 3 pasos sólo si el catálogo está vacío
 *      (counts.productos_activos === 0).
 *
 * Endpoints usados (todos existentes; ninguno inventado):
 * - GET /inventory/dashboard/critical  → chips + tabla + onboarding + tránsito
 * - GET /inventory/dashboard/secondary → chips + reservas activas
 * - GET /inventory/locations           → capacidad/capacidad_usada del gráfico
 * - GET /inventory/picking             → summary.en_curso
 * - GET /inventory/shipments           → summary.en_ruta
 * - GET /inventory/deliveries          → summary.pendientes
 * - GET /inventory/counts              → summary.pendientes
 * - GET /inventory/movements           → summary.total / summary.correcciones
 * - GET /inventory/returns             → summary.total
 *
 * Las cuatro peticiones de resumen se hacen con per_page=1 para leer sólo el
 * summary. La línea de 7 días añade fecha_desde (YYYY-MM-DD en UTC).
 *
 * Carga progresiva por zona: cada zona (alertas, tira de 7 días, stock, flujo
 * y ocupación) tiene
 * su propio skeleton (UI.skeletonGate) y su propio reintento (UI.errorState
 * con retryAction); el fallo de una zona no pinta error en las demás. No hay
 * spinner global ni timer de 5s.
 *
 * Permisos: el panel entero exige inventory.stock.view (lo exigen los dos
 * endpoints del panel: routes.php /dashboard/critical y /dashboard/secondary).
 * Dentro del panel, cada chip, fila, métrica, acción y CTA adicional gatea
 * además por el permiso de su pantalla destino: sin permiso no se pinta y no
 * se emite ninguna petición para esa fila. Sin permiso de base la vista pinta
 * la pantalla estándar de "sin permiso" (UI.deniedState).
 *
 * Navegación: los indicadores enlazan a la ruta real SIN query string. Las
 * pantallas destino no leen filtros del query (sólo ?tab= en picking,
 * shipments y deliveries), así que un ?estado=... / ?producto_id= se perdería.
 */
(function(global) {
  'use strict';

  const UI = global.UI;

  // Roles en fallback cuando la sesión aún no expone permisos. Mismos
  // fallbacks que usa cada pantalla de destino (products, locations,
  // receiving, picking, ...), de modo que el gate del dashboard coincide
  // con el de la pantalla a la que navega.
  const ROLES_VIEW = ['ADMIN', 'MANAGER', 'USER', 'VIEWER'];
  const ROLES_CREATE = ['ADMIN', 'MANAGER', 'USER'];
  const ROLES_EDIT = ['ADMIN', 'MANAGER'];
  const ROLES_ADMIN = ['ADMIN'];

  const ZONE_ALERTS = 'alerts';
  const ZONE_STOCK = 'stock';
  const ZONE_FLOW = 'flow';
  const ZONE_CAPACITY = 'capacity';
  const ZONE_WEEK = 'week';
  const ZONES = [ZONE_ALERTS, ZONE_STOCK, ZONE_FLOW, ZONE_CAPACITY, ZONE_WEEK];

  // El gráfico de ocupación pinta como máximo estas ubicaciones, ordenadas de
  // más a menos llena. El listado de ubicaciones pagina en el servidor con
  // per_page <= 100, así que el gráfico refleja esas primeras 100 filas.
  const CAPACITY_MAX_BARS = 8;
  const CAPACITY_PAGE_SIZE = 100;

  const CHEVRON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';

  // ============================================
  // ESTADO
  // ============================================
  let container = null;

  const state = {
    filter: 'sin_stock',
    zones: null
  };

  /** key -> {status: 'pending'|'ok'|'error', data, error, promise} */
  const resources = Object.create(null);

  function freshZones() {
    return {
      alerts: { loading: false },
      stock: { loading: false },
      flow: { loading: false },
      capacity: { loading: false },
      week: { loading: false }
    };
  }

  state.zones = freshZones();

  // ============================================
  // PERMISOS
  // ============================================
  function can(permission, fallbackRoles) {
    if (UI && typeof UI.can === 'function') return UI.can(permission, fallbackRoles);
    return false;
  }

  const canStockView = () => can('inventory.stock.view', ROLES_VIEW);

  // ============================================
  // HELPERS DE DATOS
  // ============================================
  function num(value) {
    const n = typeof value === 'number' ? value : parseFloat(value);
    return isFinite(n) ? n : 0;
  }

  function fmt(value) {
    const n = typeof value === 'number' ? value : parseFloat(value);
    if (!isFinite(n)) return '0';
    let locale = 'es';
    if (typeof i18n !== 'undefined' && i18n && typeof i18n.getLocale === 'function') {
      locale = i18n.getLocale();
    }
    try {
      return n.toLocaleString(locale);
    } catch (err) {
      return String(n);
    }
  }

  /** Fecha (YYYY-MM-DD) de hoy menos 7 días, calculada en UTC. */
  function sevenDaysAgo() {
    return new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
  }

  function esc(value) {
    return UI.escapeHtml(value === null || value === undefined ? '' : String(value));
  }

  /** Datos ya resueltos de una clave, o null si no está en estado 'ok'. */
  function dataOf(key) {
    const entry = resources[key];
    return entry && entry.status === 'ok' ? entry.data : null;
  }

  // ============================================
  // PETICIONES (recursos cacheados por clave)
  // ============================================
  async function get(path, params) {
    if (!global.HttpClient || typeof global.HttpClient.get !== 'function') {
      throw new Error(t('dashboard.messages.http_unavailable'));
    }
    const res = await global.HttpClient.get(path, params ? { params: params } : {});
    return (res && res.data) || {};
  }

  async function getSummary(path, params) {
    const data = await get(path, params);
    return data.summary || {};
  }

  const LOADERS = {
    critical: () => get('/inventory/dashboard/critical'),
    secondary: () => get('/inventory/dashboard/secondary'),
    // Listado de ubicaciones (capacidad + capacidad_usada) para el gráfico de
    // ocupación. Se pide sólo si el usuario tiene inventory.location.view.
    capacity_locations: () => get('/inventory/locations', { per_page: CAPACITY_PAGE_SIZE }),
    flow_picking: () => getSummary('/inventory/picking', { per_page: 1 }),
    flow_shipments: () => getSummary('/inventory/shipments', { per_page: 1 }),
    flow_deliveries: () => getSummary('/inventory/deliveries', { per_page: 1 }),
    flow_counts: () => getSummary('/inventory/counts', { per_page: 1 }),
    week_movements: () => getSummary('/inventory/movements', { per_page: 1, fecha_desde: sevenDaysAgo() }),
    week_returns: () => getSummary('/inventory/returns', { per_page: 1, fecha_desde: sevenDaysAgo() })
  };

  /**
   * Devuelve la promesa de una clave, creándola la primera vez. Varios
   * usos de la misma clave (alertas + stock + flujo) comparten UNA sola
   * petición. Un fallo queda registrado en entry.status y la clave se
   * borra sólo al reintentar, para que el reintento vuelva a pedirla.
   */
  function resource(key) {
    let entry = resources[key];
    if (!entry) {
      const loader = LOADERS[key];
      entry = { status: 'pending', data: null, error: null, promise: null };
      resources[key] = entry;
      entry.promise = Promise.resolve()
        .then(function() {
          if (typeof loader !== 'function') throw new Error('missing loader: ' + key);
          return loader();
        })
        .then(
          function(data) {
            entry.status = 'ok';
            entry.data = data;
            return data;
          },
          function(err) {
            entry.status = 'error';
            entry.error = err;
            throw err;
          }
        );
      // El error se consume aquí (se lee en entry.status): evita
      // unhandledRejection mientras otra zona espera la misma clave.
      entry.promise.catch(function() {});
    }
    return entry.promise;
  }

  // ============================================
  // ZONAS
  // ============================================
  const FLOW_ROWS = [
    {
      // Total del catálogo (counts.productos_activos del critical): la cifra
      // general de productos que el panel general dejará de mostrar con la
      // llegada de los nuevos módulos, así que vive en su panel de módulo.
      // Destino: listado de productos, gateado por inventory.item.view
      // (mismo permiso y roles que esa pantalla).
      resource: 'critical', perm: 'inventory.item.view', roles: ROLES_VIEW,
      labelKey: 'dashboard.inventory.flow.total_products', route: 'inventory',
      value: (d) => num((d.counts || {}).productos_activos)
    },
    {
      resource: 'secondary', perm: 'inventory.reservation.view', roles: ROLES_VIEW,
      labelKey: 'dashboard.inventory.flow.reservations', route: 'inventory/reservations',
      value: (d) => num((d.counts || {}).reservas_activas)
    },
    {
      resource: 'flow_picking', perm: 'inventory.picking.view', roles: ROLES_VIEW,
      labelKey: 'dashboard.inventory.flow.picking', route: 'inventory/picking',
      // El loader devuelve data.summary (objeto), no un número: hay que leer
      // la clave concreta de cada fila.
      value: (d) => num((d || {}).en_curso)
    },
    {
      resource: 'flow_shipments', perm: 'inventory.shipping.view', roles: ROLES_VIEW,
      labelKey: 'dashboard.inventory.flow.shipments', route: 'inventory/shipments',
      value: (d) => num((d || {}).en_ruta)
    },
    {
      resource: 'flow_deliveries', perm: 'inventory.delivery.view', roles: ROLES_VIEW,
      labelKey: 'dashboard.inventory.flow.deliveries', route: 'inventory/deliveries',
      value: (d) => num((d || {}).pendientes)
    },
    {
      resource: 'flow_counts', perm: 'inventory.count.view', roles: ROLES_VIEW,
      labelKey: 'dashboard.inventory.flow.counts', route: 'inventory/counts',
      value: (d) => num((d || {}).pendientes)
    },
    {
      resource: 'critical', perm: 'inventory.stock.view', roles: ROLES_VIEW,
      labelKey: 'dashboard.inventory.flow.in_transit', route: 'inventory/stock',
      value: (d) => num((d.counts || {}).unidades_en_transito)
    }
  ];

  /** Claves de las que depende cada zona (según permisos del usuario). */
  function zoneKeys(name) {
    if (name === ZONE_ALERTS) return ['critical', 'secondary'];
    if (name === ZONE_STOCK) return ['critical'];
    if (name === ZONE_FLOW) {
      const keys = [];
      FLOW_ROWS.forEach(function(row) {
        if (can(row.perm, row.roles) && keys.indexOf(row.resource) === -1) keys.push(row.resource);
      });
      return keys;
    }
    // Sin inventory.location.view no se pinta el gráfico y, sobre todo, no se
    // emite la petición de ubicaciones.
    if (name === ZONE_CAPACITY) {
      return can('inventory.location.view', ROLES_VIEW) ? ['capacity_locations'] : [];
    }
    if (name === ZONE_WEEK) {
      const keys = ['week_movements'];
      if (can('inventory.return.view', ROLES_VIEW)) keys.push('week_returns');
      return keys;
    }
    return [];
  }

  function zoneStatus(name) {
    const keys = zoneKeys(name);
    let pending = false;
    let error = false;
    for (let i = 0; i < keys.length; i++) {
      const entry = resources[keys[i]];
      if (!entry || entry.status === 'pending') pending = true;
      else if (entry.status === 'error') error = true;
    }
    if (pending) return 'loading';
    return error ? 'error' : 'ok';
  }

  /**
   * Carga de una zona: skeleton propio (UI.skeletonGate) mientras espera y
   * render al terminar. Nunca rechaza: el fallo se lee con zoneStatus().
   */
  function loadZone(name) {
    const zoneState = state.zones[name];
    if (!zoneState) return Promise.resolve();
    const gate = UI.skeletonGate(zoneState, 'loading', function() { renderZone(name); });
    const keys = zoneKeys(name);
    return Promise.all(keys.map(function(key) { return resource(key); }))
      .catch(function(err) {
        console.error('[InventoryDashboard] zone ' + name + ' error:', err);
      })
      .then(function() {
        gate.done();
        renderZone(name);
      });
  }

  function loadAll() {
    ZONES.forEach(function(name) {
      loadZone(name).catch(function(err) {
        console.error('[InventoryDashboard] loadZone ' + name + ':', err);
      });
    });
  }

  /**
   * Reintento de una zona: borra sus claves y vuelve a cargarla. Las otras
   * zonas que comparten alguna de esas claves también se recargan, para que
   * un fallo compartido (p. ej. critical) no deje tarjetas congeladas en
   * error mientras una ya se recuperó.
   */
  function retryZone(name) {
    if (ZONES.indexOf(name) === -1) return;
    const cleared = zoneKeys(name);
    cleared.forEach(function(key) { delete resources[key]; });
    ZONES.forEach(function(other) {
      const keys = zoneKeys(other);
      if (other !== name && !keys.some(function(k) { return cleared.indexOf(k) !== -1; })) return;
      loadZone(other).catch(function(err) {
        console.error('[InventoryDashboard] retryZone ' + other + ':', err);
      });
    });
  }

  // ============================================
  // RENDER
  // ============================================
  function renderZone(name) {
    if (!container) return;
    const zoneEl = container.querySelector('[data-zone="' + name + '"]');
    if (!zoneEl) return;

    const zoneState = state.zones[name] || { loading: false };
    const status = zoneStatus(name);

    if (status === 'loading' || zoneState.loading) {
      zoneEl.innerHTML = zoneSkeleton(name);
      if (name === ZONE_STOCK) renderOnboarding('');
      return;
    }
    if (status === 'error') {
      zoneEl.innerHTML = zoneError(name);
      if (name === ZONE_STOCK) renderOnboarding('');
      return;
    }

    zoneEl.innerHTML = zoneContent(name);
    if (name === ZONE_STOCK) renderOnboarding(contentOnboarding());
    if (name === ZONE_WEEK) renderWeekMetrics();
  }

  function zoneError(name) {
    const block = UI.errorState({
      title: t('dashboard.messages.error_title'),
      message: '',
      retryAction: 'retry-' + name,
      retryLabel: t('dashboard.messages.retry')
    });
    if (name === ZONE_ALERTS) return block;
    if (name === ZONE_STOCK) return stockCard(block, '');
    if (name === ZONE_FLOW) return flowCard(block);
    if (name === ZONE_CAPACITY) return capacityCard(block);
    return weekCard(block);
  }

  function zoneSkeleton(name) {
    if (name === ZONE_ALERTS) {
      const chip = (width) => '<span class="skeleton" style="width:' + width + ';height:1.5rem;border-radius:9999px"></span>';
      return '<div class="flex items-center gap-2 flex-wrap" aria-hidden="true">' +
        chip('9rem') + chip('11rem') + chip('10rem') + '</div>';
    }
    if (name === ZONE_STOCK) return stockCard(UI.tableSkeleton(stockColumns(), 6), '');
    if (name === ZONE_FLOW) return flowCard(UI.rowsSkeleton(5, [70, 40]));
    if (name === ZONE_CAPACITY) return capacityCard(UI.rowsSkeleton(4, [55, 30]));
    return weekCard(UI.rowsSkeleton(2, [60, 45]));
  }

  function zoneContent(name) {
    if (name === ZONE_ALERTS) return alertsContent();
    if (name === ZONE_STOCK) return stockContent();
    if (name === ZONE_FLOW) return flowContent();
    if (name === ZONE_CAPACITY) return capacityContent();
    return weekContent();
  }

  // ---------- alertas ----------
  function alertsContent() {
    const critical = dataOf('critical') || {};
    const secondary = dataOf('secondary') || {};
    const counts = critical.counts || {};
    const secondaryCounts = secondary.counts || {};

    const candidates = [
      {
        value: num(counts.productos_sin_stock),
        label: t('dashboard.stock.counts.products_without_stock'),
        cls: 'chip-danger', perm: 'inventory.stock.view', roles: ROLES_VIEW,
        route: 'inventory/stock'
      },
      {
        value: num(counts.ubicaciones_criticas),
        label: t('dashboard.stock.counts.critical_locations'),
        cls: 'chip-warning', perm: 'inventory.location.view', roles: ROLES_VIEW,
        route: 'inventory/locations'
      },
      {
        value: num(secondaryCounts.incidencias_abiertas),
        label: t('dashboard.metrics.open_incidents'),
        cls: 'chip-danger', perm: 'inventory.incident.view', roles: ROLES_VIEW,
        route: 'inventory/incidents'
      }
    ];

    // Cada chip exige dato > 0 + permiso para navegar a su destino.
    const visible = candidates.filter(function(c) {
      return c.value > 0 && can(c.perm, c.roles);
    });

    const wrapper = function(inner) {
      return '<div class="flex items-center gap-2 flex-wrap" aria-label="' +
        esc(t('dashboard.inventory.alerts.title')) + '">' + inner + '</div>';
    };

    if (visible.length) {
      return wrapper(visible.map(function(c) {
        return '<span class="chip ' + c.cls + '" role="button" tabindex="0" style="cursor:pointer" ' +
          'data-action="navigate" data-route="' + esc(c.route) + '">' +
          esc(c.label) + ': <strong>' + fmt(c.value) + '</strong></span>';
      }).join(''));
    }

    // Sin alertas visibles: sólo se dice "Todo en orden" si de verdad no
    // hay ninguna. Si hay valor > 0 pero no se puede navegar al destino,
    // no se pinta nada (regla: sin navegación válida no se pinta).
    const anyAlert = candidates.some(function(c) { return c.value > 0; });
    if (anyAlert) return wrapper('');
    return wrapper('<span class="chip chip-success">' + esc(t('dashboard.inventory.alerts.all_clear')) + '</span>');
  }

  // ---------- stock ----------
  function stockColumns() {
    return [
      { label: 'SKU' },
      { label: t('dashboard.stock.columns.product') },
      { label: t('dashboard.stock.columns.units'), className: 'text-right' },
      { label: t('dashboard.stock.columns.quantity'), className: 'text-right' }
    ];
  }

  function stockCard(bodyHtml, headerExtra) {
    return '<div class="card">' +
      '<div class="card-header">' +
      '<h3 class="card-title">' + esc(t('inventory.stock.title')) + '</h3>' +
      (headerExtra || '') +
      '</div>' +
      '<div class="card-body">' + bodyHtml + '</div>' +
      '</div>';
  }

  function stockRow(row) {
    return '<tr>' +
      '<td>' + esc(row.sku) + '</td>' +
      '<td>' + esc(row.nombre) + '</td>' +
      '<td class="text-right">' + fmt(row.unidades) + '</td>' +
      '<td class="text-right">' + fmt(row.cantidad) + '</td>' +
      '</tr>';
  }

  function stockToggle() {
    const noStockActive = state.filter !== 'all';
    const btn = function(active, value, label) {
      return '<button type="button" class="btn btn-sm ' + (active ? 'btn-primary' : 'btn-secondary') + '" ' +
        'data-action="filter" data-filter="' + esc(value) + '" aria-pressed="' + (active ? 'true' : 'false') + '">' +
        esc(label) + '</button>';
    };
    return '<div class="flex items-center gap-2 flex-wrap">' +
      '<div class="btn-group" role="group">' +
      btn(noStockActive, 'sin_stock', t('dashboard.inventory.stock.filter_no_stock')) +
      btn(!noStockActive, 'all', t('dashboard.inventory.stock.filter_all')) +
      '</div>' +
      '<button type="button" class="btn btn-ghost btn-sm" data-action="navigate" data-route="inventory/stock">' +
      esc(t('dashboard.inventory.stock.view_all')) + '</button>' +
      '</div>';
  }

  function stockContent() {
    const critical = dataOf('critical') || {};
    const rows = Array.isArray(critical.stock) ? critical.stock : [];
    const filtered = state.filter === 'all'
      ? rows
      : rows.filter(function(row) { return num(row.unidades) === 0; });

    let body;
    if (!rows.length) {
      body = '<p class="table-empty">' + esc(t('dashboard.stock.empty')) + '</p>';
    } else if (!filtered.length) {
      body = '<p class="table-empty">' + esc(t('dashboard.inventory.stock.no_results')) + '</p>';
    } else {
      body = '<div class="table-wrapper">' +
        UI.tableShell(stockColumns(), filtered.map(stockRow).join('')) +
        '</div>';
    }
    return stockCard(body, stockToggle());
  }

  // ---------- flujo operativo ----------
  function flowCard(bodyHtml) {
    return '<div class="card">' +
      '<div class="card-header">' +
      '<h3 class="card-title">' + esc(t('dashboard.inventory.flow.title')) + '</h3>' +
      '</div>' +
      '<div class="card-body">' + bodyHtml + '</div>' +
      '</div>';
  }

  function flowContent() {
    const rows = FLOW_ROWS.filter(function(row) { return can(row.perm, row.roles); });
    if (!rows.length) {
      return flowCard(UI.emptyState({ title: t('ui.empty_title'), message: '' }));
    }
    const body = rows.map(function(row) {
      const data = dataOf(row.resource);
      const value = data === null ? 0 : row.value(data);
      return '<div class="flex items-center justify-between gap-2 py-2 border-b border-default last:border-0 cursor-pointer" ' +
        'role="button" tabindex="0" data-action="navigate" data-route="' + esc(row.route) + '">' +
        '<span class="text-sm">' + esc(t(row.labelKey)) + '</span>' +
        '<span class="flex items-center gap-2 text-sm">' +
        '<strong>' + fmt(value) + '</strong>' + CHEVRON +
        '</span></div>';
    }).join('');
    return flowCard(body);
  }

  // ---------- ocupación de almacenes (gráfico de barras) ----------
  function capacityCard(bodyHtml) {
    return '<div class="card">' +
      '<div class="card-header">' +
      '<h3 class="card-title">' + esc(t('dashboard.inventory.capacity.title')) + '</h3>' +
      '</div>' +
      '<div class="card-body">' + bodyHtml + '</div>' +
      '</div>';
  }

  /**
   * Ubicaciones activas con capacidad definida, de más a menos llena, para
   * dibujar cuáles se están por llenar. Las ubicaciones virtuales (sin
   * capacidad, p. ej. TRANSITO) quedan fuera: no hay porcentaje que pintar.
   * Acepta la forma data.data (listado paginado) o data.locations.
   */
  function capacityRows() {
    const data = dataOf('capacity_locations');
    if (!data) return [];
    const rows = Array.isArray(data.data) ? data.data
      : (Array.isArray(data.locations) ? data.locations : []);

    return rows
      .filter(function(loc) {
        return loc.activa !== false && num(loc.capacidad) > 0;
      })
      .map(function(loc) {
        const total = num(loc.capacidad);
        const used = num(loc.capacidad_usada);
        return {
          codigo: loc.codigo,
          nombre: loc.nombre,
          total: total,
          used: used,
          pct: Math.max(0, Math.min(100, Math.round((used / total) * 100)))
        };
      })
      .sort(function(a, b) { return b.pct - a.pct || b.total - a.total; })
      .slice(0, CAPACITY_MAX_BARS);
  }

  /** Clase de barra según el grado de ocupación (tema claro y oscuro). */
  function capacityBarClass(pct) {
    if (pct >= 90) return 'danger';
    if (pct >= 70) return 'warning';
    return 'success';
  }

  function capacityContent() {
    // Sin permiso no se pinta nada (la petición tampoco se emite: ver
    // zoneKeys). Este guard evita que la zona salga como "vacía".
    if (!can('inventory.location.view', ROLES_VIEW)) return '';

    const rows = capacityRows();
    if (!rows.length) {
      return capacityCard(UI.emptyState({
        title: t('dashboard.inventory.capacity.empty'),
        message: ''
      }));
    }

    const body = rows.map(function(row) {
      const label = t('dashboard.inventory.capacity.units', {
        usadas: fmt(row.used),
        total: fmt(row.total)
      });
      return '<div class="py-2 border-b border-default last:border-0 cursor-pointer" ' +
        'role="button" tabindex="0" data-action="navigate" data-route="inventory/locations" ' +
        'title="' + esc(row.nombre || row.codigo) + '">' +
        '<div class="flex items-center justify-between gap-2 mb-1">' +
        '<span class="text-sm truncate">' + esc(row.codigo) + '</span>' +
        '<span class="text-xs text-muted whitespace-nowrap">' + esc(label) + '</span>' +
        '</div>' +
        '<div class="progress">' +
        '<div class="progress-bar ' + capacityBarClass(row.pct) + '" style="width:' + row.pct + '%"></div>' +
        '</div>' +
        '</div>';
    }).join('');

    return capacityCard(body);
  }

  // ---------- últimos 7 días (tira compacta de una línea) ----------
  // `.card.p-3` es el override de padding compacto sancionado por el repo
  // (style.css); sin él la card sumaría 1.5rem propios sobre el card-body.
  function weekCard(bodyHtml) {
    return '<div class="card p-3">' +
      '<div class="card-body">' + bodyHtml + '</div>' +
      '</div>';
  }

  function weekItems() {
    const movements = dataOf('week_movements') || {};
    const items = [
      { label: t('dashboard.inventory.week.movements'), value: num(movements.total), route: 'inventory/movements' },
      { label: t('dashboard.inventory.week.corrections'), value: num(movements.correcciones), route: 'inventory/movements' }
    ];
    if (can('inventory.return.view', ROLES_VIEW)) {
      const returns = dataOf('week_returns') || {};
      items.push({ label: t('dashboard.inventory.week.returns'), value: num(returns.total), route: 'inventory/returns' });
    }
    return items;
  }

  function weekContent() {
    return weekCard('<div data-week-metrics></div>');
  }

  /** Pinta la tira de 7 días con el componente compartido (UI.summaryCards). */
  function renderWeekMetrics() {
    const zoneEl = container.querySelector('[data-zone="' + ZONE_WEEK + '"] [data-week-metrics]');
    if (!zoneEl) return;
    UI.summaryCards(zoneEl, weekItems(), {
      inline: true,
      title: t('dashboard.inventory.week.title')
    });
  }

  // ---------- onboarding (catálogo vacío) ----------
  function contentOnboarding() {
    const critical = dataOf('critical');
    if (!critical) return '';
    const counts = critical.counts || {};
    if (num(counts.productos_activos) > 0) return '';

    const steps = [
      {
        titleKey: 'dashboard.inventory.onboarding.step_location_title',
        descKey: 'dashboard.inventory.onboarding.step_location_desc',
        ctaKey: 'inventory.locations.actions.create',
        perm: 'inventory.location.manage', roles: ROLES_ADMIN, route: 'inventory/locations'
      },
      {
        titleKey: 'dashboard.inventory.onboarding.step_product_title',
        descKey: 'dashboard.inventory.onboarding.step_product_desc',
        ctaKey: 'inventory.products.actions.create',
        perm: 'inventory.item.create', roles: ROLES_EDIT, route: 'inventory'
      },
      {
        titleKey: 'dashboard.inventory.onboarding.step_receiving_title',
        descKey: 'dashboard.inventory.onboarding.step_receiving_desc',
        ctaKey: 'inventory.receiving.card_title',
        perm: 'inventory.receiving.create', roles: ROLES_CREATE, route: 'inventory/receiving'
      }
    ];

    const body = '<div class="grid grid-cols-1 md:grid-cols-3 gap-4">' +
      steps.map(function(step, index) {
        const cta = can(step.perm, step.roles)
          ? '<button type="button" class="btn btn-secondary btn-sm" data-action="navigate" data-route="' + esc(step.route) + '">' +
            esc(t(step.ctaKey)) + '</button>'
          : '';
        return '<div class="card p-3">' +
          '<div class="flex items-center gap-2 mb-2">' +
          '<span class="chip chip-primary">' + (index + 1) + '</span>' +
          '<strong class="text-sm">' + esc(t(step.titleKey)) + '</strong>' +
          '</div>' +
          '<p class="text-muted text-sm mb-3">' + esc(t(step.descKey)) + '</p>' +
          cta +
          '</div>';
      }).join('') +
      '</div>';

    return '<div class="card">' +
      '<div class="card-header">' +
      '<div>' +
      '<h3 class="card-title">' + esc(t('dashboard.inventory.onboarding.title')) + '</h3>' +
      '<p class="text-muted text-sm">' + esc(t('dashboard.inventory.onboarding.subtitle')) + '</p>' +
      '</div>' +
      '</div>' +
      '<div class="card-body">' + body + '</div>' +
      '</div>';
  }

  function renderOnboarding(html) {
    const zoneEl = container.querySelector('[data-zone="onboarding"]');
    if (zoneEl) zoneEl.innerHTML = html;
  }

  // ---------- cabecera ----------
  function navButton(label, route, variant) {
    return '<button type="button" class="btn ' + variant + ' btn-sm" data-action="navigate" data-route="' +
      esc(route) + '">' + esc(label) + '</button>';
  }

  function headerActions() {
    const actions = [];
    if (can('inventory.item.create', ROLES_EDIT)) {
      actions.push(navButton(t('inventory.products.actions.create'), 'inventory', 'btn-primary'));
    }
    if (can('inventory.receiving.create', ROLES_CREATE)) {
      actions.push(navButton(t('inventory.receiving.title'), 'inventory/receiving', 'btn-secondary'));
    }
    if (!actions.length) return '';
    return '<div class="flex items-center gap-2 flex-wrap">' + actions.join('') + '</div>';
  }

  function deniedTemplate() {
    return UI.deniedState({
      title: t('dashboard.inventory.permission.title'),
      message: t('dashboard.inventory.permission.body', { permiso: 'inventory.stock.view' }),
      ctaHref: '#/dashboard',
      ctaLabel: t('router.go_dashboard')
    });
  }

  function buildTemplate() {
    return '<div class="max-w-7xl mx-auto">' +
      UI.pageHeader({
        title: t('dashboard.inventory.title'),
        subtitle: t('dashboard.inventory.subtitle'),
        actionsHtml: headerActions()
      }) +
      '<div data-zone="' + ZONE_ALERTS + '" class="mb-3"></div>' +
      // Tira de 7 días en una sola línea, justo debajo de las alertas.
      '<div data-zone="' + ZONE_WEEK + '" class="mb-4"></div>' +
      '<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">' +
      // Columna ancha: la tabla de stock y, debajo, el gráfico de ocupación.
      // Ir juntas en la 2/3 evita dejar un hueco grande bajo la tabla, que
      // casi siempre es corta (el filtro por defecto es "Sin stock").
      '<div class="lg:col-span-2 flex flex-col gap-4">' +
      '<div data-zone="' + ZONE_STOCK + '"></div>' +
      '<div data-zone="' + ZONE_CAPACITY + '"></div>' +
      '</div>' +
      '<div data-zone="' + ZONE_FLOW + '"></div>' +
      '</div>' +
      '<div data-zone="onboarding"></div>' +
      '</div>';
  }

  // ============================================
  // EVENTOS
  // ============================================
  function navigate(route) {
    if (!route) return;
    if (global.AppRouter && typeof global.AppRouter.navigate === 'function') {
      global.AppRouter.navigate(route);
    } else {
      global.location.hash = '#/' + route;
    }
  }

  function handleClick(e) {
    if (!container || !e.target || typeof e.target.closest !== 'function') return;
    const target = e.target.closest('[data-action]');
    if (!target || !container.contains(target)) return;

    const action = target.getAttribute('data-action') || '';
    if (action === 'navigate') {
      navigate(target.getAttribute('data-route'));
    } else if (action === 'filter') {
      state.filter = target.getAttribute('data-filter') === 'all' ? 'all' : 'sin_stock';
      renderZone(ZONE_STOCK);
    } else if (action.indexOf('retry-') === 0) {
      retryZone(action.slice('retry-'.length));
    }
  }

  function handleKeydown(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    if (!container || !e.target || typeof e.target.closest !== 'function') return;
    const target = e.target.closest('[data-action="navigate"]');
    if (!target || !container.contains(target)) return;
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
  // PUBLIC API
  // ============================================
  const InventoryDashboardModule = {
    init(params, targetContainer) {
      container = targetContainer;
      if (!container) return;

      if (!canStockView()) {
        container.innerHTML = deniedTemplate();
        return;
      }

      container.innerHTML = buildTemplate();
      bindEvents();
      loadAll();
    },

    onActivate(params) {
      if (!container) return;

      if (!canStockView()) {
        container.innerHTML = deniedTemplate();
        return;
      }

      if (!container.querySelector('[data-zone="' + ZONE_STOCK + '"]')) {
        container.innerHTML = buildTemplate();
        bindEvents();
      }
      loadAll();
    },

    destroy() {
      unbindEvents();
      if (container) container.innerHTML = '';
      container = null;

      Object.keys(resources).forEach(function(key) { delete resources[key]; });
      state.filter = 'sin_stock';
      state.zones = freshZones();
    }
  };

  global.InventoryDashboardModule = InventoryDashboardModule;

})(window);
