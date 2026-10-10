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

    global.UI = Object.assign(global.UI || {}, {
        escapeHtml: escapeHtml,
        formatNumber: formatNumber,
        toast: toast
    });

})(window);
