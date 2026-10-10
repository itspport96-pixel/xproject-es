/**
 * Module Loader - Lazy loading de módulos
 * Responsabilidades:
 * - Cargar módulos JS bajo demanda
 * - Cachear módulos ya cargados
 * - Mostrar spinner durante carga
 * - Emitir eventos: module:loading, module:loaded, module:error
 * - Prevenir cargas duplicadas concurrentes
 */
(function(global) {
  'use strict';

  // Acceso perezoso a window.AppConfig: el script inline que lo declara va
  // DESPUES de estos ficheros, asi que al evaluar loader.js todavia no existe
  // (con el servidor embebido de E2E, sin subdirectorio /xp/public, el
  // fallback fijo rompia la carga de modulos).
  function basePath() {
    return (global.AppConfig && global.AppConfig.baseUrl) || '/xp/public';
  }

  // Las rutas del manifiesto se resuelven AL CARGAR (no al registrar): el
  // registro se ejecuta al evaluar este fichero, cuando window.AppConfig
  // todavia no existe.
  function resolveModulePath(path) {
    if (path.startsWith('http')) return path;
    return `${basePath()}/js/modules/${path}`;
  }

  function resolveCssPath(path) {
    if (path.startsWith('http')) return path;
    return `${basePath()}/${path}`;
  }

  const registry = new Map();
  const pendingLoads = new Map();
  const activeByContainer = new Map();

  function showSpinner(container) {
    if (!container) return;
    container.innerHTML = `
      <div class="flex flex-col items-center justify-center py-20" data-loader-spinner>
        <div class="spinner"></div>
        <p class="text-muted text-sm mt-4">${escapeHtml(t('loader.loading_module'))}</p>
      </div>
    `;
  }

  function hideSpinner(container) {
    if (!container) return;
    const spinner = container.querySelector('[data-loader-spinner]');
    if (spinner) spinner.remove();
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

  function renderError(container, moduleName, error) {
    if (!container) return;
    const message = escapeHtml((error && error.message) || t('loader.error_unknown'));
    // El dict (recurso propio) se inserta sin escapar; el nombre del módulo
    // SÍ se escapa antes de interpolar {modulo}.
    const moduleHtml = '<strong>' + escapeHtml(moduleName) + '</strong>';
    container.innerHTML = `
      <div class="card max-w-md mx-auto mt-4">
        <div class="card-header">
          <h3 class="card-title text-danger">${escapeHtml(t('loader.error_title'))}</h3>
        </div>
        <div class="card-body">
          <p class="mb-2">${t('loader.error_body', { modulo: moduleHtml })}</p>
          <p class="text-xs text-muted">${message}</p>
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn btn-secondary btn-sm" data-action="retry">${escapeHtml(t('loader.retry'))}</button>
        </div>
      </div>
    `;
    const retryBtn = container.querySelector('[data-action="retry"]');
    if (retryBtn) {
      retryBtn.addEventListener('click', () => ModuleLoader.load(moduleName, container.id));
    }
  }

  function emit(event, data) {
    if (global.EventBus) global.EventBus.emit(event, data);
  }

  // Cache-busting: los assets se sirven con mod_expires (1 semana); AppConfig
  // .assetsVersion (max filemtime en index.php) invalida la cache al desplegar.
  function withVersion(path) {
    const v = global.AppConfig && global.AppConfig.assetsVersion;
    if (!v || typeof path !== 'string' || path.indexOf('?') !== -1) {
      return path;
    }
    // Las URLs absolutas del propio origen si se versionan (basePath es una
    // URL completa); se dechan intactas solo las de otro origen (CDN, etc.).
    if (/^https?:/.test(path)) {
      try {
        if (new URL(path, location.href).origin !== location.origin) return path;
      } catch (e) {
        return path;
      }
    }
    return `${path}?v=${v}`;
  }

  function loadScript(path) {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = withVersion(path);
      script.async = true;
      script.type = 'text/javascript';
      script.onload = () => resolve(script);
      script.onerror = () => {
        script.remove();
        reject(new Error(`Failed to load script: ${path}`));
      };
      document.head.appendChild(script);
    });
  }

  function loadStylesheet(path) {
    return new Promise((resolve, reject) => {
      const href = withVersion(path);
      const existing = document.querySelector(`link[href="${href}"]`);
      if (existing) { resolve(existing); return; }
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.onload = () => resolve(link);
      link.onerror = () => {
        link.remove();
        reject(new Error(`Failed to load stylesheet: ${path}`));
      };
      document.head.appendChild(link);
    });
  }

  const ModuleLoader = {
    register(name, path, options = {}) {
      if (registry.has(name)) {
        console.warn(`[Loader] Module "${name}" already registered, overwriting`);
      }
      registry.set(name, {
        name,
        path,
        css: options.css || null,
        deps: options.deps || [],
        loaded: false,
        loading: false,
        instance: null
      });
    },

    registerMany(modules) {
      Object.entries(modules).forEach(([name, config]) => {
        this.register(name, config.path, { css: config.css, deps: config.deps });
      });
    },

    async load(name, containerId, params = {}) {
      const entry = registry.get(name);
      if (!entry) {
        const error = new Error(t('loader.error_not_registered', { modulo: name }));
        emit('module:error', { name, error });
        throw error;
      }

      const container = containerId ? document.getElementById(containerId) : null;

      if (containerId) {
        const prevName = activeByContainer.get(containerId);
        if (prevName && prevName !== name) {
          const prevEntry = registry.get(prevName);
          if (prevEntry && prevEntry.instance && typeof prevEntry.instance.destroy === 'function') {
            try { prevEntry.instance.destroy(); } catch (e) { console.warn(`[Loader] Error destroying "${prevName}":`, e); }
          }
          activeByContainer.delete(containerId);
        }
      }

      if (entry.loaded && entry.instance) {
        if (container) hideSpinner(container);
        try {
          if (containerId && activeByContainer.get(containerId) !== name && typeof entry.instance.init === 'function') {
            await entry.instance.init(params, container);
          } else if (typeof entry.instance.onActivate === 'function') {
            entry.instance.onActivate(params);
          }
          emit('module:activated', { name, params });
        } catch (e) { console.error(`[Loader] Error activating "${name}":`, e); }
        if (containerId) activeByContainer.set(containerId, name);
        return entry.instance;
      }

      if (pendingLoads.has(name)) return pendingLoads.get(name);

      entry.loading = true;
      if (container) showSpinner(container);
      emit('module:loading', { name });

      const loadPromise = (async () => {
        try {
          for (const dep of entry.deps) {
            if (!this.isLoaded(dep)) await this.load(dep, null);
          }
          if (entry.css) {
            try { await loadStylesheet(resolveCssPath(entry.css)); }
            catch (e) { console.warn(`[Loader] Optional CSS not loaded for "${name}":`, e.message); }
          }
          await loadScript(resolveModulePath(entry.path));

          const globalKey = this.resolveGlobalKey(name);
          const instance = global[globalKey];
          if (!instance) throw new Error(t('loader.error_no_global', { modulo: name, clave: globalKey }));

          entry.instance = instance;
          entry.loaded = true;
          entry.loading = false;

          if (typeof instance.init === 'function') await instance.init(params, container);
          if (containerId) activeByContainer.set(containerId, name);
          if (container) hideSpinner(container);

          emit('module:loaded', { name, instance });
          return instance;
        } catch (error) {
          entry.loading = false;
          console.error(`[Loader] Error loading "${name}":`, error);
          if (container) renderError(container, name, error);
          emit('module:error', { name, error });
          throw error;
        } finally {
          pendingLoads.delete(name);
        }
      })();

      pendingLoads.set(name, loadPromise);
      return loadPromise;
    },

    isLoaded(name) { const entry = registry.get(name); return entry ? entry.loaded : false; },
    has(name) { return registry.has(name); },
    isLoading(name) { return pendingLoads.has(name); },
    getInstance(name) { const entry = registry.get(name); return entry && entry.loaded ? entry.instance : null; },

    list() {
      const result = [];
      registry.forEach((entry, name) => result.push({ name, loaded: entry.loaded, loading: entry.loading, path: entry.path }));
      return result;
    },

    unload(name) {
      const entry = registry.get(name);
      if (!entry) return false;
      try { if (entry.instance && typeof entry.instance.destroy === 'function') entry.instance.destroy(); } catch (e) {}
      const scriptTarget = resolveModulePath(entry.path).split('?')[0];
      document.querySelectorAll('script[src]').forEach(s => {
        const src = (s.getAttribute('src') || '').split('?')[0];
        if (src === scriptTarget) s.remove();
      });
      const globalKey = this.resolveGlobalKey(name);
      if (global[globalKey]) delete global[globalKey];
      entry.loaded = false;
      entry.instance = null;
      activeByContainer.forEach((mod, cid) => { if (mod === name) activeByContainer.delete(cid); });
      emit('module:unloaded', { name });
      return true;
    },

    resolveGlobalKey(name) {
      const parts = name.split(/[\/\-_]/);
      return parts.map(p => p.charAt(0).toUpperCase() + p.slice(1).toLowerCase()).join('') + 'Module';
    },

    async preload(name) {
      const entry = registry.get(name);
      if (!entry || entry.loaded || entry.loading) return;
      try { await this.load(name, null); } catch (e) { console.warn(`[Loader] Preload failed for "${name}":`, e.message); }
    },

    async preloadMany(names) { await Promise.all(names.map(n => this.preload(n))); },
    reset() { registry.clear(); pendingLoads.clear(); }
  };

  // ============================================
  // REGISTRO INICIAL DE MÓDULOS (ÚNICO BLOQUE)
  // ============================================
  ModuleLoader.registerMany({
    // Sprint 1: Auth
    'auth/login': { path: 'auth/login.js' },
    'auth/state': { path: 'auth/state.js' },

    // Sprint 4.5: Dashboard
    'dashboard/dashboard': { path: 'dashboard/dashboard.js' },

    // Sprint 4: Inventory
    'inventory/products': { path: 'inventory/products.js', deps: ['inventory/state'] },
    'inventory/locations': { path: 'inventory/locations.js', deps: ['inventory/state'] },
    'inventory/state': { path: 'inventory/state.js' },

    // Sprint 5 (v1.5.2): recepción y stock
    'inventory/receiving': { path: 'inventory/receiving.js' },
    'inventory/stock': { path: 'inventory/stock.js' },

    // Sprint 6 UI (v1.6.1): reservas y visor de auditoría
    'inventory/reservations': { path: 'inventory/reservations.js' },
    'audit/logs': { path: 'audit/logs.js' },

    // Sprint 7 UI (v1.7.1): picking y envíos
    'inventory/picking': { path: 'inventory/picking.js' },
    'inventory/shipments': { path: 'inventory/shipments.js' },

    // Sprint 8 UI (v1.8.4): entregas y devoluciones
    'inventory/deliveries': { path: 'inventory/deliveries.js' },
    'inventory/returns': { path: 'inventory/returns.js' },

    // Sprint v1.9.3: incidencias y conteos (componentes UI compartidos)
    'inventory/incidents': { path: 'inventory/incidents.js' },
    'inventory/counts': { path: 'inventory/counts.js' },

    // Sprint v1.10.0 (R3): listado de movimientos
    'inventory/movements': { path: 'inventory/movements.js' },

    // Sprint v1.12.0: UI de control (usuarios, roles, grupos, contrasena)
    'users/users': { path: 'users/users.js' },
    'roles/roles': { path: 'roles/roles.js' },
    'groups/groups': { path: 'groups/groups.js' },
    'auth/password': { path: 'auth/password.js' }
  });

  global.ModuleLoader = ModuleLoader;
})(window);
