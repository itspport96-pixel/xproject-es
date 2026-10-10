/**
 * i18n Runtime (v1.10.1 base, v1.10.2 GUI de idioma, v1.10.3 preferencias,
 * spec Internacionalizacion)
 *
 * - Diccionario en memoria: window.i18n (y atajo global t(clave, params)).
 * - Fuente: GET /api/locales/{locale}.json (es, en, pt, fr) con fallback:
 *      clave -> locale actual -> es -> diccionario embebido (solo pre-sesion)
 *      -> la propia clave.
 * - Persistencia: localStorage['lang'] + preferencia del servidor
 *   (users.preferencias.idioma via PATCH /api/users/me/preferences):
 *      * al cambiar de idioma con sesion se envia el PATCH; si falla se
 *        conserva la eleccion local y se avisa con toast
 *      * syncFromUser() (preferencia del servidor) solo se aplica en el
 *        login; las llamadas posteriores a /auth/me NO cambian el idioma
 *      * la eleccion explicita de la sesion (sessionChoice) gana sobre
 *        cualquier preferencia entrante hasta que el PATCH confirme
 * - Cambio de idioma SIN recargar: vuelve a buscar el diccionario, aplica
 *   data-i18n del shell y emite 'i18n:changed' (sidebar y router re-renderizan).
 * - Sesion: sin token no se pide el diccionario (la API devuelve 401 y
 *   http.js lo trataria como sesion caducada); al llegar la sesion
 *   (auth:login / auth:user-updated / auth:synced) se descarga con
 *   ensureLoaded() y se vuelve a pintar todo.
 * - inject(locale, diccionario): para smokes jsdom y tests sin red.
 */
