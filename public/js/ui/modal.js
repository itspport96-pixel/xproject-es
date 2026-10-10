/**
 * UI Components - Modal y formularios (v1.9.3)
 *
 * Estructura de modal (overlay + header/body/footer), campos de formulario
 * con su span de error y los helpers de error en línea reutilizados por todos
 * los módulos (mismo contrato `data-error="<campo>"` que ya usaban returns,
 * shipments, picking...). Depende de ui/dom.js.
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    const GRID_COLS = {
        1: 'grid grid-cols-1 gap-4',
        2: 'grid grid-cols-1 md:grid-cols-2 gap-4',
        3: 'grid grid-cols-1 md:grid-cols-3 gap-4',
        4: 'grid grid-cols-1 md:grid-cols-4 gap-4',
        5: 'grid grid-cols-1 md:grid-cols-5 gap-4',
        6: 'grid grid-cols-1 md:grid-cols-6 gap-4'
    };

    const SIZES = {
        sm: 'modal-sm',
        lg: 'modal-lg',
        xl: 'modal-xl'
    };

    /**
     * Envoltorio estándar de modal.
     *
     * @param {{title: string, size?: 'sm'|'lg'|'xl', bodyHtml: string,
     *         footerHtml?: string}} opts
     */
    function modalShell(opts) {
        const o = opts || {};
        const size = o.size && SIZES[o.size] ? ' ' + SIZES[o.size] : '';

        return `
            <div class="modal-overlay" data-modal>
                <div class="modal${size}">
                    <div class="modal-header">
                        <h3 class="modal-title">${UI.escapeHtml(o.title || '')}</h3>
                        <button class="modal-close" data-action="close-modal" aria-label="${UI.escapeHtml(t('ui.close'))}">&times;</button>
                    </div>
                    <div class="modal-body">${o.bodyHtml || ''}</div>
                    ${o.footerHtml ? `<div class="modal-footer">${o.footerHtml}</div>` : ''}
                </div>
            </div>`;
    }

    function mountModal(root, html) {
        if (!root) return;
        root.innerHTML = html || '';
    }

    function closeModal(root) {
        if (!root) return;
        root.innerHTML = '';
    }

    /**
     * Campo de formulario con su `<span data-error>`.
     *
     * @param {{label: string, name: string, type?: string, value?: string,
     *         required?: boolean, hint?: string, placeholder?: string,
     *         options?: Array<{value: string, label: string, selected?: boolean}>,
     *         rows?: number, size?: number, disabled?: boolean,
     *         maxlength?: number, className?: string}} opts
     */
    function field(opts) {
        const o = opts || {};
        const name = UI.escapeHtml(o.name || '');
        const requiredMark = o.required ? ' <span class="required">*</span>' : '';
        const hint = o.hint ? `<span class="form-hint">${UI.escapeHtml(o.hint)}</span>` : '';
        const error = `<span class="form-error hidden" data-error="${name}"></span>`;
        const attrs =
            ` name="${name}"` +
            (o.placeholder ? ` placeholder="${UI.escapeHtml(o.placeholder)}"` : '') +
            (o.disabled ? ' disabled' : '') +
            (o.maxlength ? ` maxlength="${o.maxlength}"` : '');

        let control;

        if (o.type === 'select') {
            const opciones = (o.options || []).map(op => {
                const selected = (op.selected || op.value === o.value) ? ' selected' : '';
                return `<option value="${UI.escapeHtml(op.value)}"${selected}>${UI.escapeHtml(op.label)}</option>`;
            }).join('');
            const sizeAttr = o.size ? ` size="${o.size}"` : '';
            control = `<select class="select" data-modal-field="${name}"${attrs}${sizeAttr}>${opciones}</select>`;
        } else if (o.type === 'textarea') {
            const rows = o.rows || 4;
            control = `<textarea class="input" rows="${rows}" data-modal-field="${name}"${attrs}>${UI.escapeHtml(o.value || '')}</textarea>`;
        } else {
            control = `<input type="${UI.escapeHtml(o.type || 'text')}" class="input" data-modal-field="${name}"${attrs} value="${UI.escapeHtml(o.value || '')}"${o.type === 'text' ? ' autocomplete="off"' : ''}>`;
        }

        return `
            <div class="form-group${o.className ? ' ' + o.className : ''}">
                <label class="form-label">${UI.escapeHtml(o.label || '')}${requiredMark}</label>
                ${control}
                ${error}
                ${hint}
            </div>`;
    }

    /** Envoltorio de rejilla (usa las mismas clases Tailwind que los módulos). */
    function grid(contentHtml, cols) {
        const clase = GRID_COLS[cols] || GRID_COLS[2];
        return `<div class="${clase}">${contentHtml || ''}</div>`;
    }

    function showFieldError(root, fieldName, message) {
        if (!root || !fieldName) return;
        // Los nombres de campo los controla el propio módulo (`estado`,
        // `acciones[0].justificacion`, ...): sólo se retira la comilla que
        // rompería el selector entre comillas dobles.
        const safe = String(fieldName).replace(/["\\]/g, '');
        const el = root.querySelector(`[data-error="${safe}"]`);
        if (!el) return;
        el.textContent = message || '';
        el.classList.remove('hidden');
        if (typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ block: 'nearest' });
        }
    }

    function showFormError(root, message) {
        if (!root) return;
        const el = root.querySelector('[data-form-error]');
        if (!el) return;
        if (!message) {
            el.classList.add('hidden');
            el.textContent = '';
            return;
        }
        el.textContent = message;
        el.classList.remove('hidden');
    }

    function clearErrors(root) {
        if (!root) return;
        root.querySelectorAll('[data-error]').forEach(el => {
            el.classList.add('hidden');
            el.textContent = '';
        });
        root.querySelectorAll('[data-form-error]').forEach(el => {
            el.classList.add('hidden');
            el.textContent = '';
        });
    }

    global.UI = Object.assign(global.UI || {}, {
        modalShell: modalShell,
        mountModal: mountModal,
        closeModal: closeModal,
        field: field,
        grid: grid,
        showFieldError: showFieldError,
        showFormError: showFormError,
        clearErrors: clearErrors
    });

})(window);
