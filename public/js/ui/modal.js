/**
 * UI Components - Modal y formularios
 *
 * Estructura de modal (overlay + header/body/footer), campos de formulario
 * con su span de error y los helpers de error en línea reutilizados por todos
 * los módulos (mismo contrato `data-error="<campo>"` que ya usaban returns,
 * shipments, picking...). Depende de ui/dom.js.
 *
 * Estándar M0 (docs/UI_STANDARD.md §9): tamaños sm/md/lg/xl, pie estándar
 * con UI.modalFooter, Esc cierra, Enter envía y foco en el primer campo al
 * abrir (los tres últimos se instalan una sola vez a nivel de document).
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
        md: 'modal-md',
        lg: 'modal-lg',
        xl: 'modal-xl',
        pp: 'modal-pp'
    };

    /**
     * Envoltorio estándar de modal.
     *
     * @param {{title: string, size?: 'sm'|'md'|'lg'|'xl'|'pp', bodyHtml: string,
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
            // Mínimo 4 filas (estándar §9); el textarea-autogrow ajusta después.
            const rows = Math.max(4, Number(o.rows) || 4);
            const extraClass = o.controlClass ? ' ' + o.controlClass : '';
            control = `<textarea class="input textarea${extraClass}" rows="${rows}" data-modal-field="${name}"${attrs}>${UI.escapeHtml(o.value || '')}</textarea>`;
        } else {
            const extraClass = o.controlClass ? ' ' + o.controlClass : '';
            control = `<input type="${UI.escapeHtml(o.type || 'text')}" class="input${extraClass}" data-modal-field="${name}"${attrs} value="${UI.escapeHtml(o.value || '')}"${o.type === 'text' ? ' autocomplete="off"' : ''}>`;
        }

        return `
            <div class="form-group${o.className ? ' ' + o.className : ''}">
                <label class="form-label">${UI.escapeHtml(o.label || '')}${requiredMark}</label>
                ${control}
                ${error}
                ${hint}
            </div>`;
    }

    /** Envoltorio de rejilla (usa las mismas clases Tailwind que los módulos).
     *  Por defecto una columna; dos sólo para campos cortos relacionados. */
    function grid(contentHtml, cols) {
        const clase = GRID_COLS[cols] || GRID_COLS[1];
        return `<div class="${clase}">${contentHtml || ''}</div>`;
    }

    /**
     * Pie estándar de modal (docs/UI_STANDARD.md §9).
     *
     * Orden: hint opcional a la izquierda (`mr-auto`), Cancelar y a su derecha
     * el botón primario (o peligroso). Cancelar es `type="button"` y su acción
     * por defecto es `close-modal` (cancelar ≠ cerrar con la × de la cabecera,
     * pero ambas descartan el modal). El primario NO lleva `type`: fuera de un
     * `<form>` es un clic normal (los módulos lo manejan por `data-action`) y
     * dentro de un `<form>` el submit nativo va por su listener de submit.
     * El primario lleva `data-modal-send` para que los helpers `sending` de
     * cada módulo lo puedan deshabilitar en vuelo.
     *
     * @param {{hintHtml?: string, cancelLabel?: string, cancelAction?: string,
     *         submitLabel?: string, submitAction?: string, danger?: boolean,
     *         disabled?: boolean}} opts
     * @returns {string} HTML interno para el `footerHtml` de modalShell
     */
    function modalFooter(opts) {
        const o = opts || {};
        const hint = o.hintHtml ? `<div class="mr-auto">${o.hintHtml}</div>` : '';
        const cancel =
            `<button type="button" class="btn btn-secondary" data-action="${UI.escapeHtml(o.cancelAction || 'close-modal')}">` +
            `${UI.escapeHtml(o.cancelLabel || t('ui.cancel'))}</button>`;
        let submit = '';
        if (o.submitLabel) {
            const cls = o.danger ? 'btn btn-danger' : 'btn btn-primary';
            const disabled = o.disabled ? ' disabled' : '';
            submit =
                `<button class="${cls}" data-action="${UI.escapeHtml(o.submitAction || '')}" data-modal-send${disabled}>` +
                `${UI.escapeHtml(o.submitLabel)}</button>`;
        }
        return hint + cancel + submit;
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

    // ====================================================================
    // COMPORTAMIENTO ESTANDAR DE MODALES (M0, docs/UI_STANDARD.md §9)
    // Esc cierra, Enter envía y foco en el primer campo al abrir.
    // Se registra una sola vez sobre `document` y vale para todos los
    // modales, tanto los montados con mountModal como los que escriben
    // el HTML directamente en su raíz.
    // ====================================================================

    const ENTER_SKIP_TYPES = ['hidden', 'checkbox', 'radio', 'file', 'button', 'submit', 'reset', 'image', 'search'];

    function focusFirstField(overlay) {
        if (!overlay || typeof document === 'undefined') return;
        const active = document.activeElement;
        // Si algo dentro del modal ya tiene el foco (p. ej. un buscador que el
        // módulo enfoca tras montar), no lo robamos.
        if (active && active !== document.body && overlay.contains(active)) return;
        const first =
            overlay.querySelector('[data-modal-field]:not([disabled])') ||
            overlay.querySelector('.modal-body input:not([type="hidden"]):not([disabled]), .modal-body select:not([disabled]), .modal-body textarea:not([disabled])');
        if (first && typeof first.focus === 'function') first.focus();
    }

    function modalEscape(e) {
        // Un menú de fila abierto lo cierra view.js en su listener de bubbling
        // (el nuestro va en fase de captura, antes): si hay uno, Esc no debe
        // tocar el modal, sólo cerrar el menú.
        if (document.querySelector('[data-row-menu].is-open')) return;
        // Escape en un <select> nativo cierra sólo su desplegable.
        if (e.target && e.target.tagName === 'SELECT') return;
        // Un combobox con sugerencias consume primero su propia Esc.
        if (e.target && typeof e.target.getAttribute === 'function' && e.target.getAttribute('aria-expanded') === 'true') return;
        const overlays = document.querySelectorAll('[data-modal]');
        if (!overlays.length) return;
        e.preventDefault();
        const overlay = overlays[overlays.length - 1];
        const btn = overlay.querySelector('[data-action="close-modal"]') || overlay.querySelector('.modal-close');
        if (btn) btn.click();
        else if (overlay.parentElement) overlay.remove();
    }

    function modalEnter(e) {
        const target = e.target;
        if (!target || typeof target.closest !== 'function') return;
        const overlay = target.closest('[data-modal]');
        if (!overlay) return;
        const tag = target.tagName;
        if (tag === 'TEXTAREA' || tag === 'BUTTON' || tag === 'A' || target.isContentEditable) return;
        if (tag !== 'INPUT' && tag !== 'SELECT') return;
        if (target.disabled) return;
        if (ENTER_SKIP_TYPES.indexOf((target.type || 'text').toLowerCase()) !== -1) return;
        // Combobox con sugerencias abiertas: su propio handler consume Enter
        // (y puede cancelarlo con preventDefault); aquí no enviamos.
        if (target.getAttribute('aria-expanded') === 'true') return;
        if (target.closest('[data-no-enter-submit]')) return;
        // Dentro de un <form> el submit nativo (implícito) ya se encarga.
        if (target.closest('form')) return;
        const btn =
            overlay.querySelector('.modal-footer .btn-primary[data-modal-send]:not([disabled])') ||
            overlay.querySelector('.modal-footer .btn-danger[data-modal-send]:not([disabled])') ||
            overlay.querySelector('.modal-footer [data-modal-send]:not([disabled])') ||
            overlay.querySelector('.modal-footer .btn-primary:not([disabled])');
        if (!btn) return;
        e.preventDefault();
        btn.click();
    }

    let modalKeysBound = false;

    function bindModalKeysGlobal() {
        if (modalKeysBound || typeof document === 'undefined') return;
        modalKeysBound = true;

        // Foco en el primer campo la primera vez que entra un modal en una
        // raíz; un re-render (raíz ya registrada) no re-enfoca.
        if (typeof MutationObserver !== 'undefined' && document.body) {
            const openedRoots = new Set();
            new MutationObserver(mutations => {
                mutations.forEach(m => {
                    m.removedNodes.forEach(node => {
                        if (!node || node.nodeType !== 1) return;
                        openedRoots.forEach(root => {
                            if (!root.isConnected || !root.querySelector('[data-modal]')) openedRoots.delete(root);
                        });
                    });
                    m.addedNodes.forEach(node => {
                        if (!node || node.nodeType !== 1) return;
                        const overlay = (node.matches && node.matches('[data-modal]')) ? node : node.querySelector('[data-modal]');
                        if (!overlay || !overlay.parentElement) return;
                        const root = overlay.parentElement;
                        if (openedRoots.has(root)) return;
                        openedRoots.add(root);
                        focusFirstField(overlay);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }

        // Esc en fase de captura: corre antes que el handler de menús de fila
        // de view.js (bubbling), así que puede comprobar si aún hay uno abierto.
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape' || e.defaultPrevented) return;
            modalEscape(e);
        }, true);
        // Enter en bubbling: los componentes (combobox, picker...) pueden
        // consumirlo antes con preventDefault.
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' || e.defaultPrevented) return;
            modalEnter(e);
        });
    }

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindModalKeysGlobal);
        } else {
            bindModalKeysGlobal();
        }
    }

    global.UI = Object.assign(global.UI || {}, {
        modalShell: modalShell,
        mountModal: mountModal,
        closeModal: closeModal,
        field: field,
        grid: grid,
        modalFooter: modalFooter,
        showFieldError: showFieldError,
        showFormError: showFormError,
        clearErrors: clearErrors
    });

})(window);
