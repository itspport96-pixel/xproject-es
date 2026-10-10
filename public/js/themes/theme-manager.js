/**
 * Theme Manager - UI Toggle Dark/Light/System
 * Depende de: ThemePersistence, EventBus
 */
(function(global) {
    'use strict';

    // Iconos SVG inline (mismo estilo que el sidebar: stroke, currentColor).
    // Muestran el MODO ACTUAL del tema: luna en oscuro, sol en claro,
    // monitor en sistema. El ciclo de preferencia no cambia.
    const ICONS = {
        dark: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
        light: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
        system: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>'
    };

    const LABEL_KEYS = {
        dark: 'theme.current_dark',
        light: 'theme.current_light',
        system: 'theme.current_system'
    };

    let toggleBtn = null;
    let initialized = false;

    function updateButtonUI() {
        if (!toggleBtn) return;

        const persistence = global.ThemePersistence;
        if (!persistence) return;

        const preference = persistence.getPreference();

        toggleBtn.innerHTML = '';

        const icon = document.createElement('span');
        icon.innerHTML = ICONS[preference] || ICONS.dark;
        icon.setAttribute('aria-hidden', 'true');
        toggleBtn.appendChild(icon);

        toggleBtn.setAttribute('title', t(LABEL_KEYS[preference] || 'theme.toggle_default'));
        toggleBtn.setAttribute('aria-label', t('theme.aria', { preference }));
    }

    function cycleTheme() {
        const persistence = global.ThemePersistence;
        if (!persistence) return;

        const preference = persistence.getPreference();
        const cycle = { dark: 'light', light: 'system', system: 'dark' };
        const next = cycle[preference] || 'dark';

        persistence.set(next);
        updateButtonUI();
    }

    function bindToggleButton() {
        toggleBtn = document.getElementById('theme-toggle');

        if (!toggleBtn) {
            toggleBtn = document.querySelector('[data-theme-toggle]');
        }

        if (!toggleBtn) return;

        toggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            cycleTheme();
        });

        toggleBtn.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                cycleTheme();
            }
        });

        updateButtonUI();
    }

    function bindKeyboardShortcut() {
        document.addEventListener('keydown', (e) => {
            // Ctrl + Shift + D -> toggle tema
            if (e.ctrlKey && e.shiftKey && e.key === 'D') {
                e.preventDefault();
                cycleTheme();
            }
        });
    }

    function listenThemeChanges() {
        if (!global.EventBus) return;

        global.EventBus.on('theme:changed', (data) => {
            updateButtonUI();

            if (data && data.source === 'other-tab' && global.EventBus) {
                global.EventBus.emit('toast:show', {
                    type: 'info',
                    title: t('theme.synced_title'),
                    message: t('theme.synced_message', { effective: data.effective }),
                    duration: 3000
                });
            }
        });

        global.EventBus.on('theme:system-changed', () => {
            updateButtonUI();
        });

        // Cambio de idioma sin recarga: re-pinta icono/title del toggle.
        global.EventBus.on('i18n:changed', () => {
            updateButtonUI();
        });
    }

    const ThemeManager = {
        init() {
            if (initialized) return;
            initialized = true;

            if (global.ThemePersistence) {
                global.ThemePersistence.init();
            }

            bindToggleButton();
            bindKeyboardShortcut();
            listenThemeChanges();
        },

        set(theme) {
            if (global.ThemePersistence) {
                global.ThemePersistence.set(theme);
                updateButtonUI();
            }
        },

        toggle() {
            if (global.ThemePersistence) {
                global.ThemePersistence.toggle();
                updateButtonUI();
            }
        },

        current() {
            return global.ThemePersistence ? global.ThemePersistence.getEffective() : 'dark';
        },

        isDark() {
            return this.current() === 'dark';
        },

        refreshUI() {
            bindToggleButton();
        },

        destroy() {
            if (toggleBtn) {
                toggleBtn.replaceWith(toggleBtn.cloneNode(true));
                toggleBtn = null;
            }
            initialized = false;
        }
    };

    global.ThemeManager = ThemeManager;

})(window);
