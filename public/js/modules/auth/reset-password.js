/**
 * Auth Reset Password Module - v1.17.0 (SSPR)
 * Fija la nueva contraseña con el token del correo (POST /auth/reset-password).
 *
 * Convención ModuleLoader: window.AuthResetPasswordModule (ruta auth/reset-password).
 * Ruta pública: el token llega en la hash (#/reset-password?token=...). La
 * política de fortaleza se valida también en cliente (mismas reglas que
 * password.js / PasswordPolicy); el backend es la autoridad final.
 */
(function(global) {
    'use strict';

    let container = null;
    let isSubmitting = false;
    let token = '';

    const EYE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const EYE_OFF_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function togglePasswordField(inputId, button) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        button.innerHTML = showing ? EYE_ICON : EYE_OFF_ICON;
        button.setAttribute('aria-pressed', showing ? 'false' : 'true');
        const label = showing ? t('ui.show_password') : t('ui.hide_password');
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    }

    function buildTemplate() {
        return `
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">
                    <h1 data-app-name>XP Platform</h1>
                    <p>${t('reset.title')}</p>
                </div>

                <div id="reset-error" class="alert alert-danger hidden"></div>
                <div id="reset-success" class="alert alert-success hidden"></div>

                <form id="reset-form" novalidate>
                    <p class="text-sm text-muted mb-3">${t('reset.intro')}</p>

                    <div class="form-group">
                        <label class="form-label" for="reset-password_nuevo">
                            ${t('reset.password_label')} <span class="required">*</span>
                        </label>
                        <div class="input-group">
                            <input
                                type="password"
                                id="reset-password_nuevo"
                                name="password_nuevo"
                                class="input"
                                placeholder="${t('reset.password_placeholder')}"
                                autocomplete="new-password"
                                required
                                autofocus
                            >
                            <button type="button" class="input-addon btn-eye" data-eye-for="reset-password_nuevo" aria-label="${t('ui.show_password')}" aria-pressed="false">${EYE_ICON}</button>
                        </div>
                        <p class="form-hint text-xs text-muted mt-1">${t('password.fields.rules_hint')}</p>
                        <span class="form-error hidden" data-error="password_nuevo"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="reset-password_confirm">
                            ${t('reset.confirm_label')} <span class="required">*</span>
                        </label>
                        <div class="input-group">
                            <input
                                type="password"
                                id="reset-password_confirm"
                                name="password_confirm"
                                class="input"
                                placeholder="${t('reset.confirm_placeholder')}"
                                autocomplete="new-password"
                                required
                            >
                            <button type="button" class="input-addon btn-eye" data-eye-for="reset-password_confirm" aria-label="${t('ui.show_password')}" aria-pressed="false">${EYE_ICON}</button>
                        </div>
                        <span class="form-error hidden" data-error="password_confirm"></span>
                    </div>

                    <button
                        type="submit"
                        id="reset-submit"
                        class="btn btn-primary w-full mt-2"
                    >
                        <span data-label>${t('reset.submit')}</span>
                        <span data-spinner class="spinner spinner-sm hidden"></span>
                    </button>
                </form>

                <div class="mt-4 text-center text-xs">
                    <a href="#/login" id="reset-back">${t('reset.back_to_login')}</a>
                </div>
            </div>
        </div>
    `;
    }

    function render(targetContainer) {
        if (!targetContainer) return;
        targetContainer.innerHTML = buildTemplate();

        const brand = targetContainer.querySelector('[data-app-name]');
        if (brand) {
            brand.textContent = global.AppConfig?.appName || 'XP Platform';
        }

        bindEvents();

        // Sin token en la URL: el formulario no tiene sentido.
        if (!token) {
            showGlobalError(t('reset.invalid_token'));
            const form = document.getElementById('reset-form');
            if (form) form.classList.add('hidden');
        }
    }

    function bindEvents() {
        const form = document.getElementById('reset-form');
        if (form) form.addEventListener('submit', handleSubmit);
        ['password_nuevo', 'password_confirm'].forEach(field => {
            const input = document.getElementById(`reset-${field}`);
            if (input) input.addEventListener('input', () => clearFieldError(field));
        });
        document.querySelectorAll('[data-eye-for]').forEach(btn => {
            btn.addEventListener('click', () => togglePasswordField(btn.getAttribute('data-eye-for'), btn));
        });
    }

    // Validación de cliente: mismas reglas que PasswordPolicy (v1.16.0).
    function validate(nuevo, confirmacion) {
        const errors = {};
        if (!nuevo) {
            errors.password_nuevo = t('password.errors.required_new');
        } else if (nuevo.length < 9) {
            errors.password_nuevo = t('password.errors.min_length');
        } else if (!/[A-Z]/.test(nuevo)) {
            errors.password_nuevo = t('password.errors.uppercase');
        } else if (!/[a-z]/.test(nuevo)) {
            errors.password_nuevo = t('password.errors.lowercase');
        } else if (!/[0-9]/.test(nuevo)) {
            errors.password_nuevo = t('password.errors.digit');
        } else if (!/[^A-Za-z0-9]/.test(nuevo)) {
            errors.password_nuevo = t('password.errors.special');
        }

        if (!confirmacion) {
            errors.password_confirm = t('password.errors.required_confirm');
        } else if (nuevo && confirmacion !== nuevo) {
            errors.password_confirm = t('password.errors.mismatch');
        }

        return errors;
    }

    function showFieldError(field, message) {
        const input = document.getElementById(`reset-${field}`);
        const errorEl = document.querySelector(`[data-error="${field}"]`);
        if (input) input.classList.add('is-invalid');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.classList.remove('hidden');
        }
    }

    function clearFieldError(field) {
        const input = document.getElementById(`reset-${field}`);
        const errorEl = document.querySelector(`[data-error="${field}"]`);
        if (input) input.classList.remove('is-invalid');
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.classList.add('hidden');
        }
    }

    function clearAllErrors() {
        ['password_nuevo', 'password_confirm'].forEach(clearFieldError);
        const alert = document.getElementById('reset-error');
        if (alert) {
            alert.textContent = '';
            alert.classList.add('hidden');
        }
    }

    function showGlobalError(message) {
        const alert = document.getElementById('reset-error');
        if (!alert) return;
        alert.textContent = message;
        alert.classList.remove('hidden');
    }

    function setLoading(loading) {
        isSubmitting = loading;
        const btn = document.getElementById('reset-submit');
        const label = btn ? btn.querySelector('[data-label]') : null;
        const spinner = btn ? btn.querySelector('[data-spinner]') : null;
        const inputs = ['reset-password_nuevo', 'reset-password_confirm']
            .map(id => document.getElementById(id));
        const eyeButtons = document.querySelectorAll('[data-eye-for]');
        if (btn) btn.disabled = loading;
        inputs.forEach(el => { if (el) el.disabled = loading; });
        eyeButtons.forEach(el => { el.disabled = loading; });
        if (loading) {
            if (label) label.textContent = t('reset.submitting');
            if (spinner) spinner.classList.remove('hidden');
        } else {
            if (label) label.textContent = t('reset.submit');
            if (spinner) spinner.classList.add('hidden');
        }
    }

    async function handleSubmit(event) {
        event.preventDefault();
        if (isSubmitting) return;

        clearAllErrors();

        const nuevoInput = document.getElementById('reset-password_nuevo');
        const confirmInput = document.getElementById('reset-password_confirm');
        if (!nuevoInput || !confirmInput) return;

        const nuevo = nuevoInput.value;
        const confirmacion = confirmInput.value;

        const errors = validate(nuevo, confirmacion);
        if (Object.keys(errors).length > 0) {
            Object.entries(errors).forEach(([field, msg]) => showFieldError(field, msg));
            return;
        }

        setLoading(true);

        try {
            if (!global.HttpClient) {
                throw new Error('HttpClient not available');
            }

            await global.HttpClient.post('/auth/reset-password', {
                token: token,
                password_nuevo: nuevo
            }, { requiresAuth: false });

            // Éxito: aviso + volver al login.
            const form = document.getElementById('reset-form');
            const success = document.getElementById('reset-success');
            if (form) form.classList.add('hidden');
            if (success) {
                success.textContent = t('reset.success');
                success.classList.remove('hidden');
            }
            if (global.EventBus) {
                global.EventBus.emit('toast:show', {
                    type: 'success',
                    title: t('reset.title'),
                    message: t('reset.success'),
                    duration: 4000
                });
            }
        } catch (error) {
            console.error('[ResetPasswordModule] Error:', error);
            // Errores del backend (token inválido/caducado, política): se
            // muestra el mensaje traducido; si no lo hay, genérico.
            let message = t('reset.error_generic');
            if (error && error.message) {
                message = error.message;
            }
            showGlobalError(message);
        } finally {
            setLoading(false);
        }
    }

    const AuthResetPasswordModule = {
        init(params, targetContainer) {
            container = targetContainer;
            token = (params && params.token) ? String(params.token) : '';
            render(container);
        },

        onActivate(params) {
            if (params && typeof params.token === 'string') {
                token = params.token;
            }
            if (container && !document.getElementById('reset-form')) {
                render(container);
            }
        },

        destroy() {
            const form = document.getElementById('reset-form');
            if (form) form.removeEventListener('submit', handleSubmit);
            if (container) container.innerHTML = '';
            container = null;
            isSubmitting = false;
            token = '';
        }
    };

    global.AuthResetPasswordModule = AuthResetPasswordModule;

})(window);
