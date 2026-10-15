/**
 * Settings Module (v1.13.59)
 * Configuracion del tenant: lectura y edicion inline (sin modales).
 *
 * Tabs:
 * - general: identidad + formulario de nombre_interno (con tenants.tenant.edit)
 * - localizacion: pais, zona horaria, idioma, logo, dominio (edicion inline)
 * - seguridad: politica de contrasenas (hueco: la API no la expone aun)
 * - correo: SMTP (hueco: sin endpoint ni datos de configuracion)
 * - resumen: contadores de GET /api/tenant/stats
 *
 * Datos: GET /api/tenant (tenants.tenant.view) y GET /api/tenant/stats.
 * Edicion: PATCH /api/tenant (tenants.tenant.edit) solo con los campos
 * cambiados; nombre_interno valida slug en cliente (mismo regex que la
 * entidad). nombre_publico NO se muestra ni se edita: es ciphertext
 * residual (documentado en CHANGELOG 1.13.57).
 *
 * Ruta 'settings': permiso tenants.tenant.view (fallback rol ADMIN).
 * Convencion ModuleLoader: window.SettingsSettingsModule (ruta settings/settings).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    const TABS = ['general', 'localizacion', 'seguridad', 'correo', 'resumen'];

    let state = {
        tab: 'general',
        tenant: null,
        version: '',
        stats: null,
        loading: true,
        statsLoading: true,
        error: null,
        saving: false
    };

    const ESTADOS_TENANT = [
        { value: 'ACTIVO', color: 'success' },
        { value: 'PENDIENTE_ACTIVACION', color: 'warning' },
        { value: 'SUSPENDIDO', color: 'danger' }
    ];

    const EMPTY = '—';

    // Mismo contrato que la entidad backend (Tenant::validateNombreInterno)
    const SLUG_RE = /^[a-z0-9][a-z0-9_\-]*[a-z0-9]$/;
    const MAX_NOMBRE_INTERNO = 255;

    // ====================================================================
    // PERMISOS
    // ====================================================================

    const canView = () => UI.can('tenants.tenant.view', ['ADMIN']);
    const canEdit = () => UI.can('tenants.tenant.edit', ['ADMIN']);

    // ====================================================================
    // HELPERS
    // ====================================================================

    function escapeHtml(str) {
        return UI.escapeHtml(str == null ? '' : str);
    }

    function fmt(value) {
        if (value === null || value === undefined || value === '') return EMPTY;
        return String(value);
    }

    function showToast(type, title, message) {
        if (global.UI && typeof global.UI.toast === 'function') {
            global.UI.toast(type, title, message || '');
        }
    }

    // ====================================================================
    // TEMPLATE
    // ====================================================================

    function labelValue(label, value) {
        return `
            <div>
                <div class="text-xs text-muted mb-1">${escapeHtml(label)}</div>
                <div data-field-value>${escapeHtml(fmt(value))}</div>
            </div>`;
    }

    function fieldError(field) {
        return `<p class="field-error text-danger text-sm mt-1 hidden" data-field="${escapeHtml(field)}"></p>`;
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

    function renderGeneralPanel() {
        if (state.loading) return UI.loadingState();
        if (state.error) {
            return UI.errorState({
                title: t('settings.load_error_title'),
                message: state.error,
                retryAction: 'settings-retry',
                retryLabel: t('ui.retry')
            });
        }

        const tenant = state.tenant || {};
        const identidad = `
                <div class="grid gap-4 md:grid-cols-2">
                    ${labelValue(t('settings.label_id'), tenant.id)}
                    <div>
                        <div class="text-xs text-muted mb-1">${escapeHtml(t('settings.label_estado'))}</div>
                        <div>${UI.estadoBadge(tenant.estado, ESTADOS_TENANT)}</div>
                    </div>
                    ${labelValue(t('settings.label_plan'), tenant.plan)}
                    ${labelValue(t('settings.label_configuracion'), tenant.configuracion == null ? t('settings.configuracion_null') : JSON.stringify(tenant.configuracion))}
                    ${labelValue(t('settings.label_version'), state.version)}
                </div>`;

        if (!canEdit()) {
            return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-4">${escapeHtml(t('settings.section_identidad'))}</h3>
                <div class="grid gap-4 md:grid-cols-2 mb-4">
                    ${labelValue(t('settings.label_nombre_interno'), tenant.nombre_interno)}
                </div>
                ${identidad}
            </div>`;
        }

        return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-4">${escapeHtml(t('settings.section_identidad'))}</h3>
                <form id="settings-general-form" novalidate>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium mb-1" for="settings-nombre-interno">${escapeHtml(t('settings.label_nombre_interno'))}</label>
                            <input type="text" id="settings-nombre-interno" name="nombre_interno" class="input w-full"
                                value="${escapeHtml(tenant.nombre_interno || '')}"
                                placeholder="${escapeHtml(t('settings.placeholder_nombre_interno'))}"
                                maxlength="${MAX_NOMBRE_INTERNO}" required>
                            <p class="text-muted text-xs mt-1">${escapeHtml(t('settings.help_nombre_interno'))}</p>
                            ${fieldError('nombre_interno')}
                        </div>
                    </div>
                    ${identidad}
                    ${UI.formFooter({ submitLabel: t('settings.btn_save'), submitAction: 'settings-save-general' })}
                </form>
            </div>`;
    }

    function renderLocalizacionPanel() {
        if (state.loading) return UI.loadingState();
        if (state.error) return UI.emptyState({ title: t('settings.load_error_title'), message: state.error });

        const tenant = state.tenant || {};

        if (!canEdit()) {
            return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-4">${escapeHtml(t('settings.section_localizacion'))}</h3>
                <div class="grid gap-4 md:grid-cols-2">
                    ${labelValue(t('settings.label_pais'), tenant.pais)}
                    ${labelValue(t('settings.label_zona_horaria'), tenant.zona_horaria)}
                    ${labelValue(t('settings.label_idioma_default'), tenant.idioma_default)}
                    ${labelValue(t('settings.label_logo_url'), tenant.logo_url)}
                    ${labelValue(t('settings.label_dominio_custom'), tenant.dominio_custom)}
                </div>
            </div>`;
        }

        return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-4">${escapeHtml(t('settings.section_localizacion'))}</h3>
                <form id="settings-local-form" novalidate>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium mb-1" for="settings-pais">${escapeHtml(t('settings.label_pais'))}</label>
                            <input type="text" id="settings-pais" name="pais" class="input w-full"
                                value="${escapeHtml(tenant.pais || '')}" maxlength="100">
                            ${fieldError('pais')}
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="settings-zona-horaria">${escapeHtml(t('settings.label_zona_horaria'))}</label>
                            <input type="text" id="settings-zona-horaria" name="zona_horaria" class="input w-full"
                                value="${escapeHtml(tenant.zona_horaria || '')}" maxlength="64">
                            ${fieldError('zona_horaria')}
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="settings-idioma">${escapeHtml(t('settings.label_idioma_default'))}</label>
                            <select id="settings-idioma" name="idioma_default" class="select w-full">
                                <option value="es"${tenant.idioma_default === 'es' ? ' selected' : ''}>${escapeHtml(t('profile.lang.es'))}</option>
                                <option value="en"${tenant.idioma_default === 'en' ? ' selected' : ''}>${escapeHtml(t('profile.lang.en'))}</option>
                                <option value="pt"${tenant.idioma_default === 'pt' ? ' selected' : ''}>${escapeHtml(t('profile.lang.pt'))}</option>
                                <option value="fr"${tenant.idioma_default === 'fr' ? ' selected' : ''}>${escapeHtml(t('profile.lang.fr'))}</option>
                            </select>
                            ${fieldError('idioma_default')}
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="settings-logo-url">${escapeHtml(t('settings.label_logo_url'))}</label>
                            <input type="url" id="settings-logo-url" name="logo_url" class="input w-full"
                                value="${escapeHtml(tenant.logo_url || '')}" placeholder="https://..." maxlength="500">
                            ${fieldError('logo_url')}
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="settings-dominio">${escapeHtml(t('settings.label_dominio_custom'))}</label>
                            <input type="text" id="settings-dominio" name="dominio_custom" class="input w-full"
                                value="${escapeHtml(tenant.dominio_custom || '')}" placeholder="ej. acme.example.com" maxlength="255">
                            <p class="text-muted text-xs mt-1">${escapeHtml(t('settings.help_dominio_custom'))}</p>
                            ${fieldError('dominio_custom')}
                        </div>
                    </div>
                    ${UI.formFooter({ submitLabel: t('settings.btn_save'), submitAction: 'settings-save-local' })}
                </form>
            </div>`;
    }

    function renderSeguridadPanel() {
        return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-1">${escapeHtml(t('settings.section_seguridad'))}</h3>
                <p class="text-muted text-sm mb-4">${escapeHtml(t('settings.seguridad_body'))}</p>
                ${UI.emptyState({
                    title: t('settings.hueco_title'),
                    message: t('settings.hueco_password_policy')
                })}
            </div>`;
    }

    function renderCorreoPanel() {
        return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-1">${escapeHtml(t('settings.section_correo'))}</h3>
                <p class="text-muted text-sm mb-4">${escapeHtml(t('settings.correo_body'))}</p>
                ${UI.emptyState({
                    title: t('settings.hueco_title'),
                    message: t('settings.hueco_smtp')
                })}
            </div>`;
    }

    function renderResumenPanel() {
        if (state.statsLoading) return UI.cardsSkeleton(6);
        if (!state.stats) {
            return UI.errorState({
                title: t('settings.load_error_title'),
                message: t('settings.stats_error'),
                retryAction: 'settings-retry',
                retryLabel: t('ui.retry')
            });
        }

        return '<div class="grid grid-cols-2 md:grid-cols-3 gap-3" data-settings-stats></div>';
    }

    function statsCards() {
        return [
            { label: t('settings.stats.usuarios'), value: state.stats.usuarios },
            { label: t('settings.stats.usuarios_activos'), value: state.stats.usuarios_activos },
            { label: t('settings.stats.roles_asignados'), value: state.stats.roles_asignados },
            { label: t('settings.stats.productos'), value: state.stats.productos },
            { label: t('settings.stats.ubicaciones'), value: state.stats.ubicaciones },
            { label: t('settings.stats.unidades_fisicas'), value: state.stats.unidades_fisicas }
        ];
    }

    function buildTemplate() {
        const panels = [
            { id: 'general', html: renderGeneralPanel() },
            { id: 'localizacion', html: renderLocalizacionPanel() },
            { id: 'seguridad', html: renderSeguridadPanel() },
            { id: 'correo', html: renderCorreoPanel() },
            { id: 'resumen', html: renderResumenPanel() }
        ];

        return UI.viewShell({
            title: t('settings.title'),
            subtitle: t('settings.subtitle'),
            tabs: [
                { id: 'general', label: t('settings.tab_general') },
                { id: 'localizacion', label: t('settings.tab_localizacion') },
                { id: 'seguridad', label: t('settings.tab_seguridad') },
                { id: 'correo', label: t('settings.tab_correo') },
                { id: 'resumen', label: t('settings.tab_resumen') }
            ],
            activeTab: state.tab,
            panels: panels
        });
    }

    function render() {
        if (!container) return;
        container.innerHTML = buildTemplate();
        if (state.stats) {
            const root = container.querySelector('[data-settings-stats]');
            UI.summaryCards(root, statsCards());
        }
    }

    // ====================================================================
    // CARGA DE DATOS
    // ====================================================================

    async function loadData() {
        state.loading = true;
        state.statsLoading = true;
        state.error = null;
        render();

        const tenantPromise = global.HttpClient.get('/tenant', { requiresAuth: true })
            .then(function(response) {
                const data = response && response.data ? response.data : response;
                state.tenant = (data && data.tenant) || null;
                state.version = (data && data.plataforma_version) || '';
            });

        const statsPromise = global.HttpClient.get('/tenant/stats', { requiresAuth: true })
            .then(function(response) {
                const data = response && response.data ? response.data : response;
                state.stats = (data && data.stats) || null;
            })
            .catch(function() {
                state.stats = null;
            })
            .finally(function() {
                state.statsLoading = false;
            });

        try {
            await tenantPromise;
            state.loading = false;
        } catch (err) {
            console.error('[Settings] Error loading tenant:', err);
            state.loading = false;
            state.error = err && err.message ? err.message : t('settings.load_error_body');
        }

        await statsPromise;
        render();
    }

    // ====================================================================
    // EVENTOS
    // ====================================================================

    function switchTab(tab) {
        if (TABS.indexOf(tab) === -1) return;
        state.tab = tab;
        UI.applyTabState(container, tab);
        UI.syncTabParam(tab);
        if (tab === 'resumen') {
            render();
        }
    }

    function handleClick(e) {
        const tabBtn = e.target.closest('[data-action="tab"]');
        if (tabBtn) {
            switchTab(tabBtn.getAttribute('data-tab'));
            return;
        }
        const retry = e.target.closest('[data-action="settings-retry"]');
        if (retry) {
            loadData();
        }
    }

    // ====================================================================
    // EDICION (PATCH /api/tenant)
    // ====================================================================

    function validateNombreInterno(value) {
        const nombre = String(value == null ? '' : value).trim();
        if (nombre === '') return t('settings.field_required');
        if (nombre.length > MAX_NOMBRE_INTERNO) return t('settings.nombre_interno_max_length');
        if (nombre.length > 1 && !SLUG_RE.test(nombre)) return t('settings.nombre_interno_invalid');
        return null;
    }

    function validateLogoUrl(value) {
        const url = String(value == null ? '' : value).trim();
        if (url === '') return null;
        if (!/^https?:\/\/.+/.test(url)) return t('settings.logo_url_invalid');
        return null;
    }

    /** Solo los campos que cambiaron respecto al tenant cargado. */
    function diffFields(form, tenant, fields) {
        const payload = {};
        fields.forEach(function(name) {
            const el = form.querySelector('[name="' + name + '"]');
            if (!el) return;
            let value = el.value;
            if (value !== null && typeof value === 'string') value = value.trim();
            if (name === 'dominio_custom' && value === '') value = null;
            if (name === 'logo_url' && value === '') value = null;
            const current = tenant[name] == null ? null : tenant[name];
            if (String(value == null ? '' : value) !== String(current == null ? '' : current)) {
                payload[name] = value;
            }
        });
        return payload;
    }

    async function saveForm(form, fields) {
        if (state.saving || !state.tenant) return;

        clearFieldErrors();

        let errors = null;
        if (form.querySelector('[name="nombre_interno"]')) {
            const el = form.querySelector('[name="nombre_interno"]');
            errors = validateNombreInterno(el.value);
            if (errors) {
                showFieldError('nombre_interno', errors);
                return;
            }
        }
        if (form.querySelector('[name="logo_url"]')) {
            const el = form.querySelector('[name="logo_url"]');
            const logoError = validateLogoUrl(el.value);
            if (logoError) {
                showFieldError('logo_url', logoError);
                return;
            }
        }

        const payload = diffFields(form, state.tenant, fields);
        if (Object.keys(payload).length === 0) {
            showToast('info', t('settings.title'), t('settings.error_nothing_changed'));
            return;
        }

        const submitBtn = form.querySelector('[type="submit"]');
        state.saving = true;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = t('settings.btn_saving');
        }

        try {
            const response = await global.HttpClient.patch('/tenant', payload, { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            if (data && data.tenant) {
                state.tenant = data.tenant;
            }
            showToast('success', t('settings.title'), t('settings.success_update'));
            render();
        } catch (err) {
            console.error('[Settings] Error updating tenant:', err);
            const errorMsg = err && err.message ? err.message : t('settings.update_error');
            const errorField = err && err.data && err.data.field;
            if (errorField) {
                showFieldError(errorField, errorMsg);
            } else {
                showToast('danger', t('settings.update_error'), errorMsg);
            }
        } finally {
            state.saving = false;
        }
    }

    function handleSubmit(e) {
        const form = e.target;
        if (form.id === 'settings-general-form') {
            e.preventDefault();
            saveForm(form, ['nombre_interno']);
        } else if (form.id === 'settings-local-form') {
            e.preventDefault();
            saveForm(form, ['pais', 'zona_horaria', 'idioma_default', 'logo_url', 'dominio_custom']);
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const SettingsSettingsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                container.innerHTML = UI.deniedState({
                    title: t('settings.permission.title'),
                    message: t('settings.permission.body', { permiso: 'tenants.tenant.view' })
                });
                return;
            }

            state.tab = UI.tabFromParams(params, TABS, 'general');
            render();
            container.addEventListener('click', handleClick);
            container.addEventListener('submit', handleSubmit);
            loadData();
        },

        onActivate(params) {
            if (!container) return;

            if (!canView()) {
                container.innerHTML = UI.deniedState({
                    title: t('settings.permission.title'),
                    message: t('settings.permission.body', { permiso: 'tenants.tenant.view' })
                });
                return;
            }

            if (!container.querySelector('[data-tabs]')) {
                container.addEventListener('click', handleClick);
                container.addEventListener('submit', handleSubmit);
            }

            const tab = UI.tabFromParams(params, TABS, state.tab);
            if (tab !== state.tab) {
                state.tab = tab;
                UI.applyTabState(container, tab);
            }
            loadData();
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('submit', handleSubmit);
                container.innerHTML = '';
            }
            container = null;
            state.tab = 'general';
            state.tenant = null;
            state.version = '';
            state.stats = null;
            state.loading = true;
            state.statsLoading = true;
            state.error = null;
            state.saving = false;
        }
    };

    global.SettingsSettingsModule = SettingsSettingsModule;

})(window);
