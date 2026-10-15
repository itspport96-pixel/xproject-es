/**
 * TenantBranding (v1.14.0)
 * Marca y preferencias regionales del tenant aplicadas a toda la interfaz.
 *
 * - Fuente: GET /api/tenant/branding (cualquier usuario autenticado).
 * - Caché en localStorage ('xp.branding') para pintar el color y el logo
 *   sin parpadeo antes de que responda la API.
 * - Color principal: pisa --color-primary/-hover/-foreground/--color-info
 *   en <html> (inline), recalculado al cambiar entre claro y oscuro.
 * - Emite 'branding:changed' (sidebar re-pinta logo y nombre).
 * - El formateo de fechas/moneda vive en ui/dom.js (UI.formatDate,
 *   UI.formatMoney) y lee TenantBranding.get().
 */
(function(global) {
    'use strict';

    const STORAGE_KEY = 'xp.branding';
    const HEX_RE = /^#[0-9a-fA-F]{6}$/;
    const VARS = ['--color-primary', '--color-primary-hover', '--color-primary-foreground', '--color-info'];

    let current = null;
    let previewHex = null;
    let observer = null;
    let initialized = false;

    function emit(event, data) {
        if (global.EventBus) global.EventBus.emit(event, data);
    }

    function readCache() {
        try {
            const raw = global.localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function writeCache(value) {
        try {
            if (value) global.localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
            else global.localStorage.removeItem(STORAGE_KEY);
        } catch (e) { /* cuota o modo privado: sin caché */ }
    }

    function hexToRgb(hex) {
        const n = parseInt(hex.slice(1), 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    function luminance(rgb) {
        const c = rgb.map(function(v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    }

    function mix(rgb, target, amount) {
        return rgb.map(function(v, i) { return Math.round(v + (target[i] - v) * amount); });
    }

    function contrast(a, b) {
        const la = luminance(a), lb = luminance(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }

    function isDark() {
        return global.document.documentElement.classList.contains('dark');
    }

    function primaryColor() {
        const ap = current && current.apariencia;
        const hex = previewHex !== null ? previewHex : (ap && ap.color_primario);
        return hex && HEX_RE.test(hex) ? hex : null;
    }

    function applyColor() {
        const root = global.document.documentElement;
        const hex = primaryColor();
        if (!hex) {
            VARS.forEach(function(v) { root.style.removeProperty(v); });
            return;
        }
        let rgb = hexToRgb(hex);
        // En oscuro, un color muy oscuro se aclara para que siga legible.
        if (isDark() && contrast(rgb, [28, 28, 30]) < 3) rgb = mix(rgb, [255, 255, 255], 0.3);
        const hover = isDark() ? mix(rgb, [255, 255, 255], 0.18) : mix(rgb, [0, 0, 0], 0.15);
        const fg = contrast(rgb, [255, 255, 255]) >= 3 ? [255, 255, 255] : [0, 0, 0];
        root.style.setProperty('--color-primary', rgb.join(' '));
        root.style.setProperty('--color-primary-hover', hover.join(' '));
        root.style.setProperty('--color-primary-foreground', fg.join(' '));
        root.style.setProperty('--color-info', rgb.join(' '));
    }

    function watchTheme() {
        if (observer || typeof global.MutationObserver !== 'function') return;
        observer = new global.MutationObserver(applyColor);
        observer.observe(global.document.documentElement, { attributes: true, attributeFilter: ['class'] });
    }

    function isAuthenticated() {
        return !!(global.AuthState && global.AuthState.isAuthenticated && global.AuthState.isAuthenticated());
    }

    const TenantBranding = {
        init() {
            if (initialized) return;
            initialized = true;
            current = readCache();
            applyColor();
            watchTheme();
            if (global.EventBus) {
                global.EventBus.on('auth:login', function() { TenantBranding.refresh(); });
                global.EventBus.on('auth:logout', function() { TenantBranding.set(null); });
            }
            if (isAuthenticated()) this.refresh();
        },

        async refresh() {
            if (!global.HttpClient) return;
            try {
                const response = await global.HttpClient.get('/tenant/branding', { requiresAuth: true });
                const data = response && response.data ? response.data : response;
                this.set((data && data.branding) || null);
            } catch (err) {
                console.warn('[Branding] tenant branding load failed:', err && err.message);
            }
        },

        /** Aplica una marca (p.ej. tras guardar en Configuración) sin recargar. */
        set(branding) {
            current = branding || null;
            previewHex = null;
            writeCache(current);
            applyColor();
            emit('branding:changed', { branding: current });
        },

        get() {
            return current;
        },

        /** Vista previa del color sin persistir (Configuración > Apariencia). */
        preview(hex) {
            previewHex = hex || '';
            applyColor();
        },

        restore() {
            previewHex = null;
            applyColor();
        },

        /** URL absoluta del logo: http(s) tal cual; ruta de subida bajo baseUrl. */
        logoSrc(url) {
            if (!url) return null;
            if (/^https?:\/\//i.test(url)) return url;
            const base = (global.AppConfig && global.AppConfig.baseUrl) || '';
            return base.replace(/\/$/, '') + '/' + String(url).replace(/^\//, '');
        }
    };

    global.TenantBranding = TenantBranding;
})(window);
