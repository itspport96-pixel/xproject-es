/**
 * Settings Module (v1.14.1)
 * Configuracion del tenant: lectura y edicion inline (sin modales).
 *
 * Tabs:
 * - general: identidad tecnica (nombre_interno, dominio, estado, plan),
 *   perfil (nombre publico, correo, telefono, direccion) y logo (subida;
 *   sin formulario de URL externa)
 * - apariencia: color principal (paleta + selector libre) con vista previa
 * - localizacion: pais (ISO 3166-1 alpha-2), zona horaria (IANA TZDB),
 *   idioma, moneda y formato de fecha
 * - seguridad: caducidad de contrasenas, hueco MFA y formulario SMTP
 *   (password cifrada en backend; boton de correo de prueba)
 *
 * UN SOLO boton global de guardar (cabecera): recoge los diffs de todos
 * los formularios y envia UN unico PATCH /api/tenant. El logo se sube de
 * forma inmediata al elegir el archivo (POST /api/tenant/logo).
 *
 * Datos: GET /api/tenant (tenants.tenant.view). Edicion: PATCH /api/tenant
 * (tenants.tenant.edit). direccion/color/moneda/formato/caducidad/SMTP
 * viven en tenants.configuracion (JSON) y el backend los fusiona por
 * secciones. Tras guardar se refresca TenantBranding.
 *
 * Ruta 'settings': permiso tenants.tenant.view (fallback rol ADMIN).
 * Convencion ModuleLoader: window.SettingsSettingsModule (ruta settings/settings).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    const TABS = ['general', 'apariencia', 'localizacion', 'seguridad'];
    const DEFAULT_TAB = 'general';

    let state = {
        tab: DEFAULT_TAB,
        tenant: null,
        version: '',
        loading: true,
        error: null,
        saving: false,
        uploading: false,
        color: null,
        colorDirty: false
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
    const ISO_COUNTRY_RE = /^[A-Z]{2}$/;
    const LOGO_MAX_BYTES = 819200;
    const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    // Mismo catalogo que TenantService::FORMATOS_FECHA
    const DATE_FORMATS = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD', 'DD-MM-YYYY', 'DD.MM.YYYY'];
    const CURRENCIES = ['MXN', 'USD', 'EUR', 'COP', 'ARS', 'CLP', 'PEN', 'BRL', 'GTQ', 'CRC', 'UYU', 'BOB', 'PYG', 'DOP', 'GBP', 'CAD'];
    // Mismo catalogo que TenantService::SMTP_ENCRYPTIONS
    const SMTP_ENCRYPTS = [
        { value: 'tls', label: () => t('settings.smtp_encryption.tls') },
        { value: 'ssl', label: () => t('settings.smtp_encryption.ssl') },
        { value: 'none', label: () => t('settings.smtp_encryption.none') }
    ];
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
    // ISO 3166-1 alpha-2: el backend valida el codigo y el dato vivo ya es 'MX'.
    const ISO_3166_ALPHA2 = ('AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ ' +
        'BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ ' +
        'DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP ' +
        'GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI ' +
        'KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP ' +
        'MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM ' +
        'PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX ' +
        'SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU ' +
        'WF WS YE YT ZA ZM ZW').split(' ');

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

    function countryLabel(code) {
        try {
            const lang = (global.document && global.document.documentElement.lang) || 'es';
            const dn = new Intl.DisplayNames([lang], { type: 'region' });
            return dn.of(code) || code;
        } catch (e) {
            return code;
        }
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
                   value="${escapeHtml(o.value == null ? '' : o.value)}" placeholder="${escapeHtml(o.placeholder || '')}"
                   maxlength="${o.maxlength || 255}"${o.min != null ? ` min="${o.min}"` : ''}${o.max != null ? ` max="${o.max}"` : ''}${o.required ? ' required' : ''}${o.autocomplete ? ` autocomplete="${o.autocomplete}"` : ''} data-testid="${id}-input">`;
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
        const options = (o.empty !== undefined ? `<option value="">${escapeHtml(o.empty)}</option>` : '') + o.options.map(op =>
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
    // TEMPLATE: General (identidad + perfil + logo)
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

    function renderGeneralPanel() {
        const guard = guardState();
        if (guard) return guard;
        const tenant = state.tenant || {};
        const direccion = section('perfil').direccion || '';
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
            return group(t('settings.section_perfil'), `
                <div class="grid gap-4 md:grid-cols-2">
                    ${labelValue(t('settings.label_nombre_publico'), tenant.nombre_publico)}
                    ${labelValue(t('settings.label_nombre_interno'), tenant.nombre_interno)}
                    ${labelValue(t('settings.label_email_contacto'), tenant.email_contacto)}
                    ${labelValue(t('settings.label_telefono'), tenant.telefono)}
                    ${labelValue(t('settings.label_direccion'), direccion)}
                    ${labelValue(t('settings.label_dominio_custom'), tenant.dominio_custom)}
                </div>
                ${identidad}`) + group(t('settings.section_logo'), renderLogoBox(tenant));
        }

        return group(t('settings.section_identidad'), `
                    <div class="grid gap-4 md:grid-cols-2">
                        ${inputField({ name: 'nombre_interno', label: t('settings.label_nombre_interno'), value: tenant.nombre_interno, required: true, maxlength: MAX_NOMBRE_INTERNO, placeholder: t('settings.placeholder_nombre_interno'), help: t('settings.help_nombre_interno') })}
                        ${inputField({ name: 'dominio_custom', label: t('settings.label_dominio_custom'), value: tenant.dominio_custom, placeholder: 'ej. acme.example.com', help: t('settings.help_dominio_custom') })}
                    </div>
                    ${identidad}`) +
            group(t('settings.section_perfil'), `
                    <div class="grid gap-4 md:grid-cols-2">
                        ${inputField({ name: 'nombre_publico', label: t('settings.label_nombre_publico'), value: tenant.nombre_publico, required: true, placeholder: t('settings.placeholder_nombre_publico'), autocomplete: 'organization' })}
                        ${inputField({ name: 'email_contacto', type: 'email', label: t('settings.label_email_contacto'), value: tenant.email_contacto, placeholder: 'contacto@empresa.com', help: t('settings.help_email_contacto'), autocomplete: 'email' })}
                        ${inputField({ name: 'telefono', type: 'tel', label: t('settings.label_telefono'), value: tenant.telefono, placeholder: '+52 55 1234 5678', maxlength: 30, autocomplete: 'tel' })}
                        ${inputField({ name: 'perfil.direccion', type: 'textarea', label: t('settings.label_direccion'), value: direccion, placeholder: t('settings.placeholder_direccion'), maxlength: 500, wide: true })}
                    </div>`) +
            group(t('settings.section_logo'), renderLogoBox(tenant));
    }

    // ====================================================================
    // TEMPLATE: Apariencia
    // ====================================================================

    function currentColor() {
        if (state.colorDirty) return state.color;
        return section('apariencia').color_primario || null;
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
                <div class="mt-4">
                    <button type="button" class="btn btn-ghost" data-action="settings-color-reset" data-testid="settings-color-reset-btn">${escapeHtml(t('settings.color_reset'))}</button>
                </div>` : ''}`;
        return group(t('settings.section_apariencia'), body, t('settings.foot_apariencia'));
    }

    // ====================================================================
    // TEMPLATE: Localizacion
    // ====================================================================

    function sampleDate(formato) {
        const d = new Date();
        const pad = n => String(n).padStart(2, '0');
        return formato.replace('YYYY', d.getFullYear()).replace('MM', pad(d.getMonth() + 1)).replace('DD', pad(d.getDate()));
    }

    function sampleMoney(moneda) {
        return moneda ? UI.formatMoney(1234.5, moneda) : EMPTY;
    }

    function paisOptions() {
        const actual = state.tenant && state.tenant.pais;
        const list = ISO_3166_ALPHA2.slice();
        if (actual && list.indexOf(actual) === -1) list.unshift(String(actual));
        return list.map(code => ({ value: code, label: code + ' — ' + countryLabel(code) }));
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
                    ${labelValue(t('settings.label_pais'), tenant.pais ? tenant.pais + ' — ' + countryLabel(tenant.pais) : null)}
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
                <div class="grid gap-4 md:grid-cols-2">
                    ${selectField({ name: 'pais', label: t('settings.label_pais'), value: tenant.pais || '', empty: t('settings.option_none'), options: paisOptions(), help: t('settings.help_pais_iso') })}
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
                </div>`;
        return group(t('settings.section_localizacion'), body, t('settings.foot_localizacion'));
    }

    // ====================================================================
    // TEMPLATE: Seguridad (caducidad + MFA hueco + SMTP)
    // ====================================================================

    function renderSeguridadPanel() {
        const guard = guardState();
        if (guard) return guard;
        const tenant = state.tenant || {};
        const seg = section('seguridad');
        const smtp = section('smtp');
        const dias = seg.caducidad_password_dias;
        const smtpHost = smtp.host || '';

        const caducidad = `
            <div class="grid gap-4 md:grid-cols-2">
                ${inputField({
                    name: 'seguridad.caducidad_password_dias',
                    type: 'number',
                    label: t('settings.label_caducidad_password'),
                    value: dias == null ? '' : dias,
                    min: 1,
                    max: 365,
                    placeholder: '90',
                    help: t('settings.help_caducidad_password')
                })}
            </div>`;

        const mfa = `
            <p class="text-muted text-sm mb-3">${escapeHtml(t('settings.mfa_body'))}</p>
            ${UI.emptyState({ title: t('settings.mfa_title'), message: t('settings.hueco_title') })}`;

        const pwdHelp = smtp.password_configurada
            ? t('settings.help_smtp_password_saved')
            : t('settings.help_smtp_password');
        const smtpForm = `
            <div class="grid gap-4 md:grid-cols-2">
                ${inputField({ name: 'smtp.host', label: t('settings.label_smtp_host'), value: smtpHost, maxlength: 255, placeholder: 'smtp.example.com' })}
                ${inputField({ name: 'smtp.puerto', type: 'number', label: t('settings.label_smtp_puerto'), value: smtp.puerto == null ? '' : smtp.puerto, min: 1, max: 65535, placeholder: '587' })}
                ${selectField({ name: 'smtp.encryption', label: t('settings.label_smtp_encryption'), value: smtp.encryption || 'tls', options: SMTP_ENCRYPTS.map(e => ({ value: e.value, label: e.label() })) })}
                ${inputField({ name: 'smtp.username', label: t('settings.label_smtp_username'), value: smtp.username || '', maxlength: 255, autocomplete: 'username' })}
                ${inputField({ name: 'smtp.password', type: 'password', label: t('settings.label_smtp_password'), value: '', maxlength: 512, placeholder: smtp.password_configurada ? '••••••' : '', help: pwdHelp, autocomplete: 'new-password' })}
                ${inputField({ name: 'smtp.from_email', type: 'email', label: t('settings.label_smtp_from_email'), value: smtp.from_email || '', maxlength: 255, placeholder: 'noreply@empresa.com' })}
                ${inputField({ name: 'smtp.from_name', label: t('settings.label_smtp_from_name'), value: smtp.from_name || '', maxlength: 255, placeholder: tenant.nombre_publico || '' })}
            </div>
            ${canEdit() ? `
            <div class="flex items-center gap-2 mt-4 flex-wrap">
                <button type="button" class="btn btn-secondary" data-action="settings-smtp-test" data-testid="settings-smtp-test-btn">${escapeHtml(t('settings.btn_smtp_test'))}</button>
                <p class="text-muted text-xs">${escapeHtml(t('settings.help_smtp_test'))}</p>
            </div>` : ''}`;

        if (!canEdit()) {
            return group(t('settings.section_seguridad'), `
                <div class="grid gap-4 md:grid-cols-2">
                    ${labelValue(t('settings.label_caducidad_password'), dias == null ? t('settings.option_none') : dias)}
                    ${labelValue(t('settings.label_smtp_host'), smtpHost)}
                    ${labelValue(t('settings.label_smtp_username'), smtp.username)}
                    ${labelValue(t('settings.label_smtp_from_email'), smtp.from_email)}
                </div>`) + group(t('settings.mfa_title'), mfa);
        }

        return group(t('settings.section_seguridad'), caducidad) +
            group(t('settings.mfa_title'), mfa) +
            group(t('settings.section_smtp'), smtpForm, t('settings.foot_smtp'));
    }

    // ====================================================================
    // TEMPLATE: shell
    // ====================================================================

    function buildTemplate() {
        const panels = [
            { id: 'general', html: renderGeneralPanel() },
            { id: 'apariencia', html: renderAparienciaPanel() },
            { id: 'localizacion', html: renderLocalizacionPanel() },
            { id: 'seguridad', html: renderSeguridadPanel() }
        ];

        return UI.viewShell({
            title: t('settings.title'),
            subtitle: t('settings.subtitle'),
            actionsHtml: canEdit() && !state.loading && !state.error
                ? `<button type="button" class="btn btn-primary" data-action="settings-save-all" data-testid="settings-save-all-btn">${escapeHtml(t('settings.btn_save_all'))}</button>`
                : '',
            tabs: [
                { id: 'general', label: t('settings.tab_general') },
                { id: 'apariencia', label: t('settings.tab_apariencia') },
                { id: 'localizacion', label: t('settings.tab_localizacion') },
                { id: 'seguridad', label: t('settings.tab_seguridad') }
            ],
            activeTab: state.tab,
            panels: panels
        });
    }

    function render() {
        if (!container) return;
        container.innerHTML = buildTemplate();
        container.querySelectorAll('[data-action="tab"]').forEach(b => b.setAttribute('data-testid', 'settings-tab-' + b.getAttribute('data-tab')));
    }

    // ====================================================================
    // CARGA DE DATOS
    // ====================================================================

    async function loadData() {
        state.loading = true;
        state.error = null;
        render();

        try {
            const response = await global.HttpClient.get('/tenant', { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            state.tenant = (data && data.tenant) || null;
            state.version = (data && data.plataforma_version) || '';
        } catch (err) {
            console.error('[Settings] Error loading tenant:', err);
            state.error = err && err.message ? err.message : t('settings.load_error_body');
        }

        state.loading = false;
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

    /** Cambia a la pestana que contiene el campo (para ver el error). */
    function focusField(field) {
        const input = container.querySelector('[name="' + field + '"]');
        if (!input) return;
        const panel = input.closest('[data-panel]');
        if (panel && panel.dataset.panel !== state.tab) {
            state.tab = panel.dataset.panel;
            UI.applyTabState(container, state.tab);
            UI.syncTabParam(state.tab);
        }
        input.focus();
    }

    function validateNombreInterno(value) {
        const nombre = String(value == null ? '' : value).trim();
        if (nombre === '') return t('settings.field_required');
        if (nombre.length > MAX_NOMBRE_INTERNO) return t('settings.nombre_interno_max_length');
        if (nombre.length > 1 && !SLUG_RE.test(nombre)) return t('settings.nombre_interno_invalid');
        return null;
    }

    const VALIDATORS = {
        nombre_interno: validateNombreInterno,
        nombre_publico: v => String(v || '').trim() === '' ? t('settings.field_required') : null,
        email_contacto: v => { const s = String(v || '').trim(); return s !== '' && !EMAIL_RE.test(s) ? t('settings.email_invalid') : null; },
        telefono: v => { const s = String(v || '').trim(); return s !== '' && !PHONE_RE.test(s) ? t('settings.telefono_invalid') : null; },
        'perfil.direccion': v => String(v || '').length > 500 ? t('settings.direccion_max_length') : null,
        pais: v => {
            const s = String(v || '').trim();
            if (s === '') return null;
            return ISO_COUNTRY_RE.test(s) ? null : t('settings.pais_invalid');
        },
        'seguridad.caducidad_password_dias': v => {
            const s = String(v || '').trim();
            if (s === '') return null;
            const n = Number(s);
            return (Number.isInteger(n) && n >= 1 && n <= 365) ? null : t('settings.caducidad_invalid');
        },
        'smtp.from_email': v => { const s = String(v || '').trim(); return s !== '' && !EMAIL_RE.test(s) ? t('settings.email_invalid') : null; },
        'smtp.puerto': v => {
            const s = String(v || '').trim();
            if (s === '') return null;
            const n = Number(s);
            return (Number.isInteger(n) && n >= 1 && n <= 65535) ? null : t('settings.smtp_puerto_invalid');
        }
    };

    function validateAll() {
        let first = null;
        Object.keys(VALIDATORS).forEach(function(name) {
            const el = container.querySelector('[name="' + name + '"]');
            if (!el) return;
            const msg = VALIDATORS[name](el.value);
            if (msg) {
                showFieldError(name, msg);
                if (!first) first = name;
            }
        });
        // SMTP: si hay cualquier campo, el host es obligatorio
        const hostEl = container.querySelector('[name="smtp.host"]');
        if (hostEl) {
            const host = hostEl.value.trim();
            const others = ['smtp.puerto', 'smtp.username', 'smtp.password', 'smtp.from_email', 'smtp.from_name'];
            const anyOther = others.some(function(n) {
                const el = container.querySelector('[name="' + n + '"]');
                return el && el.value.trim() !== '';
            });
            if (host === '' && anyOther) {
                showFieldError('smtp.host', t('settings.field_required'));
                if (!first) first = 'smtp.host';
            }
        }
        const color = currentColor();
        if (state.colorDirty && color && !HEX_RE.test(color)) {
            showFieldError('apariencia.color_primario', t('settings.color_invalid'));
            if (!first) first = 'apariencia.color_primario';
        }
        if (first) focusField(first);
        return first === null;
    }

    // ====================================================================
    // EDICION (PATCH /api/tenant)
    // ====================================================================

    /** Config actual SIN las claves enmascaradas (password SMTP). */
    function normalizedConfig() {
        const c = JSON.parse(JSON.stringify(config()));
        if (c.smtp && typeof c.smtp === 'object') {
            delete c.smtp.password;
            delete c.smtp.password_configurada;
        }
        return c;
    }

    /** Campos "seccion.clave" fusionados sobre configuracion; null si no cambia. */
    function setPath(obj, path, value) {
        const parts = path.split('.');
        const sec = obj[parts[0]] || {};
        if (value === null && sec[parts[1]] == null) return;
        obj[parts[0]] = Object.assign({}, sec, { [parts[1]]: value });
    }

    function inputValue(name) {
        const el = container.querySelector('[name="' + name + '"]');
        return el ? el.value : null;
    }

    /** Payload unico con los diffs de TODOS los formularios de la vista. */
    function buildPayload() {
        const payload = {};

        ['nombre_publico', 'email_contacto', 'telefono', 'nombre_interno', 'dominio_custom', 'pais', 'zona_horaria', 'idioma_default'].forEach(function(name) {
            const el = container.querySelector('[name="' + name + '"]');
            if (!el) return;
            let value = el.value;
            if (value !== null && typeof value === 'string') value = value.trim();
            if (name === 'dominio_custom' && value === '') value = null;
            if (name === 'zona_horaria' && value === '') return;
            if (name === 'pais' && value === '') value = null;
            const current = state.tenant[name] == null ? null : state.tenant[name];
            if (String(value == null ? '' : value) !== String(current == null ? '' : current)) {
                payload[name] = value;
            }
        });

        const base = normalizedConfig();
        const merged = JSON.parse(JSON.stringify(base));

        const dir = inputValue('perfil.direccion');
        if (dir !== null) setPath(merged, 'perfil.direccion', dir.trim() === '' ? null : dir.trim());

        if (state.colorDirty) {
            setPath(merged, 'apariencia.color_primario', state.color ? state.color.toUpperCase() : null);
        }

        const moneda = inputValue('regional.moneda');
        if (moneda !== null) setPath(merged, 'regional.moneda', moneda === '' ? null : moneda);

        const fmtEl = container.querySelector('[name="regional.formato_fecha"]');
        if (fmtEl) {
            const actualFmt = section('regional').formato_fecha;
            if (actualFmt || fmtEl.value !== DATE_FORMATS[0]) {
                setPath(merged, 'regional.formato_fecha', fmtEl.value);
            }
        }

        const dias = inputValue('seguridad.caducidad_password_dias');
        if (dias !== null) {
            const raw = String(dias).trim();
            setPath(merged, 'seguridad.caducidad_password_dias', raw === '' ? null : parseInt(raw, 10));
        }

        // SMTP: solo se toca si el host tiene valor (vacio = conservar lo guardado)
        const hostEl = container.querySelector('[name="smtp.host"]');
        if (hostEl && hostEl.value.trim() !== '') {
            merged.smtp = merged.smtp || {};
            merged.smtp.host = hostEl.value.trim();
            const puerto = String(inputValue('smtp.puerto') || '').trim();
            merged.smtp.puerto = puerto === '' ? null : parseInt(puerto, 10);
            const enc = inputValue('smtp.encryption');
            merged.smtp.encryption = enc || null;
            const username = String(inputValue('smtp.username') || '').trim();
            merged.smtp.username = username === '' ? null : username;
            // password: solo si el admin escribio una nueva (vacio = no cambiar;
            // no se anade la clave para no crear un diff espurio)
            const pwdEl = container.querySelector('[name="smtp.password"]');
            if (pwdEl && pwdEl.value !== '') merged.smtp.password = pwdEl.value;
            const fromEmail = String(inputValue('smtp.from_email') || '').trim();
            merged.smtp.from_email = fromEmail === '' ? null : fromEmail;
            const fromName = String(inputValue('smtp.from_name') || '').trim();
            merged.smtp.from_name = fromName === '' ? null : fromName;
        }

        if (JSON.stringify(merged) !== JSON.stringify(base)) {
            payload.configuracion = merged;
        }

        return payload;
    }

    function setBusy(busy) {
        const btn = container && container.querySelector('[data-action="settings-save-all"]');
        if (!btn) return;
        btn.disabled = busy;
        btn.classList.toggle('is-loading', busy);
        btn.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            if (!btn.hasAttribute('data-label')) btn.setAttribute('data-label', btn.textContent);
            btn.textContent = t('settings.btn_saving');
        } else if (btn.hasAttribute('data-label')) {
            btn.textContent = btn.getAttribute('data-label');
        }
    }

    function handleSaveError(err) {
        console.error('[Settings] Error updating tenant:', err);
        const errorMsg = err && err.message ? err.message : t('settings.update_error');
        const errorField = err && err.data && err.data.field;
        if (errorField && container && container.querySelector('[data-field="' + errorField + '"]')) {
            showFieldError(errorField, errorMsg);
            focusField(errorField);
        } else {
            showToast('danger', t('settings.update_error'), errorMsg);
        }
    }

    async function saveAll() {
        if (state.saving || !state.tenant || !canEdit()) return;
        clearFieldErrors();
        if (!validateAll()) return;

        const payload = buildPayload();
        if (Object.keys(payload).length === 0) {
            showToast('info', t('settings.title'), t('settings.error_nothing_changed'));
            return;
        }

        state.saving = true;
        setBusy(true);
        try {
            const response = await global.HttpClient.patch('/tenant', payload, { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            if (data && data.tenant) state.tenant = data.tenant;
            state.color = null;
            state.colorDirty = false;
            syncBranding();
            showToast('success', t('settings.title'), t('settings.success_update'));
            render();
        } catch (err) {
            handleSaveError(err);
        } finally {
            state.saving = false;
            setBusy(false);
        }
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
        state.saving = true;
        try {
            const response = await global.HttpClient.patch('/tenant', { logo_url: null }, { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            if (data && data.tenant) state.tenant = data.tenant;
            syncBranding();
            showToast('success', t('settings.title'), t('settings.logo_success'));
            render();
        } catch (err) {
            handleSaveError(err);
        } finally {
            state.saving = false;
        }
    }

    // ====================================================================
    // SMTP: correo de prueba (POST /api/tenant/smtp/test)
    // ====================================================================

    async function testSmtp() {
        const btn = container.querySelector('[data-action="settings-smtp-test"]');
        if (btn) {
            btn.disabled = true;
            btn.classList.add('is-loading');
        }
        try {
            const response = await global.HttpClient.post('/tenant/smtp/test', {}, { requiresAuth: true });
            const data = response && response.data ? response.data : response;
            const destino = data && data.enviado_a ? data.enviado_a : '';
            showToast('success', t('settings.btn_smtp_test'), t('settings.smtp_test_ok', { email: destino }));
        } catch (err) {
            showToast('danger', t('settings.btn_smtp_test'), (err && err.message) || t('settings.smtp_test_error'));
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('is-loading');
            }
        }
    }

    // ====================================================================
    // EVENTOS
    // ====================================================================

    function switchTab(tab) {
        if (TABS.indexOf(tab) === -1) return;
        if (state.tab === 'apariencia' && tab !== 'apariencia' && state.colorDirty && state.color) {
            if (global.TenantBranding) global.TenantBranding.restore();
        }
        state.tab = tab;
        UI.applyTabState(container, tab);
        UI.syncTabParam(tab);
    }

    function pickColor(hex) {
        state.color = hex;
        state.colorDirty = true;
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
        state.colorDirty = true;
        if (global.TenantBranding) global.TenantBranding.preview(null);
        render();
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
        if (e.target.closest('[data-action="settings-save-all"]')) {
            saveAll();
            return;
        }
        if (e.target.closest('[data-action="settings-smtp-test"]')) {
            testSmtp();
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
        container.addEventListener('change', handleChange);
        container.addEventListener('input', handleInput);
    }

    function unbind() {
        container.removeEventListener('click', handleClick);
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
            if (state.colorDirty && state.color && global.TenantBranding) global.TenantBranding.restore();
            container = null;
            state.tab = DEFAULT_TAB;
            state.tenant = null;
            state.version = '';
            state.loading = true;
            state.error = null;
            state.saving = false;
            state.uploading = false;
            state.color = null;
            state.colorDirty = false;
        }
    };

    global.SettingsSettingsModule = SettingsSettingsModule;

})(window);
