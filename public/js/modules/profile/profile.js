/**
 * Profile Module (v1.13.7)
 * Perfil de usuario: dos secciones con funcionalidad ya existente.
 * - Cambio de contrasena: reutiliza AuthPasswordModule (abre su modal con
 *   la logica y el endpoint actuales, POST /auth/change-password). La
 *   logica de seguridad NO se duplica aqui.
 * - Idioma: selector que reutiliza i18n.setLocale() sin modificarlo.
 *
 * Ruta 'profile': cualquier usuario autenticado, sin permiso especifico.
 * Modulo distinto del pendiente settings/settings: no se registra ni se
 * mezcla con el.
 *
 * Convencion ModuleLoader: window.ProfileProfileModule (ruta profile/profile).
 */
(function(global) {
    'use strict';

    let container = null;

    // Nombres nativos de los idiomas, via claves i18n (profile.lang.*,
    // mismo criterio que i18n.js LANG_NAMES).
    const LANG_KEYS = {
        es: 'profile.lang.es',
        en: 'profile.lang.en',
        pt: 'profile.lang.pt',
        fr: 'profile.lang.fr'
    };

    function escapeHtml(str) {
        if (global.UI && typeof global.UI.escapeHtml === 'function') {
            return global.UI.escapeHtml(str);
        }
        return String(str == null ? '' : str);
    }

    function langOptionsHtml() {
        const current = global.i18n && typeof global.i18n.getLocale === 'function'
            ? global.i18n.getLocale()
            : 'es';
        const supported = (global.i18n && global.i18n.SUPPORTED) || ['es', 'en', 'pt', 'fr'];
        return supported.map(function(locale) {
            const selected = locale === current ? ' selected' : '';
            return `<option value="${escapeHtml(locale)}"${selected}>${escapeHtml(t(LANG_KEYS[locale] || locale))}</option>`;
        }).join('');
    }

    function buildTemplate() {
        return `
            ${UI.pageHeader({ title: t('profile.title'), subtitle: t('profile.subtitle') })}
            <div class="grid gap-4 md:grid-cols-2">
                <div class="card p-4">
                    <h3 class="text-lg font-semibold mb-1">${escapeHtml(t('profile.section_password'))}</h3>
                    <p class="text-muted text-sm mb-4">${escapeHtml(t('profile.password_body'))}</p>
                    <button type="button" class="btn btn-primary" id="profile-change-password">${escapeHtml(t('shell.change_password'))}</button>
                </div>
                <div class="card p-4">
                    <h3 class="text-lg font-semibold mb-1">${escapeHtml(t('profile.section_language'))}</h3>
                    <p class="text-muted text-sm mb-4">${escapeHtml(t('profile.language_body'))}</p>
                    <select id="profile-lang-select" class="select">${langOptionsHtml()}</select>
                </div>
            </div>
        `;
    }

    async function ensurePasswordModule() {
        if (global.AuthPasswordModule) return global.AuthPasswordModule;
        if (global.ModuleLoader && typeof global.ModuleLoader.load === 'function') {
            try {
                await global.ModuleLoader.load('auth/password', null);
            } catch (e) {
                console.warn('[Profile] ' + t('profile.password_module_unavailable'), e.message);
            }
        }
        return global.AuthPasswordModule || null;
    }

    async function handleChangePasswordClick() {
        const mod = await ensurePasswordModule();
        if (mod && typeof mod.openModal === 'function') {
            mod.openModal();
        }
    }

    function handleLangChange(e) {
        const select = e.target;
        if (!select || select.id !== 'profile-lang-select') return;
        // Reutiliza la funcion de cambio actual sin modificarla.
        if (global.i18n && typeof global.i18n.setLocale === 'function') {
            global.i18n.setLocale(select.value);
        }
    }

    function onLocaleChanged() {
        if (!container) return;
        const select = container.querySelector('#profile-lang-select');
        if (select && global.i18n && typeof global.i18n.getLocale === 'function') {
            select.value = global.i18n.getLocale();
        }
    }

    function bindEvents() {
        if (!container) return;
        const pwBtn = container.querySelector('#profile-change-password');
        if (pwBtn) {
            pwBtn.addEventListener('click', handleChangePasswordClick);
        }
        container.addEventListener('change', handleLangChange);
        if (global.EventBus && typeof global.EventBus.on === 'function') {
            global.EventBus.on('i18n:changed', onLocaleChanged);
        }
    }

    function unbindEvents() {
        if (global.EventBus && typeof global.EventBus.off === 'function') {
            global.EventBus.off('i18n:changed', onLocaleChanged);
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const ProfileProfileModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            container.innerHTML = buildTemplate();
            bindEvents();
        },

        onActivate() {
            if (!container) return;
            if (!container.querySelector('#profile-lang-select')) {
                container.innerHTML = buildTemplate();
                bindEvents();
            }
        },

        destroy() {
            unbindEvents();
            if (container) {
                container.removeEventListener('change', handleLangChange);
                const pwBtn = container.querySelector('#profile-change-password');
                if (pwBtn) {
                    pwBtn.removeEventListener('click', handleChangePasswordClick);
                }
                container.innerHTML = '';
            }
            container = null;
        }
    };

    global.ProfileProfileModule = ProfileProfileModule;

})(window);
