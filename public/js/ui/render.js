/**
 * UI Components - Render (v1.9.3)
 *
 * Bloques de presentación reutilizables: cabecera de página, badges de
 * estado, estados vacío/carga, tabla, paginación y tarjetas de resumen.
 * Depende de ui/dom.js (escapeHtml, formatNumber).
 *
 * Contrato de paginación: el backend de lista devuelve
 * `{ total, page, per_page, has_next }`; se soporta también `last_page` por
 * si alguna respuesta lo trae, sin romper el formato actual.
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    /**
     * Cabecera de pagina: titulo, subtitulo y acciones.
     *
     * @param {{title: string, subtitle?: string, actionsHtml?: string,
     *          titleAttrs?: string, subtitleAttrs?: string,
     *          wrapperClass?: string}} opts
     *   titleAttrs/subtitleAttrs: atributos crudos para el <h2> y el <p>
     *     (p. ej. hooks de actualizacion posterior); por defecto vacios.
     *   wrapperClass: clases del contenedor; por defecto el layout de fila
     *     con acciones a la derecha.
     */
    function pageHeader(opts) {
        const o = opts || {};
        const titleAttrs = o.titleAttrs ? ' ' + o.titleAttrs : '';
        const subtitleAttrs = o.subtitleAttrs ? ' ' + o.subtitleAttrs : '';
        return `
            <div class="${o.wrapperClass || 'flex items-center justify-between mb-6'}">
                <div>
                    <h2 class="text-2xl font-bold"${titleAttrs}>${UI.escapeHtml(o.title || '')}</h2>
                    <p class="text-muted text-sm"${subtitleAttrs}>${UI.escapeHtml(o.subtitle || '')}</p>
                </div>
                ${o.actionsHtml || ''}
            </div>`;
    }

    /**
     * Badge de estado a partir de un catálogo [{value, label, color}].
     * Valor fuera del catálogo: badge con `defaultColor` y el valor crudo.
     */
    function estadoBadge(valor, catalogo, defaultColor) {
        const crudo = valor === null || valor === undefined ? '' : String(valor);
        const entrada = Array.isArray(catalogo)
            ? catalogo.find(x => x.value === crudo)
            : null;
        const color = entrada && entrada.color
            ? entrada.color
            : (defaultColor || 'neutral');
        const label = entrada && entrada.label ? entrada.label : crudo;

        return `<span class="badge badge-${UI.escapeHtml(color)}">${UI.escapeHtml(label)}</span>`;
    }

    function emptyState(opts) {
        const o = opts || {};
        return `
            <div class="empty-state">
                <div class="empty-state-title">${UI.escapeHtml(o.title || t('ui.empty_title'))}</div>
                <p class="text-muted">${UI.escapeHtml(o.message || '')}</p>
            </div>`;
    }

    function loadingState() {
        return `
            <div class="flex items-center justify-center py-12">
                <div class="spinner"></div>
            </div>`;
    }

    /**
     * Skeleton de tabla: misma estructura que tableShell (thead + tbody)
     * pero con filas placeholder animadas. Mantiene el layout estable
     * mientras cargan los datos — no hay parpadeo ni layout shift.
     *
     * @param {Array<{label: string, className?: string}>} columnas
     * @param {number} filas numero de filas placeholder (default 8)
     * @param {Array<number>} widths anchos relativos por columna en % (opcional)
     */
    function tableSkeleton(columnas, filas, widths) {
        const cols = columnas || [];
        const numRows = Math.max(1, Number(filas) || 8);
        const colWidths = Array.isArray(widths) && widths.length === cols.length
            ? widths
            : cols.map(() => Math.floor(100 / Math.max(1, cols.length)));

        const cabecera = cols.map(col => {
            const clase = col.className ? ` class="${col.className}"` : '';
            return `<th${clase}>${UI.escapeHtml(col.label)}</th>`;
        }).join('');

        const filasHtml = Array.from({ length: numRows }, () => {
            const celdas = colWidths.map(w => `
                <td><div class="skeleton" style="height:0.875rem;width:${w}%;max-width:12rem;border-radius:var(--radius-sm)"></div></td>
            `).join('');
            return `<tr aria-hidden="true">${celdas}</tr>`;
        }).join('');

        return `
            <table class="table">
                <thead>
                    <tr>${cabecera}</tr>
                </thead>
                <tbody>${filasHtml}</tbody>
            </table>`;
    }

    /**
     * Skeleton de tarjetas de resumen: N placeholders con la misma
     * altura y estructura que summaryCards para evitar layout shift.
     *
     * @param {number} count numero de tarjetas (default 4)
     */
    function cardsSkeleton(count) {
        const n = Math.max(1, Number(count) || 4);
        return Array.from({ length: n }, () => `
            <div class="card p-3">
                <div class="skeleton" style="height:0.75rem;width:40%;margin-bottom:0.5rem;border-radius:var(--radius-sm)"></div>
                <div class="skeleton" style="height:1.5rem;width:60%;border-radius:var(--radius-sm)"></div>
            </div>
        `).join('');
    }

    /**
     * @param {Array<{label: string, className?: string}>} columnas
     * @param {string} filasHtml
     */
    function tableShell(columnas, filasHtml) {
        const cabecera = (columnas || []).map(col => {
            const clase = col.className ? ` class="${col.className}"` : '';
            return `<th${clase}>${UI.escapeHtml(col.label)}</th>`;
        }).join('');

        return `
            <table class="table">
                <thead>
                    <tr>${cabecera}</tr>
                </thead>
                <tbody>${filasHtml || ''}</tbody>
            </table>`;
    }

    /**
     * Paginación estándar dentro de un contenedor `.pagination`.
     *
     * @param {Element} root
     * @param {{pagination: {total: number, page: number, per_page: number,
     *         has_next?: boolean, last_page?: number},
     *         shown: number, label: string}} opts
     */
    function renderPagination(root, opts) {
        if (!root) return;
        const o = opts || {};
        const p = o.pagination || {};
        const total = Number(p.total || 0);
        const page = Number(p.page || 1);
        const perPage = Number(p.per_page || 20);
        const totalPages = p.last_page
            ? Number(p.last_page)
            : Math.max(1, Math.ceil(total / perPage));
        const hasNext = p.last_page
            ? (page < Number(p.last_page))
            : !!p.has_next;

        // El dict (recurso propio) se inserta sin escapar; el label de la
        // llamada SÍ se escapa antes de interpolar {label}.
        const labelHtml = UI.escapeHtml(o.label || t('ui.records'));

        root.innerHTML = `
            <div class="pagination-info">
                ${t('ui.pagination', { shown: Number(o.shown || 0), total: total, label: labelHtml })}
            </div>
            <div class="pagination-controls">
                <button class="page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>&lsaquo;</button>
                <span class="page-btn is-active">${page} / ${totalPages}</span>
                <button class="page-btn" data-page="${page + 1}" ${!hasNext ? 'disabled' : ''}>&rsaquo;</button>
            </div>`;
    }

    /**
     * Tarjetas de resumen en un grid ya renderizado por la plantilla.
     *
     * @param {Element} root
     * @param {Array<{label: string, value: string|number}>} cards
     */
    function summaryCards(root, cards) {
        if (!root) return;
        root.innerHTML = (cards || []).map(c => `
            <div class="card p-3">
                <div class="text-xs text-muted">${UI.escapeHtml(c.label)}</div>
                <div class="text-xl font-bold">${UI.escapeHtml(UI.formatNumber(c.value))}</div>
            </div>
        `).join('');
    }

    global.UI = Object.assign(global.UI || {}, {
        pageHeader: pageHeader,
        estadoBadge: estadoBadge,
        emptyState: emptyState,
        loadingState: loadingState,
        tableSkeleton: tableSkeleton,
        cardsSkeleton: cardsSkeleton,
        tableShell: tableShell,
        renderPagination: renderPagination,
        summaryCards: summaryCards
    });

})(window);
