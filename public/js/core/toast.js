/**
 * Toast - notificaciones visuales
 * Escucha EventBus 'toast:show' y renderiza en .toast-container.
 * Todo el texto se inyecta con textContent (XSS-safe).
 */
(function(global) {
    'use strict';

    const ICONS = {
        success: '\u2713',
        warning: '\u26A0',
        danger: '\u2715',
        info: '\u2139'
    };

    function getContainer() {
        let el = document.getElementById('toast-container');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toast-container';
            el.className = 'toast-container';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        return el;
    }

    function show(payload) {
        if (!payload) return;
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => show(payload), { once: true });
            return;
        }

        const type = ICONS[payload.type] ? payload.type : 'info';
        const duration = Number(payload.duration) > 0 ? Number(payload.duration) : 4000;

        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;

        const icon = document.createElement('span');
        icon.className = 'toast-icon';
        icon.textContent = ICONS[type];

        const content = document.createElement('div');
        content.className = 'toast-content';

        const title = document.createElement('div');
        title.className = 'toast-title';
        title.textContent = payload.title || '';

        const message = document.createElement('div');
        message.className = 'toast-message';
        message.textContent = payload.message || '';

        content.appendChild(title);
        content.appendChild(message);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', t('ui.close'));
        close.textContent = '\u00D7';
        close.addEventListener('click', () => dismiss(toast));

        toast.appendChild(icon);
        toast.appendChild(content);
        toast.appendChild(close);

        getContainer().appendChild(toast);

        setTimeout(() => dismiss(toast), duration);
    }

    function dismiss(toast) {
        if (!toast || !toast.parentNode) return;
        toast.parentNode.removeChild(toast);
    }

    function init() {
        if (!global.EventBus) return;
        global.EventBus.on('toast:show', show);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    global.Toast = { show };

})(window);
