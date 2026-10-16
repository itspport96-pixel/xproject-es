/**
 * Profile Module (v1.15.0)
 * Perfil de usuario con tabs horizontales.
 *
 * Tabs:
 * - personal: nombre, apellido, email (PATCH /api/users/me)
 * - security: cambio de contraseña (reutiliza AuthPasswordModule) + MFA TOTP
 *   (enrolamiento opt-in del propio usuario: QR, códigos de respaldo, desactivar)
 * - prefs: idioma (i18n.setLocale)
 *
 * Ruta 'profile': cualquier usuario autenticado, sin permiso específico.
 * Convención ModuleLoader: window.ProfileProfileModule (ruta profile/profile).
 */
(function(global) {
    'use strict';

    let container = null;
    let activeTab = 'personal';
    let saving = false;

    // Estado MFA del usuario autenticado (opt-in, v1.15.0)
    let mfa = null;            // { habilitado, metodo, pending_setup, backup_codes_remaining }
    let mfaSetup = null;       // { secret, otpauth_url } durante el enrolamiento
    let mfaBackupCodes = null; // códigos de respaldo mostrados una sola vez
    let mfaBusy = false;
    let mfaError = null;
    let mfaLoading = false;

    // Nombres nativos de los idiomas, vía claves i18n
    const LANG_KEYS = {
        es: 'profile.lang.es',
        en: 'profile.lang.en',
        pt: 'profile.lang.pt',
        fr: 'profile.lang.fr'
    };

    const TABS = [
        { id: 'personal', labelKey: 'profile.tab_personal' },
        { id: 'security', labelKey: 'profile.tab_security' },
        { id: 'prefs', labelKey: 'profile.tab_prefs' }
        // Futuro: { id: 'mfa', labelKey: 'profile.tab_mfa' }
    ];

    // ====================================================================
    // HELPERS
    // ====================================================================

    function escapeHtml(str) {
        if (global.UI && typeof global.UI.escapeHtml === 'function') {
            return global.UI.escapeHtml(str);
        }
        return String(str == null ? '' : str);
    }

    function getUser() {
        return global.AuthState && typeof global.AuthState.getUser === 'function'
            ? global.AuthState.getUser()
            : null;
    }

    function showToast(type, title, message) {
        if (global.UI && typeof global.UI.toast === 'function') {
            global.UI.toast(type, title, message || '');
        }
    }

    // ====================================================================
    // TEMPLATE PRINCIPAL CON TABS
    // ====================================================================

    function buildTabsNav() {
        const tabsHtml = TABS.map(function(tab) {
            const isActive = tab.id === activeTab;
            const classes = isActive
                ? 'tab is-active'
                : 'tab';
            return `<button type="button" class="${classes}" data-tab="${escapeHtml(tab.id)}">${escapeHtml(t(tab.labelKey))}</button>`;
        }).join('');

        return `<div class="tabs" role="tablist">${tabsHtml}</div>`;
    }

    function buildTemplate() {
        return `
            ${UI.pageHeader({ title: t('profile.title'), subtitle: t('profile.subtitle') })}
            ${buildTabsNav()}
            <div class="tab-content" id="profile-tab-content">
                ${renderActiveTab()}
            </div>
        `;
    }

    function renderActiveTab() {
        switch (activeTab) {
            case 'personal': return renderPersonalTab();
            case 'security': return renderSecurityTab();
            case 'prefs': return renderPrefsTab();
            default: return renderPersonalTab();
        }
    }

    // ====================================================================
    // TAB: DATOS PERSONALES
    // ====================================================================

    function renderPersonalTab() {
        const user = getUser() || {};
        return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-1">${escapeHtml(t('profile.section_personal'))}</h3>
                <p class="text-muted text-sm mb-4">${escapeHtml(t('profile.personal_body'))}</p>

                <form id="profile-personal-form" class="space-y-4" novalidate>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium mb-1" for="profile-nombre">${escapeHtml(t('profile.label_nombre'))}</label>
                            <input type="text" id="profile-nombre" name="nombre" class="input w-full"
                                value="${escapeHtml(user.nombre || '')}"
                                placeholder="${escapeHtml(t('profile.placeholder_nombre'))}"
                                maxlength="100" required>
                            <p class="field-error text-danger text-sm mt-1 hidden" data-field="nombre"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="profile-apellido">${escapeHtml(t('profile.label_apellido'))}</label>
                            <input type="text" id="profile-apellido" name="apellido" class="input w-full"
                                value="${escapeHtml(user.apellido || '')}"
                                placeholder="${escapeHtml(t('profile.placeholder_apellido'))}"
                                maxlength="100" required>
                            <p class="field-error text-danger text-sm mt-1 hidden" data-field="apellido"></p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="profile-email">${escapeHtml(t('profile.label_email'))}</label>
                        <input type="email" id="profile-email" name="email" class="input w-full"
                            value="${escapeHtml(user.email || '')}"
                            placeholder="${escapeHtml(t('profile.placeholder_email'))}"
                            maxlength="255" required>
                        <p class="text-muted text-xs mt-1">${escapeHtml(t('profile.help_email_change'))}</p>
                        <p class="field-error text-danger text-sm mt-1 hidden" data-field="email"></p>
                    </div>

                    <div id="profile-password-section" class="hidden">
                        <label class="block text-sm font-medium mb-1" for="profile-password-actual">${escapeHtml(t('profile.label_password_actual'))}</label>
                        <input type="password" id="profile-password-actual" name="password_actual" class="input w-full"
                            placeholder="${escapeHtml(t('profile.placeholder_password'))}"
                            autocomplete="current-password">
                        <p class="text-muted text-xs mt-1">${escapeHtml(t('profile.help_password_email'))}</p>
                        <p class="field-error text-danger text-sm mt-1 hidden" data-field="password_actual"></p>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="btn btn-primary" id="profile-save-btn">${escapeHtml(t('profile.btn_save'))}</button>
                    </div>
                </form>
            </div>
        `;
    }

    function showPasswordSectionIfEmailChanged() {
        if (!container) return;
        const emailInput = container.querySelector('#profile-email');
        const passwordSection = container.querySelector('#profile-password-section');
        if (!emailInput || !passwordSection) return;

        const user = getUser() || {};
        const originalEmail = (user.email || '').toLowerCase();
        const currentEmail = emailInput.value.trim().toLowerCase();
        const emailChanged = currentEmail !== originalEmail;

        if (emailChanged) {
            passwordSection.classList.remove('hidden');
        } else {
            passwordSection.classList.add('hidden');
            const pwdInput = container.querySelector('#profile-password-actual');
            if (pwdInput) pwdInput.value = '';
        }
    }

    function clearFieldErrors() {
        if (!container) return;
        container.querySelectorAll('.field-error').forEach(function(el) {
            el.textContent = '';
            el.classList.add('hidden');
        });
    }

    function showFieldError(field, message) {
        if (!container) return;
        const el = container.querySelector('[data-field="' + field + '"]');
        if (el) {
            el.textContent = message;
            el.classList.remove('hidden');
        }
    }

    function validatePersonalForm(data, emailChanged) {
        const errors = {};

        if (!data.nombre || data.nombre.trim() === '') {
            errors.nombre = t('profile.field_required');
        }
        if (!data.apellido || data.apellido.trim() === '') {
            errors.apellido = t('profile.field_required');
        }
        if (!data.email || data.email.trim() === '') {
            errors.email = t('profile.field_required');
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
            errors.email = t('profile.email_invalid');
        }
        if (emailChanged && (!data.password_actual || data.password_actual === '')) {
            errors.password_actual = t('profile.password_required_for_email');
        }

        return errors;
    }

    async function handlePersonalSubmit(e) {
        e.preventDefault();
        if (saving || !container) return;

        const form = e.target;
        const nombreInput = form.querySelector('#profile-nombre');
        const apellidoInput = form.querySelector('#profile-apellido');
        const emailInput = form.querySelector('#profile-email');
        const pwdInput = form.querySelector('#profile-password-actual');
        const saveBtn = form.querySelector('#profile-save-btn');

        const user = getUser() || {};
        const originalEmail = (user.email || '').toLowerCase();
        const newEmail = emailInput.value.trim().toLowerCase();
        const emailChanged = newEmail !== originalEmail;

        const data = {
            nombre: nombreInput.value.trim(),
            apellido: apellidoInput.value.trim(),
            email: emailInput.value.trim()
        };

        if (emailChanged && pwdInput) {
            data.password_actual = pwdInput.value;
        }

        // Validación client-side
        clearFieldErrors();
        const errors = validatePersonalForm(data, emailChanged);
        if (Object.keys(errors).length > 0) {
            Object.keys(errors).forEach(function(field) {
                showFieldError(field, errors[field]);
            });
            return;
        }

        // Enviar
        saving = true;
        saveBtn.disabled = true;
        saveBtn.textContent = t('profile.btn_saving');

        try {
            const response = await global.HttpClient.patch('/users/me', data, { requiresAuth: true });
            const result = response && response.data ? response.data : response;

            // MERGE de los campos actualizados sobre el usuario existente.
            // NO reemplazar: result.user no trae roles/permisos (eso viene
            // de /auth/me). Reemplazar causaría perdida de privilegios en UI.
            if (result && result.user && global.AuthState && typeof global.AuthState.updateUser === 'function') {
                const current = global.AuthState.getUser() || {};
                const merged = { ...current, ...result.user };
                global.AuthState.updateUser(merged);
            }

            showToast('success', t('profile.title'), t('profile.success_profile'));

            // Recargar el tab para mostrar valores actualizados
            if (container) {
                container.querySelector('#profile-tab-content').innerHTML = renderActiveTab();
                bindTabEvents();
            }

        } catch (err) {
            console.error('[Profile] Error updating profile:', err);
            // El error ya viene formateado por HttpClient/UI.errorMessage
            const errorMsg = err && err.message ? err.message : t('ui.error_generic');
            const errorField = err && err.data && err.data.field;
            if (errorField) {
                showFieldError(errorField, errorMsg);
            } else {
                showToast('danger', t('ui.error_generic'), errorMsg);
            }
        } finally {
            saving = false;
            saveBtn.disabled = false;
            saveBtn.textContent = t('profile.btn_save');
        }
    }

    // ====================================================================
    // TAB: SEGURIDAD (contraseña + MFA TOTP)
    // ====================================================================

    function renderSecurityTab() {
        return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-1">${escapeHtml(t('profile.section_password'))}</h3>
                <p class="text-muted text-sm mb-4">${escapeHtml(t('profile.password_body'))}</p>
                <button type="button" class="btn btn-primary" id="profile-change-password">${escapeHtml(t('shell.change_password'))}</button>
            </div>
            ${renderMfaCard()}
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

    // ====================================================================
    // MFA (TOTP): enrolamiento del propio usuario (opt-in, v1.15.0)
    // ====================================================================

    // QR de enrolamiento con la libreria vendorizada (public/js/vendor/qrcode.js,
    // qrcode-generator 1.4.4, MIT). Si no cargo, se devuelve null y la vista
    // muestra solo el secreto manual.
    function mfaQrSvg(uri) {
        try {
            const lib = window.qrcode;
            if (typeof lib !== 'function') return null;
            const qr = lib(0, 'M');
            qr.addData(uri);
            qr.make();
            return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
        } catch (err) {
            return null;
        }
    }

    function rerenderTabContent() {
        if (!container) return;
        const tabContent = container.querySelector('#profile-tab-content');
        if (tabContent) {
            tabContent.innerHTML = renderActiveTab();
            bindTabEvents();
        }
    }

    function renderMfaCard() {
        let html = `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-1">${escapeHtml(t('profile.mfa_title'))}</h3>
                <p class="text-muted text-sm mb-3">${escapeHtml(t('profile.mfa_body'))}</p>`;

        if (mfaError) {
            html += `<div class="alert alert-danger mb-3">${escapeHtml(mfaError)}</div>`;
        }

        // Recién activado: mostrar códigos de respaldo UNA sola vez.
        if (mfaBackupCodes) {
            html += `
                <div class="alert alert-warning mb-3">
                    <p class="font-medium mb-2">${escapeHtml(t('profile.mfa_backup_title'))}</p>
                    <p class="text-sm mb-2">${escapeHtml(t('profile.mfa_backup_warning'))}</p>
                    <ul class="font-mono text-sm grid grid-cols-2 gap-1 mb-3" data-testid="mfa-backup-codes">
                        ${mfaBackupCodes.map(function(c) { return '<li>' + escapeHtml(c) + '</li>'; }).join('')}
                    </ul>
                    <button type="button" class="btn btn-secondary btn-sm" data-action="profile-mfa-backup-done" data-testid="profile-mfa-backup-done-btn">${escapeHtml(t('profile.mfa_backup_done'))}</button>
                </div>`;
            return html + '</div>';
        }

        // Enrolamiento en curso: QR (si la libreria vendorizada cargo) + secreto manual colapsable.
        if (mfaSetup) {
            const qrSvg = mfaQrSvg(mfaSetup.otpauth_url);
            html += `<p class="text-sm mb-3">${escapeHtml(t('profile.mfa_setup_intro'))}</p>`;
            if (qrSvg) {
                html += `
                <div class="flex justify-center mb-3">
                    <div class="bg-white p-3 rounded-xl border border-default" data-testid="mfa-qr" style="width: 208px; height: 208px;">${qrSvg}</div>
                </div>
                <details class="mb-3">
                    <summary class="text-sm cursor-pointer text-muted">${escapeHtml(t('profile.mfa_manual_title'))}</summary>
                    <p class="text-sm font-medium mt-2 mb-1">${escapeHtml(t('profile.mfa_setup_secret_label'))}</p>
                    <code class="block p-2 bg-surface border border-default rounded text-sm font-mono break-all mb-2" data-testid="mfa-secret">${escapeHtml(mfaSetup.secret)}</code>
                    <p class="text-xs text-muted break-all">${escapeHtml(t('profile.mfa_setup_uri_label'))}
                        <a href="${escapeHtml(mfaSetup.otpauth_url)}" class="underline" data-testid="mfa-otpauth-uri">${escapeHtml(mfaSetup.otpauth_url)}</a></p>
                </details>`;
            } else {
                html += `
                <p class="text-sm font-medium mb-1">${escapeHtml(t('profile.mfa_setup_secret_label'))}</p>
                <code class="block p-2 bg-surface border border-default rounded text-sm font-mono break-all mb-2" data-testid="mfa-secret">${escapeHtml(mfaSetup.secret)}</code>
                <p class="text-xs text-muted mb-3 break-all">${escapeHtml(t('profile.mfa_setup_uri_label'))}
                    <a href="${escapeHtml(mfaSetup.otpauth_url)}" class="underline" data-testid="mfa-otpauth-uri">${escapeHtml(mfaSetup.otpauth_url)}</a></p>`;
            }
            html += `
                <div>
                    <label class="block text-sm font-medium mb-1" for="profile-mfa-code-setup">${escapeHtml(t('profile.mfa_code_label'))}</label>
                    <input type="text" id="profile-mfa-code-setup" name="mfa_code_setup" class="input w-full"
                        placeholder="123456" maxlength="10" autocomplete="one-time-code">
                    <p class="field-error text-danger text-sm mt-1 hidden" data-field="mfa_code_setup"></p>
                </div>
                <div class="flex items-center gap-2 mt-3 flex-wrap">
                    <button type="button" class="btn btn-primary" data-action="profile-mfa-enable" data-testid="profile-mfa-enable-btn">${escapeHtml(t('profile.mfa_btn_enable'))}</button>
                    <button type="button" class="btn btn-ghost" data-action="profile-mfa-cancel" data-testid="profile-mfa-cancel-btn">${escapeHtml(t('profile.mfa_btn_cancel'))}</button>
                </div>`;
            return html + '</div>';
        }

        // Cargando estado inicial.
        if (mfaLoading || mfa === null) {
            html += `<p class="text-muted text-sm">${escapeHtml(t('profile.mfa_loading'))}</p>`;
            return html + '</div>';
        }

        // Sin MFA activo.
        if (!mfa.habilitado) {
            const pending = !!mfa.pending_setup;
            html += `
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="badge badge-neutral">${escapeHtml(t('profile.mfa_status_off'))}</span>
                    <button type="button" class="btn btn-primary" data-action="profile-mfa-setup" data-testid="profile-mfa-setup-btn">${escapeHtml(pending ? t('profile.mfa_btn_resume') : t('profile.mfa_btn_setup'))}</button>
                </div>`;
            return html + '</div>';
        }

        // MFA activo: informar y permitir desactivar con código.
        const remaining = mfa.backup_codes_remaining || 0;
        html += `
            <div class="mb-3 flex items-center gap-2 flex-wrap">
                <span class="badge badge-success">${escapeHtml(t('profile.mfa_status_on'))}</span>
                <span class="text-muted text-sm">${escapeHtml(t('profile.mfa_backup_remaining', { count: remaining }))}</span>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="profile-mfa-code-disable">${escapeHtml(t('profile.mfa_code_label'))}</label>
                <input type="text" id="profile-mfa-code-disable" name="mfa_code_disable" class="input w-full"
                    placeholder="123456" maxlength="10" autocomplete="one-time-code">
                <p class="field-error text-danger text-sm mt-1 hidden" data-field="mfa_code_disable"></p>
            </div>
            <button type="button" class="btn btn-danger mt-3" data-action="profile-mfa-disable" data-testid="profile-mfa-disable-btn">${escapeHtml(t('profile.mfa_btn_disable'))}</button>`;
        return html + '</div>';
    }

    /** Estado MFA del usuario autenticado (no lanza; guarda en mfa). */
    async function loadMfa() {
        mfaLoading = true;
        rerenderTabContent();
        try {
            const response = await global.HttpClient.get('/auth/mfa/status', { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            mfa = data || null;
        } catch (err) {
            console.error('[Profile] MFA status error:', err);
            mfa = null;
        }
        mfaLoading = false;
        rerenderTabContent();
    }

    async function mfaSetupAction() {
        if (mfaBusy) return;
        mfaBusy = true;
        mfaError = null;
        rerenderTabContent();
        try {
            const response = await global.HttpClient.post('/auth/mfa/setup', {}, { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            mfaSetup = { secret: data.secret, otpauth_url: data.otpauth_url };
        } catch (err) {
            mfaError = (err && err.message) || t('profile.mfa_error_setup');
        }
        mfaBusy = false;
        rerenderTabContent();
    }

    async function mfaEnableAction() {
        if (mfaBusy) return;
        const input = container.querySelector('[name="mfa_code_setup"]');
        const code = input ? input.value.trim() : '';
        if (!code) {
            showFieldError('mfa_code_setup', t('profile.mfa_code_required'));
            return;
        }
        mfaBusy = true;
        mfaError = null;
        rerenderTabContent();
        try {
            const response = await global.HttpClient.post('/auth/mfa/enable', { code: code }, { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            mfaBackupCodes = (data && data.backup_codes) || [];
            mfaSetup = null;
            await loadMfa();
        } catch (err) {
            mfaError = (err && err.message) || t('profile.mfa_error_enable');
        }
        mfaBusy = false;
        rerenderTabContent();
    }

    async function mfaDisableAction() {
        if (mfaBusy) return;
        const input = container.querySelector('[name="mfa_code_disable"]');
        const code = input ? input.value.trim() : '';
        if (!code) {
            showFieldError('mfa_code_disable', t('profile.mfa_code_required'));
            return;
        }
        mfaBusy = true;
        mfaError = null;
        rerenderTabContent();
        try {
            await global.HttpClient.post('/auth/mfa/disable', { code: code }, { requiresAuth: true });
            mfaBackupCodes = null;
            await loadMfa();
            showToast('success', t('profile.mfa_title'), t('profile.mfa_disabled_ok'));
        } catch (err) {
            mfaError = (err && err.message) || t('profile.mfa_error_disable');
        }
        mfaBusy = false;
        rerenderTabContent();
    }

    function mfaCancelSetup() {
        mfaSetup = null;
        mfaError = null;
        rerenderTabContent();
    }

    function mfaBackupDone() {
        mfaBackupCodes = null;
        rerenderTabContent();
    }

    // ====================================================================
    // TAB: PREFERENCIAS (idioma)
    // ====================================================================

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

    function renderPrefsTab() {
        return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-1">${escapeHtml(t('profile.section_language'))}</h3>
                <p class="text-muted text-sm mb-4">${escapeHtml(t('profile.language_body'))}</p>
                <select id="profile-lang-select" class="select">${langOptionsHtml()}</select>
            </div>
        `;
    }

    function handleLangChange(e) {
        const select = e.target;
        if (!select || select.id !== 'profile-lang-select') return;
        if (global.i18n && typeof global.i18n.setLocale === 'function') {
            global.i18n.setLocale(select.value);
        }
    }

    // ====================================================================
    // EVENTOS
    // ====================================================================

    function switchTab(tabId) {
        if (!TABS.some(function(tab) { return tab.id === tabId; })) return;
        activeTab = tabId;
        if (container) {
            const tabContent = container.querySelector('#profile-tab-content');
            if (tabContent) {
                tabContent.innerHTML = renderActiveTab();
            }
            // Actualizar clases de tabs
            container.querySelectorAll('.tab').forEach(function(btn) {
                const isActive = btn.getAttribute('data-tab') === tabId;
                btn.className = isActive ? 'tab is-active' : 'tab';
            });
            bindTabEvents();
            // Lazy-load del estado MFA al entrar en Seguridad
            if (tabId === 'security' && mfa === null && !mfaLoading) {
                loadMfa();
            }
        }
    }

    function bindTabEvents() {
        if (!container) return;

        // Tabs click
        container.querySelectorAll('.tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                switchTab(btn.getAttribute('data-tab'));
            });
        });

        // Tab personal: form submit + email change detection
        const form = container.querySelector('#profile-personal-form');
        if (form) {
            form.addEventListener('submit', handlePersonalSubmit);
            const emailInput = form.querySelector('#profile-email');
            if (emailInput) {
                emailInput.addEventListener('input', showPasswordSectionIfEmailChanged);
            }
        }

        // Tab security: password button
        const pwBtn = container.querySelector('#profile-change-password');
        if (pwBtn) {
            pwBtn.addEventListener('click', handleChangePasswordClick);
        }

        // Tab security: MFA buttons
        const mfaSetupBtn = container.querySelector('[data-action="profile-mfa-setup"]');
        if (mfaSetupBtn) {
            mfaSetupBtn.addEventListener('click', mfaSetupAction);
        }
        const mfaEnableBtn = container.querySelector('[data-action="profile-mfa-enable"]');
        if (mfaEnableBtn) {
            mfaEnableBtn.addEventListener('click', mfaEnableAction);
        }
        const mfaDisableBtn = container.querySelector('[data-action="profile-mfa-disable"]');
        if (mfaDisableBtn) {
            mfaDisableBtn.addEventListener('click', mfaDisableAction);
        }
        const mfaCancelBtn = container.querySelector('[data-action="profile-mfa-cancel"]');
        if (mfaCancelBtn) {
            mfaCancelBtn.addEventListener('click', mfaCancelSetup);
        }
        const mfaBackupBtn = container.querySelector('[data-action="profile-mfa-backup-done"]');
        if (mfaBackupBtn) {
            mfaBackupBtn.addEventListener('click', mfaBackupDone);
        }

        // Tab prefs: language change
        const langSelect = container.querySelector('#profile-lang-select');
        if (langSelect) {
            langSelect.addEventListener('change', handleLangChange);
        }
    }

    function onLocaleChanged() {
        if (!container) return;
        // Re-renderizar el tab activo con el nuevo idioma
        const tabContent = container.querySelector('#profile-tab-content');
        if (tabContent) {
            tabContent.innerHTML = renderActiveTab();
            bindTabEvents();
        }
        // Actualizar labels de tabs
        container.querySelectorAll('.tab').forEach(function(btn) {
            const tabId = btn.getAttribute('data-tab');
            const tab = TABS.find(function(t) { return t.id === tabId; });
            if (tab) {
                btn.textContent = t(tab.labelKey);
            }
        });
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const ProfileProfileModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            container.innerHTML = buildTemplate();
            bindTabEvents();

            if (global.EventBus && typeof global.EventBus.on === 'function') {
                global.EventBus.on('i18n:changed', onLocaleChanged);
            }
        },

        onActivate() {
            if (!container) return;
            if (!container.querySelector('.tabs')) {
                container.innerHTML = buildTemplate();
                bindTabEvents();
            }
        },

        destroy() {
            if (global.EventBus && typeof global.EventBus.off === 'function') {
                global.EventBus.off('i18n:changed', onLocaleChanged);
            }
            if (container) {
                container.innerHTML = '';
            }
            container = null;
            activeTab = 'personal';
            saving = false;
            mfa = null;
            mfaSetup = null;
            mfaBackupCodes = null;
            mfaBusy = false;
            mfaError = null;
            mfaLoading = false;
        }
    };

    global.ProfileProfileModule = ProfileProfileModule;

})(window);
