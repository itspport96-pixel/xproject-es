/**
 * UI Components - DOM (v1.9.3)
 *
 * Primitivas de texto/HTML que comparten todos los módulos: escape, formato
 * numérico y emisión de toasts. Sin dependencias salvo EventBus para toast.
 *
 * Convención: todo el directorio public/js/ui/* se carga con <script> clásicos
 * en index.php ANTES de los módulos (y en los smokes jsdom, antes de evaluar
 * los ficheros de módulos), de modo que `const UI = global.UI` dentro de un
 * módulo siempre encuentra el objeto ya construido.
 */
(function(global) {
    'use strict';

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatNumber(value) {
        const n = parseFloat(value);
        if (!isFinite(n)) return '0';
        return Number.isInteger(n) ? String(n) : n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    }

    function toast(type, title, message) {
        if (global.EventBus) {
            global.EventBus.emit('toast:show', {
                type: type,
                title: title,
                message: message || '',
                duration: 4000
            });
        }
    }

    // ---- Formatos regionales del tenant (v1.14.0, TenantBranding) ----
    const DEFAULT_DATE_FORMAT = 'DD/MM/YYYY';
    // Fechas "naive" de la BD (sin zona; con o sin microsegundos): se muestran
    // tal cual, sin convertir, igual en todas las vistas y navegadores.
    const LOCAL_RE = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?)?$/;

    function regional() {
        const b = global.TenantBranding && global.TenantBranding.get ? global.TenantBranding.get() : null;
        return {
            formato: (b && b.regional && b.regional.formato_fecha) || DEFAULT_DATE_FORMAT,
            moneda: (b && b.regional && b.regional.moneda) || null,
            zona: (b && b.zona_horaria) || null
        };
    }

    function locale() {
        return (global.i18n && global.i18n.getLocale && global.i18n.getLocale()) || 'es';
    }

    /** Partes {y,m,d,h,i}: texto local tal cual; ISO con zona -> zona del tenant. */
    function dateParts(value, zona) {
        const raw = String(value).trim();
        const m = raw.match(LOCAL_RE);
        if (m) return { y: m[1], m: m[2], d: m[3], h: m[4] || null, i: m[5] || null };

        const date = new Date(raw);
        if (isNaN(date.getTime())) return null;
        const opts = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
        let parts;
        try {
            parts = new Intl.DateTimeFormat('en-GB', Object.assign({ timeZone: zona || undefined }, opts)).formatToParts(date);
        } catch (e) {
            parts = new Intl.DateTimeFormat('en-GB', opts).formatToParts(date);
        }
        const get = type => (parts.find(p => p.type === type) || {}).value;
        return { y: get('year'), m: get('month'), d: get('day'), h: get('hour'), i: get('minute') };
    }

    /**
     * Fecha con el formato del tenant (DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD,
     * DD-MM-YYYY, DD.MM.YYYY). opts.time añade HH:mm si el valor lo trae.
     * Vacío o no reconocible: '—' o el texto original.
     */
    function formatDate(value, opts) {
        if (value === null || value === undefined || value === '') return '—';
        const cfg = regional();
        const p = dateParts(value, cfg.zona);
        if (!p) return String(value);
        const fecha = cfg.formato.replace('YYYY', p.y).replace('MM', p.m).replace('DD', p.d);
        return (opts && opts.time && p.h) ? fecha + ' ' + p.h + ':' + p.i : fecha;
    }

    function formatDateTime(value) {
        return formatDate(value, { time: true });
    }

    /** Importe con la moneda del tenant (o la indicada); sin moneda, solo número. */
    function formatMoney(amount, currency) {
        const n = parseFloat(amount);
        if (!isFinite(n)) return '—';
        const code = currency || regional().moneda;
        try {
            return code
                ? new Intl.NumberFormat(locale(), { style: 'currency', currency: code }).format(n)
                : new Intl.NumberFormat(locale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
        } catch (e) {
            return n.toFixed(2) + (code ? ' ' + code : '');
        }
    }

    global.UI = Object.assign(global.UI || {}, {
        escapeHtml: escapeHtml,
        formatNumber: formatNumber,
        formatDate: formatDate,
        formatDateTime: formatDateTime,
        formatMoney: formatMoney,
        toast: toast
    });

})(window);
