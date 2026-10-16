/**
 * SPA Router
 * - Hash-based routing (#/page/subpage)
 * - Lazy loading de módulos via ModuleLoader
 * - Guard de autenticación
 * - Eventos: route:change, route:not-found, route:unauthorized
 */
(function(global) {
  'use strict';

  // Mapa de rutas → configuración de módulo.
  // titleKey: clave i18n (se resuelve con t() al pintar; los valores de
  // title se mantienen para rutas registradas en runtime con addRoute).
  const routes = {
    'login': {
      module: 'auth/login',
      container: 'module-container',
      public: true,
      titleKey: 'routes.login',
      title: 'Log in'
    },
    'forgot-password': {
      module: 'auth/forgot-password',
      container: 'module-container',
      public: true,
      titleKey: 'routes.forgot_password',
      title: 'Forgot password'
    },
    'reset-password': {
      module: 'auth/reset-password',
      container: 'module-container',
      public: true,
      titleKey: 'routes.reset_password',
      title: 'Reset password'
    },
    'dashboard': {
      module: 'dashboard/dashboard',
      container: 'module-container',
      public: false,
      titleKey: 'routes.dashboard',
      title: 'Dashboard'
    },
    'profile': {
      module: 'profile/profile',
      container: 'module-container',
      public: false,
      titleKey: 'routes.profile',
      title: 'Profile'
    },
    'users': {
      module: 'users/users',
      container: 'module-container',
      public: false,
      titleKey: 'routes.users',
      title: 'Users'
    },
    'roles': {
      module: 'roles/roles',
      container: 'module-container',
      public: false,
      titleKey: 'routes.roles',
      title: 'Roles'
    },
    'groups': {
      module: 'groups/groups',
      container: 'module-container',
      public: false,
      titleKey: 'routes.groups',
      title: 'Groups'
    },
    'inventory': {
      module: 'inventory/products',
      container: 'module-container',
      public: false,
      titleKey: 'routes.inventory',
      title: 'Inventory'
    },
    'inventory/locations': {
      module: 'inventory/locations',
      container: 'module-container',
      public: false,
      titleKey: 'routes.locations',
      title: 'Locations'
    },
    'inventory/receiving': {
      module: 'inventory/receiving',
      container: 'module-container',
      public: false,
      titleKey: 'routes.receiving',
      title: 'Receiving'
    },
    'inventory/stock': {
      module: 'inventory/stock',
      container: 'module-container',
      public: false,
      titleKey: 'routes.stock',
      title: 'Current stock'
    },
    'inventory/movements': {
      module: 'inventory/movements',
      container: 'module-container',
      public: false,
      titleKey: 'routes.movements',
      title: 'Movements'
    },
    'inventory/reservations': {
      module: 'inventory/reservations',
      container: 'module-container',
      public: false,
      titleKey: 'routes.reservations',
      title: 'Reservations'
    },
    'inventory/picking': {
      module: 'inventory/picking',
      container: 'module-container',
      public: false,
      titleKey: 'routes.picking',
      title: 'Picking'
    },
    'inventory/shipments': {
      module: 'inventory/shipments',
      container: 'module-container',
      public: false,
      titleKey: 'routes.shipments',
      title: 'Shipments'
    },
    'inventory/deliveries': {
      module: 'inventory/deliveries',
      container: 'module-container',
      public: false,
      titleKey: 'routes.deliveries',
      title: 'Deliveries'
    },
    'inventory/returns': {
      module: 'inventory/returns',
      container: 'module-container',
      public: false,
      titleKey: 'routes.returns',
      title: 'Returns'
    },
    'inventory/incidents': {
      module: 'inventory/incidents',
      container: 'module-container',
      public: false,
      titleKey: 'routes.incidents',
      title: 'Incidents'
    },
    'inventory/counts': {
      module: 'inventory/counts',
      container: 'module-container',
      public: false,
      titleKey: 'routes.counts',
      title: 'Counting'
    },
    'audit': {
      module: 'audit/logs',
      container: 'module-container',
      public: false,
      titleKey: 'routes.audit',
      title: 'Audit log'
    },
    'settings': {
      module: 'settings/settings',
      container: 'module-container',
      public: false,
      titleKey: 'routes.settings',
      title: 'Settings'
    }
  };

  // Título de página traducido (t() sólo si el runtime i18n ya está listo).
  function routeTitle(config) {
    if (config.titleKey && typeof global.t === 'function') {
      const translated = t(config.titleKey);
      if (translated && translated !== config.titleKey) return translated;
    }
    return config.title || config.titleKey || '';
  }

  const DEFAULT_ROUTE = 'login';
  const AUTHENTICATED_DEFAULT = 'dashboard';

  let currentRoute = null;
  let initialized = false;

  // Cortacircuitos de bucle de redirección (p.ej. sesión caducada en servidor
  // pero aún válida en cliente): como máximo 6 saltos cada 2 s.
  let redirectBurst = 0;
  let redirectBurstAt = 0;

  function safeRedirect(hash) {
    if (window.location.hash === hash) return false;
    const now = Date.now();
    if (now - redirectBurstAt > 2000) {
      redirectBurst = 0;
      redirectBurstAt = now;
    }
    if (++redirectBurst > 6) {
      console.error('[Router] Redirect loop interrupted toward', hash);
      return false;
    }
    window.location.hash = hash;
    return true;
  }

  function parseHash() {
    const hash = window.location.hash || '';
    const clean = hash.replace(/^#\/?/, '');
    const [path, queryString] = clean.split('?');
    const params = new URLSearchParams(queryString || '');
    const paramsObj = {};
    for (const [k, v] of params.entries()) {
      paramsObj[k] = v;
    }
    return {
      path: path || '',
      params: paramsObj
    };
  }

  function resolveRoute(path) {
    // Match exacto
    if (routes[path]) {
      return { config: routes[path], path, params: {} };
    }

    // Match con parámetros dinámicos (ej: users/:id)
    for (const [routePath, config] of Object.entries(routes)) {
      if (routePath.includes(':')) {
        const match = matchDynamicRoute(routePath, path);
        if (match) {
          return { config, path, params: match };
        }
      }
    }

    return null;
  }

  function matchDynamicRoute(routePath, actualPath) {
    const routeParts = routePath.split('/');
    const actualParts = actualPath.split('/');

    if (routeParts.length !== actualParts.length) {
      return null;
    }

    const params = {};
    for (let i = 0; i < routeParts.length; i++) {
      const rp = routeParts[i];
      const ap = actualParts[i];

      if (rp.startsWith(':')) {
        params[rp.substring(1)] = ap;
      } else if (rp !== ap) {
        return null;
      }
    }

    return params;
  }

  function isAuthenticated() {
    if (!global.Storage) return false;
    const token = global.Storage.getToken();
    if (!token) return false;

    // Expiración del access token sin verificar firma (la firma la valida el backend).
    try {
      const parts = token.split('.');
      if (parts.length !== 3) return false;
      const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
      const expired = payload.exp && payload.exp < Math.floor(Date.now() / 1000);
      // Access vencido: la sesión sigue viva sólo si hay refresh token
      // (http.js lo renueva en el primer 401).
      if (expired) return !!global.Storage.getRefreshToken();
    } catch (e) {
      return false;
    }

    return true;
  }

  function showAppShell(show) {
    // Presentación del shell con clases CSS (components.css:
    // .app-layout.is-public oculta el header y anula el margen del sidebar).
    // Antes eran estilos inline sobre header/.main-content y un display
    // forzado en el overlay que anulaba la regla .sidebar.is-open + overlay.
    const app = document.getElementById('app');
    const sidebar = document.getElementById('sidebar');

    if (app) {
      app.classList.toggle('is-public', !show);
    }

    if (sidebar) {
      sidebar.classList.toggle('hidden', !show);
      // Resetear el panel móvil al cambiar de ruta (evita fugas de estado):
      // sin 'is-open' el overlay vuelve a display:none por CSS, sin fijar
      // estilos en línea.
      sidebar.classList.remove('is-open');
    }
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderNotFound(container) {
    if (!container) return;
    container.innerHTML = `
      <div class="card max-w-md mx-auto mt-8">
        <div class="card-header">
          <h3 class="card-title">${escapeHtml(t('router.not_found_title'))}</h3>
        </div>
        <div class="card-body">
          <p>${escapeHtml(t('router.not_found_body'))}</p>
        </div>
        <div class="mt-4">
          <a href="#/${AUTHENTICATED_DEFAULT}" class="btn btn-primary">${escapeHtml(t('router.go_dashboard'))}</a>
        </div>
      </div>
    `;
  }

  // true si el módulo JS está registrado en el ModuleLoader.
  // Sin loader disponible no se bloquea (no hay forma de saberlo).
  function isModuleRegistered(moduleName) {
    if (!global.ModuleLoader || typeof global.ModuleLoader.has !== 'function') return true;
    return global.ModuleLoader.has(moduleName);
  }

  function renderModuleUnavailable(container, config) {
    if (!container) return;
    // El dict (recurso propio) se inserta sin escapar; el título de ruta SÍ
    // se escapa antes de interpolar {titulo}.
    const titleHtml = '<strong>' + escapeHtml(routeTitle(config)) + '</strong>';
    container.innerHTML = `
      <div class="card max-w-md mx-auto mt-8">
        <div class="card-header">
          <h3 class="card-title">${escapeHtml(t('router.unavailable_title'))}</h3>
        </div>
        <div class="card-body">
          <p>${t('router.unavailable_body', { titulo: titleHtml })}</p>
        </div>
        <div class="mt-4">
          <a href="#/${AUTHENTICATED_DEFAULT}" class="btn btn-primary">${escapeHtml(t('router.go_dashboard'))}</a>
        </div>
      </div>
    `;
  }

  async function handleRoute() {
    const { path, params: hashParams } = parseHash();
    const resolved = resolveRoute(path);

    // Ruta no encontrada
    if (!resolved) {
      emit('route:not-found', { path });

      // Sin sesión no se pinta el shell (header + sidebar).
      if (!isAuthenticated()) {
        emit('route:unauthorized', { path });
        safeRedirect('#/login');
        return;
      }

      const container = document.getElementById('module-container');
      showAppShell(true);
      renderNotFound(container);
      return;
    }

    const { config, params: routeParams } = resolved;
    const allParams = { ...hashParams, ...routeParams };

    // Guard de autenticación
    if (!config.public && !isAuthenticated()) {
      emit('route:unauthorized', { path, config });
      safeRedirect('#/login');
      return;
    }

    // Si es login y ya está autenticado → dashboard
    if (path === 'login' && isAuthenticated()) {
      safeRedirect(`#/${AUTHENTICATED_DEFAULT}`);
      return;
    }

    // Ruta declarada pero sin módulo JS registrado (rutas huérfanas del
    // roadmap): no intentar cargarla, aviso claro en vez de tarjeta de error.
    if (!isModuleRegistered(config.module)) {
      emit('route:unavailable', { path, module: config.module });
      showAppShell(!config.public);
      document.title = `${routeTitle(config)} - ${global.AppConfig?.appName || 'XP Platform'}`;
      renderModuleUnavailable(document.getElementById(config.container || 'module-container'), config);
      return;
    }

    // Mostrar/ocultar app shell (controla header, sidebar y main-content)
    showAppShell(config.public ? false : true);

    // Actualizar título
    document.title = `${routeTitle(config)} - ${global.AppConfig?.appName || 'XP Platform'}`;

    // Evitar recargar el mismo módulo si ya está activo
    if (currentRoute === path && global.ModuleLoader && global.ModuleLoader.isLoaded(config.module)) {
      const instance = global.ModuleLoader.getInstance(config.module);
      if (instance && typeof instance.onActivate === 'function') {
        try {
          instance.onActivate(allParams);
        } catch (e) {
          console.error('[Router] onActivate error:', e);
        }
      }
      emit('route:activated', { path, params: allParams });
      return;
    }

    currentRoute = path;

    // Cargar módulo
    const containerId = config.container || 'module-container';
    const container = document.getElementById(containerId);

    if (!container) {
      console.error(`[Router] Container #${containerId} not found`);
      return;
    }

    emit('route:loading', { path, module: config.module });

    try {
      if (global.ModuleLoader) {
        await global.ModuleLoader.load(config.module, containerId, allParams);
      } else {
        console.error('[Router] ModuleLoader not available');
        container.innerHTML = '<p class="text-danger">' + escapeHtml(t('router.unavailable_title')) + '</p>';
      }
      emit('route:loaded', { path, module: config.module, params: allParams });
    } catch (error) {
      console.error(`[Router] Error loading module "${config.module}":`, error);
      emit('route:error', { path, module: config.module, error });
    }
  }

  function emit(event, data) {
    if (global.EventBus) {
      global.EventBus.emit(event, data);
    }
  }

  const AppRouter = {
    init() {
      if (initialized) return;
      initialized = true;

      // Listener de cambios de hash
      window.addEventListener('hashchange', handleRoute);

      // Listener de popstate (back/forward)
      window.addEventListener('popstate', handleRoute);

      // Auth events
      if (global.EventBus) {
        global.EventBus.on('auth:login', () => {
          const { path } = parseHash();
          if (path === 'login' || path === '') {
            // v1.10.2: esperar al diccionario completo (la carga inicial
            // fue pre-sesion y no pudo descargarlo) para que la primera
            // pantalla tras el login no pinte claves en crudo.
            const ready = (global.i18n && typeof global.i18n.ensureLoaded === 'function')
              ? global.i18n.ensureLoaded()
              : Promise.resolve();
            Promise.resolve(ready).then(() => safeRedirect(`#/${AUTHENTICATED_DEFAULT}`));
          }
        });

        global.EventBus.on('auth:logout', () => {
          safeRedirect('#/login');
        });

        global.EventBus.on('auth:unauthorized', () => {
          safeRedirect('#/login');
        });

        // Cambio de idioma SIN recarga: descarga el módulo activo y vuelve a
        // enrutar para que repinte sus textos con el diccionario nuevo.
        global.EventBus.on('i18n:changed', () => {
          AppRouter.refresh();
        });
      }

      // Initial route
      handleRoute();

      // Si no hay hash, redirigir según auth
      if (!window.location.hash || window.location.hash === '#' || window.location.hash === '#/') {
        const target = isAuthenticated() ? AUTHENTICATED_DEFAULT : DEFAULT_ROUTE;
        window.location.hash = `#/${target}`;
      }
    },

    navigate(path, params = {}) {
      let hash = `#/${path.replace(/^\//, '')}`;
      const qs = new URLSearchParams(params).toString();
      if (qs) {
        hash += `?${qs}`;
      }
      window.location.hash = hash;
    },

    replace(path, params = {}) {
      let hash = `#/${path.replace(/^\//, '')}`;
      const qs = new URLSearchParams(params).toString();
      if (qs) {
        hash += `?${qs}`;
      }
      const url = window.location.pathname + hash;
      window.history.replaceState(null, '', url);
      handleRoute();
    },

    getCurrentRoute() {
      return currentRoute;
    },

    // Re-pinta la ruta actual (i18n:changed): unload del módulo + re-enrute.
    refresh() {
      const { path } = parseHash();
      const resolved = resolveRoute(path);
      if (resolved && global.ModuleLoader && global.ModuleLoader.isLoaded(resolved.config.module)) {
        global.ModuleLoader.unload(resolved.config.module);
      }
      currentRoute = null;
      handleRoute();
    },

    getParams() {
      return parseHash().params;
    },

    addRoute(path, config) {
      routes[path] = config;
    },

    addRoutes(newRoutes) {
      Object.assign(routes, newRoutes);
    },

    getRoutes() {
      return { ...routes };
    },

    isPublic(path) {
      const resolved = resolveRoute(path);
      return resolved ? resolved.config.public : false;
    },

    // true si la ruta existe Y su módulo JS está registrado en el ModuleLoader.
    // El sidebar/dashboard lo usan para no enlazar a módulos aún no implementados.
    isModuleAvailable(path) {
      const resolved = resolveRoute(path);
      if (!resolved) return false;
      return isModuleRegistered(resolved.config.module);
    },

    destroy() {
      window.removeEventListener('hashchange', handleRoute);
      window.removeEventListener('popstate', handleRoute);
      initialized = false;
      currentRoute = null;
    }
  };

  global.AppRouter = AppRouter;

})(window);
