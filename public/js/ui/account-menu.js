/**
 * Account Menu (v1.13.7)
 * Drop de cuenta del header: el contenido se genera desde UNA sola lista
 * (ACCOUNT_ITEMS) con id, labelKey, icon, route y permission opcional.
 * Anadir un item futuro (p. ej. Ayuda) requiere SOLO agregar una entrada
 * a la lista: el componente la recorre y respeta el permiso de cada item.
 *
 * Disparador y apertura/cierre: mecanismo existente de #user-menu (Alpine,
 * index.php). Aqui solo se pinta #user-dropdown y se navega al hacer clic.
 * El item activo se marca cuando la ruta coincide (route:loaded/activated).
 */
(function(global) {
  'use strict';

  // Iconos SVG inline (mismo estilo que el sidebar: stroke, currentColor).
  const ICONS = {
    user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
  };

  // ============================================
  // LISTA UNICA DE ITEMS (fuente de verdad)
  // ============================================
  // permission opcional: si se indica, el item solo se pinta si el usuario
  // autenticado lo tiene (AuthState.hasPermission). Sin permission = cualquier
  // usuario autenticado.
  const ACCOUNT_ITEMS = [
    { id: 'profile', labelKey: 'nav.profile', icon: 'user', route: 'profile' }
  ];

  let bound = false;

  function escapeHtml(str) {
    if (global.UI && typeof global.UI.escapeHtml === 'function') {
      return global.UI.escapeHtml(str);
    }
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function getVisibleItems() {
    if (!(global.AuthState && global.AuthState.isAuthenticated())) return [];
    return ACCOUNT_ITEMS.filter(function(item) {
      return !item.permission || global.AuthState.hasPermission(item.permission);
    });
  }

  function render() {
    const dropdown = document.getElementById('user-dropdown');
    if (!dropdown) return;

    const currentRoute = global.AppRouter && typeof global.AppRouter.getCurrentRoute === 'function'
      ? global.AppRouter.getCurrentRoute()
      : '';

    dropdown.innerHTML = getVisibleItems().map(function(item) {
      const active = item.route === currentRoute;
      const iconSvg = ICONS[item.icon] || '';
      const label = global.t ? t(item.labelKey) : item.labelKey;
      return `
        <button type="button" class="account-item${active ? ' is-active' : ''}" role="menuitem"
                data-route="${escapeHtml(item.route)}"${active ? ' aria-current="page"' : ''}>
          <span class="account-item-icon" aria-hidden="true">${iconSvg}</span>
          <span class="account-item-label">${escapeHtml(label)}</span>
        </button>`;
    }).join('');
  }

  function handleClick(e) {
    const item = e.target.closest('[data-route]');
    if (!item) return;
    const route = item.getAttribute('data-route');
    if (route && global.AppRouter && typeof global.AppRouter.navigate === 'function') {
      global.AppRouter.navigate(route);
    }
  }

  function bind() {
    if (!global.document || bound) return;
    const dropdown = document.getElementById('user-dropdown');
    if (!dropdown) return;
    bound = true;

    dropdown.addEventListener('click', handleClick);

    if (global.EventBus && typeof global.EventBus.on === 'function') {
      global.EventBus.on('route:loaded', render);
      global.EventBus.on('route:activated', render);
      global.EventBus.on('i18n:changed', render);
      global.EventBus.on('auth:login', render);
      global.EventBus.on('auth:logout', render);
      global.EventBus.on('auth:user-updated', render);
      global.EventBus.on('auth:synced', render);
    }
  }

  const AccountMenu = {
    init() {
      bind();
      render();
    },

    render: render,

    // Lista expuesta para tests/smokes (fuente de verdad unica).
    items: ACCOUNT_ITEMS
  };

  global.AccountMenu = AccountMenu;

})(window);
