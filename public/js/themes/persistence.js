/**
 * Theme Persistence
 * Gestiona la persistencia del tema (dark/light) en localStorage
 * y sincroniza con la preferencia del sistema operativo.
 *
 * Responsabilidades:
 * - Leer/escribir tema en localStorage (namespace 'xp.theme')
 * - Detectar prefers-color-scheme del SO
 * - Aplicar clases 'dark' / 'light' al <html>
 * - Sincronizar entre pestañas (storage event)
 * - Emitir eventos: theme:changed, theme:system-changed
 */
(function(global) {
    'use strict';

    const STORAGE_KEY = 'xp.theme';
    const VALID_THEMES = ['dark', 'light', 'system'];
    const DEFAULT_THEME = 'system';

    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

    function isLocalStorageAvailable() {
        try {
            const testKey = '__theme_test__';
            localStorage.setItem(testKey, '1');
            localStorage.removeItem(testKey);
            return true;
        } catch (e) {
            return false;
        }
    }

    function readStored() {
        if (!isLocalStorageAvailable()) return null;
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;

            // Compatibilidad con formato serializado del Storage core
            try {
                const parsed = JSON.parse(raw);
                return parsed && parsed.v ? parsed.v : raw;
            } catch (e) {
                return raw;
            }
        } catch (e) {
            return null;
        }
    }

    function writeStored(theme) {
        if (!isLocalStorageAvailable()) return false;
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                v: theme,
                t: Date.now()
            }));
            return true;
        } catch (e) {
            console.warn('[ThemePersistence] write error:', e);
            return false;
        }
    }

    function getSystemPreference() {
        return mediaQuery.matches ? 'dark' : 'light';
    }

    function resolveEffectiveTheme(preference) {
        if (preference === 'dark' || preference === 'light') {
            return preference;
        }
        return getSystemPreference();
    }

    /**
     * Color del tema desde los tokens de themes.css: --color-background
     * viaja como triplet RGB ("15 23 42") y aqui se convierte a #rrggbb.
     * Devuelve null si el CSS no está disponible (sin valor fijo en JS).
     */
    function themeColorFromTokens(root) {
        let raw = '';
        try {
            raw = window.getComputedStyle(root).getPropertyValue('--color-background').trim();
        } catch (e) {
            return null;
        }
        const parts = raw.split(/\s+/).map(Number);
        if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) {
            return null;
        }
        return '#' + parts.map((n) => Math.max(0, Math.min(255, Math.round(n)))
            .toString(16).padStart(2, '0')).join('');
    }

    function applyTheme(effectiveTheme) {
        const root = document.documentElement;
        if (!root) return;

        root.classList.remove('dark', 'light');
        root.classList.add(effectiveTheme);

        // Actualizar meta theme-color para mobile (siempre desde los tokens)
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            const hex = themeColorFromTokens(root);
            if (hex) {
                meta.setAttribute('content', hex);
            }
        }
    }

    function emit(event, data) {
        if (global.EventBus) {
            global.EventBus.emit(event, data);
        }
    }

    const ThemePersistence = {
        /**
         * Obtiene la preferencia guardada ('dark' | 'light' | 'system' | null)
         */
        getPreference() {
            const stored = readStored();
            if (stored && VALID_THEMES.includes(stored)) {
                return stored;
            }
            return DEFAULT_THEME;
        },

        /**
         * Obtiene el tema efectivo actualmente aplicado ('dark' | 'light')
         */
        getEffective() {
            return resolveEffectiveTheme(this.getPreference());
        },

        /**
         * Obtiene la preferencia del sistema operativo
         */
        getSystemPreference() {
            return getSystemPreference();
        },

        /**
         * Establece el tema y lo persiste
         * @param {'dark'|'light'|'system'} theme
         */
        set(theme) {
            if (!VALID_THEMES.includes(theme)) {
                console.warn(`[ThemePersistence] Invalid theme: ${theme}`);
                return false;
            }

            const previous = this.getEffective();
            writeStored(theme);

            const effective = resolveEffectiveTheme(theme);
            applyTheme(effective);

            if (previous !== effective) {
                emit('theme:changed', {
                    preference: theme,
                    effective: effective,
                    previous: previous
                });
            }

            return true;
        },

        /**
         * Alterna entre dark y light (ignora 'system')
         */
        toggle() {
            const current = this.getEffective();
            const next = current === 'dark' ? 'light' : 'dark';
            return this.set(next);
        },

        /**
         * Resetea a la preferencia del sistema
         */
        resetToSystem() {
            return this.set('system');
        },

        /**
         * Limpia preferencia guardada (vuelve a 'system')
         */
        clear() {
            if (isLocalStorageAvailable()) {
                try {
                    localStorage.removeItem(STORAGE_KEY);
                } catch (e) {
                    // Ignorar
                }
            }
            const effective = getSystemPreference();
            applyTheme(effective);
            emit('theme:changed', {
                preference: 'system',
                effective: effective,
                previous: this.getEffective()
            });
        },

        /**
         * Aplica el tema guardado sin cambiar la preferencia
         * Útil para inicialización temprana (evitar FOUC)
         */
        apply() {
            const preference = this.getPreference();
            const effective = resolveEffectiveTheme(preference);
            applyTheme(effective);
            return effective;
        },

        /**
         * Inicializa listeners
         */
        init() {
            // Aplicar tema actual inmediatamente
            this.apply();

            // Escuchar cambios en prefers-color-scheme
            try {
                const handler = (e) => {
                    const preference = this.getPreference();
                    if (preference === 'system') {
                        const effective = e.matches ? 'dark' : 'light';
                        applyTheme(effective);
                        emit('theme:system-changed', {
                            effective: effective,
                            systemDark: e.matches
                        });
                    }
                };

                if (typeof mediaQuery.addEventListener === 'function') {
                    mediaQuery.addEventListener('change', handler);
                } else if (typeof mediaQuery.addListener === 'function') {
                    mediaQuery.addListener(handler);
                }
            } catch (e) {
                console.warn('[ThemePersistence] Could not watch prefers-color-scheme');
            }

            // Sincronizar entre pestañas
            window.addEventListener('storage', (e) => {
                if (e.key !== STORAGE_KEY) return;
                if (e.storageArea !== localStorage) return;

                const newValue = e.newValue;
                if (newValue === null) {
                    this.apply();
                    emit('theme:changed', { preference: 'system', source: 'other-tab' });
                    return;
                }

                try {
                    const parsed = JSON.parse(newValue);
                    const theme = parsed && parsed.v ? parsed.v : newValue;
                    if (VALID_THEMES.includes(theme)) {
                        const effective = resolveEffectiveTheme(theme);
                        applyTheme(effective);
                        emit('theme:changed', {
                            preference: theme,
                            effective: effective,
                            source: 'other-tab'
                        });
                    }
                } catch (err) {
                    // Ignorar
                }
            });
        },

        /**
         * Lista temas válidos
         */
        getValidThemes() {
            return VALID_THEMES.slice();
        },

        /**
         * Verifica si localStorage está disponible
         */
        isStorageAvailable() {
            return isLocalStorageAvailable();
        }
    };

    global.ThemePersistence = ThemePersistence;

})(window);
