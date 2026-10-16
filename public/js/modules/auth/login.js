/**
 * Auth Login Module
 * Renderiza formulario de login y maneja autenticación.
 * Expuesto como window.AuthLoginModule (convención ModuleLoader).
 */
(function(global) {
    'use strict';

    let container = null;
    let isSubmitting = false;
    // v1.15.0 MFA: cuando el backend responde mfa_required, se guarda aquí la
    // credencial (email+password) para reenviar el login con mfa_code añadido.
    let mfaPending = false;
    let pendingCreds = null;

    // Plantilla construida en render() (NO al cargar el script): así los
    // literales pasan por t() con el diccionario ya inicializado.
    function buildTemplate() {
        return `
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">
                    <h1 data-app-name>XP Platform</h1>
                    <p>${global.AppConfig?.appTagline || t('login.tagline')}</p>
                </div>

                <form id="login-form" novalidate>
                    <div id="login-error" class="alert alert-danger hidden"></div>

                    <div class="form-group">
                        <label class="form-label" for="login-email">
                            ${t('login.email')} <span class="required">*</span>
                        </label>
                        <input
                            type="email"
                            id="login-email"
                            name="email"
                            class="input"
                            placeholder="${t('login.email_placeholder')}"
                            autocomplete="email"
                            required
                            autofocus
                        >
                        <span class="form-error hidden" data-error="email"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="login-password">
                            ${t('login.password')} <span class="required">*</span>
                        </label>
                        <input
                            type="password"
                            id="login-password"
                            name="password"
                            class="input"
                            placeholder="${t('login.password_placeholder')}"
                            autocomplete="current-password"
                            required
                            minlength="8"
                        >
                        <span class="form-error hidden" data-error="password"></span>
                    </div>

                    <!-- Password Reset SSPR (v1.17.0): enlace siempre visible -->
                    <div class="text-right mb-2" id="login-forgot-wrap">
                        <a href="#/forgot-password" id="login-forgot" class="text-xs">
                            ${t('login.forgot_password')}
                        </a>
                    </div>

                    <!-- Paso MFA (v1.15.0): oculto hasta que el backend pida el código -->
                    <div class="form-group hidden" id="login-mfa-group">
                        <label class="form-label" for="login-mfa-code">
                            ${t('login.mfa_label')} <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            id="login-mfa-code"
                            name="mfa_code"
                            class="input"
                            placeholder="${t('login.mfa_placeholder')}"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            maxlength="10"
                        >
                        <p class="form-hint text-xs text-muted mt-1">${t('login.mfa_hint')}</p>
                        <span class="form-error hidden" data-error="mfa_code"></span>
                        <button type="button" id="login-mfa-back" class="btn btn-ghost btn-sm mt-2">
                            ${t('login.mfa_back')}
                        </button>
                    </div>

                    <button
                        type="submit"
                        id="login-submit"
                        class="btn btn-primary w-full mt-2"
                    >
                        <span data-label>${t('login.submit')}</span>
                        <span data-spinner class="spinner spinner-sm hidden"></span>
                    </button>
                </form>

                <div class="mt-4 text-center text-xs text-muted">
                    <p>${t('login.terms')}</p>
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
        const form = document.getElementById('login-form');
        if (!form) return;

        form.addEventListener('submit', handleSubmit);

        // Limpiar errores al escribir
        ['email', 'password', 'mfa_code'].forEach(field => {
            const input = document.getElementById(`login-${field}`);
            if (input) {
                input.addEventListener('input', () => clearFieldError(field));
            }
        });

        // Botón "volver" del paso MFA: regresa al formulario de contraseña
        const mfaBack = document.getElementById('login-mfa-back');
        if (mfaBack) {
            mfaBack.addEventListener('click', () => {
                exitMfaMode();
                const pwd = document.getElementById('login-password');
                if (pwd) pwd.focus();
            });
        }
    }

    function validate(data) {
        const errors = {};

        if (!data.email || data.email.trim() === '') {
            errors.email = t('login.error_required_email');
        } else if (!isValidEmail(data.email)) {
            errors.email = t('login.error_invalid_email');
        }

        if (!data.password || data.password === '') {
            errors.password = t('login.error_required_password');
        } else if (data.password.length < 8) {
            errors.password = t('login.error_short_password');
        }

        return {
            valid: Object.keys(errors).length === 0,
            errors
        };
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function showFieldError(field, message) {
        const input = document.getElementById(`login-${field}`);
        const errorEl = document.querySelector(`[data-error="${field}"]`);

        if (input) input.classList.add('is-invalid');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.classList.remove('hidden');
        }
    }

    function clearFieldError(field) {
        const input = document.getElementById(`login-${field}`);
        const errorEl = document.querySelector(`[data-error="${field}"]`);

        if (input) input.classList.remove('is-invalid');
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.classList.add('hidden');
        }
    }

    function clearAllErrors() {
        ['email', 'password', 'mfa_code'].forEach(clearFieldError);
        const alert = document.getElementById('login-error');
        if (alert) {
            alert.textContent = '';
            alert.classList.add('hidden');
        }
    }

    function showGlobalError(message) {
        const alert = document.getElementById('login-error');
        if (!alert) return;

        alert.textContent = message;
        alert.classList.remove('hidden');
    }

    function setLoading(loading) {
        isSubmitting = loading;
        const btn = document.getElementById('login-submit');
        const label = btn ? btn.querySelector('[data-label]') : null;
        const spinner = btn ? btn.querySelector('[data-spinner]') : null;
        const emailInput = document.getElementById('login-email');
        const passwordInput = document.getElementById('login-password');
        const mfaInput = document.getElementById('login-mfa-code');
        const mfaBack = document.getElementById('login-mfa-back');

        if (btn) btn.disabled = loading;
        if (emailInput) emailInput.disabled = loading;
        if (passwordInput) passwordInput.disabled = loading;
        if (mfaInput) mfaInput.disabled = loading;
        if (mfaBack) mfaBack.disabled = loading;

        if (loading) {
            if (label) label.textContent = t('login.submitting');
            if (spinner) spinner.classList.remove('hidden');
        } else {
            if (label) label.textContent = mfaPending ? t('login.mfa_submit') : t('login.submit');
            if (spinner) spinner.classList.add('hidden');
        }
    }

    async function handleSubmit(event) {
        event.preventDefault();

        if (isSubmitting) return;

        clearAllErrors();

        const emailInput = document.getElementById('login-email');
        const passwordInput = document.getElementById('login-password');
        const mfaInput = document.getElementById('login-mfa-code');

        if (!emailInput || !passwordInput) return;

        let data;
        if (mfaPending && pendingCreds) {
            // Reenvío del login con el código MFA; se reutiliza la credencial
            // guardada al entrar al paso del código.
            const mfaCode = mfaInput ? mfaInput.value.trim() : '';
            if (!mfaCode) {
                showFieldError('mfa_code', t('login.mfa_required'));
                return;
            }
            data = {
                email: pendingCreds.email,
                password: pendingCreds.password,
                mfa_code: mfaCode
            };
        } else {
            data = {
                email: emailInput.value.trim(),
                password: passwordInput.value
            };
            const validation = validate(data);
            if (!validation.valid) {
                Object.entries(validation.errors).forEach(([field, msg]) => {
                    showFieldError(field, msg);
                });
                return;
            }
            // Se guarda para poder reenviar con mfa_code si el backend lo pide.
            pendingCreds = { email: data.email, password: data.password };
        }

        setLoading(true);

        try {
            if (!global.HttpClient) {
                throw new Error('HttpClient not available');
            }

            const response = await global.HttpClient.post('/auth/login', data, {
                requiresAuth: false
            });

            // MFA requerido (200 sin tokens): entrar al paso del código.
            if (response && response.data && response.data.mfa_required && !response.data.tokens) {
                enterMfaMode();
                return;
            }

            if (!response || !response.data || !response.data.tokens || !response.data.user) {
                throw new Error(t('login.error_invalid_response'));
            }

            // Éxito: salir del modo MFA y continuar con la sesión.
            exitMfaMode();

            // Guardar sesión
            if (global.AuthState) {
                global.AuthState.setSession(response.data.user, response.data.tokens);
            }

            // Emitir evento
            if (global.EventBus) {
                global.EventBus.emit('auth:login', {
                    user: response.data.user
                });
            }

            // v1.12.0: el backend exige renovar la contrasena en el primer
            // acceso (password_change_required en la respuesta de login).
            if (response.data.password_change_required && global.EventBus) {
                global.EventBus.emit('auth:password-required', { forced: true });
            }

            // Mostrar toast de éxito
            if (global.EventBus) {
                global.EventBus.emit('toast:show', {
                    type: 'success',
                    title: t('login.welcome'),
                    message: t('login.welcome_message', { nombre: response.data.user.nombre || response.data.user.email }),
                    duration: 3000
                });
            }

            // Redirigir a dashboard: esperar a que el diccionario completo
            // este descargado (carga inicial pre-sesion, v1.10.2) para no
            // pintar claves en crudo en la primera pantalla tras el login.
            const dictReady = (global.i18n && typeof global.i18n.ensureLoaded === 'function')
                ? global.i18n.ensureLoaded()
                : Promise.resolve();
            Promise.resolve(dictReady).then(function() {
                if (global.AppRouter) {
                    global.AppRouter.navigate('dashboard');
                } else {
                    window.location.hash = '#/dashboard';
                }
            });
        } catch (error) {
            console.error('[LoginModule] Error:', error);

            // Código MFA inválido (401 estando en el paso del código): se marca
            // el campo y se permanece en el paso MFA para reintentar.
            if (mfaPending && error.status === 401) {
                showFieldError('mfa_code', t('login.mfa_invalid'));
                return;
            }

            // Otro error (credenciales, rate limit, etc.): salir del paso MFA.
            exitMfaMode();

            let message = t('login.error_generic');

            if (error.status === 401) {
                message = t('login.error_401');
            } else if (error.status === 403) {
                message = t('login.error_403');
            } else if (error.status === 429) {
                message = t('login.error_429');
            } else if (error.status === 408) {
                message = t('login.error_408');
            } else if (error.message) {
                // Hueco: mensajes de error generados por el backend/passthrough
                // (se reportan en CHANGELOG; no se traducen en frontend).
                message = error.message;
            }

            showGlobalError(message);
        } finally {
            setLoading(false);
        }
    }

    /** Muestra el paso del código MFA y oculta email/contraseña. */
    function enterMfaMode() {
        mfaPending = true;
        const mfaGroup = document.getElementById('login-mfa-group');
        if (mfaGroup) mfaGroup.classList.remove('hidden');
        ['login-email', 'login-password'].forEach(id => {
            const el = document.getElementById(id);
            const grp = el ? el.closest('.form-group') : null;
            if (grp) grp.classList.add('hidden');
        });
        const forgotWrap = document.getElementById('login-forgot-wrap');
        if (forgotWrap) forgotWrap.classList.add('hidden');
        const label = document.querySelector('#login-submit [data-label]');
        if (label) label.textContent = t('login.mfa_submit');
        const mfaInput = document.getElementById('login-mfa-code');
        if (mfaInput) mfaInput.focus();
    }

    /** Vuelve al formulario de contraseña y limpia el estado MFA. */
    function exitMfaMode() {
        mfaPending = false;
        pendingCreds = null;
        const mfaGroup = document.getElementById('login-mfa-group');
        if (mfaGroup) mfaGroup.classList.add('hidden');
        ['login-email', 'login-password'].forEach(id => {
            const el = document.getElementById(id);
            const grp = el ? el.closest('.form-group') : null;
            if (grp) grp.classList.remove('hidden');
        });
        const forgotWrap = document.getElementById('login-forgot-wrap');
        if (forgotWrap) forgotWrap.classList.remove('hidden');
        const mfaInput = document.getElementById('login-mfa-code');
        if (mfaInput) {
            mfaInput.value = '';
            mfaInput.disabled = false;
        }
        clearFieldError('mfa_code');
        const label = document.querySelector('#login-submit [data-label]');
        if (label) label.textContent = t('login.submit');
    }

    const AuthLoginModule = {
        init(params, targetContainer) {
            container = targetContainer;
            render(container);
        },

        onActivate(params) {
            // Si ya está autenticado, redirigir
            if (global.AuthState && global.AuthState.isAuthenticated()) {
                if (global.AppRouter) {
                    global.AppRouter.navigate('dashboard');
                }
                return;
            }

            // Re-renderizar si es necesario
            if (container && !document.getElementById('login-form')) {
                render(container);
            }
        },

        destroy() {
            const form = document.getElementById('login-form');
            if (form) {
                form.removeEventListener('submit', handleSubmit);
            }
            if (container) {
                container.innerHTML = '';
            }
            container = null;
            isSubmitting = false;
        }
    };

    global.AuthLoginModule = AuthLoginModule;

})(window);
