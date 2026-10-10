/**
 * Storage Manager
 * Wrapper sobre localStorage con namespace, serialización JSON y manejo de errores.
 */
(function(global) {
    'use strict';

    const NAMESPACE = 'xp.';

    function isAvailable() {
        try {
            const testKey = '__storage_test__';
            localStorage.setItem(testKey, '1');
            localStorage.removeItem(testKey);
            return true;
        } catch (e) {
            return false;
        }
    }

    function serialize(value) {
        try {
            return JSON.stringify({
                v: value,
                t: Date.now()
            });
        } catch (e) {
            console.warn('[Storage] Serialize error:', e);
            return null;
        }
    }

    function deserialize(raw) {
        if (raw === null || raw === undefined) {
            return { value: undefined, found: false };
        }
        try {
            const parsed = JSON.parse(raw);
            return { value: parsed.v, found: true, timestamp: parsed.t };
        } catch (e) {
            // Compatibilidad con valores legacy no serializados
            return { value: raw, found: true, timestamp: null };
        }
    }

    const Storage = {
        available: isAvailable(),

        get(key, defaultValue = null) {
            if (!this.available) return defaultValue;
            try {
                const raw = localStorage.getItem(NAMESPACE + key);
                const { value, found } = deserialize(raw);
                return found ? value : defaultValue;
            } catch (e) {
                console.warn('[Storage] get error:', e);
                return defaultValue;
            }
        },

        set(key, value) {
            if (!this.available) return false;
            try {
                const serialized = serialize(value);
                if (serialized === null) return false;
                localStorage.setItem(NAMESPACE + key, serialized);
                return true;
            } catch (e) {
                console.warn('[Storage] set error:', e);
                if (e.name === 'QuotaExceededError' || e.code === 22) {
                    global.EventBus && global.EventBus.emit('storage:quota_exceeded');
                }
                return false;
            }
        },

        remove(key) {
            if (!this.available) return false;
            try {
                localStorage.removeItem(NAMESPACE + key);
                return true;
            } catch (e) {
                console.warn('[Storage] remove error:', e);
                return false;
            }
        },

        has(key) {
            if (!this.available) return false;
            return localStorage.getItem(NAMESPACE + key) !== null;
        },

        clear(prefix = '') {
            if (!this.available) return 0;
            const fullPrefix = NAMESPACE + prefix;
            const keysToRemove = [];

            for (let i = 0; i < localStorage.length; i++) {
                const k = localStorage.key(i);
                if (k && k.startsWith(fullPrefix)) {
                    keysToRemove.push(k);
                }
            }

            keysToRemove.forEach(k => localStorage.removeItem(k));
            return keysToRemove.length;
        },

        keys(prefix = '') {
            if (!this.available) return [];
            const fullPrefix = NAMESPACE + prefix;
            const result = [];

            for (let i = 0; i < localStorage.length; i++) {
                const k = localStorage.key(i);
                if (k && k.startsWith(fullPrefix)) {
                    result.push(k.substring(NAMESPACE.length));
                }
            }
            return result;
        },

        // === Atajos específicos ===

        getJson(key, defaultValue = null) {
            return this.get(key, defaultValue);
        },

        setJson(key, value) {
            return this.set(key, value);
        },

        // Token auth
        setToken(token) {
            return this.set('auth.token', token);
        },

        getToken() {
            return this.get('auth.token');
        },

        setRefreshToken(token) {
            return this.set('auth.refresh_token', token);
        },

        getRefreshToken() {
            return this.get('auth.refresh_token');
        },

        clearAuth() {
            this.remove('auth.token');
            this.remove('auth.refresh_token');
            this.remove('auth.user');
        },

        setUser(user) {
            return this.set('auth.user', user);
        },

        getUser() {
            return this.get('auth.user');
        },

        // Tema
        setTheme(theme) {
            return this.set('theme', theme);
        },

        getTheme() {
            return this.get('theme');
        },

        // Tenant actual
        setTenantId(tenantId) {
            return this.set('tenant.current', tenantId);
        },

        getTenantId() {
            return this.get('tenant.current');
        }
    };

    global.Storage = Storage;

})(window);
