/**
 * Auth Password Module - v1.12.0
 * Cambio de contrasena desde el menu de usuario (POST /auth/change-password)
 * y modal OBLIGATORIO cuando el backend exige renovarla.
 *
 * Convencion ModuleLoader: window.AuthPasswordModule (ruta auth/password).
 * Se precarga desde auth/state.js con container null: no pinta en
 * #module-container; monta su propio #password-modal-root en document.body
 * (sobrevive a los cambios de ruta) y un boton en el menu de usuario.
 *
 * Disparadores del modo obligatorio:
 *  - evento 'auth:password-required' (login con password_change_required)
 *  - 'http:error' 403 con data.data.category === 'password_change_required'
 *    (PasswordChangeRequiredException de cualquier endpoint)
 */
(function(global) {
    'use strict';

    let initialized = false;
    let forced = false;
    let sending = false;
    let modalOpen = false;

    // ====================================================================
    // ROOT DEL MODAL (fuera de #module-container)
    // ====================================================================

    function getRoot() {
        let root = document.getElementById('password-modal-root');
        if (!root) {
            root = document.createElement('div');
            root.id = 'password-modal-root';
            document.body.appendChild(root);
        }
        return root;
    }

    // ====================================================================
    // BOTON EN EL MENU DE USUARIO
    // ====================================================================

    function mountMenuButton() {
        const logout = document.getElementById('logout-btn');
        if (!logout || !logout.parentNode) return;
        if (document.getElementById('change-password-btn')) return;
        if (!(global.AuthState && global.AuthState.isAuthenticated())) return;

        const btn = document.createElement('button');
        btn.id = 'change-password-btn';
        btn.type = 'button';
        btn.className = 'block w-full text-left px-4 py-2 hover:bg-surface-alt';
        btn.setAttribute('data-i18n', 'shell.change_password');
        btn.textContent = t('shell.change_password');
        btn.addEventListener('click', function() {
            open(false);
        });
        logout.parentNode.insertBefore(btn, logout);
    }

    function removeMenuButton() {
        const btn = document.getElementById('change-password-btn');
        if (btn && btn.parentNode) btn.parentNode.removeChild(btn);
    }

    // ====================================================================
    // VALIDACION (mismas reglas que UserService.validatePasswordStrength)
    // ====================================================================

    function validate(actual, nuevo, confirmacion) {
        const errors = {};

        if (!actual) {
            errors.password_actual = t('password.errors.required_actual');
        }
        if (!nuevo) {
            errors.password_nuevo = t('password.errors.required_new');
        } else if (nuevo.length < 8) {
            errors.password_nuevo = t('password.errors.min_length');
        } else if (!/[A-Z]/.test(nuevo)) {
            errors.password_nuevo = t('password.errors.uppercase');
        } else if (!/[a-z]/.test(nuevo)) {
            errors.password_nuevo = t('password.errors.lowercase');
        } else if (!/[0-9]/.test(nuevo)) {
            errors.password_nuevo = t('password.errors.digit');
        } else if (nuevo === actual) {
            errors.password_nuevo = t('password.errors.same_as_current');
        }

        if (!confirmacion) {
            errors.password_confirm = t('password.errors.required_confirm');
        } else if (nuevo && confirmacion !== nuevo) {
            errors.password_confirm = t('password.errors.mismatch');
        }

        return errors;
    }

    // ====================================================================
    // MODAL
    // ====================================================================

    function open(forcedFlag) {
        forced = forced || !!forcedFlag;
        modalOpen = true;
        renderModal();
    }

    function close() {
        if (forced) return;
        modalOpen = false;
        sending = false;
        UI.closeModal(getRoot());
    }

    function forceClose() {
        forced = false;
        modalOpen = false;
        sending = false;
        UI.closeModal(getRoot());
    }

    function bodyHtml() {
        const tituloForzado = forced
            ? `<div class="alert alert-warning mb-3">${t('password.forced.notice')}</div>`
            : '';

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${tituloForzado}
            ${UI.grid(`
                ${UI.field({
                    label: t('password.fields.current'),
                    name: 'password_actual',
                    type: 'password',
                    required: true,
                    placeholder: t('password.fields.current_placeholder')
                })}
                ${UI.field({
                    label: t('password.fields.new'),
                    name: 'password_nuevo',
                    type: 'password',
                    required: true,
                    placeholder: t('password.fields.new_placeholder'),
                    hint: t('password.fields.rules_hint')
                })}
                ${UI.field({
                    label: t('password.fields.confirm'),
                    name: 'password_confirm',
                    type: 'password',
                    required: true,
                    placeholder: t('password.fields.confirm_placeholder')
                })}
            `, 1)}`;
    }

    function footerHtml() {
        if (forced) {
            // Sin cancel: solo guardar o salir. El cambio es obligatorio.
            return `
                <button class="btn btn-danger" data-action="pw-logout">${t('password.actions.logout')}</button>
                <button class="btn btn-primary" data-action="pw-submit" data-modal-send${sending ? ' disabled' : ''}>${t('password.actions.submit')}</button>`;
        }
        return `
            <button class="btn btn-secondary" data-action="pw-cancel">${t('ui.close')}</button>
            <button class="btn btn-primary" data-action="pw-submit" data-modal-send${sending ? ' disabled' : ''}>${t('password.actions.submit')}</button>`;
    }

    function renderModal() {
        const root = getRoot();

        UI.mountModal(root, UI.modalShell({
            title: t('password.title'),
            size: 'sm',
            bodyHtml: bodyHtml(),
            footerHtml: footerHtml()
        }));

        // Modal obligatorio: sin boton de cierre en la cabecera.
        if (forced) {
            const closeBtn = root.querySelector('.modal-close');
            if (closeBtn && closeBtn.parentNode) closeBtn.parentNode.removeChild(closeBtn);
        }
    }

    function setSending(value) {
        sending = value;
        const root = getRoot();
        root.querySelectorAll('[data-modal-send]').forEach(btn => { btn.disabled = value; });
    }

    async function submit() {
        if (sending) return;
        const root = getRoot();
        UI.clearErrors(root);

        const actual = fieldValue(root, 'password_actual');
        const nuevo = fieldValue(root, 'password_nuevo');
        const confirmacion = fieldValue(root, 'password_confirm');

        const errors = validate(actual, nuevo, confirmacion);
        const keys = Object.keys(errors);
        if (keys.length > 0) {
            keys.forEach(field => UI.showFieldError(root, field, errors[field]));
            return;
        }

        setSending(true);

        try {
            await global.HttpClient.post('/auth/change-password', {
                password_actual: actual,
                password_nuevo: nuevo
            });

            forceClose();
            UI.toast('success', t('password.success.title'), t('password.success.body'));
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('password.errors.generic'),
                root: getRoot(),
                inline: true,
                tag: 'Password'
            });
        } finally {
            setSending(false);
        }
    }

    function fieldValue(root, name) {
        const el = root.querySelector(`[data-modal-field="${name}"]`);
        return el ? String(el.value || '') : '';
    }

    // ====================================================================
    // EVENTOS
    // ====================================================================

    function handleClick(e) {
        const btn = e.target.closest('[data-action]');
        if (!btn || btn.disabled) return;

        switch (btn.dataset.action) {
            case 'pw-submit':
                submit();
                break;
            case 'pw-cancel':
                close();
                break;
            case 'pw-logout':
                if (global.AuthState) global.AuthState.logout();
                break;
            case 'close-modal':
                // El modal obligatorio solo se cierra al cambiar la clave.
                if (!forced) close();
                break;
        }
    }

    function onLogin() {
        mountMenuButton();
    }

    function onLogout() {
        removeMenuButton();
        forceClose();
    }

    function onPasswordRequired() {
        if (!(global.AuthState && global.AuthState.isAuthenticated())) return;
        open(true);
    }

    function onHttpError(payload) {
        if (!payload || payload.status !== 403) return;
        if (payload.path && String(payload.path).indexOf('/auth/change-password') !== -1) return;
        const inner = payload.data && payload.data.data;
        if (inner && inner.category === 'password_change_required') {
            onPasswordRequired();
        }
    }

    function onLocaleChanged() {
        const btn = document.getElementById('change-password-btn');
        if (btn) btn.textContent = t('shell.change_password');
        if (modalOpen) renderModal();
    }

    function bindGlobalEvents() {
        if (!global.EventBus) return;
        global.EventBus.on('auth:login', onLogin);
        global.EventBus.on('auth:logout', onLogout);
        global.EventBus.on('auth:password-required', onPasswordRequired);
        global.EventBus.on('http:error', onHttpError);
        global.EventBus.on('i18n:changed', onLocaleChanged);
    }

    function unbindGlobalEvents() {
        if (!global.EventBus) return;
        global.EventBus.off('auth:login', onLogin);
        global.EventBus.off('auth:logout', onLogout);
        global.EventBus.off('auth:password-required', onPasswordRequired);
        global.EventBus.off('http:error', onHttpError);
        global.EventBus.off('i18n:changed', onLocaleChanged);
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const AuthPasswordModule = {
        init() {
            if (initialized) {
                mountMenuButton();
                return;
            }
            initialized = true;

            bindGlobalEvents();
            mountMenuButton();

            const root = getRoot();
            root.addEventListener('click', handleClick);
        },

        onActivate() {
            mountMenuButton();
        },

        mountMenuButton: mountMenuButton,

        isForced() {
            return forced;
        },

        isOpen() {
            return modalOpen;
        },

        destroy() {
            unbindGlobalEvents();
            removeMenuButton();
            forceClose();
            const root = document.getElementById('password-modal-root');
            if (root && root.parentNode) root.parentNode.removeChild(root);
            initialized = false;
        }
    };

    global.AuthPasswordModule = AuthPasswordModule;

})(window);
