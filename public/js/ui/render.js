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
     * Skeleton de lista/panel (v1.17.11): N barras apiladas con anchos
     * variados. Para modales, paneles de detalle y holders donde
     * tableSkeleton (con thead) no encaja. Sustituye a loadingState()
     * (spinner) en esas regiones.
     *
     * @param {number} count numero de barras (default 5)
     * @param {Array<number>} widths anchos relativos en % (opcional)
     */
    function rowsSkeleton(count, widths) {
        const n = Math.max(1, Number(count) || 5);
        const defecto = [92, 78, 85, 64, 88, 70, 80, 75];
        const filas = Array.from({ length: n }, (_, i) => {
            const w = Array.isArray(widths) && widths[i] != null ? widths[i] : defecto[i % defecto.length];
            return `<div class="skeleton" style="height:0.875rem;width:${w}%;margin-bottom:0.625rem;border-radius:var(--radius-sm)"></div>`;
        }).join('');
        return `<div class="skeleton-rows" aria-hidden="true">${filas}</div>`;
    }

    // ==================================================================
    // UMBRAL DE SKELETON + AVISO DE CARGA LARGA (v1.17.11)
    // ==================================================================

    const SKELETON_GATE_DELAY = 180;
    const gateTimers = new WeakMap();
    const gateSeen = new WeakMap();
    let skeletonSlowMs = 8000;
    let pendingGates = 0;
    let slowTimer = null;
    let slowNote = null;

    // El aviso de carga larga es global: mientras haya al menos una carga
    // pendiente mas de skeletonSlowMs, se muestra una nota discreta fija.
    // Sin cableado por vista: cualquier gate la alimenta.
    function slowNoteShow() {
        if (slowNote || !document.body) return;
        slowNote = document.createElement('div');
        slowNote.className = 'load-slow-note';
        slowNote.setAttribute('role', 'status');
        slowNote.textContent = t('ui.slow_load');
        document.body.appendChild(slowNote);
    }

    function slowNoteHide() {
        if (slowNote && slowNote.parentNode) slowNote.parentNode.removeChild(slowNote);
        slowNote = null;
    }

    function pendingTrack(delta) {
        pendingGates = Math.max(0, pendingGates + delta);
        if (pendingGates === 0) {
            if (slowTimer) { clearTimeout(slowTimer); slowTimer = null; }
            slowNoteHide();
        } else if (!slowTimer) {
            slowTimer = setTimeout(() => {
                slowTimer = null;
                if (pendingGates > 0) slowNoteShow();
            }, skeletonSlowMs);
        }
    }

    /**
     * Umbral de skeleton (v1.17.11): la region NO pinta skeleton al instante
     * en cada recarga. Primera carga del estado+clave: skeleton inmediato
     * (no hay contenido previo que conservar). Recargas: el contenido
     * estable permanece y el skeleton se enciende solo si la peticion
     * supera ~180 ms — respuestas rapidas no producen parpadeo.
     *
     * Uso:
     *   const gate = UI.skeletonGate(state, 'loading', renderTabla);
     *   try { ... } finally { gate.done(); renderTabla(); }
     *
     * @param {object} state objeto de estado de la vista (o subobjeto)
     * @param {string} key nombre de la flag de loading en state
     * @param {Function} render repintado de la region
     * @param {number} delay umbral en ms (default 180)
     */
    function skeletonGate(state, key, render, delay) {
        const ms = (typeof delay === 'number' && delay >= 0) ? delay : SKELETON_GATE_DELAY;
        const slot = gateTimers.get(state) || {};
        if (slot[key]) {
            clearTimeout(slot[key].timer);
            slot[key] = null;
            pendingTrack(-1);
        }

        const seenMap = gateSeen.get(state) || {};
        const primera = !seenMap[key];
        seenMap[key] = true;
        gateSeen.set(state, seenMap);

        let finalized = false;

        if (primera) {
            state[key] = true;
            render();
        } else {
            state[key] = false;
        }

        const entry = {};
        entry.timer = setTimeout(() => {
            entry.timer = null;
            pendingTrack(-1);
            if (finalized || primera) return;
            state[key] = true;
            render();
        }, ms);
        slot[key] = entry;
        gateTimers.set(state, slot);
        pendingTrack(1);

        return {
            done() {
                if (finalized) return;
                finalized = true;
                if (entry.timer) {
                    clearTimeout(entry.timer);
                    entry.timer = null;
                    pendingTrack(-1);
                }
                state[key] = false;
            }
        };
    }

    // Exposed para tests (smoke): el umbral de "carga larga" por defecto
    // es 8 s; los smokes lo bajan para no esperar.
    function setSkeletonSlowMs(ms) {
        if (typeof ms === 'number' && ms > 0) skeletonSlowMs = ms;
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
        const perPage = Number(p.per_page || 25);
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
     * Con `opts.inline` la misma lista se pinta como UNA sola fila compacta
     * (titulo opcional + pares etiqueta/valor en linea) para resumenes de una
     * linea; cada item puede llevar `route` para quedar enlazado mediante el
     * gestor de acciones de la vista (`data-action="navigate"`). Sin `route`
     * el item sale como texto plano, igual que el modo apilado.
     *
     * @param {Element} root
     * @param {Array<{label: string, value: string|number, route?: string}>} cards
     * @param {{inline?: boolean, title?: string}} [opts]
     */
    function summaryCards(root, cards, opts) {
        if (!root) return;
        const list = cards || [];

        if (opts && opts.inline) {
            const title = opts.title
                ? `<span class="text-sm font-medium">${UI.escapeHtml(opts.title)}</span>`
                : '';
            root.innerHTML = `
                <div class="flex items-center gap-x-4 gap-y-2 flex-wrap">
                    ${title}
                    ${list.map(summaryInlineItem).join('')}
                </div>`;
            animateList(root);
            return;
        }

        root.innerHTML = list.map(c => `
            <div class="card p-3">
                <div class="text-xs text-muted">${UI.escapeHtml(c.label)}</div>
                <div class="text-xl font-bold">${UI.escapeHtml(UI.formatNumber(c.value))}</div>
            </div>
        `).join('');
        animateList(root);
    }

    /** Par etiqueta/valor de la tira inline (ver summaryCards). */
    function summaryInlineItem(c) {
        const inner = `<span class="text-xs text-muted">${UI.escapeHtml(c.label)}</span>
            <strong class="text-sm">${UI.escapeHtml(UI.formatNumber(c.value))}</strong>`;
        if (!c.route) {
            return `<span class="flex items-baseline gap-1.5">${inner}</span>`;
        }
        return `<span class="flex items-baseline gap-1.5 cursor-pointer" role="button" tabindex="0"
            data-action="navigate" data-route="${UI.escapeHtml(c.route)}">${inner}</span>`;
    }

    // ---- AutoAnimate (v1.19) -------------------------------------------------
    // Al filtrar o paginar la región de una tabla se repinta entera y antes no
    // había transición entre la lista vieja y la nueva. Se entrega la región a
    // AutoAnimate (public/js/vendor/auto-animate.js, IIFE global) una sola vez;
    // desde ahí él mismo anima los siguientes repintados.
    // El objetivo es SIEMPRE la región contenedora: pasarle un <tr> rompe
    // display: table-row. Sin autoAnimate cargado o con prefers-reduced-motion
    // no se anima nada.
    const animatedRoots = new WeakSet();

    function motionAllowed() {
        return !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function animateList(root) {
        if (!root || animatedRoots.has(root)) return;
        if (root.closest('[data-panel]')) return;
        if (typeof global.autoAnimate !== 'function' || !motionAllowed()) return;
        animatedRoots.add(root);
        global.autoAnimate(root);
    }

    // Observa #module-container: cuando una región que contiene una <table>
    // cambia de contenido se le entrega a AutoAnimate. El primer repintado de
    // cada región queda sin animar (es el montaje); los siguientes, sí.
    function watchListRegions(container) {
        if (!container || typeof MutationObserver !== 'function' || !motionAllowed()) return;
        const observer = new MutationObserver((records) => {
            for (const record of records) {
                if (record.type !== 'childList') continue;
                const target = record.target;
                if (!target || typeof target.querySelector !== 'function') continue;
                if (!target.querySelector('table')) continue;
                animateList(target);
            }
        });
        observer.observe(container, { childList: true, subtree: true });
    }

    function initListAnimations() {
        watchListRegions(document.getElementById('module-container'));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initListAnimations, { once: true });
    } else {
        initListAnimations();
    }

    global.UI = Object.assign(global.UI || {}, {
        pageHeader: pageHeader,
        estadoBadge: estadoBadge,
        emptyState: emptyState,
        loadingState: loadingState,
        tableSkeleton: tableSkeleton,
        cardsSkeleton: cardsSkeleton,
        rowsSkeleton: rowsSkeleton,
        skeletonGate: skeletonGate,
        setSkeletonSlowMs: setSkeletonSlowMs,
        tableShell: tableShell,
        renderPagination: renderPagination,
        summaryCards: summaryCards,
        animateList: animateList
    });

})(window);
