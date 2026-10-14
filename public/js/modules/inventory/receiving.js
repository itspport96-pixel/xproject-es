/**
 * Inventory Receiving Module
 * Recepción de inventario con separación de funciones (v1.13.32):
 *  - receive (inventory.receiving.create): recepción contra un documento
 *            planificado de OTRO usuario. La cantidad esperada se MUESTRA
 *            bloqueada (la fija el documento; el payload no admite
 *            cantidad_esperada). Se introduce sólo lo contado; discrepancia
 *            -> unidad con lo contado + incidencia, sin reconteo.
 *  - plan    (inventory.receiving.plan): crear/editar/cancelar documentos
 *            de recepción con cantidades esperadas.
 *
 * No existe recepción sin documento: POST /api/inventory/receive está
 * cerrado en el backend (422). Sin pestaña "Registro".
 *
 * Convención ModuleLoader: window.InventoryReceivingModule
 *
 * Idempotency-Key (spec R7): se genera al abrir el formulario y se REUTILIZA
 * en cada reintento del mismo envío; se regenera sólo tras un éxito (2xx) y
 * ante un 409 (clave ya usada con carga útil distinta o línea ya cerrada).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    const TABS_RECEIVING = ['receive', 'plan'];

    const ESTADOS_DOC = ['PLANIFICADA', 'EN_RECEPCION', 'CERRADA', 'ANULADA'];

    const DOC_STATE_KEYS = {
        PLANIFICADA: 'inventory.receiving.doc_states.PLANIFICADA',
        EN_RECEPCION: 'inventory.receiving.doc_states.EN_RECEPCION',
        CERRADA: 'inventory.receiving.doc_states.CERRADA',
        ANULADA: 'inventory.receiving.doc_states.ANULADA'
    };

    const LINE_STATE_KEYS = {
        RECIBIDA: 'inventory.receiving.line_states.RECIBIDA',
        RECHAZADA: 'inventory.receiving.line_states.RECHAZADA',
        RECONTEO: 'inventory.receiving.line_states.RECONTEO',
        RECIBIDA_CON_INCIDENCIA: 'inventory.receiving.line_states.RECIBIDA_CON_INCIDENCIA'
    };

    // Estados de línea que se pueden recibir (PENDIENTE y RECONTEO legado).
    const LINEAS_RECIBIBLES = ['PENDIENTE', 'RECONTEO'];

    let container = null;

    let state = {
        tab: 'receive',
        products: [],
        locations: [],
        idempotencyKey: '',
        loadingCatalog: false,
        // plan
        planDocs: [],
        planPagination: null,
        planLoading: false,
        planError: '',
        planFilters: { estado: '', search: '', page: 1 },
        planEditing: null,
        planSaving: false,
        // receive
        receiveDocs: [],
        receiveLoading: false,
        receiveError: '',
        receiveSelectedId: '',
        receiveSending: false,
        receiveResult: null
    };

    // ====================================================================
    // PERMISOS
    // ====================================================================

    function can(permission, fallbackRoles) {
        if (global.UI && typeof global.UI.can === 'function') {
            return global.UI.can(permission, fallbackRoles);
        }
        return false;
    }

    const canPlan = () => can('inventory.receiving.plan', ['ADMIN', 'MANAGER']);
    const canCreate = () => can('inventory.receiving.create', ['ADMIN', 'MANAGER', 'USER']);
    const canAny = () => canPlan() || canCreate();

    function visibleTabs() {
        const tabs = [];
        if (canCreate()) tabs.push({ id: 'receive', label: t('inventory.receiving.tabs.receive') });
        if (canPlan()) tabs.push({ id: 'plan', label: t('inventory.receiving.tabs.plan') });
        return tabs;
    }

    function fallbackTab() {
        const tabs = visibleTabs();
        return tabs.length ? tabs[0].id : 'receive';
    }

    // ====================================================================
    // TEMPLATE (SHELL + PANELES)
    // ====================================================================

    function buildTemplate() {
        const tabs = visibleTabs();

        const panels = [];

        if (canCreate()) {
            panels.push({ id: 'receive', html: buildReceivePanel() });
        }

        if (canPlan()) {
            panels.push({ id: 'plan', html: buildPlanPanel() });
        }

        return UI.viewShell({
            title: t('inventory.receiving.title'),
            subtitle: t('inventory.receiving.subtitle'),
            tabs: tabs,
            activeTab: state.tab,
            panels: panels
        }) + `
        <div data-modal-root></div>
    `;
    }

    // --------------------------------------------------------------------
    // PESTAÑA: RECEPCIÓN (contra documento, con esperado visible)
    // --------------------------------------------------------------------

    function buildReceivePanel() {
        return `
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">${t('inventory.receiving.receive.title')}</h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">${t('inventory.receiving.receive.expected_notice')}</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group mb-0">
                            <label class="form-label">${t('inventory.receiving.receive.select_doc')}</label>
                            <select class="select" data-receive-doc-select>
                                <option value="">${t('inventory.receiving.receive.select_placeholder')}</option>
                            </select>
                            <span class="form-hint">${t('inventory.receiving.receive.select_hint')}</span>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">${t('inventory.receiving.receive.ubicacion_destino')} <span class="required">*</span></label>
                            <select class="select" data-receive-ubicacion>
                                <option value="">${t('inventory.receiving.selects.ubicacion')}</option>
                            </select>
                            <span class="form-hint">${t('inventory.receiving.hints.ubicacion')}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div data-receive-doc-detail></div>
            <div data-receive-result></div>`;
    }

    // --------------------------------------------------------------------
    // PESTAÑA: PLANIFICACIÓN
    // --------------------------------------------------------------------

    function buildPlanPanel() {
        return `
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">${t('inventory.receiving.plan.title')}</h3>
                    <button type="button" class="btn btn-primary btn-sm" data-action="plan-new">${t('inventory.receiving.plan.new')}</button>
                </div>
                <div class="card-body">
                    ${UI.filtersCard(`
                        <div class="form-group mb-0">
                            <label class="form-label">${t('inventory.receiving.plan.filters.estado')}</label>
                            <select class="select" data-filter="estado">
                                <option value="">${t('inventory.receiving.plan.filters.all_states')}</option>
                                ${ESTADOS_DOC.map(e => `<option value="${e}">${t(DOC_STATE_KEYS[e])}</option>`).join('')}
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">${t('inventory.receiving.plan.filters.search')}</label>
                            <input type="text" class="input" data-filter="search" maxlength="100"
                                   placeholder="${t('inventory.receiving.plan.filters.search_placeholder')}" autocomplete="off">
                        </div>`, {
                        cols: 2,
                        applyLabel: t('inventory.receiving.plan.filters.apply'),
                        clearLabel: t('inventory.receiving.plan.filters.clear'),
                        clearTitle: t('inventory.receiving.plan.filters.clear_title')
                    })}
                </div>
            </div>

            <div data-plan-editor></div>

            <div class="card p-0">
                <div class="table-wrapper" data-plan-table></div>
                <div data-plan-pagination class="pagination"></div>
            </div>`;
    }

    // ====================================================================
    // IDEMPOTENCY KEY
    // ====================================================================

    function fallbackKey() {
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let out = '';
        for (let i = 0; i < 32; i++) {
            out += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
        }
        return out;
    }

    function newIdempotencyKey() {
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return global.crypto.randomUUID();
        }
        return fallbackKey();
    }

    function renderKeyHint() {
        const root = container && container.querySelector('[data-key-hint]');
        if (!root) return;
        root.innerHTML = `Idempotency-Key: <span class="font-mono">${escapeHtml(state.idempotencyKey)}</span>`;
    }

    // ====================================================================
    // PESTAÑAS
    // ====================================================================

    function applyTab(tab) {
        state.tab = tab;
        if (UI.applyTabState) UI.applyTabState(container, tab);
        if (UI.syncTabParam) UI.syncTabParam(tab);
    }

    function switchTab(tab) {
        if (TABS_RECEIVING.indexOf(tab) === -1) return;

        applyTab(tab);
        if (tab === 'plan') loadPlanDocs();
        else if (tab === 'receive') loadReceiveDocs();
    }

    // ====================================================================
    // RENDER: PLANIFICACIÓN
    // ====================================================================

    function renderPlanTable() {
        const root = container && container.querySelector('[data-plan-table]');
        if (!root) return;

        if (state.planLoading) {
            root.innerHTML = UI.loadingState();
            return;
        }

        if (state.planError) {
            root.innerHTML = UI.errorState({
                title: t('inventory.receiving.plan.load_error_title'),
                message: state.planError,
                retryAction: 'plan-reload'
            });
            return;
        }

        if (!state.planDocs.length) {
            root.innerHTML = UI.emptyState({
                title: t('inventory.receiving.plan.empty_title'),
                message: t('inventory.receiving.plan.empty_message')
            });
            return;
        }

        const rows = state.planDocs.map(d => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(d.numero_documento)}</td>
                <td>${escapeHtml(d.proveedor || '—')}</td>
                <td>${escapeHtml(d.referencia || '—')}</td>
                <td>${docStateBadge(d.estado)}</td>
                <td>${d.lineas_recibidas}/${d.lineas_totales}</td>
                <td class="text-xs text-muted">${escapeHtml((d.created_at || '').replace('T', ' ').substring(0, 16))}</td>
                <td class="table-actions col-actions">${planRowActions(d)}</td>
            </tr>`).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('inventory.receiving.plan.columns.numero')}</th>
                        <th>${t('inventory.receiving.plan.columns.proveedor')}</th>
                        <th>${t('inventory.receiving.plan.columns.referencia')}</th>
                        <th>${t('inventory.receiving.plan.columns.estado')}</th>
                        <th>${t('inventory.receiving.plan.columns.lineas')}</th>
                        <th>${t('inventory.receiving.plan.columns.fecha')}</th>
                        <th class="col-actions">${t('inventory.receiving.plan.columns.acciones')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;
    }

    function planRowActions(d) {
        const primary = d.estado === 'PLANIFICADA'
            ? { action: 'plan-edit', id: d.id, label: t('inventory.receiving.plan.actions.edit') }
            : null;

        const groups = [];

        if (d.estado === 'PLANIFICADA' || d.estado === 'EN_RECEPCION') {
            groups.push([
                {
                    action: 'plan-cancel',
                    id: d.id,
                    label: t('inventory.receiving.plan.actions.cancel'),
                    danger: true
                }
            ]);
        }

        return UI.rowActions({ primary: primary, groups: groups });
    }

    function renderPlanPagination() {
        const root = container && container.querySelector('[data-plan-pagination]');
        if (!root) return;

        const p = state.planPagination;
        if (!p || p.total <= p.per_page) {
            root.innerHTML = '';
            return;
        }

        UI.renderPagination(root, {
            pagination: p,
            shown: state.planDocs.length,
            label: t('inventory.receiving.plan.pagination_label')
        });
    }

    function renderPlanEditor() {
        const root = container && container.querySelector('[data-plan-editor]');
        if (!root) return;

        if (!state.planEditing) {
            root.innerHTML = '';
            return;
        }

        const e = state.planEditing;
        const isEdit = !!e.id;
        const lineas = e.lineas || [];

        const linesHtml = lineas.map((l, i) => `
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end mb-3" data-plan-line="${i}">
                <div class="form-group mb-0 md:col-span-2">
                    <label class="form-label">${t('inventory.receiving.plan.editor.sku')} <span class="required">*</span></label>
                    <select class="select" data-line-sku>
                        <option value="">${t('inventory.receiving.selects.producto')}</option>
                        ${state.products.map(p => `
                            <option value="${escapeHtml(p.sku)}" ${p.sku === l.sku ? 'selected' : ''}>
                                ${escapeHtml(p.sku)} - ${escapeHtml(p.nombre)}
                            </option>`).join('')}
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">${t('inventory.receiving.plan.editor.cantidad_esperada')} <span class="required">*</span></label>
                    <input type="number" class="input" data-line-cantidad min="0.01" step="any"
                           inputmode="decimal" value="${l.cantidad_esperada !== undefined && l.cantidad_esperada !== null ? escapeHtml(String(l.cantidad_esperada)) : ''}">
                </div>
            </div>`).join('');

        root.innerHTML = `
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">${isEdit ? t('inventory.receiving.plan.editor.edit_title') : t('inventory.receiving.plan.editor.new_title')}</h3>
                </div>
                <div class="card-body">
                    <form data-plan-form novalidate>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="form-group">
                                <label class="form-label">${t('inventory.receiving.plan.editor.numero')} <span class="required">*</span></label>
                                <input type="text" class="input" name="numero_documento" maxlength="100" value="${escapeHtml(e.numero_documento || '')}">
                                <span class="form-error hidden" data-error="numero_documento"></span>
                            </div>
                            <div class="form-group">
                                <label class="form-label">${t('inventory.receiving.plan.editor.proveedor')}</label>
                                <input type="text" class="input" name="proveedor" maxlength="255" value="${escapeHtml(e.proveedor || '')}">
                                <span class="form-error hidden" data-error="proveedor"></span>
                            </div>
                            <div class="form-group">
                                <label class="form-label">${t('inventory.receiving.plan.editor.referencia')}</label>
                                <input type="text" class="input" name="referencia" maxlength="255" value="${escapeHtml(e.referencia || '')}">
                                <span class="form-error hidden" data-error="referencia"></span>
                            </div>
                        </div>

                        <div class="form-label mt-2 mb-1">${t('inventory.receiving.plan.editor.lineas')}</div>
                        <div data-plan-lines>${linesHtml}</div>
                        <button type="button" class="btn btn-ghost btn-sm" data-action="plan-add-line">${t('inventory.receiving.plan.editor.add_line')}</button>
                        <span class="form-error hidden mt-1" data-error="lineas"></span>

                        <div data-plan-form-error class="alert alert-danger hidden mt-4"></div>

                        <div class="flex flex-col md:flex-row md:items-center md:justify-end gap-3 mt-4">
                            <button type="button" class="btn btn-ghost" data-action="plan-cancel-edit">${t('ui.cancel')}</button>
                            <button type="submit" class="btn btn-primary" data-action="plan-save" ${state.planSaving ? 'disabled' : ''}>
                                ${state.planSaving ? t('inventory.receiving.plan.editor.saving') : t('inventory.receiving.plan.editor.save')}
                            </button>
                        </div>
                    </form>
                </div>
            </div>`;
    }

    // ====================================================================
    // RENDER: RECEPCIÓN
    // ====================================================================

    function renderReceiveSelect() {
        const sel = container && container.querySelector('[data-receive-doc-select]');
        if (!sel) return;

        if (state.receiveLoading) {
            sel.innerHTML = `<option value="">${t('inventory.receiving.receive.loading')}</option>`;
            return;
        }

        if (state.receiveError) {
            sel.innerHTML = `<option value="">${t('inventory.receiving.receive.select_placeholder')}</option>`;
            return;
        }

        sel.innerHTML = `<option value="">${t('inventory.receiving.receive.select_placeholder')}</option>` +
            state.receiveDocs.map(d => `
                <option value="${escapeHtml(d.id)}" ${d.id === state.receiveSelectedId ? 'selected' : ''}>
                    ${escapeHtml(d.numero_documento)}${d.proveedor ? ' · ' + escapeHtml(d.proveedor) : ''}
                </option>`).join('');
    }

    function renderReceiveDetail() {
        const root = container && container.querySelector('[data-receive-doc-detail]');
        if (!root) return;

        const doc = state.receiveDocs.find(d => d.id === state.receiveSelectedId) || null;
        if (!doc) {
            root.innerHTML = '';
            return;
        }

        const lineas = (doc.lineas || []).filter(l => LINEAS_RECIBIBLES.indexOf(l.estado) !== -1);

        if (!lineas.length) {
            root.innerHTML = `
                <div class="card">
                    <div class="card-body">
                        ${UI.emptyState({
                            title: t('inventory.receiving.receive.all_received_title'),
                            message: t('inventory.receiving.receive.all_received_message')
                        })}
                    </div>
                </div>`;
            return;
        }

        // La cantidad esperada se MUESTRA pero va bloqueada: el documento la
        // fija y el payload no admite cantidad_esperada (v1.13.32).
        const linesHtml = lineas.map((l, i) => `
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end mb-3" data-receive-line="${i}" data-sku="${escapeHtml(l.sku)}">
                <div class="form-group mb-0 md:col-span-1">
                    <label class="form-label">${t('inventory.receiving.plan.columns.sku')}</label>
                    <div class="font-mono text-sm">${escapeHtml(l.sku)}</div>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">${t('inventory.receiving.labels.cantidad_esperada')}</label>
                    <input type="number" class="input" data-line-esperada value="${formatNumber(l.cantidad_esperada)}" readonly disabled>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">${t('inventory.receiving.receive.cantidad_recibida')} <span class="required">*</span></label>
                    <input type="number" class="input" data-line-recibida min="0" step="any" inputmode="decimal" placeholder="0">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">${t('inventory.receiving.labels.lote')}</label>
                    <input type="text" class="input" data-line-lote maxlength="255" placeholder="${t('inventory.receiving.placeholders.opcional')}">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">${t('inventory.receiving.labels.numero_serie')}</label>
                    <input type="text" class="input" data-line-serie maxlength="255" placeholder="${t('inventory.receiving.placeholders.opcional')}">
                </div>
            </div>`).join('');

        root.innerHTML = `
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title font-mono">${escapeHtml(doc.numero_documento)}</h3>
                    ${docStateBadge(doc.estado)}
                </div>
                <div class="card-body">
                    <div class="text-sm mb-3">${escapeHtml(doc.proveedor || '—')} ${doc.referencia ? '· ' + escapeHtml(doc.referencia) : ''}</div>
                    ${linesHtml}
                    <div class="form-group md:col-span-2 mt-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" data-rechazo-total>
                            <span class="form-label mb-0">${t('inventory.receiving.labels.rechazo_total')}</span>
                        </label>
                        <span class="form-hint">${t('inventory.receiving.hints.rechazo_total')}</span>
                    </div>
                    <div data-receive-form-error class="alert alert-danger hidden mt-4"></div>
                    <div class="flex items-center justify-end gap-3 mt-4 flex-wrap">
                        <div class="text-xs text-muted" data-key-hint></div>
                        <button type="button" class="btn btn-primary" data-action="submit-doc-receive" ${state.receiveSending ? 'disabled' : ''}>
                            ${state.receiveSending ? t('inventory.receiving.receive.submitting') : t('inventory.receiving.receive.submit')}
                        </button>
                    </div>
                </div>
            </div>`;
    }

    function renderReceiveResult() {
        const root = container && container.querySelector('[data-receive-result]');
        if (!root) return;

        const r = state.receiveResult;
        if (!r) {
            root.innerHTML = '';
            return;
        }

        const lineas = r.lineas || [];

        const linesHtml = lineas.map(l => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(l.sku)}</td>
                <td>${lineStateBadge(l.estado_linea)}</td>
                <td>${l.cantidad_recibida === null || l.cantidad_recibida === undefined ? '—' : formatNumber(l.cantidad_recibida)}</td>
                <td class="font-mono text-xs">${l.unidad_fisica_id ? escapeHtml(String(l.unidad_fisica_id).substring(0, 8)) : '—'}</td>
                <td class="font-mono text-xs">${l.incidencia_id ? escapeHtml(String(l.incidencia_id).substring(0, 8)) : '—'}</td>
            </tr>`).join('');

        const badge = r.rechazo_total
            ? `<span class="badge badge-danger">${t('inventory.receiving.receive.states.rechazada')}</span>`
            : `<span class="badge badge-success">${t('inventory.receiving.receive.states.ok')}</span>`;

        root.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">${t('inventory.receiving.receive.result_title')}</h3>
                    ${badge}
                </div>
                <div class="card-body">
                    <div class="text-sm mb-2">
                        ${t('inventory.receiving.receive.messages.doc_estado')}: ${docStateBadge(r.estado_documento)}
                    </div>
                    <div class="text-xs text-muted mb-3">${escapeHtml(r.mensaje || '')}</div>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>${t('inventory.receiving.plan.columns.sku')}</th>
                                <th>${t('inventory.receiving.receive.columns.estado_linea')}</th>
                                <th>${t('inventory.receiving.receive.columns.contada')}</th>
                                <th>${t('inventory.receiving.receive.columns.unidad')}</th>
                                <th>${t('inventory.receiving.receive.columns.incidencia')}</th>
                            </tr>
                        </thead>
                        <tbody>${linesHtml}</tbody>
                    </table>
                </div>
            </div>`;
    }

    // ====================================================================
    // RENDER: CATÁLOGOS (ubicaciones para la recepción; productos para plan)
    // ====================================================================

    function renderCatalogs() {
        if (!container) return;

        const receiveLocationSelect = container.querySelector('[data-receive-ubicacion]');
        if (receiveLocationSelect) {
            const selected = receiveLocationSelect.value;
            receiveLocationSelect.innerHTML = `<option value="">${t('inventory.receiving.selects.ubicacion')}</option>` +
                state.locations.map(l => {
                    const capacidad = (l.capacidad === null || l.capacidad === undefined)
                        ? t('inventory.receiving.options.sin_limite')
                        : `${l.capacidad_usada || 0}/${l.capacidad}`;
                    const sinEspacio = l.capacidad !== null && l.capacidad !== undefined
                        && (l.capacidad_usada || 0) >= l.capacidad;
                    return `
                        <option value="${escapeHtml(l.codigo)}" ${l.codigo === selected ? 'selected' : ''} ${sinEspacio ? 'disabled' : ''}>
                            ${escapeHtml(l.codigo)} (${escapeHtml(capacidad)})${sinEspacio ? t('inventory.receiving.options.sin_espacio') : ''}
                        </option>`;
                }).join('');
        }
    }

    function showReceiveFormError(message) {
        const el = container && container.querySelector('[data-receive-form-error]');
        if (!el) return;
        if (!message) {
            el.classList.add('hidden');
            el.textContent = '';
            return;
        }
        el.textContent = message;
        el.classList.remove('hidden');
    }

    function showFieldError(field, message) {
        const el = container && container.querySelector(`[data-error="${field}"]`);
        if (!el) return;
        if (!message) {
            el.classList.add('hidden');
            el.textContent = '';
            return;
        }
        el.textContent = message;
        el.classList.remove('hidden');
        el.scrollIntoView({ block: 'nearest' });
    }

    function clearFieldErrors() {
        if (!container) return;
        container.querySelectorAll('[data-error]').forEach(el => {
            el.classList.add('hidden');
            el.textContent = '';
        });
    }

    // ====================================================================
    // CATALOGOS
    // ====================================================================

    async function loadCatalogs() {
        state.loadingCatalog = true;
        try {
            const [productsRes, locationsRes] = await Promise.all([
                global.HttpClient.get('/inventory/products', {
                    params: { activo: '1', page: 1, per_page: 100 }
                }),
                global.HttpClient.get('/inventory/locations', {
                    params: { activa: '1', page: 1, per_page: 100 }
                })
            ]);

            const productsPayload = productsRes.data || productsRes;
            const locationsPayload = locationsRes.data || locationsRes;

            state.products = productsPayload.data || [];
            state.locations = locationsPayload.data || [];
            renderCatalogs();
            renderPlanEditor();
            return true;
        } catch (err) {
            console.error('[Receiving] catalog load error:', err);
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.receiving.messages.catalog_error'));
            return false;
        } finally {
            state.loadingCatalog = false;
        }
    }

    // ====================================================================
    // DATOS: PLANIFICACIÓN
    // ====================================================================

    async function loadPlanDocs() {
        if (!canPlan()) return;
        state.planLoading = true;
        state.planError = '';
        renderPlanTable();

        try {
            const params = { page: state.planFilters.page, per_page: 20 };
            if (state.planFilters.estado) params.estado = state.planFilters.estado;
            if (state.planFilters.search) params.search = state.planFilters.search;

            const res = await global.HttpClient.get('/inventory/receiving/documents', { params });
            const payload = res.data || res;

            state.planDocs = payload.data || [];
            state.planPagination = payload.pagination || null;
        } catch (err) {
            console.error('[Receiving] plan load error:', err);
            state.planError = err.message || t('inventory.receiving.plan.load_error_fallback');
        } finally {
            state.planLoading = false;
            renderPlanTable();
            renderPlanPagination();
        }
    }

    function openPlanNew() {
        state.planEditing = { numero_documento: '', proveedor: '', referencia: '', lineas: [{ sku: '', cantidad_esperada: '' }] };
        renderPlanEditor();
    }

    function openPlanEdit(id) {
        const doc = state.planDocs.find(d => d.id === id);
        state.planEditing = {
            id: id,
            numero_documento: doc ? doc.numero_documento : '',
            proveedor: doc ? (doc.proveedor || '') : '',
            referencia: doc ? (doc.referencia || '') : '',
            lineas: [{ sku: '', cantidad_esperada: '' }]
        };
        loadPlanDetail(id);
    }

    async function loadPlanDetail(id) {
        try {
            const res = await global.HttpClient.get('/inventory/receiving/documents/' + encodeURIComponent(id));
            const payload = res.data || res;
            const doc = payload.documento || null;
            if (doc && state.planEditing && state.planEditing.id === id) {
                state.planEditing = {
                    id: id,
                    numero_documento: doc.numero_documento || '',
                    proveedor: doc.proveedor || '',
                    referencia: doc.referencia || '',
                    lineas: (doc.lineas || []).map(l => ({ sku: l.sku, cantidad_esperada: l.cantidad_esperada }))
                };
                if (!state.planEditing.lineas.length) {
                    state.planEditing.lineas = [{ sku: '', cantidad_esperada: '' }];
                }
                renderPlanEditor();
            }
        } catch (err) {
            console.error('[Receiving] plan detail load error:', err);
        }
    }

    function readPlanForm() {
        const form = container.querySelector('[data-plan-form]');
        if (!form) return null;

        const raw = Object.fromEntries(new FormData(form));
        const lineNodes = form.querySelectorAll('[data-plan-line]');
        const lineas = [];
        lineNodes.forEach(node => {
            const sku = (node.querySelector('[data-line-sku]').value || '').trim();
            const cantidad = (node.querySelector('[data-line-cantidad]').value || '').trim();
            if (sku !== '' || cantidad !== '') {
                lineas.push({ sku: sku, cantidad_esperada: cantidad });
            }
        });

        return {
            numero_documento: (raw.numero_documento || '').trim(),
            proveedor: (raw.proveedor || '').trim(),
            referencia: (raw.referencia || '').trim(),
            lineas: lineas
        };
    }

    function validatePlan(data) {
        if (!data.numero_documento) {
            return { field: 'numero_documento', message: t('inventory.receiving.plan.messages.error_numero_required') };
        }
        if (!data.lineas.length) {
            return { field: 'lineas', message: t('inventory.receiving.plan.messages.error_lineas_required') };
        }
        for (let i = 0; i < data.lineas.length; i++) {
            const l = data.lineas[i];
            if (!l.sku) {
                return { field: 'lineas', message: t('inventory.receiving.plan.messages.error_linea_sku') };
            }
            const cant = parseFloat(l.cantidad_esperada);
            if (!isFinite(cant) || cant <= 0) {
                return { field: 'lineas', message: t('inventory.receiving.plan.messages.error_linea_cantidad') };
            }
        }
        return null;
    }

    async function submitPlan() {
        if (state.planSaving) return;

        clearFieldErrors();
        const data = readPlanForm();
        if (!data) return;

        const invalid = validatePlan(data);
        if (invalid) {
            showFieldError(invalid.field, invalid.message);
            return;
        }

        state.planSaving = true;
        renderPlanEditor();

        try {
            const editing = state.planEditing;
            const body = {
                numero_documento: data.numero_documento,
                proveedor: data.proveedor,
                referencia: data.referencia,
                lineas: data.lineas.map(l => ({ sku: l.sku, cantidad_esperada: parseFloat(l.cantidad_esperada) }))
            };

            if (editing && editing.id) {
                await global.HttpClient.patch('/inventory/receiving/documents/' + encodeURIComponent(editing.id), body);
                showToast('success', t('inventory.receiving.plan.messages.saved_title'), t('inventory.receiving.plan.messages.updated_ok'));
            } else {
                await global.HttpClient.post('/inventory/receiving/documents', body);
                showToast('success', t('inventory.receiving.plan.messages.saved_title'), t('inventory.receiving.plan.messages.created_ok'));
            }

            state.planEditing = null;
            await loadPlanDocs();
        } catch (err) {
            console.error('[Receiving] plan save error:', err);
            const el = container.querySelector('[data-plan-form-error]');
            const message = err.message || t('inventory.receiving.plan.messages.save_error');
            if (el) {
                el.textContent = message;
                el.classList.remove('hidden');
            }
            if (err.data && err.data.field) showFieldError(err.data.field, message);
            // Aviso de separación de funciones: el tenant necesita otro
            // usuario activo con receiving.create (409 del plan-save).
            if (err.status === 409 && message.indexOf('otro usuario') !== -1) {
                showToast('warning', t('inventory.receiving.plan.messages.saved_title'),
                    t('inventory.receiving.plan.messages.error_second_receiver'));
            }
        } finally {
            state.planSaving = false;
            renderPlanEditor();
        }
    }

    async function cancelPlanDoc(id) {
        try {
            await global.HttpClient.post('/inventory/receiving/documents/' + encodeURIComponent(id) + '/cancel');
            showToast('success', t('inventory.receiving.plan.messages.cancelled_title'), t('inventory.receiving.plan.messages.cancelled_ok'));
            await loadPlanDocs();
            if (state.receiveSelectedId === id) {
                state.receiveSelectedId = '';
                renderReceiveSelect();
                renderReceiveDetail();
            }
        } catch (err) {
            console.error('[Receiving] plan cancel error:', err);
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.receiving.plan.messages.cancel_error'));
        }
    }

    function addPlanLine() {
        if (!state.planEditing) return;
        const data = readPlanForm();
        if (data) state.planEditing = Object.assign({}, state.planEditing, data);
        state.planEditing.lineas = (state.planEditing.lineas || []).concat([{ sku: '', cantidad_esperada: '' }]);
        renderPlanEditor();
    }

    // ====================================================================
    // DATOS: RECEPCIÓN (pendientes del receptor)
    // ====================================================================

    async function loadReceiveDocs() {
        if (!canCreate()) return;
        state.receiveLoading = true;
        state.receiveError = '';
        renderReceiveSelect();

        try {
            const res = await global.HttpClient.get('/inventory/receiving/documents/pending', {
                params: { page: 1, per_page: 100 }
            });
            const payload = res.data || res;

            state.receiveDocs = payload.data || [];
        } catch (err) {
            console.error('[Receiving] pending docs load error:', err);
            state.receiveError = err.message || t('inventory.receiving.receive.load_error_fallback');
        } finally {
            state.receiveLoading = false;
            renderReceiveSelect();
            renderReceiveDetail();
        }
    }

    function readReceiveLines() {
        const root = container.querySelector('[data-receive-doc-detail]');
        if (!root) return null;

        const ubicacion = (container.querySelector('[data-receive-ubicacion]').value || '').trim();
        const rechazoTotal = !!(root.querySelector('[data-rechazo-total]') || {}).checked;
        const lineNodes = root.querySelectorAll('[data-receive-line]');
        const lineas = [];

        lineNodes.forEach(node => {
            const sku = node.getAttribute('data-sku');
            const lote = (node.querySelector('[data-line-lote]').value || '').trim();
            const serie = (node.querySelector('[data-line-serie]').value || '').trim();

            const item = { sku: sku };
            if (lote !== '') item.lote = lote;
            if (serie !== '') item.numero_serie = serie;

            if (!rechazoTotal) {
                const recibida = (node.querySelector('[data-line-recibida]').value || '').trim();
                item.cantidad_recibida = recibida;
            }

            lineas.push(item);
        });

        return { ubicacion_codigo: ubicacion, rechazo_total: rechazoTotal, lineas: lineas };
    }

    async function submitDocReceive() {
        if (state.receiveSending) return;
        if (!state.receiveSelectedId) return;

        showReceiveFormError('');

        const data = readReceiveLines();
        if (!data) return;

        if (!data.ubicacion_codigo) {
            showReceiveFormError(t('inventory.receiving.receive.messages.error_ubicacion_required'));
            return;
        }

        if (!data.rechazo_total) {
            for (let i = 0; i < data.lineas.length; i++) {
                const l = data.lineas[i];
                if (l.cantidad_recibida === '' || l.cantidad_recibida === undefined) {
                    showReceiveFormError(t('inventory.receiving.receive.messages.error_lineas_required'));
                    return;
                }
                const n = parseFloat(l.cantidad_recibida);
                if (!isFinite(n) || n < 0) {
                    showReceiveFormError(t('inventory.receiving.receive.messages.error_lineas_required'));
                    return;
                }
                l.cantidad_recibida = n;
            }
        }

        const body = { ubicacion_codigo: data.ubicacion_codigo, lineas: data.lineas };
        if (data.rechazo_total) body.rechazo_total = true;

        state.receiveSending = true;
        renderReceiveDetail();
        renderKeyHint();

        try {
            const response = await global.HttpClient.post(
                '/inventory/receiving/documents/' + encodeURIComponent(state.receiveSelectedId) + '/receive',
                body,
                { headers: { 'Idempotency-Key': state.idempotencyKey } }
            );

            state.receiveResult = (response && response.data) ? response.data : response;

            state.idempotencyKey = newIdempotencyKey();

            renderReceiveResult();
            renderKeyHint();

            await loadReceiveDocs();

            showToast(
                state.receiveResult.rechazo_total ? 'warning' : 'success',
                t('inventory.receiving.receive.messages.toast_title'),
                state.receiveResult.mensaje || ''
            );
        } catch (err) {
            handleReceiveError(err);
        } finally {
            state.receiveSending = false;
            renderReceiveDetail();
            renderKeyHint();
        }
    }

    function handleReceiveError(err) {
        const status = err.status;
        const message = err.message || t('inventory.receiving.receive.messages.submit_error');

        console.error('[Receiving] doc receive error:', status, message);

        if (status === 409) {
            state.idempotencyKey = newIdempotencyKey();
            renderKeyHint();
            showReceiveFormError(message);
            showToast('warning', t('inventory.receiving.receive.messages.toast_title'),
                t('inventory.receiving.receive.messages.error_409'));
            loadReceiveDocs();
            return;
        }

        if (status === 403 || status === 422 || status === 404 || status === 400) {
            showReceiveFormError(message);
            showToast('danger', t('ui.error_generic'), message);
            if (status === 404 || status === 422) loadReceiveDocs();
            return;
        }

        showReceiveFormError(message);
        showToast('danger', t('ui.error_generic'), message);
    }

    // ====================================================================
    // EVENTS
    // ====================================================================

    function bindEvents() {
        if (!container) return;

        container.removeEventListener('submit', handleSubmit);
        container.removeEventListener('change', handleChange);
        container.removeEventListener('click', handleClick);
        container.addEventListener('submit', handleSubmit);
        container.addEventListener('change', handleChange);
        container.addEventListener('click', handleClick);
    }

    function handleChange(e) {
        if (e.target.matches('[data-rechazo-total]')) {
            const detail = container.querySelector('[data-receive-doc-detail]');
            if (!detail) return;
            detail.querySelectorAll('[data-line-recibida]').forEach(input => {
                input.disabled = e.target.checked;
                if (e.target.checked) input.value = '';
            });
            return;
        }

        if (e.target.matches('[data-filter]')) {
            const key = e.target.getAttribute('data-filter');
            state.planFilters[key] = e.target.value;
            state.planFilters.page = 1;
            return;
        }

        if (e.target.matches('[data-receive-doc-select]')) {
            state.receiveSelectedId = e.target.value;
            state.receiveResult = null;
            renderReceiveResult();
            renderReceiveDetail();
            renderKeyHint();
        }
    }

    function handleClick(e) {
        const pageBtn = e.target.closest('[data-page]');
        if (pageBtn && container.contains(pageBtn)) {
            const page = parseInt(pageBtn.getAttribute('data-page'), 10);
            if (isFinite(page)) {
                state.planFilters.page = page;
                loadPlanDocs();
            }
            return;
        }

        const btn = e.target.closest('[data-action]');
        if (!btn) return;

        const action = btn.getAttribute('data-action');

        if (action === 'tab') {
            switchTab(btn.getAttribute('data-tab'));
            return;
        }

        if (action === 'apply-filters') {
            state.planFilters.page = 1;
            loadPlanDocs();
            return;
        }

        if (action === 'clear-filters') {
            state.planFilters = { estado: '', search: '', page: 1 };
            container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
            loadPlanDocs();
            return;
        }

        if (action === 'plan-new') {
            openPlanNew();
            return;
        }

        if (action === 'plan-edit') {
            openPlanEdit(btn.getAttribute('data-id'));
            return;
        }

        if (action === 'plan-cancel') {
            const id = btn.getAttribute('data-id');
            if (!id) return;
            if (!global.confirm(t('inventory.receiving.plan.actions.cancel_confirm'))) {
                return;
            }
            cancelPlanDoc(id);
            return;
        }

        if (action === 'plan-reload') {
            loadPlanDocs();
            return;
        }

        if (action === 'plan-add-line') {
            addPlanLine();
            return;
        }

        if (action === 'plan-cancel-edit') {
            state.planEditing = null;
            renderPlanEditor();
            return;
        }

        if (action === 'plan-save') {
            submitPlan();
            return;
        }

        if (action === 'submit-doc-receive') {
            submitDocReceive();
            return;
        }
    }

    function handleSubmit(e) {
        if (e.target.matches('[data-plan-form]')) {
            e.preventDefault();
            submitPlan();
        }
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatNumber(value) {
        const n = parseFloat(value);
        if (!isFinite(n)) return '0';
        return Number.isInteger(n) ? String(n) : n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    }

    function docStateBadge(estado) {
        const map = {
            PLANIFICADA: 'badge-neutral',
            EN_RECEPCION: 'badge-warning',
            CERRADA: 'badge-success',
            ANULADA: 'badge-danger'
        };
        const cls = map[estado] || 'badge-neutral';
        const label = DOC_STATE_KEYS[estado] ? t(DOC_STATE_KEYS[estado]) : estado;
        return `<span class="badge ${cls}">${escapeHtml(label)}</span>`;
    }

    function lineStateBadge(estado) {
        const map = {
            RECIBIDA: 'badge-success',
            RECHAZADA: 'badge-danger',
            RECONTEO: 'badge-warning',
            RECIBIDA_CON_INCIDENCIA: 'badge-danger'
        };
        const cls = map[estado] || 'badge-neutral';
        const label = LINE_STATE_KEYS[estado] ? t(LINE_STATE_KEYS[estado]) : estado;
        return `<span class="badge ${cls}">${escapeHtml(label)}</span>`;
    }

    function showToast(type, title, message = '') {
        if (global.EventBus) {
            global.EventBus.emit('toast:show', { type, title, message, duration: 4000 });
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const InventoryReceivingModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canAny()) {
                container.innerHTML = UI.deniedState({
                    title: t('inventory.receiving.permission.title'),
                    message: t('inventory.receiving.permission.body', { permiso: 'inventory.receiving' })
                });
                return;
            }

            state.tab = UI.tabFromParams ? UI.tabFromParams(params, visibleTabs().map(x => x.id), fallbackTab()) : fallbackTab();
            state.idempotencyKey = newIdempotencyKey();

            container.innerHTML = buildTemplate();
            bindEvents();

            if (canCreate()) {
                renderReceiveSelect();
                renderReceiveDetail();
                renderReceiveResult();
                renderKeyHint();
            }
            if (canPlan()) renderPlanTable();

            loadCatalogs();
            if (state.tab === 'receive') loadReceiveDocs();
            else if (state.tab === 'plan') loadPlanDocs();
        },

        onActivate() {
            if (!container) return;

            if (!canAny()) {
                container.innerHTML = UI.deniedState({
                    title: t('inventory.receiving.permission.title'),
                    message: t('inventory.receiving.permission.body', { permiso: 'inventory.receiving' })
                });
                return;
            }

            if (!container.querySelector('[data-plan-table], [data-receive-doc-select]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
            }

            if (!state.idempotencyKey) state.idempotencyKey = newIdempotencyKey();

            if (canCreate()) {
                renderReceiveSelect();
                renderReceiveDetail();
                renderReceiveResult();
                renderKeyHint();
            }
            if (canPlan()) { renderPlanTable(); renderPlanPagination(); }

            if (state.tab === 'receive') loadReceiveDocs();
            else if (state.tab === 'plan') loadPlanDocs();
        },

        destroy() {
            if (container) {
                container.removeEventListener('submit', handleSubmit);
                container.removeEventListener('change', handleChange);
                container.removeEventListener('click', handleClick);
                container.innerHTML = '';
            }
            container = null;
            state = {
                tab: 'receive',
                products: [],
                locations: [],
                idempotencyKey: '',
                loadingCatalog: false,
                planDocs: [],
                planPagination: null,
                planLoading: false,
                planError: '',
                planFilters: { estado: '', search: '', page: 1 },
                planEditing: null,
                planSaving: false,
                receiveDocs: [],
                receiveLoading: false,
                receiveError: '',
                receiveSelectedId: '',
                receiveSending: false,
                receiveResult: null
            };
        }
    };

    global.InventoryReceivingModule = InventoryReceivingModule;

})(window);
