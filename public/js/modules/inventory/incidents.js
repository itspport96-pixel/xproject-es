/**
 * Inventory Incidents Module
 * Pantalla "Incidencias" (CU-010) - v1.9.3.
 *
 * Convención ModuleLoader: window.InventoryIncidentsModule
 *
 * Bloques (sólo endpoints existentes en src/Modules/Inventory/routes.php):
 *  1. Listado paginado `GET /api/inventory/incidents` con filtros por estado,
 *     tipo, unidad y rango de fechas, más el summary de la respuesta
 *     (total, abiertas, investigacion, resueltas, no_resueltas).
 *  2. Alta `POST /api/inventory/incidents/report` con Idempotency-Key
 *     OBLIGATORIA (crypto.randomUUID al abrir el modal, reutilizada en
 *     reintentos y renovada tras éxito o 409):
 *       - tipo: DAÑO, PERDIDA, DISCREPANCIA_CANTIDAD, UBICACION_DESCONOCIDA,
 *         ENTREGA_RECHAZADA, TRANSPORTE_FALLIDO, INVENTARIO_DESAPARECIDO, OTRO.
 *       - descripcion obligatoria; unidad, ubicación y observaciones opcionales
 *         (DISCREPANCIA_CANTIDAD exige ubicación: la propia de la unidad sirve).
 *       - unidad: selector de `GET /api/inventory/stock` (o ID libre si la
 *         sesión no tiene inventory.stock.view); ubicación: `GET
 *         /api/inventory/locations?activa=1`.
 *  3. Detalle `GET /api/inventory/incidents/{id}` (resolución y conteo vinculado).
 *  4. `POST /api/inventory/incidents/{id}/investigate` (ABIERTA → INVESTIGACION,
 *     idempotencia por estado) y `POST /api/inventory/incidents/{id}/resolve`
 *     (RESUELTA | NO_RESUELTO con Idempotency-Key opcional).
 *
 * Permisos (routes.php):
 *  - inventory.incident.view    → listado y detalle
 *  - inventory.incident.report  → reportar
 *  - inventory.incident.resolve → investigar y resolver
 *
 * Errores del backend → toast + error de campo cuando traen `field`:
 * 400 (tipo/estado/fecha inválidos, campos obligatorios, longitud máx.),
 * 401, 403, 404 (incidencia o unidad inexistente), 409 (idempotencia),
 * 422 (transición de estado o tipo de unidad no permitida).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    const state = {
        items: [],
        summary: { total: 0, abiertas: 0, investigacion: 0, resueltas: 0, no_resueltas: 0 },
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        loading: false,
        filters: { estado: '', tipo: '', unidad_fisica_id: '', fecha_desde: '', fecha_hasta: '' },
        unitFilterMode: 'select',
        unitOptions: [],
        modal: null,
        sending: false
    };

    const ESTADOS = [
        { value: 'ABIERTA', labelKey: 'inventory.incidents.states.abierta', color: 'danger' },
        { value: 'INVESTIGACION', labelKey: 'inventory.incidents.states.investigacion', color: 'warning' },
        { value: 'RESUELTA', labelKey: 'inventory.incidents.states.resuelta', color: 'success' },
        { value: 'NO_RESUELTO', labelKey: 'inventory.incidents.states.no_resuelto', color: 'neutral' }
    ];

    const TIPOS = [
        { value: 'DAÑO', labelKey: 'inventory.incidents.types.dano' },
        { value: 'PERDIDA', labelKey: 'inventory.incidents.types.perdida' },
        { value: 'DISCREPANCIA_CANTIDAD', labelKey: 'inventory.incidents.types.discrepancia_cantidad' },
        { value: 'UBICACION_DESCONOCIDA', labelKey: 'inventory.incidents.types.ubicacion_desconocida' },
        { value: 'ENTREGA_RECHAZADA', labelKey: 'inventory.incidents.types.entrega_rechazada' },
        { value: 'TRANSPORTE_FALLIDO', labelKey: 'inventory.incidents.types.transporte_fallido' },
        { value: 'INVENTARIO_DESAPARECIDO', labelKey: 'inventory.incidents.types.inventario_desaparecido' },
        { value: 'OTRO', labelKey: 'inventory.incidents.types.otro' }
    ];

    const ESTADOS_RESOLUCION = [
        { value: 'RESUELTA', labelKey: 'inventory.incidents.states.resuelta', color: 'success' },
        { value: 'NO_RESUELTO', labelKey: 'inventory.incidents.states.no_resuelto', color: 'neutral' }
    ];

    const estadoCatalog = (catalogo) => catalogo.map(e => ({
        value: e.value,
        label: t(e.labelKey),
        color: e.color
    }));

    const MAX_TEXT = 2000;

    const ESTADOS_ABIERTOS = ['ABIERTA', 'INVESTIGACION'];

    // v1.10.0 (E4): tipos que cierran con `resultado` (espejo de
    // Incident::TIPOS_CON_RESOLUCION_UNIDAD en el backend). Para esos tipos
    // el reporte bloquea la unidad y resolver exige ENCONTRADA/NO_ENCONTRADA.
    const TIPOS_CON_RESOLUCION_UNIDAD = ['UBICACION_DESCONOCIDA', 'INVENTARIO_DESAPARECIDO'];

    const RESULTADOS = [
        { value: 'ENCONTRADA', labelKey: 'inventory.incidents.results.encontrada' },
        { value: 'NO_ENCONTRADA', labelKey: 'inventory.incidents.results.no_encontrada' }
    ];

    const resultadosOptions = () => RESULTADOS.map(r => ({ value: r.value, label: t(r.labelKey) }));

    const resultadoLabel = (valor) => {
        const entrada = RESULTADOS.find(r => r.value === valor);
        return entrada ? t(entrada.labelKey) : valor;
    };

    // v1.10.0 (E1): prioridad. La recepción marca ALTA cuando rechaza todo
    // o se pasa del umbral de discrepancia.
    const PRIORIDADES = {
        ALTA: { labelKey: 'inventory.incidents.priorities.alta', color: 'danger' },
        NORMAL: { labelKey: 'inventory.incidents.priorities.normal', color: 'neutral' }
    };

    const prioridadBadge = (valor) => {
        const p = PRIORIDADES[valor];
        return p
            ? `<span class="badge badge-${p.color}">${t(p.labelKey)}</span>`
            : `<span class="badge badge-neutral">${t('inventory.incidents.priorities.normal')}</span>`;
    };

    const requiereResultado = (tipo) => TIPOS_CON_RESOLUCION_UNIDAD.includes(tipo);

    const tipoLabel = (valor) => {
        const entrada = TIPOS.find(x => x.value === valor);
        return entrada ? t(entrada.labelKey) : (valor || '—');
    };

    // ====================================================================
    // PERMISOS
    // ====================================================================

    const canView = () => UI.can('inventory.incident.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
    const canReport = () => UI.can('inventory.incident.report', ['ADMIN', 'MANAGER', 'USER']);
    const canResolve = () => UI.can('inventory.incident.resolve', ['ADMIN', 'MANAGER']);

    // ====================================================================
    // PLANTILLA
    // ====================================================================

    function buildTemplate() {
        return `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.incidents.title'),
                subtitle: t('inventory.incidents.subtitle'),
                actionsHtml: canReport()
                    ? `<button class="btn btn-primary btn-sm" data-action="open-report">${t('inventory.incidents.actions.report')}</button>`
                    : ''
            })}

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4" data-inc-summary></div>

            <div class="card mb-4">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.incidents.filters.estado')}</label>
                        <select class="select" data-filter="estado">
                            <option value="">${t('inventory.incidents.filters.all')}</option>
                            ${ESTADOS.map(e => `<option value="${UI.escapeHtml(e.value)}">${UI.escapeHtml(t(e.labelKey))}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.incidents.filters.tipo')}</label>
                        <select class="select" data-filter="tipo">
                            <option value="">${t('inventory.incidents.filters.all')}</option>
                            ${TIPOS.map(tp => `<option value="${UI.escapeHtml(tp.value)}">${UI.escapeHtml(t(tp.labelKey))}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group mb-0" data-unit-filter></div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.incidents.filters.fecha_desde')}</label>
                        <input type="date" class="input" data-filter="fecha_desde">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.incidents.filters.fecha_hasta')}</label>
                        <input type="date" class="input" data-filter="fecha_hasta">
                    </div>
                    <div class="form-group mb-0 md:col-span-5 flex gap-2">
                        <button class="btn btn-secondary flex-1" data-action="apply-filters">${t('inventory.incidents.filters.apply')}</button>
                        <button class="btn btn-ghost" data-action="clear-filters" title="${t('inventory.incidents.filters.clear_title')}">${t('inventory.incidents.filters.clear')}</button>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="table-wrapper" data-inc-table></div>
                <div data-inc-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>`;
    }

    const DENIED_TEMPLATE = `
        <div class="card max-w-md mx-auto mt-8">
            <div class="card-header">
                <h3 class="card-title">${t('inventory.incidents.permission.title')}</h3>
            </div>
            <div class="card-body">
                <p>${t('inventory.incidents.permission.body', { permiso: 'inventory.incident.view' })}</p>
                <div class="mt-4">
                    <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                </div>
            </div>
        </div>`;

    // ====================================================================
    // RENDER - LISTADO
    // ====================================================================

    function renderSummary() {
        const root = container.querySelector('[data-inc-summary]');
        if (!root) return;

        UI.summaryCards(root, [
            { label: t('inventory.incidents.summary.incidencias'), value: state.summary.total },
            { label: t('inventory.incidents.summary.abiertas'), value: state.summary.abiertas },
            { label: t('inventory.incidents.summary.investigacion'), value: state.summary.investigacion },
            { label: t('inventory.incidents.summary.resueltas'), value: state.summary.resueltas },
            { label: t('inventory.incidents.summary.no_resueltas'), value: state.summary.no_resueltas }
        ]);
    }

    function renderUnitFilter() {
        const root = container && container.querySelector('[data-unit-filter]');
        if (!root) return;

        if (state.unitFilterMode === 'select') {
            root.innerHTML = `
                <label class="form-label">${t('inventory.incidents.filters.unidad')}</label>
                <select class="select" data-filter="unidad_fisica_id">
                    <option value="">${t('inventory.incidents.filters.all_units')}</option>
                    ${state.unitOptions.map(op => `
                        <option value="${UI.escapeHtml(op.value)}">${UI.escapeHtml(op.label)}</option>`).join('')}
                </select>`;
            const select = root.querySelector('[data-filter]');
            select.value = state.filters.unidad_fisica_id || '';
        } else {
            root.innerHTML = `
                <label class="form-label">${t('inventory.incidents.filters.unidad_id')}</label>
                <input type="text" class="input" data-filter="unidad_fisica_id"
                       placeholder="${t('inventory.incidents.filters.unit_placeholder')}" autocomplete="off">`;
            root.querySelector('[data-filter]').value = state.filters.unidad_fisica_id || '';
        }
    }

    function truncate(text, max) {
        const str = text === null || text === undefined ? '' : String(text);
        if (str.length <= max) return str;
        return str.slice(0, max - 1) + '…';
    }

    function renderTable() {
        const root = container.querySelector('[data-inc-table]');
        if (!root) return;

        if (state.loading) {
            root.innerHTML = UI.tableSkeleton([
                { label: t('inventory.incidents.columns.incidencia') },
                { label: t('inventory.incidents.columns.tipo') },
                { label: t('inventory.incidents.columns.descripcion') },
                { label: t('inventory.incidents.columns.unidad') },
                { label: t('inventory.incidents.columns.ubicacion') },
                { label: t('inventory.incidents.columns.estado') },
                { label: t('inventory.incidents.columns.prioridad') },
                { label: t('inventory.incidents.columns.deteccion') },
                { label: t('inventory.incidents.columns.acciones'), className: 'col-actions' }
            ], 8, [12, 8, 20, 10, 10, 10, 8, 12, 10]);
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('inventory.incidents.empty.title'),
                message: t('inventory.incidents.empty.message')
            });
            return;
        }

        const resolveAllowed = canResolve();

        const rows = state.items.map(r => {
            const abierta = r.estado === 'ABIERTA';
            const abiertaONoCerrada = ESTADOS_ABIERTOS.includes(r.estado);

            const acciones = UI.rowActions({
                primary: {
                    action: 'open-detail',
                    id: r.incidencia_id,
                    label: t('inventory.incidents.actions.view')
                },
                groups: [
                    [resolveAllowed && abierta
                        ? { action: 'investigate', id: r.incidencia_id, label: t('inventory.incidents.actions.investigate') }
                        : null],
                    [resolveAllowed && abiertaONoCerrada
                        ? { action: 'open-resolve', id: r.incidencia_id, label: t('inventory.incidents.actions.resolve') }
                        : null]
                ],
                ariaLabel: t('inventory.incidents.columns.acciones')
            });

            return `
            <tr>
                <td class="font-mono text-xs">${UI.escapeHtml(r.incidencia_id)}</td>
                <td class="text-xs">${UI.escapeHtml(tipoLabel(r.tipo))}</td>
                <td class="text-xs" title="${UI.escapeHtml(r.descripcion || '')}">${UI.escapeHtml(truncate(r.descripcion, 60))}</td>
                <td class="font-mono text-xs">${UI.escapeHtml(r.codigo_barcode || '—')}</td>
                <td class="font-mono text-xs">${UI.escapeHtml(r.ubicacion_codigo || '—')}</td>
                <td>${UI.estadoBadge(r.estado, estadoCatalog(ESTADOS))}</td>
                <td>${prioridadBadge(r.prioridad)}</td>
                <td class="text-xs">${UI.escapeHtml(r.fecha_deteccion || '—')}</td>
                <td class="table-actions col-actions">${acciones}</td>
            </tr>`;
        }).join('');

        root.innerHTML = UI.tableShell([
            { label: t('inventory.incidents.columns.incidencia') },
            { label: t('inventory.incidents.columns.tipo') },
            { label: t('inventory.incidents.columns.descripcion') },
            { label: t('inventory.incidents.columns.unidad') },
            { label: t('inventory.incidents.columns.ubicacion') },
            { label: t('inventory.incidents.columns.estado') },
            { label: t('inventory.incidents.columns.prioridad') },
            { label: t('inventory.incidents.columns.deteccion') },
            { label: t('inventory.incidents.columns.acciones'), className: 'col-actions' }
        ], rows);
    }

    function renderPagination() {
        const root = container.querySelector('[data-inc-pagination]');
        if (!root) return;

        UI.renderPagination(root, {
            pagination: state.pagination,
            shown: state.items.length,
            label: t('inventory.incidents.pagination_label')
        });
    }

    function renderAll() {
        renderSummary();
        renderTable();
        renderPagination();
    }

    // ====================================================================
    // API - LISTADO
    // ====================================================================

    async function loadIncidents(page = 1) {
        state.loading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            Object.entries(state.filters).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) params[key] = value;
            });

            const response = await global.HttpClient.get('/inventory/incidents', { params });
            const payload = response && response.data ? response.data : response;

            state.items = (payload && payload.data) || [];
            state.summary = (payload && payload.summary) || state.summary;
            state.pagination = (payload && payload.pagination) || state.pagination;
            state.pagination.page = (payload && payload.pagination && payload.pagination.page) || page;
            return true;
        } catch (err) {
            console.error('[Incidents] load error:', err);
            state.items = [];

            if (err && err.status === 403) {
                UI.toast('danger', t('inventory.incidents.permission.title'), t('inventory.incidents.messages.forbidden_view'));
            } else {
                UI.toast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.incidents.messages.load_error'));
            }
            return false;
        } finally {
            state.loading = false;
            renderAll();
        }
    }

    async function loadUnitFilterOptions() {
        try {
            const response = await global.HttpClient.get('/inventory/stock', {
                params: { page: 1, per_page: 100 }
            });
            const payload = response && response.data ? response.data : response;
            state.unitOptions = ((payload && payload.data) || []).map(u => ({
                value: u.unidad_fisica_id,
                label: `${u.codigo_barcode || u.unidad_fisica_id} · ${u.producto_nombre || u.sku || ''}` +
                    (u.ubicacion_codigo ? ` · ${u.ubicacion_codigo}` : '')
            }));
            state.unitFilterMode = 'select';
        } catch (err) {
            // Sin inventory.stock.view no hay catálogo: filtro por ID libre.
            console.warn('[Incidents] no unit catalog for filter:', err && err.status);
            state.unitOptions = [];
            state.unitFilterMode = 'input';
        }
        renderUnitFilter();
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
        state.filters = { estado: '', tipo: '', unidad_fisica_id: '', fecha_desde: '', fecha_hasta: '' };
        container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
        loadIncidents(1);
    }

    // ====================================================================
    // MODAL - REPORTAR
    // ====================================================================

    function openReport() {
        if (!canReport()) return;

        state.modal = {
            mode: 'report',
            tipo: '',
            descripcion: '',
            unidad_fisica_id: '',
            ubicacion_id: '',
            observaciones: '',
            stock: [],
            stockLoading: true,
            stockSearch: '',
            locations: [],
            locationsLoading: true,
            key: UI.newIdempotencyKey(),
            formError: '',
            errors: {}
        };
        renderModal();

        loadStockForReport();
        loadLocationsForReport();
    }

    async function loadStockForReport() {
        const modal = state.modal;
        if (!modal || modal.mode !== 'report') return;

        modal.stockLoading = true;
        renderModal();

        try {
            const response = await global.HttpClient.get('/inventory/stock', {
                params: { page: 1, per_page: 100 }
            });
            const payload = response && response.data ? response.data : response;
            modal.stock = (payload && payload.data) || [];
        } catch (err) {
            modal.stock = [];
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.incidents.messages.stock_load_error'),
                tag: 'Incidents'
            });
        } finally {
            if (state.modal === modal) {
                modal.stockLoading = false;
                renderModal();
            }
        }
    }

    async function loadLocationsForReport() {
        const modal = state.modal;
        if (!modal || modal.mode !== 'report') return;

        modal.locationsLoading = true;
        renderModal();

        try {
            const response = await global.HttpClient.get('/inventory/locations', {
                params: { activa: 'true', page: 1, per_page: 100 }
            });
            const payload = response && response.data ? response.data : response;
            modal.locations = (payload && payload.data) || [];
        } catch (err) {
            modal.locations = [];
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.incidents.messages.locations_load_error'),
                tag: 'Incidents'
            });
        } finally {
            if (state.modal === modal) {
                modal.locationsLoading = false;
                renderModal();
            }
        }
    }

    function filteredStock() {
        const modal = state.modal;
        if (!modal) return [];

        const term = (modal.stockSearch || '').trim().toLowerCase();
        if (!term) return modal.stock;

        return modal.stock.filter(u =>
            (u.codigo_barcode || '').toLowerCase().includes(term)
            || (u.producto_nombre || '').toLowerCase().includes(term)
            || (u.sku || '').toLowerCase().includes(term)
            || (u.ubicacion_codigo || '').toLowerCase().includes(term)
        );
    }

    function stockOptions(unidades, seleccion) {
        return `<option value="">${t('inventory.incidents.modals.report.no_unit_option')}</option>` + unidades.map(u => `
            <option value="${UI.escapeHtml(u.unidad_fisica_id)}"
                ${u.unidad_fisica_id === seleccion ? 'selected' : ''}>
                ${UI.escapeHtml(u.codigo_barcode || u.unidad_fisica_id)}
                · ${UI.escapeHtml(u.producto_nombre || u.sku || '')}
                ${u.ubicacion_codigo ? '· ' + UI.escapeHtml(u.ubicacion_codigo) : ''}
            </option>`).join('');
    }

    function unitLocationId(modal) {
        if (!modal || !modal.unidad_fisica_id) return '';
        const unidad = modal.stock.find(u => u.unidad_fisica_id === modal.unidad_fisica_id);
        return unidad && unidad.ubicacion_id ? unidad.ubicacion_id : '';
    }

    function validateReport(modal) {
        if (!modal.tipo) return { field: 'tipo', message: t('inventory.incidents.validation.tipo_required') };
        if (!modal.descripcion.trim()) return { field: 'descripcion', message: t('inventory.incidents.validation.descripcion_required') };
        if (modal.descripcion.trim().length > MAX_TEXT) {
            return { field: 'descripcion', message: t('inventory.incidents.validation.text_max', { max: MAX_TEXT }) };
        }
        if (modal.observaciones.trim().length > MAX_TEXT) {
            return { field: 'observaciones', message: t('inventory.incidents.validation.observaciones_max', { max: MAX_TEXT }) };
        }
        if (modal.tipo === 'DISCREPANCIA_CANTIDAD' && !modal.ubicacion_id && !unitLocationId(modal)) {
            return {
                field: 'ubicacion_id',
                message: t('inventory.incidents.validation.ubicacion_required')
            };
        }
        return null;
    }

    async function submitReport() {
        const modal = state.modal;
        if (state.sending || !modal || modal.mode !== 'report') return;

        UI.clearErrors(getModalRoot());
        state.modal.formError = '';

        const invalid = validateReport(modal);
        if (invalid) {
            UI.showFieldError(getModalRoot(), invalid.field, invalid.message);
            return;
        }

        const payload = {
            tipo: modal.tipo,
            descripcion: modal.descripcion.trim()
        };
        if (modal.unidad_fisica_id) payload.unidad_fisica_id = modal.unidad_fisica_id;
        if (modal.ubicacion_id) payload.ubicacion_id = modal.ubicacion_id;
        if (modal.observaciones.trim()) payload.observaciones = modal.observaciones.trim();

        setSending(true);

        try {
            await global.HttpClient.post('/inventory/incidents/report', payload, {
                headers: { 'Idempotency-Key': modal.key }
            });

            closeModal();
            const recargado = await loadIncidents(1);
            if (recargado) {
                UI.toast('success', t('inventory.incidents.messages.reported_title'), t('inventory.incidents.messages.reported_message'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.incidents.messages.report_error'),
                root: getModalRoot(),
                inline: true,
                tag: 'Incidents',
                onConflict: () => {
                    if (state.modal && state.modal.mode === 'report') {
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
        state.modal = { mode: 'detail', id, data: null, loading: true };
        renderModal();

        const token = state.modal;

        try {
            const response = await global.HttpClient.get('/inventory/incidents/' + encodeURIComponent(id));
            const payload = response && response.data ? response.data : response;
            token.data = payload;
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.incidents.messages.incident_load_error'),
                tag: 'Incidents'
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

    function renderDetailBody(data) {
        const resolucion = data.resolucion || null;

        const resolucionBlock = resolucion ? `
            <div class="card p-3 mt-4">
                <div class="text-xs text-muted mb-2">${t('inventory.incidents.detail.resolution_section')}</div>
                ${detailRow(t('inventory.incidents.detail.final_state'), UI.estadoBadge(resolucion.estado_final || data.estado, estadoCatalog(ESTADOS_RESOLUCION)))}
                ${resolucion.resultado
                    ? detailRow(t('inventory.incidents.detail.resultado'), UI.escapeHtml(resultadoLabel(resolucion.resultado)))
                    : ''}
                ${resolucion.ubicacion_destino_id
                    ? detailRow(t('inventory.incidents.detail.ubicacion_destino'), `<span class="font-mono text-xs">${UI.escapeHtml(resolucion.ubicacion_destino_id)}</span>`)
                    : ''}
                ${detailRow(t('inventory.incidents.detail.descripcion'), UI.escapeHtml(resolucion.descripcion_resolucion || '—'))}
                ${detailRow(t('inventory.incidents.detail.acciones_tomadas'), UI.escapeHtml(resolucion.acciones_tomadas || '—'))}
                ${detailRow(t('inventory.incidents.detail.compensacion'), UI.escapeHtml(resolucion.compensacion || '—'))}
                ${detailRow(t('inventory.incidents.detail.resuelta_por'), UI.escapeHtml(resolucion.actor_resuelve || '—'))}
                ${detailRow(t('inventory.incidents.detail.fecha'), UI.escapeHtml(resolucion.fecha_resolucion || '—'))}
            </div>` : '';

        return `
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="card p-3">
                    <div class="text-xs text-muted mb-2">${t('inventory.incidents.detail.incident_section')}</div>
                    ${detailRow(t('inventory.incidents.detail.id'), `<span class="font-mono text-xs">${UI.escapeHtml(data.incidencia_id)}</span>`)}
                    ${detailRow(t('inventory.incidents.detail.tipo'), UI.escapeHtml(tipoLabel(data.tipo)))}
                    ${detailRow(t('inventory.incidents.detail.estado'), UI.estadoBadge(data.estado, estadoCatalog(ESTADOS)))}
                    ${detailRow(t('inventory.incidents.detail.prioridad'), prioridadBadge(data.prioridad))}
                    ${detailRow(t('inventory.incidents.detail.deteccion'), UI.escapeHtml(data.fecha_deteccion || '—'))}
                    ${detailRow(t('inventory.incidents.detail.creada'), UI.escapeHtml(data.fecha_creacion || '—'))}
                    ${detailRow(t('inventory.incidents.detail.actualizada'), UI.escapeHtml(data.fecha_actualizacion || '—'))}
                </div>
                <div class="card p-3">
                    <div class="text-xs text-muted mb-2">${t('inventory.incidents.detail.context_section')}</div>
                    ${detailRow(t('inventory.incidents.detail.unidad'), data.unidad_fisica_id
                        ? `<span class="font-mono text-xs">${UI.escapeHtml(data.codigo_barcode || data.unidad_fisica_id)}</span>`
                        : '—')}
                    ${detailRow(t('inventory.incidents.detail.ubicacion'), data.ubicacion_codigo
                        ? `<span class="font-mono text-xs">${UI.escapeHtml(data.ubicacion_codigo)}</span>`
                        : '—')}
                    ${detailRow(t('inventory.incidents.detail.reportada_por'), UI.escapeHtml(data.actor_id_reporta || '—'))}
                    ${detailRow(t('inventory.incidents.detail.conteo_vinculado'), data.inventory_count_id
                        ? `<span class="font-mono text-xs">${UI.escapeHtml(data.inventory_count_id)}</span>`
                        : '—')}
                </div>
            </div>

            <div class="card p-3 mt-4">
                <div class="text-xs text-muted mb-1">${t('inventory.incidents.detail.descripcion')}</div>
                <p>${UI.escapeHtml(data.descripcion || '—')}</p>
                <div class="text-xs text-muted mt-3 mb-1">${t('inventory.incidents.detail.observaciones')}</div>
                <p>${UI.escapeHtml(data.observaciones || '—')}</p>
            </div>

            ${resolucionBlock}`;
    }

    function renderDetailFooter(data) {
        const botones = [`<button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>`];

        if (canResolve() && data) {
            if (data.estado === 'ABIERTA') {
                botones.unshift(`<button class="btn btn-secondary" data-action="investigate" data-id="${UI.escapeHtml(data.incidencia_id)}">${t('inventory.incidents.actions.investigate')}</button>`);
            }
            if (ESTADOS_ABIERTOS.includes(data.estado)) {
                botones.unshift(`<button class="btn btn-primary" data-action="open-resolve" data-id="${UI.escapeHtml(data.incidencia_id)}">${t('inventory.incidents.actions.resolve')}</button>`);
            }
        }

        return botones.join('');
    }

    // ====================================================================
    // MODAL - RESOLVER
    // ====================================================================

    function openResolve(id) {
        if (!canResolve()) return;

        // El tipo decide si `resultado` es obligatorio (E4). Se rescata del
        // detalle abierto o de la fila de la lista antes de pisar state.modal.
        let tipo = '';
        if (state.modal && state.modal.mode === 'detail' && state.modal.id === id && state.modal.data) {
            tipo = state.modal.data.tipo || '';
        }
        if (!tipo) {
            const row = state.items.find(i => i.incidencia_id === id);
            tipo = (row && row.tipo) || '';
        }

        state.modal = {
            mode: 'resolve',
            id,
            tipo,
            estado: 'RESUELTA',
            descripcion_resolucion: '',
            acciones_tomadas: '',
            compensacion: '',
            resultado: '',
            ubicacion_destino_id: '',
            locations: [],
            locationsLoading: false,
            key: UI.newIdempotencyKey(),
            formError: '',
            errors: {}
        };
        renderModal();

        if (requiereResultado(tipo)) {
            loadLocationsForResolve();
        }
    }

    async function loadLocationsForResolve() {
        const modal = state.modal;
        if (!modal || modal.mode !== 'resolve') return;
        if (modal.locationsLoading || modal.locations.length > 0) return;

        modal.locationsLoading = true;
        renderModal();

        try {
            const response = await global.HttpClient.get('/inventory/locations', {
                params: { activa: 'true', page: 1, per_page: 100 }
            });
            const payload = response && response.data ? response.data : response;
            modal.locations = (payload && payload.data) || [];
        } catch (err) {
            modal.locations = [];
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.incidents.messages.locations_load_error'),
                tag: 'Incidents'
            });
        } finally {
            if (state.modal === modal) {
                modal.locationsLoading = false;
                renderModal();
            }
        }
    }

    function validateResolve(modal) {
        if (!['RESUELTA', 'NO_RESUELTO'].includes(modal.estado)) {
            return { field: 'estado', message: t('inventory.incidents.validation.estado_required') };
        }
        if (requiereResultado(modal.tipo)
            && !['ENCONTRADA', 'NO_ENCONTRADA'].includes(modal.resultado)) {
            return { field: 'resultado', message: t('inventory.incidents.validation.resultado_required') };
        }
        if (!modal.descripcion_resolucion.trim()) {
            return { field: 'descripcion_resolucion', message: t('inventory.incidents.validation.resolucion_descripcion_required') };
        }
        if (modal.descripcion_resolucion.trim().length > MAX_TEXT) {
            return { field: 'descripcion_resolucion', message: t('inventory.incidents.validation.text_max', { max: MAX_TEXT }) };
        }
        if (!modal.acciones_tomadas.trim()) {
            return { field: 'acciones_tomadas', message: t('inventory.incidents.validation.acciones_required') };
        }
        if (modal.acciones_tomadas.trim().length > MAX_TEXT) {
            return { field: 'acciones_tomadas', message: t('inventory.incidents.validation.acciones_max', { max: MAX_TEXT }) };
        }
        if (modal.compensacion.trim().length > MAX_TEXT) {
            return { field: 'compensacion', message: t('inventory.incidents.validation.compensacion_max', { max: MAX_TEXT }) };
        }
        return null;
    }

    async function submitResolve() {
        const modal = state.modal;
        if (state.sending || !modal || modal.mode !== 'resolve') return;

        UI.clearErrors(getModalRoot());
        state.modal.formError = '';

        const invalid = validateResolve(modal);
        if (invalid) {
            UI.showFieldError(getModalRoot(), invalid.field, invalid.message);
            return;
        }

        const payload = {
            estado: modal.estado,
            descripcion_resolucion: modal.descripcion_resolucion.trim(),
            acciones_tomadas: modal.acciones_tomadas.trim()
        };
        if (modal.compensacion.trim()) payload.compensacion = modal.compensacion.trim();

        // v1.10.0 (E4): sólo los tipos de resolución de unidad admiten
        // `resultado`; enviarlo en otro tipo devuelve 400 en el backend.
        if (requiereResultado(modal.tipo) && modal.resultado) {
            payload.resultado = modal.resultado;
            if (modal.resultado === 'ENCONTRADA' && modal.ubicacion_destino_id) {
                payload.ubicacion_destino_id = modal.ubicacion_destino_id;
            }
        }

        setSending(true);

        try {
            await global.HttpClient.post(
                '/inventory/incidents/' + encodeURIComponent(modal.id) + '/resolve',
                payload,
                { headers: { 'Idempotency-Key': modal.key } }
            );

            closeModal();
            const recargado = await loadIncidents(state.pagination.page || 1);
            if (recargado) {
                UI.toast('success', t('inventory.incidents.messages.resolved_title'), t('inventory.incidents.messages.resolved_message'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.incidents.messages.resolve_error'),
                root: getModalRoot(),
                inline: true,
                tag: 'Incidents',
                onConflict: () => {
                    if (state.modal && state.modal.mode === 'resolve') {
                        state.modal.key = UI.newIdempotencyKey();
                        renderModal();
                    }
                },
                reload: () => loadIncidents(state.pagination.page || 1)
            });
            if (err && (err.status === 422 || err.status === 404)) {
                await loadIncidents(state.pagination.page || 1);
            }
        } finally {
            setSending(false);
        }
    }

    // ====================================================================
    // INVESTIGAR (sin modal)
    // ====================================================================

    async function investigate(id) {
        if (!canResolve()) return;

        const confirmed = global.confirm(t('inventory.incidents.messages.confirm_investigate'));
        if (!confirmed) return;

        try {
            await global.HttpClient.post('/inventory/incidents/' + encodeURIComponent(id) + '/investigate');

            let recargadoDetalle = true;
            if (state.modal && state.modal.mode === 'detail' && state.modal.id === id) {
                recargadoDetalle = await openDetail(id);
            }
            const recargadoLista = await loadIncidents(state.pagination.page || 1);
            if (recargadoDetalle && recargadoLista) {
                UI.toast('success', t('inventory.incidents.messages.investigating_title'), t('inventory.incidents.messages.investigating_message'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('inventory.incidents.messages.investigate_error'),
                tag: 'Incidents'
            });
            if (err && (err.status === 409 || err.status === 422)) {
                if (state.modal && state.modal.mode === 'detail' && state.modal.id === id) {
                    await openDetail(id);
                }
                await loadIncidents(state.pagination.page || 1);
            }
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
                btn.textContent = t('inventory.incidents.messages.sending');
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

    function renderReportBody(modal) {
        return UI.grid(`
            <div>
                ${UI.field({
                    label: t('inventory.incidents.modals.report.tipo_label'),
                    name: 'tipo',
                    type: 'select',
                    required: true,
                    value: modal.tipo,
                    options: [{ value: '', label: t('inventory.incidents.modals.report.select_tipo') }]
                        .concat(TIPOS.map(tp => ({ value: tp.value, label: t(tp.labelKey) }))),
                    hint: t('inventory.incidents.modals.report.tipo_hint')
                })}

                <div class="form-group">
                    <label class="form-label">${t('inventory.incidents.modals.report.unit_label')}</label>
                    <input type="text" class="input" data-modal-field="stockSearch"
                           value="${UI.escapeHtml(modal.stockSearch)}"
                           placeholder="${t('inventory.incidents.modals.report.unit_search_placeholder')}" autocomplete="off">
                    <select class="select" data-modal-field="unidad_fisica_id" size="6">
                        ${stockOptions(filteredStock(), modal.unidad_fisica_id)}
                    </select>
                    <span class="form-error hidden" data-error="unidad_fisica_id"></span>
                    ${modal.stockLoading
                        ? `<span class="form-hint">${t('inventory.incidents.modals.report.loading_units')}</span>`
                        : `<span class="form-hint">${t('inventory.incidents.modals.report.units_available', { count: filteredStock().length })}</span>`}
                </div>

                ${UI.field({
                    label: t('inventory.incidents.modals.report.location_label'),
                    name: 'ubicacion_id',
                    type: 'select',
                    value: modal.ubicacion_id,
                    options: [{ value: '', label: t('inventory.incidents.modals.report.no_location') }]
                        .concat(modal.locations.map(l => ({
                            value: l.id,
                            label: l.codigo + (l.nombre ? ' · ' + l.nombre : '')
                        }))),
                    hint: modal.locationsLoading
                        ? t('inventory.incidents.modals.report.loading_locations')
                        : t('inventory.incidents.modals.report.location_hint')
                })}
            </div>

            <div>
                ${UI.field({
                    label: t('inventory.incidents.modals.report.descripcion_label'),
                    name: 'descripcion',
                    type: 'textarea',
                    rows: 5,
                    required: true,
                    value: modal.descripcion,
                    maxlength: MAX_TEXT,
                    placeholder: t('inventory.incidents.modals.report.descripcion_placeholder')
                })}

                ${UI.field({
                    label: t('inventory.incidents.modals.report.observaciones_label'),
                    name: 'observaciones',
                    type: 'textarea',
                    rows: 4,
                    value: modal.observaciones,
                    maxlength: MAX_TEXT,
                    placeholder: t('inventory.incidents.modals.report.observaciones_placeholder')
                })}
            </div>
        `, 2);
    }

    function renderResolveBody(modal) {
        const conResultado = requiereResultado(modal.tipo);

        const resultadoBlock = conResultado ? `
                ${UI.field({
                    label: t('inventory.incidents.modals.resolve.resultado_label'),
                    name: 'resultado',
                    type: 'select',
                    required: true,
                    value: modal.resultado,
                    options: [{ value: '', label: t('inventory.incidents.modals.resolve.select_resultado') }]
                        .concat(resultadosOptions()),
                    hint: t('inventory.incidents.modals.resolve.resultado_hint')
                })}
                ${modal.resultado === 'ENCONTRADA' ? `
                ${UI.field({
                    label: t('inventory.incidents.modals.resolve.ubicacion_destino_label'),
                    name: 'ubicacion_destino_id',
                    type: 'select',
                    value: modal.ubicacion_destino_id,
                    options: [{ value: '', label: t('inventory.incidents.modals.resolve.previous_location') }]
                        .concat(modal.locations.map(l => ({
                            value: l.id,
                            label: l.codigo + (l.nombre ? ' · ' + l.nombre : '')
                        }))),
                    hint: modal.locationsLoading
                        ? t('inventory.incidents.modals.resolve.loading_locations')
                        : t('inventory.incidents.modals.resolve.ubicacion_destino_hint')
                })}` : ''}` : '';

        return UI.grid(`
            <div>
                ${UI.field({
                    label: t('inventory.incidents.modals.resolve.estado_label'),
                    name: 'estado',
                    type: 'select',
                    required: true,
                    value: modal.estado,
                    options: ESTADOS_RESOLUCION.map(e => ({ value: e.value, label: t(e.labelKey) })),
                    hint: t('inventory.incidents.modals.resolve.estado_hint')
                })}

                ${resultadoBlock}

                ${UI.field({
                    label: t('inventory.incidents.modals.resolve.compensacion_label'),
                    name: 'compensacion',
                    type: 'textarea',
                    rows: 3,
                    value: modal.compensacion,
                    maxlength: MAX_TEXT,
                    placeholder: t('inventory.incidents.modals.resolve.compensacion_placeholder')
                })}
            </div>

            <div>
                ${UI.field({
                    label: t('inventory.incidents.modals.resolve.descripcion_label'),
                    name: 'descripcion_resolucion',
                    type: 'textarea',
                    rows: 4,
                    required: true,
                    value: modal.descripcion_resolucion,
                    maxlength: MAX_TEXT
                })}

                ${UI.field({
                    label: t('inventory.incidents.modals.resolve.acciones_label'),
                    name: 'acciones_tomadas',
                    type: 'textarea',
                    rows: 4,
                    required: true,
                    value: modal.acciones_tomadas,
                    maxlength: MAX_TEXT
                })}
            </div>
        `, 2);
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

        if (modal.mode === 'report') {
            title = t('inventory.incidents.modals.report.title');
            body = `
                <div class="form-error hidden mb-3" data-form-error></div>
                ${renderReportBody(modal)}`;
            footer = `
                <div class="text-xs text-muted mr-auto">Idempotency-Key: <span class="font-mono" data-key-hint>${UI.escapeHtml(modal.key)}</span></div>
                <button class="btn btn-secondary" data-action="close-modal">${t('inventory.incidents.modals.cancel')}</button>
                <button class="btn btn-primary" data-action="submit-report" data-modal-send${state.sending ? ' disabled' : ''}>${t('inventory.incidents.modals.report.submit')}</button>`;
        } else if (modal.mode === 'detail') {
            title = t('inventory.incidents.modals.detail.title');
            size = 'lg';
            body = modal.loading
                ? UI.loadingState()
                : (modal.data ? renderDetailBody(modal.data) : UI.emptyState({ title: t('ui.empty_title'), message: '' }));
            footer = modal.data ? renderDetailFooter(modal.data)
                : `<button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>`;
        } else if (modal.mode === 'resolve') {
            title = t('inventory.incidents.modals.resolve.title');
            body = `
                <div class="form-error hidden mb-3" data-form-error></div>
                <div class="text-xs text-muted mb-3">
                    ${t('inventory.incidents.modals.resolve.context_label')} <span class="font-mono">${UI.escapeHtml(modal.id)}</span>
                    · ${UI.escapeHtml(tipoLabel(modal.tipo))}
                    ${requiereResultado(modal.tipo) ? ' · ' + t('inventory.incidents.modals.resolve.requires_result') : ''}
                </div>
                ${renderResolveBody(modal)}`;
            footer = `
                <div class="text-xs text-muted mr-auto">Idempotency-Key: <span class="font-mono" data-key-hint>${UI.escapeHtml(modal.key)}</span></div>
                <button class="btn btn-secondary" data-action="close-modal">${t('inventory.incidents.modals.cancel')}</button>
                <button class="btn btn-primary" data-action="submit-resolve" data-modal-send${state.sending ? ' disabled' : ''}>${t('inventory.incidents.modals.resolve.submit')}</button>`;
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
            case 'open-report':
                openReport();
                break;
            case 'close-modal':
                closeModal();
                break;
            case 'submit-report':
                submitReport();
                break;
            case 'open-detail':
                openDetail(id);
                break;
            case 'investigate':
                investigate(id);
                break;
            case 'open-resolve':
                openResolve(id);
                break;
            case 'submit-resolve':
                submitResolve();
                break;
            case 'apply-filters':
                readFilters();
                loadIncidents(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
        }

        if (page !== undefined && page !== null && page !== '') {
            loadIncidents(parseInt(page, 10));
        }
    }

    function handleChange(e) {
        const el = e.target;
        if (!state.modal) return;

        if (el.matches('[data-modal-field="tipo"]')) {
            state.modal.tipo = el.value;
            return;
        }
        if (el.matches('[data-modal-field="unidad_fisica_id"]')) {
            state.modal.unidad_fisica_id = el.value;
            return;
        }
        if (el.matches('[data-modal-field="ubicacion_id"]')) {
            state.modal.ubicacion_id = el.value;
            return;
        }
        if (el.matches('[data-modal-field="estado"]')) {
            state.modal.estado = el.value;
            return;
        }
        if (el.matches('[data-modal-field="resultado"]')) {
            state.modal.resultado = el.value;
            state.modal.ubicacion_destino_id = '';
            renderModal();
            if (el.value === 'ENCONTRADA') {
                loadLocationsForResolve();
            }
            return;
        }
        if (el.matches('[data-modal-field="ubicacion_destino_id"]')) {
            state.modal.ubicacion_destino_id = el.value;
        }
    }

    function handleInput(e) {
        const el = e.target;
        if (!state.modal) return;

        if (el.matches('[data-modal-field="stockSearch"]')) {
            state.modal.stockSearch = el.value;
            const select = container.querySelector('[data-modal-field="unidad_fisica_id"]');
            if (select) {
                select.innerHTML = stockOptions(filteredStock(), state.modal.unidad_fisica_id);
            }
            return;
        }

        const textField = ['descripcion', 'observaciones', 'descripcion_resolucion', 'acciones_tomadas', 'compensacion'];
        if (el.matches('[data-modal-field]') && textField.includes(el.dataset.modalField)) {
            state.modal[el.dataset.modalField] = el.value;
        }
    }

    function handleSubmit(e) {
        e.preventDefault();
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const InventoryIncidentsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            container.innerHTML = buildTemplate();
            bindEvents();
            renderUnitFilter();
            renderSummary();
            loadUnitFilterOptions();
            loadIncidents(1);
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            if (!container.querySelector('[data-inc-table]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
                renderUnitFilter();
            }
            loadIncidents(state.pagination.page || 1);
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
            state.filters = { estado: '', tipo: '', unidad_fisica_id: '', fecha_desde: '', fecha_hasta: '' };
            state.unitOptions = [];
        }
    };

    global.InventoryIncidentsModule = InventoryIncidentsModule;

})(window);