(function(global) {
  'use strict';

  const SUPPORTED = ['es', 'en', 'pt', 'fr'];
  const DEFAULT_LOCALE = 'es';
  const STORAGE_KEY = 'lang';

  // Diccionario embebido (es): SÓLO las cadenas que se pintan ANTES de que
  // exista sesión (la API de locales exige usuario autenticado). El resto de
  // claves viven en resources/locales/*.json.
  const BUILTIN_ES = {
    shell: {
      guest: 'Invitado',
      logout: 'Cerrar sesion',
      language: 'Idioma',
      toggle_menu: 'Alternar menú',
      toggle_theme: 'Alternar tema',
      toggle_language: 'Seleccionar idioma',
      no_role: 'Sin rol',
      sidebar_close: 'Cerrar menú',
      sidebar_menu: 'Menú principal',
      session_expired: 'Sesión expirada',
      session_idle: 'Se cerro tu sesion por inactividad.'
    },
    ui: {
      empty_title: 'Sin datos',
      pagination: 'Mostrando {shown} de {total} {label}',
      records: 'registros',
      close: 'Cerrar',
      error_generic: 'Error',
      error_not_csv: 'La respuesta no es un CSV.',
      toast_invalid: 'Datos inválidos',
      toast_session: 'Sesión expirada',
      toast_forbidden: 'Acceso denegado',
      toast_not_found: 'No encontrado',
      toast_conflict: 'Conflicto',
      toast_rejected: 'Operación rechazada',
      toast_lang_save_title: 'Idioma',
      toast_lang_save_failed: 'No se pudo guardar el idioma en el servidor; se mantiene solo en este navegador.'
    },
    theme: {
      action_dark: 'Claro',
      action_light: 'Oscuro',
      action_system: 'Auto',
      title_dark: 'Modo claro',
      title_light: 'Modo oscuro',
      title_system: 'Auto (sistema)',
      current_dark: 'Modo oscuro',
      current_light: 'Modo claro',
      current_system: 'Modo sistema',
      toggle_default: 'Cambiar tema',
      aria: 'Tema actual: {preference}. Click para cambiar.',
      synced_title: 'Tema sincronizado',
      synced_message: 'Tema cambiado a {effective} desde otra pestaña'
    },
    login: {
      tagline: 'Gestión de Inventario Multi-Tenant',
      email: 'Correo electrónico',
      password: 'Contraseña',
      email_placeholder: 'tu@empresa.com',
      password_placeholder: 'Ingresa tu contraseña',
      submit: 'Iniciar sesión',
      submitting: 'Iniciando sesión...',
      terms: 'Al iniciar sesión aceptas los términos de uso.',
      error_required_email: 'El correo electrónico es obligatorio',
      error_invalid_email: 'Formato de correo inválido',
      error_required_password: 'La contraseña es obligatoria',
      error_short_password: 'La contraseña debe tener al menos 6 caracteres',
      welcome: 'Bienvenido',
      welcome_message: 'Hola, {nombre}',
      error_invalid_response: 'Respuesta inválida del servidor',
      error_generic: 'Error al iniciar sesión. Intenta nuevamente.',
      error_401: 'Credenciales inválidas. Verifica tu correo y contraseña.',
      error_403: 'Cuenta no activa. Contacta al administrador.',
      error_429: 'Demasiados intentos. Espera unos minutos.',
      error_408: 'Tiempo de espera agotado. Verifica tu conexión.'
    },
    loader: {
      loading_module: 'Cargando módulo...',
      error_title: 'Error al cargar módulo',
      error_body: 'No se pudo cargar el módulo {modulo}.',
      retry: 'Reintentar',
      error_unknown: 'Error desconocido',
      error_not_registered: 'Módulo "{modulo}" no registrado',
      error_no_global: 'Módulo "{modulo}" no expuso "{clave}" en window'
    },
    router: {
      not_found_title: 'Ruta no encontrada',
      not_found_body: 'La página que buscas no existe.',
      go_dashboard: 'Ir al dashboard',
      unavailable_title: 'Módulo no disponible',
      unavailable_body: '{titulo} está planificado pero aún no está habilitado en esta versión de la plataforma.'
    },
    nav: {
      dashboard: 'Dashboard',      products: 'Productos',
      locations: 'Ubicaciones',
      receiving: 'Recepción',
      stock: 'Stock',
      movements: 'Movimientos',
      reservations: 'Reservas',
      picking: 'Picking',
      shipments: 'Envíos',
      deliveries: 'Entregas',
      returns: 'Devoluciones',
      incidents: 'Incidencias',
      counts: 'Conteos',
      users: 'Usuarios',
      audit: 'Auditoría',
      settings: 'Configuración',
      section_principal: 'Principal',
      section_inventory: 'Inventario',
      section_admin: 'Administración',
      section_system: 'Sistema'
    },
    routes: {
      login: 'Iniciar sesión',
      dashboard: 'Dashboard',
      users: 'Usuarios',
      inventory: 'Inventario',
      locations: 'Ubicaciones',
      receiving: 'Recepción',
      stock: 'Stock actual',
      movements: 'Movimientos',
      reservations: 'Reservas',
      picking: 'Picking',
      shipments: 'Envíos',
      deliveries: 'Entregas',
      returns: 'Devoluciones',
      incidents: 'Incidencias',
      counts: 'Conteos',
      audit: 'Auditoría',
      settings: 'Configuración'
    }
  };

  const dicts = {};
  const fetched = {};   // locale con el diccionario COMPLETO ya descargado
  const inflight = {};  // peticiones en curso por locale (un solo GET)
  let current = DEFAULT_LOCALE;
  let initPromise = null;
  // Eleccion explicita de idioma hecha en esta sesion (selector/llamada
  // directa a setLocale). Manda sobre lo que devuelva /auth/me hasta que el
  // PATCH de preferencias confirme; se reinicia al cerrar sesion.
  let sessionChoice = false;
  // Ultimo idioma confirmado en el servidor (evita PATCH duplicados cuando
  // 'auth:login' se emite mas de una vez o al re-elegir el mismo idioma).
  let lastPersisted = null;

  dicts[DEFAULT_LOCALE] = BUILTIN_ES;

  function isSupported(locale) {
    return SUPPORTED.indexOf(locale) !== -1;
  }

  function readStoredLocale() {
    try {
      const raw = global.localStorage ? global.localStorage.getItem(STORAGE_KEY) : null;
      return raw && isSupported(raw) ? raw : null;
    } catch (e) {
      return null;
    }
  }

  function writeStoredLocale(locale) {
    try {
      if (global.localStorage) global.localStorage.setItem(STORAGE_KEY, locale);
    } catch (e) {
      /* localStorage no disponible: el locale sigue en memoria */
    }
  }

  function lookup(dict, key) {
    if (!dict || !key) return undefined;
    let node = dict;
    const parts = String(key).split('.');
    for (let i = 0; i < parts.length; i++) {
      if (node === null || typeof node !== 'object') return undefined;
      node = node[parts[i]];
    }
    return typeof node === 'string' ? node : undefined;
  }

  function interpolate(text, params) {
    if (!params) return text;
    return text.replace(/\{(\w+)\}/g, function(match, name) {
      return Object.prototype.hasOwnProperty.call(params, name)
        ? String(params[name])
        : match;
    });
  }

  function t(key, params) {
    let value = lookup(dicts[current], key);

    if (value === undefined && current !== DEFAULT_LOCALE) {
      value = lookup(dicts[DEFAULT_LOCALE], key);
    }

    if (value === undefined) {
      value = lookup(BUILTIN_ES, key);
    }

    if (value === undefined) {
      return key;
    }

    return interpolate(value, params);
  }

  function fetchDictionary(locale) {
    if (!global.HttpClient || typeof global.HttpClient.get !== 'function') {
      return Promise.reject(new Error('HttpClient not available'));
    }

    // La API de locales exige sesion (401 sin token). Sin sesion no se pide
    // nada: http.js trataria el 401 como sesion caducada (limpia la auth y
    // redirige). La descarga se reintenta tras el login via ensureLoaded().
    const hasSession = global.Storage &&
      typeof global.Storage.getToken === 'function' && !!global.Storage.getToken();
    if (!hasSession) {
      return Promise.reject(new Error('no session: dictionary pending download'));
    }

    if (inflight[locale]) return inflight[locale];

    inflight[locale] = Promise.resolve(global.HttpClient.get('/locales/' + locale + '.json'))
      .then(function(res) {
        const dictionary = res && res.data && res.data.dictionary;
        if (!dictionary || typeof dictionary !== 'object') {
          throw new Error('Respuesta de locales invalida');
        }
        dicts[locale] = dictionary;
        fetched[locale] = true;
        return dictionary;
      }, function(err) {
        delete inflight[locale]; // permitir reintentar (p. ej. tras login)
        throw err;
      });

    return inflight[locale];
  }

  /**
   * Descarga el diccionario del locale actual si aun no esta completo
   * (p. ej. tras el primer login: la carga inicial era pre-sesion y no
   * pudo descargar nada). Emite 'i18n:changed' al terminar para que el
   * shell, el sidebar y la pantalla activa repinten. Un solo vuelo en
   * curso: las sucesivas llamadas (auth:login + auth:user-updated)
   * comparten la misma promesa y no emiten dos veces.
   * @returns {Promise<string>} locale activo (nunca rechaza)
   */
  let ensureInflight = null;
  function ensureLoaded() {
    if (fetched[current]) return Promise.resolve(current);
    if (ensureInflight) return ensureInflight;

    ensureInflight = fetchDictionary(current).then(
      function() {
        ensureInflight = null;
        applyDom();
        emitChanged();
        return current;
      },
      function() {
        ensureInflight = null;
        return current;
      }
    );

    return ensureInflight;
  }

  function applyDom() {
    if (!global.document) return;
    const doc = global.document;

    doc.documentElement.setAttribute('lang', current);

    doc.querySelectorAll('[data-i18n]').forEach(function(el) {
      el.textContent = t(el.getAttribute('data-i18n'));
    });

    doc.querySelectorAll('[data-i18n-aria]').forEach(function(el) {
      el.setAttribute('aria-label', t(el.getAttribute('data-i18n-aria')));
    });

    doc.querySelectorAll('[data-i18n-placeholder]').forEach(function(el) {
      el.setAttribute('placeholder', t(el.getAttribute('data-i18n-placeholder')));
    });

    const select = doc.getElementById('lang-select');
    if (select) select.value = current;

    const codeEl = doc.getElementById('lang-code');
    if (codeEl) codeEl.textContent = current.toUpperCase();
  }

  function emitChanged() {
    if (global.EventBus && typeof global.EventBus.emit === 'function') {
      global.EventBus.emit('i18n:changed', { locale: current });
    }
  }

  function bindSelector() {
    if (!global.document) return;
    const select = global.document.getElementById('lang-select');
    if (!select || select.dataset.i18nBound === '1') return;
    select.dataset.i18nBound = '1';
    select.value = current;
    select.addEventListener('change', function() {
      setLocale(select.value);
    });
  }

  // ====================================================================
  // Selector de idioma del header (v1.13.7): boton icono con el codigo
  // del idioma + menu pequeño. Reutiliza setLocale() sin modificarlo.
  // ====================================================================

  const LANG_NAMES = {
    es: 'Español',
    en: 'English',
    pt: 'Português',
    fr: 'Français'
  };

  const CHECK_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><polyline points="20 6 9 17 4 12"/></svg>';

  let langToggle = null;
  let langDropdown = null;
  let langBound = false;

  function renderLangToggle() {
    if (!langToggle) return;
    const codeEl = langToggle.querySelector('#lang-code');
    if (codeEl) codeEl.textContent = current.toUpperCase();
    langToggle.setAttribute('title', t('shell.toggle_language'));
    langToggle.setAttribute('aria-label', t('shell.toggle_language'));
  }

  function renderLangMenu() {
    if (!langDropdown) return;
    langDropdown.innerHTML = '';
    SUPPORTED.forEach(function(locale) {
      const btn = global.document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('role', 'menuitem');
      btn.className = 'flex items-center justify-between w-full text-left px-4 py-2 text-sm cursor-pointer hover:bg-surface-alt';
      if (locale === current) {
        btn.className += ' text-primary font-medium';
        btn.setAttribute('aria-current', 'true');
      }
      const label = global.document.createElement('span');
      label.textContent = LANG_NAMES[locale] || locale;
      btn.appendChild(label);
      if (locale === current) {
        const check = global.document.createElement('span');
        check.setAttribute('aria-hidden', 'true');
        check.innerHTML = CHECK_ICON;
        btn.appendChild(check);
      }
      btn.addEventListener('click', function() {
        closeLangMenu();
        if (locale !== current) setLocale(locale);
      });
      langDropdown.appendChild(btn);
    });
  }

  function openLangMenu() {
    if (!langDropdown || !langToggle) return;
    renderLangMenu();
    langDropdown.classList.remove('hidden');
    langToggle.setAttribute('aria-expanded', 'true');
  }

  function closeLangMenu() {
    if (!langDropdown || !langToggle) return;
    langDropdown.classList.add('hidden');
    langToggle.setAttribute('aria-expanded', 'false');
  }

  function bindLangToggle() {
    if (!global.document) return;
    if (langBound) {
      renderLangToggle();
      return;
    }
    langToggle = global.document.getElementById('lang-toggle');
    langDropdown = global.document.getElementById('lang-dropdown');
    if (!langToggle || !langDropdown) return;
    langBound = true;

    renderLangToggle();

    langToggle.addEventListener('click', function(e) {
      e.stopPropagation();
      const isOpen = langToggle.getAttribute('aria-expanded') === 'true';
      if (isOpen) closeLangMenu(); else openLangMenu();
    });

    global.document.addEventListener('click', function(e) {
      if (langDropdown.classList.contains('hidden')) return;
      if (langDropdown.contains(e.target) || langToggle.contains(e.target)) return;
      closeLangMenu();
    });

    global.document.addEventListener('keydown', function(e) {
      if (e.key !== 'Escape') return;
      if (langDropdown.classList.contains('hidden')) return;
      closeLangMenu();
      langToggle.focus();
    });

    // Menu abierto: re-pinta las opciones marcando el nuevo idioma.
    if (global.EventBus && typeof global.EventBus.on === 'function') {
      global.EventBus.on('i18n:changed', function() {
        renderLangToggle();
        if (!langDropdown.classList.contains('hidden')) renderLangMenu();
      });
    }
  }

  /**
   * Aplica un locale (descarga diccionario si falta, repinta, emite).
   * No marca eleccion de sesion ni persiste en el servidor: uso interno
   * (syncFromUser en el login).
   * @param {string} locale
   * @returns {Promise<string>} el locale ya activo
   */
  function applyLocale(locale) {
    if (!isSupported(locale)) {
      return Promise.resolve(current);
    }

    if (locale === current && fetched[locale]) {
      applyDom();
      return Promise.resolve(current);
    }

    current = locale;
    writeStoredLocale(locale);

    const load = fetched[locale]
      ? Promise.resolve(dicts[locale])
      : fetchDictionary(locale);

    return load.then(
      function() {
        applyDom();
        emitChanged();
        return current;
      },
      function() {
        // Sin diccionario (p. ej. 401 pre-sesion): siguen las cadenas
        // embebidas de es y el fallback de claves.
        applyDom();
        emitChanged();
        return current;
      }
    );
  }

  /**
   * Guarda la eleccion de idioma en el servidor (users.preferencias.idioma)
   * via PATCH /api/users/me/preferences. Solo con sesion; sin red o con
   * error la eleccion local se conserva y se avisa con toast.
   * @param {string} locale
   */
  function persistIdioma(locale) {
    const hasSession = global.Storage &&
      typeof global.Storage.getToken === 'function' && !!global.Storage.getToken();
    if (!hasSession || !global.HttpClient || typeof global.HttpClient.patch !== 'function') {
      return;
    }
    if (locale === lastPersisted) return;
    lastPersisted = locale;

    global.HttpClient.patch('/users/me/preferences', { idioma: locale }, { requiresAuth: true })
      .catch(function() {
        lastPersisted = null; // reintento en la proxima eleccion o login
        if (global.EventBus && typeof global.EventBus.emit === 'function') {
          global.EventBus.emit('toast:show', {
            type: 'warning',
            title: t('ui.toast_lang_save_title'),
            message: t('ui.toast_lang_save_failed'),
            duration: 4000
          });
        }
      });
  }

  /**
   * Cambio de idioma sin recargar la pagina (eleccion explicita del usuario).
   * Marca la eleccion de sesion (gana sobre /auth/me hasta que el PATCH
   * confirme) y persiste la preferencia en el backend.
   * @param {string} locale
   * @returns {Promise<string>} el locale ya activo
   */
  function setLocale(locale) {
    if (!isSupported(locale)) {
      return Promise.resolve(current);
    }

    sessionChoice = true;
    persistIdioma(locale);

    return applyLocale(locale);
  }

  /**
   * Arranque: locale desde localStorage y descarga del diccionario.
   * Nunca rechaza: el bootstrap de la app no debe quedar bloqueado.
   * @returns {Promise<string>}
   */
  function init() {
    if (initPromise) return initPromise;

    const stored = readStoredLocale();
    current = stored || DEFAULT_LOCALE;

    // Tras cualquier establecimiento de sesion (login, /auth/me, sincronia
    // entre pestaanas) se descarga el diccionario completo si la carga
    // inicial fue pre-sesion y no pudo pedirlo (401). Cubre tambien el caso
    // de elegir idioma en la pantalla de login: el GET se hace con token.
    // NINGUNO de estos eventos cambia el locale activo: la preferencia del
    // servidor solo se aplica en el login (syncFromUser desde setSession).
    if (global.EventBus && typeof global.EventBus.on === 'function') {
      ['auth:login', 'auth:user-updated', 'auth:synced'].forEach(function(evt) {
        global.EventBus.on(evt, function() { ensureLoaded(); });
      });

      // Eleccion explicita pre-sesion (selector en la pantalla de login):
      // en cuanto hay sesion se confirma con el PATCH pendiente.
      global.EventBus.on('auth:login', function() {
        if (sessionChoice) persistIdioma(current);
      });

      // Sesion cerrada: la proxima eleccion de idioma de un nuevo login
      // vuelve a regir la preferencia del servidor.
      global.EventBus.on('auth:logout', function() {
        sessionChoice = false;
      });
    }

    initPromise = fetchDictionary(current)
      .catch(function() {
        /* pre-sesion (401) o red caida: builtin es + claves */
        return null;
      })
      .then(function() {
        if (global.document) {
          if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', function() {
              applyDom();
              bindSelector();
              bindLangToggle();
            }, { once: true });
          } else {
            applyDom();
            bindSelector();
            bindLangToggle();
          }
        }
        return current;
      });

    return initPromise;
  }

  /**
   * Inyección directa de un diccionario (smokes jsdom, tests). No usa red.
   * @param {string} locale
   * @param {object} dictionary
   */
  function inject(locale, dictionary) {
    if (!isSupported(locale) || !dictionary || typeof dictionary !== 'object') return;
    dicts[locale] = dictionary;
    fetched[locale] = true;
    if (locale === current) applyDom();
  }

  /**
   * Preferencia de idioma guardada del usuario. Se llama SOLO en el login
   * (AuthState.setSession); las respuestas posteriores de /auth/me pasan por
   * updateUser y ya no disparan sincronia (v1.10.3: la eleccion explicita
   * de la sesion manda). No marca sessionChoice ni persiste en el servidor.
   * @param {{preferencias?: {idioma?: string}}} user
   */
  function syncFromUser(user) {
    if (sessionChoice) return;
    const preferred = user && user.preferencias && user.preferencias.idioma;
    if (!preferred || !isSupported(preferred)) return;
    if (preferred === current && fetched[current]) return;
    applyLocale(preferred);
  }

  const api = {
    SUPPORTED: SUPPORTED,
    DEFAULT_LOCALE: DEFAULT_LOCALE,
    t: t,
    init: init,
    setLocale: setLocale,
    ensureLoaded: ensureLoaded,
    getLocale: function() { return current; },
    isSupported: isSupported,
    inject: inject,
    applyDom: applyDom,
    bindSelector: bindSelector,
    syncFromUser: syncFromUser
  };

  global.i18n = api;
  global.t = t;

})(window);
