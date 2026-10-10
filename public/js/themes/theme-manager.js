/**
 * Theme Manager - UI Toggle Dark/Light/System
 * Depende de: ThemePersistence, EventBus
 */
(function(global) {
    'use strict';

    // Iconos de texto (sin emojis) - muestran la ACCION al hacer click
    // (tema activo -> accion para cambiar). Claves i18n resueltas con t().
    const ICON_KEYS = {
        dark: 'theme.action_dark',
        light: 'theme.action_light',
        system: 'theme.action_system'
    };

    const LABEL_KEYS = {
        dark: 'theme.title_dark',
        light: 'theme.title_light',
        system: 'theme.title_system'
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
        icon.textContent = t(ICON_KEYS[preference] || ICON_KEYS.dark);
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
