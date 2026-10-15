/**
 * Settings Module (v1.14.0)
 * Configuracion del tenant: lectura y edicion inline (sin modales).
 *
 * Tabs:
 * - perfil: nombre publico, correo de contacto, telefono, direccion y logo
 *   (subida POST /api/tenant/logo o URL externa)
 * - apariencia: color principal (paleta + selector libre) con vista previa
 * - localizacion: preferencias regionales (pais, zona horaria, idioma,
 *   moneda y formato de fecha)
 * - general: identidad tecnica (nombre_interno, dominio, estado, plan)
 * - seguridad / correo: huecos declarados (sin endpoint)
 * - resumen: contadores de GET /api/tenant/stats
 *
 * Datos: GET /api/tenant (tenants.tenant.view) y GET /api/tenant/stats.
 * Edicion: PATCH /api/tenant (tenants.tenant.edit) solo con los campos
 * cambiados. direccion, color, moneda y formato de fecha viven en
 * tenants.configuracion (JSON): {perfil:{direccion}, apariencia:
 * {color_primario}, regional:{moneda, formato_fecha}}; el PATCH envia el
 * objeto completo fusionado (el backend lo reemplaza entero).
 * Tras guardar se refresca TenantBranding (color/logo/formatos en vivo).
 *
 * Ruta 'settings': permiso tenants.tenant.view (fallback rol ADMIN).
 * Convencion ModuleLoader: window.SettingsSettingsModule (ruta settings/settings).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    const TABS = ['perfil', 'apariencia', 'localizacion', 'general', 'seguridad', 'correo', 'resumen'];
    const DEFAULT_TAB = 'perfil';

    let state = {
        tab: DEFAULT_TAB,
        tenant: null,
        version: '',
        stats: null,
        loading: true,
        statsLoading: true,
        error: null,
        saving: false,
        uploading: false,
        color: null
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
    const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    const PHONE_RE = /^\+?[0-9][0-9 ().\-]{5,29}$/;
    const HEX_RE = /^#[0-9a-fA-F]{6}$/;
    const LOGO_MAX_BYTES = 819200;
    const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    // Mismo catalogo que TenantService::FORMATOS_FECHA
    const DATE_FORMATS = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD', 'DD-MM-YYYY', 'DD.MM.YYYY'];
    const CURRENCIES = ['MXN', 'USD', 'EUR', 'COP', 'ARS', 'CLP', 'PEN', 'BRL', 'GTQ', 'CRC', 'UYU', 'BOB', 'PYG', 'DOP', 'GBP', 'CAD'];
    const currencyLabels = () => ({
        MXN: t('settings.currency.MXN'),
        USD: t('settings.currency.USD'),
        EUR: t('settings.currency.EUR'),
        COP: t('settings.currency.COP'),
        ARS: t('settings.currency.ARS'),
        CLP: t('settings.currency.CLP'),
        PEN: t('settings.currency.PEN'),
        BRL: t('settings.currency.BRL'),
        GTQ: t('settings.currency.GTQ'),
        CRC: t('settings.currency.CRC'),
        UYU: t('settings.currency.UYU'),
        BOB: t('settings.currency.BOB'),
        PYG: t('settings.currency.PYG'),
        DOP: t('settings.currency.DOP'),
        GBP: t('settings.currency.GBP'),
        CAD: t('settings.currency.CAD')
    });
    const COLOR_PRESETS = [
        { hex: '#0066D6', id: 'azul', label: () => t('settings.color.azul') },
        { hex: '#5856D6', id: 'indigo', label: () => t('settings.color.indigo') },
        { hex: '#AF52DE', id: 'morado', label: () => t('settings.color.morado') },
        { hex: '#D70015', id: 'rojo', label: () => t('settings.color.rojo') },
        { hex: '#C93400', id: 'naranja', label: () => t('settings.color.naranja') },
        { hex: '#248A3D', id: 'verde', label: () => t('settings.color.verde') },
        { hex: '#0E7C86', id: 'turquesa', label: () => t('settings.color.turquesa') },
        { hex: '#3A3A3C', id: 'grafito', label: () => t('settings.color.grafito') }
    ];
    const FALLBACK_TIMEZONES = [
        'America/Mexico_City', 'America/Bogota', 'America/Lima', 'America/Santiago',
        'America/Argentina/Buenos_Aires', 'America/Sao_Paulo', 'America/Caracas',
        'America/Guatemala', 'America/New_York', 'America/Los_Angeles', 'Europe/Madrid',
        'Europe/London', 'UTC'
    ];

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

    function config() {
        const c = state.tenant && state.tenant.configuracion;
        return c && typeof c === 'object' && !Array.isArray(c) ? c : {};
    }

    function section(name) {
        const s = config()[name];
        return s && typeof s === 'object' ? s : {};
    }

    function timezones() {
        let list = FALLBACK_TIMEZONES;
        try {
            if (typeof Intl.supportedValuesOf === 'function') list = Intl.supportedValuesOf('timeZone');
        } catch (e) { /* navegador sin soporte: lista corta */ }
        const actual = state.tenant && state.tenant.zona_horaria;
        return actual && list.indexOf(actual) === -1 ? [actual].concat(list) : list;
    }

    function brandingFromTenant(tenant) {
        const c = (tenant && tenant.configuracion) || {};
        return {
            nombre_publico: tenant.nombre_publico,
            logo_url: tenant.logo_url,
            zona_horaria: tenant.zona_horaria,
            idioma_default: tenant.idioma_default,
            apariencia: c.apariencia || null,
            regional: c.regional || null
        };
    }

    function syncBranding() {
        if (global.TenantBranding && state.tenant) {
            global.TenantBranding.set(brandingFromTenant(state.tenant));
        }
    }

    // ====================================================================
    // TEMPLATE: piezas comunes
    // ====================================================================

    function labelValue(label, value) {
        return `
            <div>
                <div class="text-xs text-muted mb-1">${escapeHtml(label)}</div>
                <div data-field-value>${escapeHtml(fmt(value))}</div>
            </div>`;
    }

    function fieldError(field) {
        return `<p class="field-error text-danger text-sm mt-1 hidden" data-field="${escapeHtml(field)}" data-testid="settings-error-${escapeHtml(field.replace(/\./g, '-'))}"></p>`;
    }

    function inputField(o) {
        const id = 'settings-' + o.name.replace(/[._]/g, '-');
        const control = o.type === 'textarea'
            ? `<textarea id="${id}" name="${o.name}" class="input textarea w-full" rows="3" maxlength="${o.maxlength || 500}"
                   placeholder="${escapeHtml(o.placeholder || '')}" data-testid="${id}-input">${escapeHtml(o.value || '')}</textarea>`
            : `<input type="${o.type || 'text'}" id="${id}" name="${o.name}" class="input w-full"
                   value="${escapeHtml(o.value || '')}" placeholder="${escapeHtml(o.placeholder || '')}"
                   maxlength="${o.maxlength || 255}"${o.required ? ' required' : ''}${o.autocomplete ? ` autocomplete="${o.autocomplete}"` : ''} data-testid="${id}-input">`;
        return `
            <div class="${o.wide ? 'md:col-span-2' : ''}">
                <label class="block text-sm font-medium mb-1" for="${id}">${escapeHtml(o.label)}${o.required ? ' <span class="text-danger">*</span>' : ''}</label>
                ${control}
                ${o.help ? `<p class="text-muted text-xs mt-1">${escapeHtml(o.help)}</p>` : ''}
                ${fieldError(o.name)}
            </div>`;
    }

    function selectField(o) {
        const id = 'settings-' + o.name.replace(/[._]/g, '-');
        const options = (o.empty ? `<option value="">${escapeHtml(o.empty)}</option>` : '') + o.options.map(op =>
            `<option value="${escapeHtml(op.value)}"${op.value === o.value ? ' selected' : ''}>${escapeHtml(op.label)}</option>`
        ).join('');
        return `
            <div>
                <label class="block text-sm font-medium mb-1" for="${id}">${escapeHtml(o.label)}</label>
                <select id="${id}" name="${o.name}" class="select w-full" data-testid="${id}-select">${options}</select>
                ${o.help ? `<p class="text-muted text-xs mt-1">${escapeHtml(o.help)}</p>` : ''}
                ${fieldError(o.name)}
            </div>`;
    }

    function group(title, body, foot) {
        return `
            <section class="settings-section">
                <h3 class="settings-section-title">${escapeHtml(title)}</h3>
                <div class="card p-4">${body}</div>
                ${foot ? `<p class="settings-section-foot">${escapeHtml(foot)}</p>` : ''}
            </section>`;
    }

    function guardState() {
        if (state.loading) return UI.loadingState();
        if (state.error) {
            return UI.errorState({
                title: t('settings.load_error_title'),
                message: state.error,
                retryAction: 'settings-retry',
                retryLabel: t('ui.retry')
            });
        }
        return null;
    }

    // ====================================================================
    // TEMPLATE: Perfil
    // ====================================================================

    function renderLogoBox(tenant) {
        const src = global.TenantBranding ? global.TenantBranding.logoSrc(tenant.logo_url) : tenant.logo_url;
        const preview = src
            ? `<img src="${escapeHtml(src)}" alt="${escapeHtml(t('settings.logo_alt'))}" data-testid="settings-logo-preview-img">`
            : `<span class="text-xs">${escapeHtml(t('settings.logo_none'))}</span>`;
        if (!canEdit()) {
            return `<div class="settings-logo-box"><div class="settings-logo-preview">${preview}</div></div>`;
        }
        return `
            <div class="settings-logo-box">
                <div class="settings-logo-preview" data-testid="settings-logo-preview">${preview}</div>
                <div class="flex flex-col gap-2">
                    <div class="flex gap-2 flex-wrap">
                        <label class="btn btn-secondary btn-sm${state.uploading ? ' is-loading' : ''}" data-testid="settings-logo-upload-btn">
                            ${escapeHtml(state.uploading ? t('settings.logo_uploading') : t('settings.logo_upload'))}
                            <input type="file" class="sr-only" name="logo_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-testid="settings-logo-file-input">
                        </label>
                        ${tenant.logo_url ? `<button type="button" class="btn btn-ghost btn-sm text-danger" data-action="settings-logo-remove" data-testid="settings-logo-remove-btn">${escapeHtml(t('settings.logo_remove'))}</button>` : ''}
                    </div>
                    <p class="text-muted text-xs">${escapeHtml(t('settings.logo_help'))}</p>
                    ${fieldError('logo_file')}
                </div>
            </div>`;
    }

    function renderPerfilPanel() {
        const guard = guardState();
        if (guard) return guard;
        const tenant = state.tenant || {};
        const direccion = section('perfil').direccion || '';

        if (!canEdit()) {
            return group(t('settings.section_perfil'), `
                <div class="grid gap-4 md:grid-cols-2">
                    ${labelValue(t('settings.label_nombre_publico'), tenant.nombre_publico)}
                    ${labelValue(t('settings.label_email_contacto'), tenant.email_contacto)}
                    ${labelValue(t('settings.label_telefono'), tenant.telefono)}
                    ${labelValue(t('settings.label_direccion'), direccion)}
                </div>`) + group(t('settings.section_logo'), renderLogoBox(tenant));
        }

        return `
            <form id="settings-profile-form" novalidate data-testid="settings-profile-form">
                ${group(t('settings.section_perfil'), `
                    <div class="grid gap-4 md:grid-cols-2">
                        ${inputField({ name: 'nombre_publico', label: t('settings.label_nombre_publico'), value: tenant.nombre_publico, required: true, placeholder: t('settings.placeholder_nombre_publico'), autocomplete: 'organization' })}
                        ${inputField({ name: 'email_contacto', type: 'email', label: t('settings.label_email_contacto'), value: tenant.email_contacto, placeholder: 'contacto@empresa.com', help: t('settings.help_email_contacto'), autocomplete: 'email' })}
                        ${inputField({ name: 'telefono', type: 'tel', label: t('settings.label_telefono'), value: tenant.telefono, placeholder: '+52 55 1234 5678', maxlength: 30, autocomplete: 'tel' })}
                        ${inputField({ name: 'perfil.direccion', type: 'textarea', label: t('settings.label_direccion'), value: direccion, placeholder: t('settings.placeholder_direccion'), maxlength: 500, wide: true })}
                    </div>
                    ${UI.formFooter({ submitLabel: t('settings.btn_save'), submitAction: 'settings-save-profile' })}
                `, t('settings.foot_perfil'))}
            </form>
            ${group(t('settings.section_logo'), `
                ${renderLogoBox(tenant)}
                <form id="settings-logo-url-form" class="mt-4" novalidate data-testid="settings-logo-url-form">
                    <div class="grid gap-4 md:grid-cols-2">
                        ${inputField({ name: 'logo_url', type: 'url', label: t('settings.label_logo_url'), value: /^https?:/i.test(tenant.logo_url || '') ? tenant.logo_url : '', placeholder: 'https://...', help: t('settings.help_logo_url') })}
                    </div>
                    ${UI.formFooter({ submitLabel: t('settings.btn_save_url'), submitAction: 'settings-save-logo-url' })}
                </form>`)}`;
    }

    // ====================================================================
    // TEMPLATE: Apariencia
    // ====================================================================

    function currentColor() {
        return state.color || section('apariencia').color_primario || null;
    }

    function renderSwatches(selected) {
        return COLOR_PRESETS.map(p => `
            <button type="button" class="color-swatch${selected && selected.toUpperCase() === p.hex ? ' is-selected' : ''}"
                style="background-color:${p.hex}" data-action="settings-pick-color" data-color="${p.hex}"
                aria-label="${escapeHtml(p.label())}" title="${escapeHtml(p.label())}"
                aria-pressed="${selected && selected.toUpperCase() === p.hex ? 'true' : 'false'}"
                data-testid="settings-color-swatch-${p.id}"${canEdit() ? '' : ' disabled'}></button>`).join('');
    }

    function renderPreview() {
        const tenant = state.tenant || {};
        const name = tenant.nombre_publico || (global.AppConfig && global.AppConfig.appName) || '';
        return `
            <div class="brand-preview" data-testid="settings-brand-preview">
                <div class="brand-preview-bar bg-primary" style="color: rgb(var(--color-primary-foreground))">${escapeHtml(name)}</div>
                <div class="flex gap-2 flex-wrap items-center">
                    <span class="btn btn-primary btn-sm" aria-hidden="true">${escapeHtml(t('settings.preview_primary'))}</span>
                    <span class="btn btn-secondary btn-sm" aria-hidden="true">${escapeHtml(t('settings.preview_secondary'))}</span>
                    <span class="badge badge-info">${escapeHtml(t('settings.preview_badge'))}</span>
                    <span class="text-primary text-sm font-medium">${escapeHtml(t('settings.preview_link'))}</span>
                </div>
            </div>`;
    }

    function renderAparienciaPanel() {
        const guard = guardState();
        if (guard) return guard;
        const color = currentColor();
        const body = `
            <form id="settings-appearance-form" novalidate data-testid="settings-appearance-form">
                <div class="grid gap-6 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium mb-2">${escapeHtml(t('settings.label_color_primario'))}</label>
                        <div class="color-swatches" role="group" aria-label="${escapeHtml(t('settings.label_color_primario'))}">${renderSwatches(color)}</div>
                        <div class="color-custom mt-4">
                            <input type="color" name="color_custom" value="${escapeHtml(color || '#0066D6')}" aria-label="${escapeHtml(t('settings.color_custom'))}" data-testid="settings-color-custom-input"${canEdit() ? '' : ' disabled'}>
                            <span class="text-sm text-muted">${escapeHtml(t('settings.color_custom'))}</span>
                            <code data-color-code data-testid="settings-color-code">${escapeHtml(color || t('settings.color_default'))}</code>
                        </div>
                        <p class="text-muted text-xs mt-3">${escapeHtml(t('settings.help_color'))}</p>
                        ${fieldError('apariencia.color_primario')}
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">${escapeHtml(t('settings.label_preview'))}</label>
                        ${renderPreview()}
                    </div>
                </div>
                ${canEdit() ? `
                <div class="flex items-center justify-between mt-4 gap-3 flex-wrap">
                    <button type="button" class="btn btn-ghost" data-action="settings-color-reset" data-testid="settings-color-reset-btn">${escapeHtml(t('settings.color_reset'))}</button>
                    <button type="submit" class="btn btn-primary" data-action="settings-save-appearance" data-testid="settings-save-appearance-btn">${escapeHtml(t('settings.btn_save'))}</button>
                </div>` : ''}
            </form>`;
        return group(t('settings.section_apariencia'), body, t('settings.foot_apariencia'));
    }

    // ====================================================================
    // TEMPLATE: Preferencias regionales
    // ====================================================================

    function sampleDate(formato) {
        const d = new Date();
        const pad = n => String(n).padStart(2, '0');
        return formato.replace('YYYY', d.getFullYear()).replace('MM', pad(d.getMonth() + 1)).replace('DD', pad(d.getDate()));
    }

    function sampleMoney(moneda) {
        return moneda ? UI.formatMoney(1234.5, moneda) : EMPTY;
    }

    function renderLocalizacionPanel() {
        const guard = guardState();
        if (guard) return guard;
        const tenant = state.tenant || {};
        const regional = section('regional');
        const formato = regional.formato_fecha || DATE_FORMATS[0];
        const moneda = regional.moneda || '';

        if (!canEdit()) {
            return group(t('settings.section_localizacion'), `
                <div class="grid gap-4 md:grid-cols-2">
                    ${labelValue(t('settings.label_pais'), tenant.pais)}
                    ${labelValue(t('settings.label_zona_horaria'), tenant.zona_horaria)}
                    ${labelValue(t('settings.label_idioma_default'), tenant.idioma_default)}
                    ${labelValue(t('settings.label_moneda'), moneda)}
                    ${labelValue(t('settings.label_formato_fecha'), formato)}
                </div>`);
        }

        const langs = [
            { value: 'es', label: t('profile.lang.es') },
            { value: 'en', label: t('profile.lang.en') },
            { value: 'pt', label: t('profile.lang.pt') },
            { value: 'fr', label: t('profile.lang.fr') }
        ];
        const body = `
            <form id="settings-local-form" novalidate data-testid="settings-local-form">
                <div class="grid gap-4 md:grid-cols-2">
                    ${inputField({ name: 'pais', label: t('settings.label_pais'), value: tenant.pais, maxlength: 100, autocomplete: 'country-name' })}
                    ${selectField({ name: 'zona_horaria', label: t('settings.label_zona_horaria'), value: tenant.zona_horaria || '', empty: t('settings.option_none'), options: timezones().map(z => ({ value: z, label: z.replace(/_/g, ' ') })) })}
                    ${selectField({ name: 'idioma_default', label: t('settings.label_idioma_default'), value: tenant.idioma_default || 'es', options: langs })}
                    ${selectField({ name: 'regional.moneda', label: t('settings.label_moneda'), value: moneda, empty: t('settings.option_none'), options: CURRENCIES.map(c => ({ value: c, label: c + ' - ' + currencyLabels()[c] })), help: t('settings.help_moneda') })}
                    ${selectField({ name: 'regional.formato_fecha', label: t('settings.label_formato_fecha'), value: formato, options: DATE_FORMATS.map(f => ({ value: f, label: f + '  (' + sampleDate(f) + ')' })) })}
                    <div>
                        <div class="text-sm font-medium mb-1 text-muted">${escapeHtml(t('settings.label_muestra'))}</div>
                        <div class="card p-3" style="box-shadow:none;background:rgb(var(--color-surface-alt))">
                            <div class="text-xs text-muted">${escapeHtml(t('settings.label_formato_fecha'))}</div>
                            <div class="format-sample" data-sample-date data-testid="settings-sample-date">${escapeHtml(sampleDate(formato))}</div>
                            <div class="text-xs text-muted mt-2">${escapeHtml(t('settings.label_moneda'))}</div>
                            <div class="format-sample" data-sample-money data-testid="settings-sample-money">${escapeHtml(sampleMoney(moneda))}</div>
                        </div>
                    </div>
                </div>
                ${UI.formFooter({ submitLabel: t('settings.btn_save'), submitAction: 'settings-save-local' })}
            </form>`;
        return group(t('settings.section_localizacion'), body, t('settings.foot_localizacion'));
    }

    // ====================================================================
    // TEMPLATE: General, Seguridad, Correo, Resumen
    // ====================================================================

    function renderGeneralPanel() {
        const guard = guardState();
        if (guard) return guard;
        const tenant = state.tenant || {};
        const identidad = `
                <div class="grid gap-4 md:grid-cols-2 mt-4">
                    ${labelValue(t('settings.label_id'), tenant.id)}
                    <div>
                        <div class="text-xs text-muted mb-1">${escapeHtml(t('settings.label_estado'))}</div>
                        <div>${UI.estadoBadge(tenant.estado, ESTADOS_TENANT)}</div>
                    </div>
                    ${labelValue(t('settings.label_plan'), tenant.plan)}
                    ${labelValue(t('settings.label_version'), state.version)}
                </div>`;

        if (!canEdit()) {
            return group(t('settings.section_identidad'), `
                <div class="grid gap-4 md:grid-cols-2">
                    ${labelValue(t('settings.label_nombre_interno'), tenant.nombre_interno)}
                    ${labelValue(t('settings.label_dominio_custom'), tenant.dominio_custom)}
                </div>
                ${identidad}`);
        }

        return group(t('settings.section_identidad'), `
                <form id="settings-general-form" novalidate data-testid="settings-general-form">
                    <div class="grid gap-4 md:grid-cols-2">
                        ${inputField({ name: 'nombre_interno', label: t('settings.label_nombre_interno'), value: tenant.nombre_interno, required: true, maxlength: MAX_NOMBRE_INTERNO, placeholder: t('settings.placeholder_nombre_interno'), help: t('settings.help_nombre_interno') })}
                        ${inputField({ name: 'dominio_custom', label: t('settings.label_dominio_custom'), value: tenant.dominio_custom, placeholder: 'ej. acme.example.com', help: t('settings.help_dominio_custom') })}
                    </div>
                    ${identidad}
                    ${UI.formFooter({ submitLabel: t('settings.btn_save'), submitAction: 'settings-save-general' })}
                </form>`);
    }

    function renderHueco(titleKey, bodyKey, huecoKey) {
        return group(t(titleKey), `
                <p class="text-muted text-sm mb-4">${escapeHtml(t(bodyKey))}</p>
                ${UI.emptyState({ title: t('settings.hueco_title'), message: t(huecoKey) })}`);
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
        return '<div class="grid grid-cols-2 md:grid-cols-3 gap-3" data-settings-stats data-testid="settings-stats"></div>';
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
            { id: 'perfil', html: renderPerfilPanel() },
            { id: 'apariencia', html: renderAparienciaPanel() },
            { id: 'localizacion', html: renderLocalizacionPanel() },
            { id: 'general', html: renderGeneralPanel() },
            { id: 'seguridad', html: renderHueco('settings.section_seguridad', 'settings.seguridad_body', 'settings.hueco_password_policy') },
            { id: 'correo', html: renderHueco('settings.section_correo', 'settings.correo_body', 'settings.hueco_smtp') },
            { id: 'resumen', html: renderResumenPanel() }
        ];

        return UI.viewShell({
            title: t('settings.title'),
            subtitle: t('settings.subtitle'),
            tabs: [
                { id: 'perfil', label: t('settings.tab_perfil') },
                { id: 'apariencia', label: t('settings.tab_apariencia') },
                { id: 'localizacion', label: t('settings.tab_localizacion') },
                { id: 'general', label: t('settings.tab_general') },
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
        container.querySelectorAll('[data-action="tab"]').forEach(b => b.setAttribute('data-testid', 'settings-tab-' + b.getAttribute('data-tab')));
        container.querySelectorAll('button[type="submit"][data-action]').forEach(b => {
            if (!b.hasAttribute('data-testid')) b.setAttribute('data-testid', b.getAttribute('data-action') + '-btn');
        });
        if (state.stats) {
            UI.summaryCards(container.querySelector('[data-settings-stats]'), statsCards());
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
    // VALIDACION
    // ====================================================================

    function clearFieldErrors() {
        if (!container) return;
        container.querySelectorAll('.field-error').forEach(function(el) {
            el.textContent = '';
            el.classList.add('hidden');
        });
        container.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    }

    function showFieldError(field, message) {
        if (!container) return;
        const el = container.querySelector('[data-field="' + field + '"]');
        if (el) {
            el.textContent = message;
            el.classList.remove('hidden');
        }
        const input = container.querySelector('[name="' + field + '"]');
        if (input) {
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
        }
    }

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
        if (!/^https?:\/\/[^\s"'<>]+$/i.test(url)) return t('settings.logo_url_invalid');
        return null;
    }

    const VALIDATORS = {
        nombre_interno: validateNombreInterno,
        logo_url: validateLogoUrl,
        nombre_publico: v => String(v || '').trim() === '' ? t('settings.field_required') : null,
        email_contacto: v => { const s = String(v || '').trim(); return s !== '' && !EMAIL_RE.test(s) ? t('settings.email_invalid') : null; },
        telefono: v => { const s = String(v || '').trim(); return s !== '' && !PHONE_RE.test(s) ? t('settings.telefono_invalid') : null; },
        'perfil.direccion': v => String(v || '').length > 500 ? t('settings.direccion_max_length') : null
    };

    function validateForm(form) {
        let first = null;
        Object.keys(VALIDATORS).forEach(function(name) {
            const el = form.querySelector('[name="' + name + '"]');
            if (!el) return;
            const msg = VALIDATORS[name](el.value);
            if (msg) {
                showFieldError(name, msg);
                if (!first) first = el;
            }
        });
        if (first) first.focus();
        return first === null;
    }

    // ====================================================================
    // EDICION (PATCH /api/tenant)
    // ====================================================================

    /** Campos planos que cambiaron respecto al tenant cargado. */
    function diffFields(form, tenant, fields) {
        const payload = {};
        fields.forEach(function(name) {
            const el = form.querySelector('[name="' + name + '"]');
            if (!el) return;
            let value = el.value;
            if (value !== null && typeof value === 'string') value = value.trim();
            if ((name === 'dominio_custom' || name === 'logo_url') && value === '') value = null;
            const current = tenant[name] == null ? null : tenant[name];
            if (String(value == null ? '' : value) !== String(current == null ? '' : current)) {
                payload[name] = value;
            }
        });
        return payload;
    }

    /** Campos "seccion.clave" fusionados sobre configuracion; null si no cambia. */
    function diffConfig(form, names, extra) {
        const merged = JSON.parse(JSON.stringify(config()));
        names.forEach(function(path) {
            const el = form.querySelector('[name="' + path + '"]');
            if (!el) return;
            setPath(merged, path, el.value.trim() === '' ? null : el.value.trim());
        });
        Object.keys(extra || {}).forEach(path => setPath(merged, path, extra[path]));
        return JSON.stringify(merged) === JSON.stringify(config()) ? null : merged;
    }

    /** null = borrar la clave (el backend fusiona y elimina los null). */
    function setPath(obj, path, value) {
        const parts = path.split('.');
        const sec = obj[parts[0]] || {};
        if (value === null && sec[parts[1]] == null) return;
        obj[parts[0]] = Object.assign({}, sec, { [parts[1]]: value });
    }

    function setBusy(form, busy) {
        const btn = form && form.querySelector('[type="submit"]');
        if (!btn) return;
        btn.disabled = busy;
        btn.classList.toggle('is-loading', busy);
        btn.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            if (!btn.hasAttribute('data-label')) btn.setAttribute('data-label', btn.textContent);
            btn.textContent = t('settings.btn_saving');
        }
    }

    function handleSaveError(err) {
        console.error('[Settings] Error updating tenant:', err);
        const errorMsg = err && err.message ? err.message : t('settings.update_error');
        const errorField = err && err.data && err.data.field;
        if (errorField && container && container.querySelector('[data-field="' + errorField + '"]')) {
            showFieldError(errorField, errorMsg);
        } else {
            showToast('danger', t('settings.update_error'), errorMsg);
        }
    }

    async function patchTenant(form, payload) {
        if (Object.keys(payload).length === 0) {
            showToast('info', t('settings.title'), t('settings.error_nothing_changed'));
            return;
        }
        state.saving = true;
        setBusy(form, true);
        try {
            const response = await global.HttpClient.patch('/tenant', payload, { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            if (data && data.tenant) state.tenant = data.tenant;
            state.color = null;
            syncBranding();
            showToast('success', t('settings.title'), t('settings.success_update'));
            render();
        } catch (err) {
            setBusy(form, false);
            const btn = form && form.querySelector('[type="submit"]');
            if (btn) btn.textContent = btn.getAttribute('data-label') || t('settings.btn_save');
            handleSaveError(err);
        } finally {
            state.saving = false;
        }
    }

    const FORMS = {
        'settings-general-form': form => diffFields(form, state.tenant, ['nombre_interno', 'dominio_custom']),
        'settings-profile-form': function(form) {
            const payload = diffFields(form, state.tenant, ['nombre_publico', 'email_contacto', 'telefono']);
            const cfg = diffConfig(form, ['perfil.direccion']);
            if (cfg) payload.configuracion = cfg;
            return payload;
        },
        'settings-logo-url-form': form => diffFields(form, state.tenant, ['logo_url']),
        'settings-local-form': function(form) {
            const payload = diffFields(form, state.tenant, ['pais', 'zona_horaria', 'idioma_default']);
            if (payload.zona_horaria === '') delete payload.zona_horaria;
            const names = ['regional.moneda'];
            // El select muestra DD/MM/YYYY por defecto: sin cambio real no se guarda
            const fmtEl = form.querySelector('[name="regional.formato_fecha"]');
            if (section('regional').formato_fecha || (fmtEl && fmtEl.value !== DATE_FORMATS[0])) names.push('regional.formato_fecha');
            const cfg = diffConfig(form, names);
            if (cfg) payload.configuracion = cfg;
            return payload;
        },
        'settings-appearance-form': function() {
            const color = currentColor();
            if (color && !HEX_RE.test(color)) {
                showFieldError('apariencia.color_primario', t('settings.color_invalid'));
                return null;
            }
            const cfg = diffConfig(document.createElement('form'), [], { 'apariencia.color_primario': color ? color.toUpperCase() : null });
            return cfg ? { configuracion: cfg } : {};
        }
    };

    function handleSubmit(e) {
        const form = e.target;
        const build = FORMS[form.id];
        if (!build) return;
        e.preventDefault();
        if (state.saving || !state.tenant) return;
        clearFieldErrors();
        if (!validateForm(form)) return;
        const payload = build(form);
        if (payload) patchTenant(form, payload);
    }

    // ====================================================================
    // LOGO (POST /api/tenant/logo)
    // ====================================================================

    async function uploadLogo(file) {
        clearFieldErrors();
        if (!file) return;
        if (LOGO_TYPES.indexOf(file.type) === -1) {
            showFieldError('logo_file', t('settings.logo_type_invalid'));
            return;
        }
        if (file.size > LOGO_MAX_BYTES) {
            showFieldError('logo_file', t('settings.logo_too_large'));
            return;
        }
        const fd = new FormData();
        fd.append('logo', file);
        state.uploading = true;
        render();
        try {
            const response = await global.HttpClient.upload('/tenant/logo', fd, { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            if (data && data.tenant) state.tenant = data.tenant;
            syncBranding();
            showToast('success', t('settings.title'), t('settings.logo_success'));
        } catch (err) {
            showToast('danger', t('settings.update_error'), (err && err.message) || t('settings.update_error'));
        } finally {
            state.uploading = false;
            render();
        }
    }

    async function removeLogo() {
        if (!state.tenant || state.saving) return;
        if (!global.confirm(t('settings.logo_remove_confirm'))) return;
        const form = container.querySelector('#settings-logo-url-form');
        await patchTenant(form, { logo_url: null });
    }

    // ====================================================================
    // EVENTOS
    // ====================================================================

    function switchTab(tab) {
        if (TABS.indexOf(tab) === -1) return;
        if (state.tab === 'apariencia' && tab !== 'apariencia' && state.color) {
            state.color = null;
            if (global.TenantBranding) global.TenantBranding.restore();
            render();
        }
        state.tab = tab;
        UI.applyTabState(container, tab);
        UI.syncTabParam(tab);
        if (tab === 'resumen') render();
    }

    function pickColor(hex) {
        state.color = hex;
        if (global.TenantBranding) global.TenantBranding.preview(hex);
        container.querySelectorAll('.color-swatch').forEach(function(b) {
            const sel = b.getAttribute('data-color') === hex.toUpperCase();
            b.classList.toggle('is-selected', sel);
            b.setAttribute('aria-pressed', sel ? 'true' : 'false');
        });
        const code = container.querySelector('[data-color-code]');
        if (code) code.textContent = hex.toUpperCase();
        const picker = container.querySelector('[name="color_custom"]');
        if (picker && picker.value.toUpperCase() !== hex.toUpperCase()) picker.value = hex;
    }

    function resetColor() {
        state.color = null;
        if (global.TenantBranding) global.TenantBranding.preview(null);
        const cfg = diffConfig(document.createElement('form'), [], { 'apariencia.color_primario': null });
        if (!cfg) {
            render();
            showToast('info', t('settings.title'), t('settings.error_nothing_changed'));
            return;
        }
        patchTenant(container.querySelector('#settings-appearance-form'), { configuracion: cfg });
    }

    function handleClick(e) {
        const tabBtn = e.target.closest('[data-action="tab"]');
        if (tabBtn) {
            switchTab(tabBtn.getAttribute('data-tab'));
            return;
        }
        if (e.target.closest('[data-action="settings-retry"]')) {
            loadData();
            return;
        }
        const swatch = e.target.closest('[data-action="settings-pick-color"]');
        if (swatch && canEdit()) {
            pickColor(swatch.getAttribute('data-color'));
            return;
        }
        if (e.target.closest('[data-action="settings-color-reset"]')) {
            resetColor();
            return;
        }
        if (e.target.closest('[data-action="settings-logo-remove"]')) {
            removeLogo();
        }
    }

    function handleChange(e) {
        const el = e.target;
        if (el.name === 'logo_file') {
            uploadLogo(el.files && el.files[0]);
        } else if (el.name === 'color_custom') {
            pickColor(el.value);
        } else if (el.name === 'regional.formato_fecha') {
            const s = container.querySelector('[data-sample-date]');
            if (s) s.textContent = sampleDate(el.value);
        } else if (el.name === 'regional.moneda') {
            const s = container.querySelector('[data-sample-money]');
            if (s) s.textContent = sampleMoney(el.value);
        }
    }

    function handleInput(e) {
        if (e.target.name === 'color_custom') pickColor(e.target.value);
        if (e.target.classList.contains('is-invalid')) {
            e.target.classList.remove('is-invalid');
            const err = container.querySelector('[data-field="' + e.target.name + '"]');
            if (err) err.classList.add('hidden');
        }
    }

    function bind() {
        container.addEventListener('click', handleClick);
        container.addEventListener('submit', handleSubmit);
        container.addEventListener('change', handleChange);
        container.addEventListener('input', handleInput);
    }

    function unbind() {
        container.removeEventListener('click', handleClick);
        container.removeEventListener('submit', handleSubmit);
        container.removeEventListener('change', handleChange);
        container.removeEventListener('input', handleInput);
    }

    function denied() {
        container.innerHTML = UI.deniedState({
            title: t('settings.permission.title'),
            message: t('settings.permission.body', { permiso: 'tenants.tenant.view' })
        });
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const SettingsSettingsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;
            if (!canView()) return denied();

            state.tab = UI.tabFromParams(params, TABS, DEFAULT_TAB);
            render();
            bind();
            loadData();
        },

        onActivate(params) {
            if (!container) return;
            if (!canView()) return denied();

            unbind();
            bind();

            const tab = UI.tabFromParams(params, TABS, state.tab);
            if (tab !== state.tab) {
                state.tab = tab;
                UI.applyTabState(container, tab);
            }
            loadData();
        },

        destroy() {
            if (container) {
                unbind();
                container.innerHTML = '';
            }
            if (state.color && global.TenantBranding) global.TenantBranding.restore();
            container = null;
            state.tab = DEFAULT_TAB;
            state.tenant = null;
            state.version = '';
            state.stats = null;
            state.loading = true;
            state.statsLoading = true;
            state.error = null;
            state.saving = false;
            state.uploading = false;
            state.color = null;
        }
    };

    global.SettingsSettingsModule = SettingsSettingsModule;

})(window);
