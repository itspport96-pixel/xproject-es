/**
 * Inventory Counts Module
 * Pantalla "Conteos" (CU-011) - v1.9.3.
 *
 * Convención ModuleLoader: window.InventoryCountsModule
 *
 * Bloques (sólo endpoints existentes en src/Modules/Inventory/routes.php):
 *  1. Listado paginado `GET /api/inventory/counts` con filtros por estado,
 *     ubicación y rango de fechas, más el summary de la respuesta (total,
 *     pendientes, en_curso, completados, reconciliados).
 *  2. Inicio `POST /api/inventory/counts/start` con Idempotency-Key
 *     OBLIGATORIA (crypto.randomUUID al abrir el modal, reutilizada en
 *     reintentos y renovada tras éxito o 409):
 *       - ubicacion_id opcional (sin él se cuentan todas las unidades no
 *         virtuales del tenant); fecha opcional (`fecha_conteo`).
 *  3. Detalle `GET /api/inventory/counts/{id}` (lista teórica, resultados,
 *     discrepancias y ajustes aplicados) en un modal grande con secciones.
 *  4. Resultados `POST /api/inventory/counts/{id}/results` (sin clave): lee
 *     los inputs del DOM sólo de las filas con cantidad informada y los
 *     fusiona; pasa PENDIENTE → EN_CURSO.
 *  5. Completar `POST /api/inventory/counts/{id}/complete` (sin clave): si
 *     faltan unidades responde 422 `field: resultados` con los ids en el
 *     mensaje ("Faltan resultados para N unidad(es): id1, id2."); se marcan
 *     esas filas en la hoja teórica.
 *  6. Reconciliar `POST /api/inventory/counts/{id}/reconcile` con
 *     Idempotency-Key OBLIGATORIA: una acción (AJUSTAR | JUSTIFICAR) por
 *     discrepancia pendiente; JUSTIFICAR exige justificación.
 *  7. Hoja CSV `GET /api/inventory/counts/{id}/sheet?format=csv` vía
 *     UI.downloadCsv (fetch con JWT, nunca enlace directo).
 *
 * Permisos (routes.php):
 *  - inventory.count.view      → listado, detalle y hoja
 *  - inventory.count.create    → start, results y complete
 *  - inventory.count.reconcile → reconcile
 *
 * Errores del backend → toast + error de campo cuando traen `field`:
 * 400 (fecha o acción inválidas), 401, 403, 404 (conteo o ubicación), 409
 * (idempotencia o discrepancia ya resuelta), 422 (estado del conteo, faltan
 * resultados, unidad fuera de la hoja, cantidad vencida y JUSTIFICAR sin
 * justificación). La API sólo expone `message/category/field` en el 422 de
 * cantidad vencida: el detalle (unidad, esperada y actual) se extrae del
 * mensaje con regex (desviación documentada en el informe).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    const state = {
        items: [],
        summary: { total: 0, pendientes: 0, en_curso: 0, completados: 0, reconciliados: 0 },
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        loading: false,
        filters: { estado: '', ubicacion_id: '', fecha_desde: '', fecha_hasta: '' },
        locations: [],
        locationsMode: 'select',
        modal: null,
        sending: false
    };

    const ESTADOS = [
        { value: 'PENDIENTE', labelKey: 'inventory.counts.states.pendiente', color: 'warning' },
        { value: 'EN_CURSO', labelKey: 'inventory.counts.states.en_curso', color: 'info' },
        { value: 'COMPLETADO', labelKey: 'inventory.counts.states.completado', color: 'success' },
        { value: 'RECONCILIADO', labelKey: 'inventory.counts.states.reconciliado', color: 'neutral' }
    ];

    const ACCIONES = [
        { value: 'AJUSTAR', labelKey: 'inventory.counts.actions.ajustar' },
        { value: 'JUSTIFICAR', labelKey: 'inventory.counts.actions.justificar' }
    ];

    const estadoCatalog = () => ESTADOS.map(e => ({
        value: e.value,
        label: t(e.labelKey),
        color: e.color
    }));

    const ABIERTOS = ['PENDIENTE', 'EN_CURSO'];

    // ====================================================================
    // PERMISOS
    // ====================================================================

    const canView = () => UI.can('inventory.count.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
    const canCreate = () => UI.can('inventory.count.create', ['ADMIN', 'MANAGER', 'USER']);
    const canReconcile = () => UI.can('inventory.count.reconcile', ['ADMIN', 'MANAGER']);

    // ====================================================================
    // PLANTILLA
    // ====================================================================

    function buildTemplate() {
        return `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.counts.title'),
                subtitle: t('inventory.counts.subtitle'),
                actionsHtml: canCreate()
                    ? `<button class="btn btn-primary btn-sm" data-action="open-start">${t('inventory.counts.actions.start')}</button>`
                    : ''
            })}

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4" data-cnt-summary></div>

            <div class="card mb-4">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.counts.filters.estado')}</label>
                        <select class="select" data-filter="estado">
                            <option value="">${t('inventory.counts.filters.all_states')}</option>
                            ${ESTADOS.map(e => `<option value="${UI.escapeHtml(e.value)}">${t(e.labelKey)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group mb-0" data-location-filter></div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.counts.filters.fecha_desde')}</label>
                        <input type="date" class="input" data-filter="fecha_desde">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.counts.filters.fecha_hasta')}</label>
                        <input type="date" class="input" data-filter="fecha_hasta">
                    </div>
                    <div class="form-group mb-0 flex gap-2 items-end">
                        <button class="btn btn-secondary flex-1" data-action="apply-filters">${t('inventory.counts.filters.apply')}</button>
                        <button class="btn btn-ghost" data-action="clear-filters" title="${t('inventory.counts.filters.clear_title')}">${t('inventory.counts.filters.clear')}</button>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="table-wrapper" data-cnt-table></div>
                <div data-cnt-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>`;
    }

    const DENIED_TEMPLATE = `
        <div class="card max-w-md mx-auto mt-8">
            <div class="card-header">
                <h3 class="card-title">${t('inventory.counts.permission.title')}</h3>
            </div>
            <div class="card-body">
                <p>${t('inventory.counts.permission.body')}</p>
                <div class="mt-4">
                    <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                </div>
            </div>
        </div>`;

    // ====================================================================
    // RENDER - LISTADO
    // ====================================================================

    function renderSummary() {
        const root = container.querySelector('[data-cnt-summary]');
        if (!root) return;

        UI.summaryCards(root, [
            { label: t('inventory.counts.summary.conteos'), value: state.summary.total },
            { label: t('inventory.counts.summary.pendientes'), value: state.summary.pendientes },
            { label: t('inventory.counts.summary.en_curso'), value: state.summary.en_curso },
            { label: t('inventory.counts.summary.completados'), value: state.summary.completados },
            { label: t('inventory.counts.summary.reconciliados'), value: state.summary.reconciliados }
        ]);
    }

    function renderLocationFilter() {
        const root = container && container.querySelector('[data-location-filter]');
        if (!root) return;

        if (state.locationsMode === 'select') {
            root.innerHTML = `
                <label class="form-label">${t('inventory.counts.filters.ubicacion')}</label>
                <select class="select" data-filter="ubicacion_id">
                    <option value="">${t('inventory.counts.filters.all_locations')}</option>
                    ${state.locations.map(l => `
                        <option value="${UI.escapeHtml(l.id)}">${UI.escapeHtml(l.codigo)}${l.nombre ? ' · ' + UI.escapeHtml(l.nombre) : ''}</option>`).join('')}
                </select>`;
            root.querySelector('[data-filter]').value = state.filters.ubicacion_id || '';
        } else {
            root.innerHTML = `
                <label class="form-label">${t('inventory.counts.filters.ubicacion_id')}</label>
                <input type="text" class="input" data-filter="ubicacion_id"
                       placeholder="${t('inventory.counts.filters.ubicacion_placeholder')}" autocomplete="off">`;
            root.querySelector('[data-filter]').value = state.filters.ubicacion_id || '';
        }
    }

    function renderTable() {
        const root = container.querySelector('[data-cnt-table]');
        if (!root) return;

        if (state.loading) {
            root.innerHTML = UI.tableSkeleton([
                { label: t('inventory.counts.columns.conteo') },
                { label: t('inventory.counts.columns.ubicacion') },
                { label: t('inventory.counts.columns.estado') },
                { label: t('inventory.counts.columns.fecha') },
                { label: t('inventory.counts.columns.discrepancias') },
                { label: t('inventory.counts.columns.reconciliado') },
                { label: t('inventory.counts.columns.acciones'), className: 'col-actions' }
            ], 8, [18, 16, 12, 14, 14, 12, 14]);
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('inventory.counts.empty.title'),
                message: t('inventory.counts.empty.message')
            });
            return;
        }

        const rows = state.items.map(c => {
            const abierto = ABIERTOS.includes(c.estado);
            const porReconciliar = c.estado === 'COMPLETADO' && Number(c.discrepancias_pendientes || 0) > 0;

            let etiqueta = t('inventory.counts.actions.view');
            if (canCreate() && abierto) {
                etiqueta = t('inventory.counts.actions.count');
            }
            if (canReconcile() && porReconciliar) {
                etiqueta = t('inventory.counts.actions.reconcile');
            }

            const acciones = UI.rowActions({
                primary: { action: 'open-detail', id: c.conteo_id, label: etiqueta },
                ariaLabel: t('inventory.counts.columns.acciones')
            });

            return `
            <tr>
                <td class="font-mono text-xs">${UI.escapeHtml(c.conteo_id)}</td>
                <td class="font-mono text-xs">${UI.escapeHtml(c.ubicacion_codigo || '—')}</td>
                <td>${UI.estadoBadge(c.estado, estadoCatalog())}</td>
                <td class="text-xs">${UI.escapeHtml(c.fecha_conteo || '—')}</td>
                <td class="text-xs">${UI.formatNumber(c.discrepancias || 0)} / ${UI.formatNumber(c.total_discrepancias || 0)}</td>
                <td>${c.reconciliado
                    ? `<span class="badge badge-success">${t('inventory.counts.badges.si')}</span>`
                    : `<span class="badge badge-neutral">${t('inventory.counts.badges.no')}</span>`}</td>
                <td class="table-actions col-actions">${acciones}</td>
            </tr>`;
        }).join('');

        root.innerHTML = UI.tableShell([
            { label: t('inventory.counts.columns.conteo') },
            { label: t('inventory.counts.columns.ubicacion') },
            { label: t('inventory.counts.columns.estado') },
            { label: t('inventory.counts.columns.fecha') },
            { label: t('inventory.counts.columns.discrepancias') },
            { label: t('inventory.counts.columns.reconciliado') },
            { label: t('inventory.counts.columns.acciones'), className: 'col-actions' }
        ], rows);
    }

    function renderPagination() {
        const root = container.querySelector('[data-cnt-pagination]');
        if (!root) return;

        UI.renderPagination(root, {
            pagination: state.pagination,
            shown: state.items.length,
            label: t('inventory.counts.pagination_label')
        });
    }

    function renderAll() {
        renderSummary();
        renderTable();
        renderPagination();
    }

    // ====================================================================
    // API - LISTADO Y CATÁLOGOS
    // ====================================================================

    async function loadCounts(page = 1) {
        state.loading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            Object.entries(state.filters).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) params[key] = value;
            });

            const response = await global.HttpClient.get('/inventory/counts', { params });
            const payload = response && response.data ? response.data : response;

            state.items = (payload && payload.data) || [];
            state.summary = (payload && payload.summary) || state.summary;
            state.pagination = (payload && payload.pagination) || state.pagination;
            state.pagination.page = (payload && payload.pagination && payload.pagination.page) || page;
            return true;
        } catch (err) {
            console.error('[Counts] load error:', err);
            state.items = [];

            if (err && err.status === 403) {
                UI.toast('danger', t('inventory.counts.permission.title'), t('inventory.counts.messages.forbidden_view'));
            } else {
                UI.toast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.counts.messages.load_error'));
            }
            return false;
        } finally {
            state.loading = false;
            renderAll();
        }
    }

    async function loadLocationFilterOptions() {
        try {
            const response = await global.HttpClient.get('/inventory/locations', {
                params: { activa: 'true', page: 1, per_page: 100 }
            });
            const payload = response && response.data ? response.data : response;
            state.locations = (payload && payload.data) || [];
            state.locationsMode = 'select';
        } catch (err) {
            // Sin inventory.location.view no hay catálogo: filtro por ID libre.
            console.warn('[Counts] no location catalog for filter:', err && err.status);
            state.locations = [];
            state.locationsMode = 'input';
        }
        renderLocationFilter();
    }

    // ====================================================================
    // FILTROS
    // ====================================================================

    function readFilters() {
        container.querySelectorAll('[data-filter]').forEach(el => {
            state.filters[el.dataset.filter] = el.value;
        });
    }

    function clearFilters() {
        state.filters = { estado: '', ubicacion_id: '', fecha_desde: '', fecha_hasta: '' };
        container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
        loadCounts(1);
    }

    // ====================================================================
    // MODAL - INICIAR CONTEO
    // ====================================================================

    function openStart() {
        if (!canCreate()) return;

        state.modal = {
            mode: 'start',
            ubicacion_id: '',
            fecha: '',
            key: UI.newIdempotencyKey(),
            formError: '',
            errors: {}
        };
        renderModal();
    }

    function startBody(modal) {
        const opciones = [{ value: '', label: t('inventory.counts.modals.start.all_tenant') }].concat(
            state.locations.map(l => ({
                value: String(l.id),
                label: l.codigo + (l.nombre ? ' · ' + l.nombre : '')
            }))
        );

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${UI.grid(`
                <div>
                    ${UI.field({
                        label: t('inventory.counts.modals.start.ubicacion_label'),
                        name: 'ubicacion_id',
                        type: 'select',
                        value: modal.ubicacion_id,
                        options: state.locationsMode === 'select'
                            ? opciones
                            : [{ value: '', label: t('inventory.counts.modals.start.all_tenant') }],
                        hint: t('inventory.counts.modals.start.ubicacion_hint')
                    })}
                </div>
                <div>
                    ${UI.field({
                        label: t('inventory.counts.modals.start.fecha_label'),
                        name: 'fecha',
                        type: 'date',
                        value: modal.fecha,
                        hint: t('inventory.counts.modals.start.fecha_hint')
                    })}
                </div>
            `, 2)}
        `;
    }

    async function submitStart() {
        const modal = state.modal;
        if (state.sending || !modal || modal.mode !== 'start') return;

        const root = getModalRoot();
        UI.clearErrors(root);

        const ubicacionEl = root.querySelector('[data-modal-field="ubicacion_id"]');
        const fechaEl = root.querySelector('[data-modal-field="fecha"]');
        const ubicacion = ubicacionEl ? ubicacionEl.value.trim() : '';
        const fecha = fechaEl ? fechaEl.value.trim() : '';

        modal.ubicacion_id = ubicacion;
        modal.fecha = fecha;

        const payload = {};
        if (ubicacion) payload.ubicacion_id = ubicacion;
        if (fecha) payload.fecha = fecha;

        setSending(true);

        try {
            const response = await global.HttpClient.post('/inventory/counts/start', payload, {
                headers: { 'Idempotency-Key': modal.key }
            });
            const data = response && response.data ? response.data : response;
            const conteo = (data && data.conteo) || null;

            closeModal();
            const recargadoLista = await loadCounts(1);
            let recargadoDetalle = true;

            if (conteo && conteo.conteo_id) {
                recargadoDetalle = await openDetail(conteo.conteo_id);
            }

            if (recargadoLista && recargadoDetalle) {
                UI.toast('success', t('inventory.counts.messages.started_title'),
                    (data && data.unidades_en_lista !== undefined)
                        ? t('inventory.counts.messages.started_units', { count: UI.formatNumber(data.unidades_en_lista) })
                        : t('inventory.counts.messages.started_pending'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.counts.messages.start_error'),
                root: getModalRoot(),
                inline: true,
                tag: 'Counts',
                onConflict: () => {
                    if (state.modal && state.modal.mode === 'start') {
                        state.modal.key = UI.newIdempotencyKey();
                        renderModal();
                    }
                }
            });
        } finally {
            setSending(false);
        }
    }

    // ====================================================================
    // MODAL - DETALLE
    // ====================================================================

    async function openDetail(id) {
        state.modal = { mode: 'detail', id, data: null, loading: true, key: UI.newIdempotencyKey() };
        renderModal();

        const token = state.modal;

        try {
            const response = await global.HttpClient.get('/inventory/counts/' + encodeURIComponent(id));
            const payload = response && response.data ? response.data : response;
            token.data = payload;
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.counts.messages.detail_load_error'),
                tag: 'Counts'
            });
            closeModal();
            return false;
        }

        if (state.modal === token) {
            token.loading = false;
            renderModal();
        }
        return true;
    }

    function detailRow(label, value) {
        return `
            <div class="flex justify-between gap-4 py-1 border-b border-default last:border-0">
                <span class="text-muted text-sm">${UI.escapeHtml(label)}</span>
                <span class="text-sm text-right">${value}</span>
            </div>`;
    }

    /** Discrepancias sin acción registrada todavía. */
    function discrepanciasPendientes(d) {
        const resueltas = new Set((d.ajustes_aplicados || []).map(a => a.unidad_fisica_id));
        return (d.detalle_discrepancias || []).filter(f => !resueltas.has(f.unidad_fisica_id));
    }

    function renderDetailBody(d) {
        const abierto = ABIERTOS.includes(d.estado);
        const edita = abierto && canCreate();
        const pendientes = discrepanciasPendientes(d);
        const reconcilia = d.estado === 'COMPLETADO' && pendientes.length > 0 && canReconcile();

        const info = `
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="card p-3">
                    <div class="text-xs text-muted mb-2">${t('inventory.counts.detail.info_title')}</div>
                    ${detailRow(t('inventory.counts.detail.field_id'), `<span class="font-mono text-xs">${UI.escapeHtml(d.conteo_id)}</span>`)}
                    ${detailRow(t('inventory.counts.detail.field_estado'), UI.estadoBadge(d.estado, estadoCatalog()))}
                    ${detailRow(t('inventory.counts.detail.field_fecha'), UI.escapeHtml(d.fecha_conteo || '—'))}
                    ${detailRow(t('inventory.counts.detail.field_responsable'), UI.escapeHtml(d.usuario_responsable_id || '—'))}
                </div>
                <div class="card p-3">
                    <div class="text-xs text-muted mb-2">${t('inventory.counts.detail.coverage_title')}</div>
                    ${detailRow(t('inventory.counts.detail.field_ubicacion'), d.ubicacion_codigo
                        ? `<span class="font-mono text-xs">${UI.escapeHtml(d.ubicacion_codigo)}</span>`
                        : t('inventory.counts.detail.all_tenant'))}
                    ${detailRow(t('inventory.counts.detail.field_unidades_contadas'), `${UI.formatNumber(d.unidades_contadas || 0)} / ${UI.formatNumber(d.unidades_lista || 0)}`)}
                    ${detailRow(t('inventory.counts.detail.field_discrepancias'), UI.formatNumber(d.discrepancias || 0))}
                    ${detailRow(t('inventory.counts.detail.field_pendientes'), UI.formatNumber(pendientes.length))}
                </div>
            </div>`;

        // ---- Hoja teórica (resultados) ----
        const filas = (d.lista_teorica || []).map(f => {
            const unidadId = String(f.unidad_fisica_id);
            const guardado = (d.resultados || []).find(r => String(r.unidad_fisica_id) === unidadId) || null;

            const contada = edita
                ? `<input class="input" type="number" step="any" min="0" data-result="${UI.escapeHtml(unidadId)}"
                          value="${guardado && guardado.cantidad_encontrada !== null && guardado.cantidad_encontrada !== undefined
                              ? UI.escapeHtml(guardado.cantidad_encontrada) : ''}"
                          placeholder="—">`
                : (guardado ? UI.formatNumber(guardado.cantidad_encontrada) : '—');

            const notas = edita
                ? `<input class="input" type="text" data-notas="${UI.escapeHtml(unidadId)}"
                          value="${guardado && guardado.notas ? UI.escapeHtml(guardado.notas) : ''}"
                          placeholder="${t('inventory.counts.detail.notes_placeholder')}">`
                : UI.escapeHtml(guardado && guardado.notas ? guardado.notas : '—');

            return `
            <tr>
                <td class="font-mono text-xs">${UI.escapeHtml(unidadId)}</td>
                <td class="text-xs">${UI.escapeHtml(f.codigo_barcode || '—')}</td>
                <td class="font-mono text-xs">${UI.escapeHtml(f.ubicacion_codigo || '—')}</td>
                <td class="text-xs">${UI.escapeHtml(UI.formatNumber(f.cantidad_esperada))}</td>
                <td>
                    ${contada}
                    <span class="form-error hidden" data-error="resultados__${UI.escapeHtml(unidadId)}"></span>
                </td>
                <td>${notas}</td>
            </tr>`;
        }).join('');

        const hoja = `
            <div class="card p-3 mt-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-xs text-muted">${t('inventory.counts.detail.sheet_title', { count: UI.formatNumber((d.lista_teorica || []).length) })}</div>
                    ${edita ? `<span class="form-hint">${t('inventory.counts.detail.sheet_hint')}</span>` : ''}
                </div>
                ${(d.lista_teorica || []).length === 0
                    ? UI.emptyState({ title: t('inventory.counts.detail.sheet_empty_title'), message: t('inventory.counts.detail.sheet_empty_message') })
                    : `<div class="table-wrapper">${UI.tableShell([
                        { label: t('inventory.counts.columns.unidad') },
                        { label: t('inventory.counts.columns.barcode') },
                        { label: t('inventory.counts.columns.ubicacion') },
                        { label: t('inventory.counts.columns.esperada') },
                        { label: t('inventory.counts.columns.contada') },
                        { label: t('inventory.counts.columns.notas') }
                    ], filas)}</div>`}
            </div>`;

        // ---- Discrepancias ----
        let discrepancias = '';

        if ((d.detalle_discrepancias || []).length > 0) {
            const filasDisc = (d.detalle_discrepancias || []).map(f => {
                const unidadId = String(f.unidad_fisica_id);
                const yaAccionada = !pendientes.some(p => String(p.unidad_fisica_id) === unidadId);
                const diferencia = Number(f.cantidad_encontrada) - Number(f.cantidad_esperada);

                const accion = reconcilia && !yaAccionada
                    ? `<select class="select" data-accion="${UI.escapeHtml(unidadId)}">
                            <option value="">${t('inventory.counts.detail.select_action')}</option>
                            ${ACCIONES.map(a => `<option value="${a.value}">${t(a.labelKey)}</option>`).join('')}
                       </select>
                       <span class="form-error hidden" data-error="accion__${UI.escapeHtml(unidadId)}"></span>`
                    : (yaAccionada
                        ? `<span class="badge badge-neutral">${t('inventory.counts.badges.resuelta')}</span>`
                        : '—');

                const justificacion = reconcilia && !yaAccionada
                    ? `<input class="input" type="text" data-justificacion="${UI.escapeHtml(unidadId)}"
                              placeholder="${t('inventory.counts.detail.justification_placeholder')}" autocomplete="off">
                       <span class="form-error hidden" data-error="justificacion__${UI.escapeHtml(unidadId)}"></span>`
                    : '—';

                return `
                <tr>
                    <td class="font-mono text-xs">${UI.escapeHtml(unidadId)}</td>
                    <td class="text-xs">${UI.escapeHtml(f.codigo_barcode || '—')}</td>
                    <td class="text-xs">${UI.escapeHtml(UI.formatNumber(f.cantidad_esperada))}</td>
                    <td class="text-xs">${UI.escapeHtml(UI.formatNumber(f.cantidad_encontrada))}</td>
                    <td class="text-xs">${diferencia > 0 ? '+' : ''}${UI.escapeHtml(UI.formatNumber(diferencia))}</td>
                    <td>${accion}</td>
                    <td>${justificacion}</td>
                </tr>`;
            }).join('');

            discrepancias = `
            <div class="card p-3 mt-4">
                <div class="text-xs text-muted mb-2">${t('inventory.counts.detail.discrepancies_title', { count: UI.formatNumber((d.detalle_discrepancias || []).length) })}</div>
                <div class="table-wrapper">${UI.tableShell([
                    { label: t('inventory.counts.columns.unidad') },
                    { label: t('inventory.counts.columns.barcode') },
                    { label: t('inventory.counts.columns.esperada') },
                    { label: t('inventory.counts.columns.encontrada') },
                    { label: t('inventory.counts.columns.diferencia') },
                    { label: t('inventory.counts.columns.accion') },
                    { label: t('inventory.counts.columns.justificacion') }
                ], filasDisc)}</div>
                ${reconcilia
                    ? `<span class="form-hint">${t('inventory.counts.detail.reconcile_hint')}</span>`
                    : ''}
            </div>`;
        }

        // ---- Ajustes aplicados ----
        let ajustes = '';

        if ((d.ajustes_aplicados || []).length > 0) {
            const filasAjustes = (d.ajustes_aplicados || []).map(a => `
                <tr>
                    <td class="font-mono text-xs">${UI.escapeHtml(a.unidad_fisica_id)}</td>
                    <td class="text-xs">${UI.escapeHtml(a.accion || '—')}</td>
                    <td class="text-xs">${UI.escapeHtml(a.justificacion || '—')}</td>
                    <td class="text-xs">${a.cantidad_ajustada !== null && a.cantidad_ajustada !== undefined
                        ? UI.escapeHtml(UI.formatNumber(a.cantidad_ajustada)) : '—'}</td>
                    <td class="font-mono text-xs">${UI.escapeHtml(a.incidencia_id || '—')}</td>
                    <td class="text-xs">${UI.escapeHtml(a.actor_autoriza || '—')}</td>
                    <td class="text-xs">${UI.escapeHtml(a.fecha || '—')}</td>
                </tr>`).join('');

            ajustes = `
            <div class="card p-3 mt-4">
                <div class="text-xs text-muted mb-2">${t('inventory.counts.detail.adjustments_title', { count: UI.formatNumber((d.ajustes_aplicados || []).length) })}</div>
                <div class="table-wrapper">${UI.tableShell([
                    { label: t('inventory.counts.columns.unidad') },
                    { label: t('inventory.counts.columns.accion') },
                    { label: t('inventory.counts.columns.justificacion') },
                    { label: t('inventory.counts.columns.cantidad') },
                    { label: t('inventory.counts.columns.incidencia') },
                    { label: t('inventory.counts.columns.autoriza') },
                    { label: t('inventory.counts.columns.fecha') }
                ], filasAjustes)}</div>
            </div>`;
        }

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${info}
            ${hoja}
            ${discrepancias}
            ${ajustes}`;
    }

    function renderDetailFooter(d) {
        if (!d) {
            return `<button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>`;
        }

        const abierto = ABIERTOS.includes(d.estado);
        const pendientes = discrepanciasPendientes(d).length;
        const botones = [];

        botones.push(`<button class="btn btn-ghost" data-action="download-sheet" data-id="${UI.escapeHtml(d.conteo_id)}">${t('inventory.counts.actions.download_sheet')}</button>`);

        if (abierto && canCreate()) {
            botones.push(`<button class="btn btn-secondary" data-action="submit-results" data-modal-send>${t('inventory.counts.actions.submit_results')}</button>`);
            botones.push(`<button class="btn btn-secondary" data-action="complete" data-modal-send>${t('inventory.counts.actions.complete')}</button>`);
        }
        if (d.estado === 'COMPLETADO' && pendientes > 0 && canReconcile()) {
            botones.push(`<button class="btn btn-primary" data-action="submit-reconcile" data-modal-send>${t('inventory.counts.actions.reconcile')}</button>`);
        }

        botones.push(`<button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>`);

        return botones.join('');
    }

    // ====================================================================
    // ACCIONES DEL DETALLE
    // ====================================================================

    function readResultados(root) {
        const filas = root.querySelectorAll('[data-result]');
        const resultados = [];

        for (const inp of filas) {
            const unidadId = inp.dataset.result;
            const raw = (inp.value || '').trim();

            if (raw === '') continue;

            const cantidad = Number(raw);

            if (!Number.isFinite(cantidad) || cantidad < 0) {
                UI.showFieldError(root, `resultados__${unidadId}`, t('inventory.counts.messages.error_invalid_quantity'));
                return { error: true };
            }

            const notasEl = root.querySelector(`[data-notas="${unidadId}"]`);
            const notas = notasEl ? (notasEl.value || '').trim() : '';

            resultados.push({
                unidad_fisica_id: unidadId,
                cantidad_encontrada: cantidad,
                notas: notas === '' ? undefined : notas
            });
        }

        return { resultados };
    }

    async function submitResults() {
        const modal = state.modal;
        if (state.sending || !modal || modal.mode !== 'detail' || !modal.data) return;

        const root = getModalRoot();
        UI.clearErrors(root);

        const leido = readResultados(root);

        if (leido.error) return;

        if (leido.resultados.length === 0) {
            UI.showFormError(root, t('inventory.counts.messages.error_results_required'));
            return;
        }

        setSending(true);

        try {
            const response = await global.HttpClient.post(
                '/inventory/counts/' + encodeURIComponent(modal.id) + '/results',
                { resultados: leido.resultados }
            );
            const data = response && response.data ? response.data : response;

            const recargadoDetalle = await openDetail(modal.id);
            const recargadoLista = await loadCounts(state.pagination.page || 1);
            if (recargadoDetalle && recargadoLista) {
                UI.toast('success', t('inventory.counts.messages.results_registered_title'),
                    t('inventory.counts.messages.results_registered_message', {
                        count: UI.formatNumber(data && data.unidades_pendientes !== undefined ? data.unidades_pendientes : 0)
                    }));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.counts.messages.results_error'),
                root: getModalRoot(),
                inline: true,
                tag: 'Counts'
            });
            if (err && (err.status === 422 || err.status === 404)) {
                await openDetail(modal.id);
            }
        } finally {
            setSending(false);
        }
    }

    /** 422 de CompleteCountUseCase: "Faltan resultados para N unidad(es): id1, id2." */
    function markMissingRows(root, message) {
        const match = /Faltan resultados para \d+ unidad\(es\):\s*([^]+?)\.\s*$/.exec(String(message || ''));

        if (!match) return;

        const ids = match[1].split(',').map(s => s.trim()).filter(Boolean);
        ids.forEach(id => {
            UI.showFieldError(root, `resultados__${id}`, t('inventory.counts.messages.missing_unit_row'));
        });
    }

    async function completeCount() {
        const modal = state.modal;
        if (state.sending || !modal || modal.mode !== 'detail' || !modal.data) return;

        const root = getModalRoot();
        UI.clearErrors(root);

        const confirmed = global.confirm(t('inventory.counts.messages.confirm_complete'));
        if (!confirmed) return;

        setSending(true);

        try {
            const response = await global.HttpClient.post(
                '/inventory/counts/' + encodeURIComponent(modal.id) + '/complete'
            );
            const data = response && response.data ? response.data : response;

            const recargadoDetalle = await openDetail(modal.id);
            const recargadoLista = await loadCounts(state.pagination.page || 1);
            if (recargadoDetalle && recargadoLista) {
                UI.toast('success', t('inventory.counts.messages.completed_title'),
                    t('inventory.counts.messages.completed_message', {
                        count: UI.formatNumber(data && data.total_discrepancias !== undefined ? data.total_discrepancias : 0)
                    }));
            }
        } catch (err) {
            const message = (err && err.message) || '';
            const faltaResultados = err && err.status === 422
                && err.data && err.data.field === 'resultados'
                && message.indexOf('Faltan resultados para') === 0;

            if (faltaResultados) {
                UI.showFormError(getModalRoot(), message);
                markMissingRows(getModalRoot(), message);
                UI.toast('danger', t('ui.toast_rejected'), message);
                console.error('[Counts] complete error:', err.status, message);
            } else {
                UI.handleActionError(err, {
                    fallbackMessage: t('inventory.counts.messages.complete_error'),
                    root: getModalRoot(),
                    inline: true,
                    tag: 'Counts'
                });
                if (err && (err.status === 422 || err.status === 404)) {
                    await openDetail(modal.id);
                }
            }
        } finally {
            setSending(false);
        }
    }

    /**
     * 422 quantityStale: el backend sólo manda `message`; la unidad y los
     * números se extraen con regex y la fila pasa a JUSTIFICAR.
     */
    function handleStaleQuantity(root, message) {
        const match = /La unidad física '([^']+)' ya no coincide con la hoja teórica del conteo: cantidad_esperada ([0-9.]+), cantidad actual ([0-9.]+)\./.exec(String(message || ''));

        if (!match) return false;

        const unidadId = match[1];
        const select = root.querySelector(`[data-accion="${unidadId}"]`);

        if (select) {
            select.value = 'JUSTIFICAR';
            select.focus();
        }

        UI.showFormError(root, message);
        UI.showFieldError(root, `justificacion__${unidadId}`, t('inventory.counts.messages.stale_field_error'));
        UI.toast('warning', t('inventory.counts.messages.stale_title'),
            t('inventory.counts.messages.stale_message', {
                unidad: unidadId,
                esperada: match[2],
                actual: match[3]
            }));

        return true;
    }

    /** 422 adjustmentStateNotAllowed: unidad en estado que AJUSTAR no admite. */
    function handleAdjustmentState(root, message) {
        const match = /La unidad física '([^']+)' está en/.exec(String(message || ''));

        if (!match) return false;

        const select = root.querySelector(`[data-accion="${match[1]}"]`);

        if (select) {
            select.value = 'JUSTIFICAR';
            select.focus();
        }

        UI.showFormError(root, message);
        UI.showFieldError(root, `justificacion__${match[1]}`, t('inventory.counts.messages.adjustment_state_field_error'));
        UI.toast('danger', t('ui.toast_rejected'), message);

        return true;
    }

    function readAcciones(root) {
        const selects = root.querySelectorAll('[data-accion]');
        const acciones = [];

        for (const select of selects) {
            const unidadId = select.dataset.accion;
            const accion = (select.value || '').trim();

            if (accion === '') continue;

            const justEl = root.querySelector(`[data-justificacion="${unidadId}"]`);
            const justificacion = justEl ? (justEl.value || '').trim() : '';

            if (accion === 'JUSTIFICAR' && justificacion === '') {
                UI.showFieldError(root, `justificacion__${unidadId}`, t('inventory.counts.messages.justification_required'));
                return { error: true };
            }

            acciones.push({
                unidad_fisica_id: unidadId,
                accion,
                justificacion: justificacion === '' ? undefined : justificacion
            });
        }

        return { acciones };
    }

    async function submitReconcile() {
        const modal = state.modal;
        if (state.sending || !modal || modal.mode !== 'detail' || !modal.data) return;

        const root = getModalRoot();
        UI.clearErrors(root);

        const leido = readAcciones(root);

        if (leido.error) return;

        if (leido.acciones.length === 0) {
            UI.showFormError(root, t('inventory.counts.messages.error_actions_required'));
            return;
        }

        setSending(true);

        try {
            const response = await global.HttpClient.post(
                '/inventory/counts/' + encodeURIComponent(modal.id) + '/reconcile',
                { acciones: leido.acciones },
                { headers: { 'Idempotency-Key': modal.key } }
            );
            const data = response && response.data ? response.data : response;

            const incidencias = data && Array.isArray(data.incidencias) ? data.incidencias.length : 0;

            if (state.modal && state.modal.mode === 'detail') {
                state.modal.key = UI.newIdempotencyKey();
            }

            const recargadoDetalle = await openDetail(modal.id);
            const recargadoLista = await loadCounts(state.pagination.page || 1);
            if (recargadoDetalle && recargadoLista) {
                UI.toast('success', t('inventory.counts.messages.reconciled_title'),
                    incidencias > 0
                        ? t('inventory.counts.messages.reconciled_message_incidents', {
                            acciones: UI.formatNumber(leido.acciones.length),
                            incidencias: UI.formatNumber(incidencias)
                        })
                        : t('inventory.counts.messages.reconciled_message', {
                            acciones: UI.formatNumber(leido.acciones.length)
                        }));
            }
        } catch (err) {
            const rootNow = getModalRoot();
            const message = (err && err.message) || '';

            if (err && err.status === 422 && err.data && err.data.field === 'cantidad'
                && handleStaleQuantity(rootNow, message)) {
                console.error('[Counts] reconcile stale quantity:', message);
            } else if (err && err.status === 422 && err.data && err.data.field === 'estado'
                && message.indexOf('el ajuste automático') !== -1
                && handleAdjustmentState(rootNow, message)) {
                console.error('[Counts] reconcile state rejected:', message);
            } else {
                UI.handleActionError(err, {
                    fallbackMessage: t('inventory.counts.messages.reconcile_error'),
                    root: rootNow,
                    inline: true,
                    tag: 'Counts',
                    onConflict: () => {
                        if (state.modal && state.modal.mode === 'detail') {
                            state.modal.key = UI.newIdempotencyKey();
                        }
                    },
                    reload: () => openDetail(modal.id)
                });
                if (err && (err.status === 422 || err.status === 404)) {
                    if (err.data && err.data.field === 'estado') {
                        await openDetail(modal.id);
                        await loadCounts(state.pagination.page || 1);
                    }
                }
            }
        } finally {
            setSending(false);
        }
    }

    async function downloadSheet(id) {
        try {
            await UI.downloadCsv(
                '/inventory/counts/' + encodeURIComponent(id) + '/sheet?format=csv',
                'conteo-' + id + '.csv'
            );
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.counts.messages.download_error'),
                tag: 'Counts'
            });
        }
    }

    // ====================================================================
    // MODAL - RENDER Y CICLO
    // ====================================================================

    function getModalRoot() {
        return container && container.querySelector('[data-modal-root]');
    }

    function closeModal() {
        UI.closeModal(getModalRoot());
        state.modal = null;
        state.sending = false;
    }

    function setSending(sending) {
        state.sending = sending;
        const root = getModalRoot();
        if (!root) return;
        root.querySelectorAll('[data-modal-send]').forEach(btn => {
            btn.disabled = sending;
            if (sending) {
                btn.dataset.label = btn.textContent;
                btn.textContent = t('inventory.counts.messages.sending');
            } else if (btn.dataset.label) {
                btn.textContent = btn.dataset.label;
            }
        });
    }

    function applyErrors() {
        const modal = state.modal;
        const root = getModalRoot();
        if (!modal || !root) return;

        if (modal.formError) UI.showFormError(root, modal.formError);
        Object.keys(modal.errors || {}).forEach(field => {
            UI.showFieldError(root, field, modal.errors[field]);
        });
    }

    function renderModal() {
        const root = getModalRoot();
        if (!root) return;

        const modal = state.modal;
        if (!modal) {
            UI.closeModal(root);
            return;
        }

        let title = '';
        let size = 'lg';
        let body = '';
        let footer = '';

        if (modal.mode === 'start') {
            title = t('inventory.counts.modals.start.title');
            body = startBody(modal);
            footer = `
                <div class="text-xs text-muted mr-auto">Idempotency-Key: <span class="font-mono" data-key-hint>${UI.escapeHtml(modal.key)}</span></div>
                <button class="btn btn-secondary" data-action="close-modal">${t('inventory.counts.modals.cancel')}</button>
                <button class="btn btn-primary" data-action="submit-start" data-modal-send${state.sending ? ' disabled' : ''}>${t('inventory.counts.modals.start.submit')}</button>`;
        } else if (modal.mode === 'detail') {
            title = t('inventory.counts.modals.detail.title');
            size = 'xl';
            body = modal.loading
                ? UI.loadingState()
                : (modal.data ? renderDetailBody(modal.data) : UI.emptyState({ title: t('ui.empty_title'), message: '' }));
            footer = renderDetailFooter(modal.data);
        }

        UI.mountModal(root, UI.modalShell({ title, size, bodyHtml: body, footerHtml: footer }));
        applyErrors();
    }

    // ====================================================================
    // EVENTS
    // ====================================================================

    function bindEvents() {
        if (!container) return;

        container.removeEventListener('click', handleClick);
        container.removeEventListener('change', handleChange);
        container.removeEventListener('input', handleInput);
        container.removeEventListener('submit', handleSubmit);

        container.addEventListener('click', handleClick);
        container.addEventListener('change', handleChange);
        container.addEventListener('input', handleInput);
        container.addEventListener('submit', handleSubmit);
    }

    function handleClick(e) {
        const btn = e.target.closest('[data-action], [data-page]');
        if (!btn || btn.disabled) return;

        const action = btn.dataset.action;
        const page = btn.dataset.page;
        const id = btn.dataset.id;

        switch (action) {
            case 'open-start':
                openStart();
                break;
            case 'close-modal':
                closeModal();
                break;
            case 'submit-start':
                submitStart();
                break;
            case 'open-detail':
                openDetail(id);
                break;
            case 'submit-results':
                submitResults();
                break;
            case 'complete':
                completeCount();
                break;
            case 'submit-reconcile':
                submitReconcile();
                break;
            case 'download-sheet':
                downloadSheet(id);
                break;
            case 'apply-filters':
                readFilters();
                loadCounts(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
        }

        if (page !== undefined && page !== null && page !== '') {
            loadCounts(parseInt(page, 10));
        }
    }

    function handleChange(e) {
        const el = e.target;
        if (!state.modal) return;

        if (el.matches('[data-modal-field="ubicacion_id"]')) {
            state.modal.ubicacion_id = el.value;
            return;
        }
        if (el.matches('[data-modal-field="fecha"]')) {
            state.modal.fecha = el.value;
        }
    }

    function handleInput() {
        // Los valores del modal se leen del DOM al enviar.
    }

    function handleSubmit(e) {
        e.preventDefault();
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const InventoryCountsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            container.innerHTML = buildTemplate();
            bindEvents();
            renderLocationFilter();
            renderSummary();
            loadLocationFilterOptions();
            loadCounts(1);
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            if (!container.querySelector('[data-cnt-table]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
                renderLocationFilter();
            }
            loadCounts(state.pagination.page || 1);
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('change', handleChange);
                container.removeEventListener('input', handleInput);
                container.removeEventListener('submit', handleSubmit);
                container.innerHTML = '';
            }
            container = null;
            state.modal = null;
            state.sending = false;
            state.items = [];
            state.filters = { estado: '', ubicacion_id: '', fecha_desde: '', fecha_hasta: '' };
            state.locations = [];
        }
    };

    global.InventoryCountsModule = InventoryCountsModule;

})(window);
