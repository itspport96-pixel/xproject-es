/**
 * Auth Forgot Password Module - v1.17.0 (SSPR)
 * Solicita el enlace de restablecimiento de contraseña (POST /auth/forgot-password).
 *
 * Convención ModuleLoader: window.AuthForgotPasswordModule (ruta auth/forgot-password).
 * Ruta pública (sin sesión). Anti-enumeración: el backend SIEMPRE responde 200
 * con el mismo mensaje, exista o no el email; la UI muestra siempre el mismo
 * panel de éxito genérico tras enviar.
 */
(function(global) {
    'use strict';

    let container = null;
    let isSubmitting = false;

    function buildTemplate() {
        return `
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">
                    <h1 data-app-name>XP Platform</h1>
                    <p>${t('forgot.title')}</p>
                </div>

                <div id="forgot-success" class="alert alert-success hidden"></div>
                <div id="forgot-error" class="alert alert-danger hidden"></div>

                <form id="forgot-form" novalidate>
                    <p class="text-sm text-muted mb-3">${t('forgot.intro')}</p>

                    <div class="form-group">
                        <label class="form-label" for="forgot-email">
                            ${t('forgot.email_label')} <span class="required">*</span>
                        </label>
                        <input
                            type="email"
                            id="forgot-email"
                            name="email"
                            class="input"
                            placeholder="${t('forgot.email_placeholder')}"
                            autocomplete="email"
                            required
                            autofocus
                        >
                        <span class="form-error hidden" data-error="email"></span>
                    </div>

                    <button
                        type="submit"
                        id="forgot-submit"
                        class="btn btn-primary w-full mt-2"
                    >
                        <span data-label>${t('forgot.submit')}</span>
                        <span data-spinner class="spinner spinner-sm hidden"></span>
                    </button>
                </form>

                <div class="mt-4 text-center text-xs">
                    <a href="#/login" id="forgot-back">${t('forgot.back_to_login')}</a>
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
    }

    function bindEvents() {
        const form = document.getElementById('forgot-form');
        if (form) {
            form.addEventListener('submit', handleSubmit);
        }
        const email = document.getElementById('forgot-email');
        if (email) {
            email.addEventListener('input', () => clearFieldError('email'));
        }
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function showFieldError(field, message) {
        const input = document.getElementById(`forgot-${field}`);
        const errorEl = document.querySelector(`[data-error="${field}"]`);
        if (input) input.classList.add('is-invalid');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.classList.remove('hidden');
        }
    }

    function clearFieldError(field) {
        const input = document.getElementById(`forgot-${field}`);
        const errorEl = document.querySelector(`[data-error="${field}"]`);
        if (input) input.classList.remove('is-invalid');
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.classList.add('hidden');
        }
    }

    function showGlobalError(message) {
        const alert = document.getElementById('forgot-error');
        if (!alert) return;
        alert.textContent = message;
        alert.classList.remove('hidden');
    }

    function setLoading(loading) {
        isSubmitting = loading;
        const btn = document.getElementById('forgot-submit');
        const label = btn ? btn.querySelector('[data-label]') : null;
        const spinner = btn ? btn.querySelector('[data-spinner]') : null;
        const emailInput = document.getElementById('forgot-email');
        if (btn) btn.disabled = loading;
        if (emailInput) emailInput.disabled = loading;
        if (loading) {
            if (label) label.textContent = t('forgot.submitting');
            if (spinner) spinner.classList.remove('hidden');
        } else {
            if (label) label.textContent = t('forgot.submit');
            if (spinner) spinner.classList.add('hidden');
        }
    }

    /** Panel de éxito genérico: se oculta el formulario y se muestra el aviso. */
    function showSuccess() {
        const form = document.getElementById('forgot-form');
        const success = document.getElementById('forgot-success');
        if (form) form.classList.add('hidden');
        if (success) {
            success.textContent = t('forgot.sent_generic');
            success.classList.remove('hidden');
        }
    }

    async function handleSubmit(event) {
        event.preventDefault();
        if (isSubmitting) return;

        const alert = document.getElementById('forgot-error');
        if (alert) alert.classList.add('hidden');
        clearFieldError('email');

        const emailInput = document.getElementById('forgot-email');
        if (!emailInput) return;
        const email = emailInput.value.trim();

        if (!email || !isValidEmail(email)) {
            showFieldError('email', t('forgot.error_invalid_email'));
            return;
        }

        setLoading(true);

        try {
            if (!global.HttpClient) {
                throw new Error('HttpClient not available');
            }
            // El backend responde 200 con mensaje genérico siempre; no hay
            // nada diferencial que interpretar.
            await global.HttpClient.post('/auth/forgot-password', { email }, {
                requiresAuth: false
            });
            showSuccess();
        } catch (error) {
            console.error('[ForgotPasswordModule] Error:', error);
            // Incluso en error de red se muestra el mismo aviso genérico para
            // no revelar nada sobre la existencia de la cuenta.
            showSuccess();
        } finally {
            setLoading(false);
        }
    }

    const AuthForgotPasswordModule = {
        init(params, targetContainer) {
            container = targetContainer;
            render(container);
        },

        onActivate() {
            if (container && !document.getElementById('forgot-form')) {
                render(container);
            }
        },

        destroy() {
            const form = document.getElementById('forgot-form');
            if (form) form.removeEventListener('submit', handleSubmit);
            if (container) container.innerHTML = '';
            container = null;
            isSubmitting = false;
        }
    };

    global.AuthForgotPasswordModule = AuthForgotPasswordModule;

})(window);
