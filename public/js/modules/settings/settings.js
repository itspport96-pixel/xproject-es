/**
 * Settings Module (v1.13.58)
 * Vista de configuracion del tenant (solo lectura).
 *
 * Tabs:
 * - general: nombre interno, id, estado, plan, configuracion, version
 * - localizacion: pais, zona horaria, idioma por defecto, logo, dominio
 * - seguridad: politica de contrasenas (hueco: la API no la expone aun)
 * - correo: SMTP (hueco: sin endpoint ni datos de configuracion)
 * - resumen: contadores de GET /api/tenant/stats
 *
 * Datos: GET /api/tenant (requiere tenants.tenant.view) y
 * GET /api/tenant/stats. nombre_publico NO se muestra: es ciphertext
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
        error: null
    };

    const ESTADOS_TENANT = [
        { value: 'ACTIVO', color: 'success' },
        { value: 'PENDIENTE_ACTIVACION', color: 'warning' },
        { value: 'SUSPENDIDO', color: 'danger' }
    ];

    const EMPTY = '—';

    // ====================================================================
    // PERMISOS
    // ====================================================================

    const canView = () => UI.can('tenants.tenant.view', ['ADMIN']);

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
        return `
            <div class="card p-4">
                <h3 class="text-lg font-semibold mb-4">${escapeHtml(t('settings.section_identidad'))}</h3>
                <div class="grid gap-4 md:grid-cols-2">
                    ${labelValue(t('settings.label_nombre_interno'), tenant.nombre_interno)}
                    ${labelValue(t('settings.label_id'), tenant.id)}
                    <div>
                        <div class="text-xs text-muted mb-1">${escapeHtml(t('settings.label_estado'))}</div>
                        <div>${UI.estadoBadge(tenant.estado, ESTADOS_TENANT)}</div>
                    </div>
                    ${labelValue(t('settings.label_plan'), tenant.plan)}
                    ${labelValue(t('settings.label_configuracion'), tenant.configuracion == null ? t('settings.configuracion_null') : JSON.stringify(tenant.configuracion))}
                    ${labelValue(t('settings.label_version'), state.version)}
                </div>
            </div>`;
    }

    function renderLocalizacionPanel() {
        if (state.loading) return UI.loadingState();
        if (state.error) return UI.emptyState({ title: t('settings.load_error_title'), message: state.error });

        const tenant = state.tenant || {};
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
        }
    };

    global.SettingsSettingsModule = SettingsSettingsModule;

})(window);
