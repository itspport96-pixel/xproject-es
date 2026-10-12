/**
 * UI Components - Vista (v1.13.11)
 *
 * Estandar de estructura de vista (docs/UI_STANDARD.md): envoltorio de
 * pagina, pestanas con estado en la URL, tarjeta de filtros, pie de
 * formulario, estado de error con reintento y pantalla de permiso.
 * Depende de ui/dom.js (escapeHtml) y ui/render.js (pageHeader).
 *
 * Convencion de pestanas (mismo patron que modules/users/users.js y el CSS
 * .tabs/.tab de public/css/components.css):
 *  - La barra se pinta con <div class="tabs mb-4" role="tablist"> y botones
 *    [data-action="tab"][data-tab="id"].
 *  - Cada bloque de contenido vive en <div data-panel="id">; el panel
 *    inactivo lleva la clase "hidden". Todos los paneles se montan a la vez
 *    (los datos se cargan en init) y cambiar de pestana alterna la
 *    visibilidad y refresca el panel destino.
 *  - El modulo guarda state.tab, lo aplica con applyTabState() y lo refleja
 *    en el hash de la ruta (?tab=id) con syncTabParam(), de modo que
 *    init(params)/onActivate(params) pueden restaurarlo (tabFromParams).
 *  - Si una pestana no aplica (permiso) no se pinta su boton; con menos de
 *    dos botones visibles no se pinta barra alguna y la vista queda monolitica.
 *
 * Regla de i18n: las etiquetas se pasan ya traducidas con t(); este modulo
 * sólo usa t() para los textos genericos ui.error_title, ui.retry y
 * router.go_dashboard. title/message de deniedState se insertan SIN escapar
 * porque las traducciones de permisos contienen <code>{permiso}</code>.
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    /**
     * Barra de pestanas. `tabs` = [{id, label, show?}]; con menos de dos
     * botones visibles no devuelve nada (la vista queda sin barra).
     */
    function tabsBar(tabs, activeId) {
        const visibles = (tabs || []).filter(tab => tab && tab.show !== false);
        if (visibles.length < 2) return '';

        const botones = visibles.map(tab => {
            const activa = tab.id === activeId;
            return `
                <button type="button" role="tab" class="tab${activa ? ' is-active' : ''}"
                        aria-selected="${activa ? 'true' : 'false'}"
                        data-action="tab" data-tab="${UI.escapeHtml(tab.id)}">${UI.escapeHtml(tab.label || '')}</button>`;
        }).join('');

        return `<div class="tabs mb-4" role="tablist" data-tabs>${botones}</div>`;
    }

    /** Panel de pestana; el inactivo lleva la clase "hidden". */
    function tabPanel(id, active, html) {
        return `<div role="tabpanel" data-panel="${UI.escapeHtml(id)}"${active ? '' : ' class="hidden"'}>${html || ''}</div>`;
    }

    /**
     * Sincroniza el DOM con la pestana activa: boton is-active/aria-selected
     * y clase hidden de los paneles. No toca state ni recarga datos.
     */
    function applyTabState(root, activeId) {
        if (!root) return;

        root.querySelectorAll('[data-action="tab"]').forEach(btn => {
            const on = btn.dataset.tab === activeId;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        root.querySelectorAll('[data-panel]').forEach(panel => {
            panel.classList.toggle('hidden', panel.dataset.panel !== activeId);
        });
    }

    /**
     * Pestana pedida en la URL (?tab=...) si es una de las ids permitidas;
     * en caso contrario la del fallo (por defecto la primera visible).
     *
     * @param {{tab?: string}|null} params
     * @param {string[]} ids ids validas de pestana
     * @param {string} fallback
     */
    function tabFromParams(params, ids, fallback) {
        const raw = params && params.tab !== undefined && params.tab !== null
            ? String(params.tab)
            : '';
        return raw !== '' && (ids || []).indexOf(raw) !== -1 ? raw : fallback;
    }

    /**
     * Refleja la pestana activa en el hash de la ruta (?tab=id) con
     * replaceState: no dispara hashchange, asi que el router no recarga el
     * modulo. Sin hash (smokes/entornos sin router) no hace nada.
     */
    function syncTabParam(tab) {
        try {
            const hash = global.location && global.location.hash ? global.location.hash : '';
            if (!hash) return;

            const cut = hash.indexOf('?');
            const path = cut === -1 ? hash : hash.slice(0, cut);
            const query = new URLSearchParams(cut === -1 ? '' : hash.slice(cut + 1));
            if (tab) query.set('tab', tab);
            else query.delete('tab');

            const qs = query.toString();
            const next = path + (qs ? '?' + qs : '');
            if (next !== hash) {
                global.history.replaceState(null, '', global.location.pathname + global.location.search + next);
            }
        } catch (e) {
            // Entorno sin history (o hash vacio): la pestana vive en state.
        }
    }

    /**
     * Envoltorio de vista: contenedor, cabecera, barra de pestanas y paneles.
     *
     * @param {{title: string, subtitle?: string, actionsHtml?: string,
     *          tabs?: Array<{id: string, label: string, show?: boolean}>,
     *          activeTab?: string,
     *          panels?: Array<{id: string, html: string}>}} opts
     * @returns {string} sin <div data-modal-root>: lo monta el modulo.
     */
    function viewShell(opts) {
        const o = opts || {};
        const paneles = (o.panels || [])
            .map(p => tabPanel(p.id, p.id === o.activeTab, p.html))
            .join('');

        return `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: o.title,
                subtitle: o.subtitle,
                actionsHtml: o.actionsHtml || ''
            })}
            ${tabsBar(o.tabs || [], o.activeTab)}
            ${paneles}
        </div>`;
    }

    /**
     * Tarjeta de filtros: rejilla de campos + fila de acciones con Aplicar
     * (btn-secondary) y Limpiar (btn-ghost). Los botones van SIEMPRE en su
     * propia fila (patron de modules/users/users.js), con type="button" para
     * que no disparen submit.
     *
     * @param {string} fieldsHtml campos (form-group) ya traducidos
     * @param {{applyLabel: string, clearLabel: string, clearTitle?: string,
     *          applyAction?: string, clearAction?: string,
     *          cols?: number, className?: string}} opts
     */
    function filtersCard(fieldsHtml, opts) {
        const o = opts || {};
        const cols = Number(o.cols || 4);
        const applyAction = o.applyAction || 'apply-filters';
        const clearAction = o.clearAction || 'clear-filters';

        return `
        <div class="${o.className || 'card mb-4'}">
            <div class="grid grid-cols-1 md:grid-cols-${cols} gap-3">${fieldsHtml || ''}</div>
            <div class="flex items-center gap-2 mt-3 flex-wrap">
                <button type="button" class="btn btn-secondary" data-action="${UI.escapeHtml(applyAction)}">${UI.escapeHtml(o.applyLabel || '')}</button>
                <button type="button" class="btn btn-ghost" data-action="${UI.escapeHtml(clearAction)}"${o.clearTitle ? ` title="${UI.escapeHtml(o.clearTitle)}"` : ''}>${UI.escapeHtml(o.clearLabel || '')}</button>
            </div>
        </div>`;
    }

    /**
     * Pie de formulario: pista de Idempotency-Key a la izquierda (o cualquier
     * html auxiliar) y el boton de envio a la derecha. El contenedor
     * [data-key-hint] lo rellena renderKeyHint() de cada modulo.
     *
     * @param {{hintHtml?: string, submitLabel: string, submitAction: string}} opts
     */
    function formFooter(opts) {
        const o = opts || {};
        return `
                <div class="flex items-center justify-between mt-4 gap-3 flex-wrap">
                    <div class="text-xs text-muted" data-key-hint>${o.hintHtml || ''}</div>
                    <button type="submit" class="btn btn-primary" data-action="${UI.escapeHtml(o.submitAction || '')}">${UI.escapeHtml(o.submitLabel || '')}</button>
                </div>`;
    }

    /**
     * Estado de error de carga con boton de reintento (para el contenedor de
     * una tabla/lista). Sin retryAction no pinta boton.
     *
     * @param {{title?: string, message?: string, retryAction?: string,
     *          retryLabel?: string}} opts
     */
    function errorState(opts) {
        const o = opts || {};
        const retry = o.retryAction
            ? `
                <div class="mt-3">
                    <button type="button" class="btn btn-secondary" data-action="${UI.escapeHtml(o.retryAction)}">${UI.escapeHtml(o.retryLabel || t('ui.retry'))}</button>
                </div>`
            : '';

        return `
            <div class="empty-state">
                <div class="empty-state-title">${UI.escapeHtml(o.title || t('ui.error_title'))}</div>
                <p class="text-muted">${UI.escapeHtml(o.message || '')}</p>${retry}
            </div>`;
    }

    /**
     * Pantalla de "sin permiso" (card centrada con CTA al dashboard).
     * title y message se insertan SIN escapar: las traducciones llevan
     * <code>{permiso}</code>.
     *
     * @param {{title?: string, message?: string, ctaHref?: string,
     *          ctaLabel?: string}} opts
     */
    function deniedState(opts) {
        const o = opts || {};
        return `
        <div class="card max-w-md mx-auto mt-8">
            <div class="card-header">
                <h3 class="card-title">${o.title || ''}</h3>
            </div>
            <div class="card-body">
                <p>${o.message || ''}</p>
                <div class="mt-4">
                    <a href="${UI.escapeHtml(o.ctaHref || '#/dashboard')}" class="btn btn-secondary">${UI.escapeHtml(o.ctaLabel || t('router.go_dashboard'))}</a>
                </div>
            </div>
        </div>`;
    }

    // ====================================================================
    // ACCIONES DE FILA (rowActions)
    // ====================================================================

    /**
     * Cierra el menu abierto y limpia aria-expanded. `except` permite
     * conservar el desplegable que se acaba de abrir.
     */
    function closeRowMenus(except) {
        document.querySelectorAll('[data-row-menu].is-open').forEach(menu => {
            if (menu === except) return;
            menu.classList.remove('is-open');
            menu.setAttribute('aria-hidden', 'true');
            const trigger = menu.parentElement && menu.parentElement.querySelector('[data-row-menu-trigger]');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    }

    /**
     * Posiciona el menú dentro del viewport con `position: fixed` para que
     * el overflow del `.table-wrapper` no lo recorte: se ancla bajo el
     * disparador y, si no cabe, se coloca encima; el `left` se ajusta para
     * no salirse por la derecha ni por la izquierda.
     */
    function positionRowMenu(dropdown, menu) {
        const trigger = dropdown.querySelector('[data-row-menu-trigger]');
        if (!trigger) return;

        menu.style.top = '0';
        menu.style.left = '0';
        menu.style.bottom = 'auto';
        menu.style.right = 'auto';
        menu.style.marginTop = '0';
        menu.style.marginBottom = '0';
        menu.style.visibility = 'hidden';

        const t = trigger.getBoundingClientRect();
        const m = menu.getBoundingClientRect();
        const vw = document.documentElement.clientWidth || window.innerWidth;
        const vh = document.documentElement.clientHeight || window.innerHeight;

        const gap = 4;
        let top = t.bottom + gap;
        if (top + m.height > vh - gap && t.top - m.height - gap > gap) {
            top = t.top - m.height - gap;
        }
        top = Math.max(gap, Math.min(top, vh - m.height - gap));

        let left = t.right - m.width;
        left = Math.max(gap, Math.min(left, vw - m.width - gap));

        menu.style.top = Math.round(top) + 'px';
        menu.style.left = Math.round(left) + 'px';
        menu.style.visibility = '';
    }

    function openRowMenu(trigger) {
        const dropdown = trigger.closest('.dropdown');
        if (!dropdown) return;
        const menu = dropdown.querySelector('[data-row-menu]');
        if (!menu) return;

        const willOpen = !menu.classList.contains('is-open');
        closeRowMenus(willOpen ? menu : null);
        if (!willOpen) {
            menu.classList.remove('is-open');
            menu.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            return;
        }

        menu.classList.add('is-open');
        menu.setAttribute('aria-hidden', 'false');
        trigger.setAttribute('aria-expanded', 'true');
        positionRowMenu(dropdown, menu);

        const first = menu.querySelector('.dropdown-item:not([disabled])');
        if (first) first.focus();
    }

    /**
     * Acciones de fila: principal discreta + menu de tres puntos.
     *
     * @param {{primary?: {action: string, id?: string, label: string,
     *                     disabled?: boolean}|null,
     *          groups?: Array<Array<{action: string, id?: string, label: string,
     *                                 danger?: boolean, disabled?: boolean}>>,
     *          ariaLabel?: string}} opts
     *   primary: accion mas frecuente (p. ej. Editar) como btn-ghost.
     *   groups:  cada array es un grupo del menu; se separan con divider y
     *            las opciones `danger` van al final del ultimo grupo (la
     *            convencion es ponerlas en su propio grupo).
     *   Si no hay primary ni opciones tras filtrar permisos, devuelve ''.
     *   El menu se pinta con aria-haspopup="menu" y aria-expanded="false".
     */
    function rowActions(opts) {
        const o = opts || {};
        const primary = o.primary || null;
        const allGroups = Array.isArray(o.groups) ? o.groups : [];
        const ariaLabel = o.ariaLabel || t('ui.row_actions');

        const groups = allGroups
            .map(g => (Array.isArray(g) ? g : []).filter(item => item && item.action))
            .filter(g => g.length > 0);

        if (!primary && groups.length === 0) return '';

        const primaryHtml = primary && primary.action
            ? `<button type="button" class="btn btn-ghost btn-sm" data-action="${UI.escapeHtml(primary.action)}"${primary.id ? ` data-id="${UI.escapeHtml(primary.id)}"` : ''}${primary.disabled ? ' disabled' : ''}>${UI.escapeHtml(primary.label || '')}</button>`
            : '';

        if (groups.length === 0) {
            return `<div class="row-actions">${primaryHtml}</div>`;
        }

        const menuHtml = groups.map((grupo, index) => {
            const items = grupo.map(item => `
                <button type="button" role="menuitem" class="dropdown-item${item.danger ? ' danger' : ''}" data-action="${UI.escapeHtml(item.action)}"${item.id ? ` data-id="${UI.escapeHtml(item.id)}"` : ''}${item.disabled ? ' disabled' : ''}>${UI.escapeHtml(item.label || '')}</button>`).join('');
            const divider = index < groups.length - 1 ? '<div class="dropdown-divider" role="separator"></div>' : '';
            return items + divider;
        }).join('');

        return `
            <div class="row-actions">
                ${primaryHtml}
                <div class="dropdown">
                    <button type="button" class="btn btn-ghost btn-sm row-actions-trigger" data-row-menu-trigger
                            aria-haspopup="menu" aria-expanded="false" aria-label="${UI.escapeHtml(ariaLabel)}">&#8942;</button>
                    <div class="dropdown-menu" data-row-menu role="menu" aria-hidden="true">${menuHtml}</div>
                </div>
            </div>`;
    }

    // Delegacion global de menu de fila: un solo par de listeners para
    // abrir/cerrar con clic, Esc y clic fuera. Se registra una sola vez.
    let rowMenuBound = false;

    function bindRowMenuGlobal() {
        if (rowMenuBound || typeof document === 'undefined') return;
        rowMenuBound = true;

        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-row-menu-trigger]');
            if (trigger) {
                e.preventDefault();
                e.stopPropagation();
                openRowMenu(trigger);
                return;
            }
            if (!e.target.closest('[data-row-menu]')) {
                closeRowMenus(null);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const open = document.querySelector('[data-row-menu].is-open');
                if (!open) return;
                const trigger = open.parentElement && open.parentElement.querySelector('[data-row-menu-trigger]');
                closeRowMenus(null);
                if (trigger) trigger.focus();
            }
        });

        window.addEventListener('resize', () => closeRowMenus(null));
        window.addEventListener('scroll', () => closeRowMenus(null), true);
    }

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindRowMenuGlobal);
        } else {
            bindRowMenuGlobal();
        }
    }

    global.UI = Object.assign(global.UI || {}, {
        tabsBar: tabsBar,
        tabPanel: tabPanel,
        applyTabState: applyTabState,
        tabFromParams: tabFromParams,
        syncTabParam: syncTabParam,
        viewShell: viewShell,
        filtersCard: filtersCard,
        formFooter: formFooter,
        errorState: errorState,
        deniedState: deniedState,
        rowActions: rowActions
    });

})(window);
