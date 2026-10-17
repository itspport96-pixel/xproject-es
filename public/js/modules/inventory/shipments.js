/**
 * Inventory Shipments Module
 * Pantalla "Envíos" (CU-005 carga y CU-006 salida) - v1.7.1.
 *
 * Convención ModuleLoader: window.InventoryShipmentsModule
 *
 * Bloques:
 *  1. Nuevo envío: form (tipo, origen, destino, destinatario, conductor,
 *     vehículo, observaciones) + unidades EN_PREPARACION cuyo picking esté
 *     COMPLETADA o VERIFICADA, tomadas de un lote concreto o de una selección
 *     manual. Se envía con `POST /api/inventory/shipments/load` (header
 *     Idempotency-Key obligatorio, spec R7).
 *  2. Listado (`GET /api/inventory/shipments`) con detalle
 *     (`GET /api/inventory/shipments/{id}`) y "Autorizar salida"
 *     (`POST /api/inventory/shipments/authorize`) tras `confirm()`.
 *
 * Permisos (routes.php):
 *  - inventory.shipping.view    → lectura de listado y detalle
 *  - inventory.shipping.create  → cargar unidades
 *  - inventory.shipping.confirm → autorizar la salida
 *
 * Idempotencia (mismo patrón que stock.js): la clave del formulario se genera
 * al montarlo, se reutiliza en cada reintento del mismo envío y se renueva
 * tras un éxito y en 409; la de autorizar se gestiona por shipment_id.
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    let state = {
        tab: 'load',
        items: [],
        summary: { total: 0, en_preparacion: 0, en_ruta: 0, entregados: 0 },
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        filters: { estado: '' },
        listLoading: false,
        listError: false,
        prepared: [],
        preparedLoading: false,
        lotes: [],
        locations: [],
        mode: 'lote',
        selectedLote: '',
        selected: new Set(),
        shipmentKeys: {},
        loadKey: '',
        detail: null,
        detailItems: [],
        detailLoading: false,
        sending: false
    };

    const ESTADOS_ENVIO = [
        { value: 'PREPARACION', labelKey: 'inventory.shipments.states.preparacion', color: 'warning' },
        { value: 'EN_RUTA', labelKey: 'inventory.shipments.states.en_ruta', color: 'info' },
        { value: 'ENTREGADO', labelKey: 'inventory.shipments.states.entregado', color: 'success' },
        { value: 'FALLO_ENTREGA', labelKey: 'inventory.shipments.states.fallo_entrega', color: 'danger' },
        { value: 'DEVUELTO', labelKey: 'inventory.shipments.states.devuelto', color: 'neutral' }
    ];

    const TIPOS_ENVIO = ['PROPIO', 'VEHICULO', 'MENSAJERIA', 'TERCERO'];

    const TIPO_KEYS = {
        PROPIO: 'inventory.shipments.types.propio',
        VEHICULO: 'inventory.shipments.types.vehiculo',
        MENSAJERIA: 'inventory.shipments.types.mensajeria',
        TERCERO: 'inventory.shipments.types.tercero'
    };

    const tipoLabel = (v) => TIPO_KEYS[v] ? t(TIPO_KEYS[v]) : (v || '—');

    const ITEM_ESTADO_KEYS = {
        PREPARADA: 'inventory.shipments.item_states.preparada',
        CARGADA: 'inventory.shipments.item_states.cargada',
        EN_TRANSITO: 'inventory.shipments.item_states.en_transito',
        ENTREGADA: 'inventory.shipments.item_states.entregada',
        RECHAZADA: 'inventory.shipments.item_states.rechazada',
        DEVUELTA: 'inventory.shipments.item_states.devuelta'
    };

    const itemEstadoLabel = (v) => ITEM_ESTADO_KEYS[v] ? t(ITEM_ESTADO_KEYS[v]) : (v || '—');

    const estadoColor = (v) => {
        const e = ESTADOS_ENVIO.find(x => x.value === v);
        return e ? e.color : 'neutral';
    };

    const estadoLabel = (v) => {
        const e = ESTADOS_ENVIO.find(x => x.value === v);
        return e ? t(e.labelKey) : v;
    };

    const ESTADOS_PICKING_CARGABLES = ['COMPLETADA', 'VERIFICADA'];

    // Permiso real (BD) con fallback a roles cuando aún no hay permisos.
    function can(permission, fallbackRoles) {
        if (global.AuthState && typeof global.AuthState.getPermissions === 'function') {
            const permissions = global.AuthState.getPermissions();
            if (permissions.length > 0) return permissions.includes(permission);
        }
        if (global.AuthState && typeof global.AuthState.getRoles === 'function') {
            const roles = global.AuthState.getRoles();
            if (roles.length > 0) return (fallbackRoles || []).some(r => roles.includes(r));
        }
        return false;
    }

    const canView = () => can('inventory.shipping.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
    const canLoad = () => can('inventory.shipping.create', ['ADMIN', 'MANAGER', 'USER']);
    const canAuthorize = () => can('inventory.shipping.confirm', ['ADMIN', 'MANAGER']);

    // Pestañas de la vista: "Nuevo envío" sólo con inventory.shipping.create
    // (si no queda una sola, no se pinta barra y la vista es monolítica).
    const TABS_SHIPMENTS = ['load', 'list'];

    function buildTemplate() {
        const panels = [];

        if (canLoad()) {
            panels.push({ id: 'load', html: '<div data-load-section></div>' });
        }

        panels.push({
            id: 'list',
            html: `
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4" data-ship-summary></div>

            ${UI.filtersCard(`
                <div class="form-group mb-0">
                    <select class="select" data-filter="estado">
                        <option value="">${t('inventory.shipments.filters.all_states')}</option>
                        ${ESTADOS_ENVIO.map(e => `<option value="${e.value}">${t(e.labelKey)}</option>`).join('')}
                    </select>
                </div>`, {
                cols: 4,
                applyLabel: t('inventory.shipments.filters.apply'),
                clearLabel: t('inventory.shipments.filters.clear'),
                clearTitle: t('inventory.shipments.filters.clear_title')
            })}

            <div class="card p-0">
                <div class="table-wrapper" data-ship-table></div>
                <div data-ship-pagination class="pagination"></div>
            </div>`
        });

        return UI.viewShell({
            title: t('inventory.shipments.title'),
            subtitle: t('inventory.shipments.subtitle'),
            tabs: [
                { id: 'load', label: t('inventory.shipments.load.title'), show: canLoad() },
                { id: 'list', label: t('inventory.shipments.list_title') }
            ],
            activeTab: state.tab,
            panels: panels
        }) + `

        <div data-modal-root></div>
    `;
    }

    const LOAD_TEMPLATE = `
        <div class="card mb-4">
            <form data-load-form novalidate>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="form-group">
                        <label class="form-label">${t('inventory.shipments.labels.tipo')} <span class="required">*</span></label>
                        <select class="select" name="tipo" required>
                            ${TIPOS_ENVIO.map(tipo => `<option value="${tipo}" ${tipo === 'PROPIO' ? 'selected' : ''}>${tipoLabel(tipo)}</option>`).join('')}
                        </select>
                        <span class="form-error hidden" data-error="tipo"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">${t('inventory.shipments.labels.origen')}</label>
                        <select class="select" name="origen_ubicacion_id">
                            <option value="">${t('inventory.shipments.load.selects.sin_origen')}</option>
                        </select>
                        <span class="form-error hidden" data-error="origen_ubicacion_id"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">${t('inventory.shipments.labels.referencia_externa')}</label>
                        <input type="text" class="input" name="referencia_externa" maxlength="255"
                               placeholder="${t('inventory.shipments.load.placeholders.opcional')}" autocomplete="off">
                        <span class="form-error hidden" data-error="referencia_externa"></span>
                    </div>

                    <div class="form-group md:col-span-3">
                        <label class="form-label">${t('inventory.shipments.labels.destino_direccion')}</label>
                        <input type="text" class="input" name="destino_direccion" maxlength="2000"
                               placeholder="${t('inventory.shipments.load.placeholders.destino_direccion')}" autocomplete="off">
                        <span class="form-error hidden" data-error="destino_direccion"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label">${t('inventory.shipments.labels.destinatario')} <span class="required">*</span></label>
                        <input type="text" class="input" name="destinatario_nombre" maxlength="255"
                               placeholder="${t('inventory.shipments.load.placeholders.destinatario_nombre')}" autocomplete="off">
                        <span class="form-hint">${t('inventory.shipments.load.hints.destinatario')}</span>
                        <span class="form-error hidden" data-error="destinatario_nombre"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">${t('inventory.shipments.labels.destinatario_contacto')}</label>
                        <input type="text" class="input" name="destinatario_contacto" maxlength="255"
                               placeholder="${t('inventory.shipments.load.placeholders.contacto')}" autocomplete="off">
                        <span class="form-error hidden" data-error="destinatario_contacto"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">${t('inventory.shipments.labels.entrega_esperada')}</label>
                        <input type="datetime-local" class="input" name="hora_entrega_esperada">
                        <span class="form-error hidden" data-error="hora_entrega_esperada"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label">${t('inventory.shipments.labels.conductor')}</label>
                        <input type="text" class="input" name="conductor_nombre" maxlength="255"
                               placeholder="${t('inventory.shipments.load.placeholders.conductor_nombre')}" autocomplete="off">
                        <span class="form-error hidden" data-error="conductor_nombre"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">${t('inventory.shipments.labels.conductor_contacto')}</label>
                        <input type="text" class="input" name="conductor_contacto" maxlength="255"
                               placeholder="${t('inventory.shipments.load.placeholders.contacto')}" autocomplete="off">
                        <span class="form-error hidden" data-error="conductor_contacto"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">${t('inventory.shipments.labels.vehiculo')}</label>
                        <input type="text" class="input" name="vehiculo_referencia" maxlength="100"
                               placeholder="${t('inventory.shipments.load.placeholders.vehiculo_referencia')}" autocomplete="off">
                        <span class="form-error hidden" data-error="vehiculo_referencia"></span>
                    </div>

                    <div class="form-group md:col-span-3">
                        <label class="form-label">${t('inventory.shipments.labels.observaciones')}</label>
                        <textarea class="input" name="observaciones" rows="2" maxlength="2000"
                                  placeholder="${t('inventory.shipments.load.placeholders.opcional')}"></textarea>
                        <span class="form-error hidden" data-error="observaciones"></span>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <div class="font-medium">${t('inventory.shipments.load.units_title')}</div>
                        <div class="flex gap-2">
                            <button type="button" class="btn btn-ghost btn-sm"
                                    data-action="mode-lote" data-mode="lote">${t('inventory.shipments.load.modes.lote')}</button>
                            <button type="button" class="btn btn-ghost btn-sm"
                                    data-action="mode-unidades" data-mode="unidades">${t('inventory.shipments.load.modes.unidades')}</button>
                        </div>
                    </div>

                    <div data-mode-panel></div>

                        <div class="flex items-center justify-between mt-3">
                            <div class="text-sm text-muted">
                                <span data-selection-count>0</span> ${t('inventory.shipments.load.selection_count')}
                                <span class="form-error hidden" data-error="unidades"></span>
                            </div>
                        <div class="flex gap-2">
                            <button type="button" class="btn btn-ghost btn-sm" data-action="select-all">${t('inventory.shipments.load.select_all')}</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-action="clear-selection">${t('inventory.shipments.load.clear_selection')}</button>
                        </div>
                    </div>
                </div>

                <div data-form-error class="alert alert-danger hidden mt-4"></div>

                ${UI.formFooter({
                    submitLabel: t('inventory.shipments.load.submit'),
                    submitAction: 'submit-load'
                })}
            </form>
        </div>
    `;

    // ====================================================================
    // PESTAÑAS
    // ====================================================================

    /** Aplica la pestaña al DOM, a state y a la URL (sin recargar datos). */
    function applyTab(tab) {
        state.tab = tab;
        UI.applyTabState(container, tab);
        UI.syncTabParam(tab);
    }

    /** Cambio de pestaña: aplica y refresca los datos del panel destino. */
    function switchTab(tab) {
        if (TABS_SHIPMENTS.indexOf(tab) === -1) return;
        if (tab === 'load' && !canLoad()) return;

        applyTab(tab);
        if (tab === 'load') loadCatalogs();
        else loadShipments(state.pagination.page || 1);
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
        const scope = container && container.querySelector('[data-load-section]');
        const el = scope && scope.querySelector('[data-key-hint]');
        if (!el) return;
        el.innerHTML = `Idempotency-Key: <span class="font-mono">${escapeHtml(state.loadKey)}</span>`;
    }

    function authorizeKey(shipmentId) {
        if (!state.shipmentKeys[shipmentId]) state.shipmentKeys[shipmentId] = newIdempotencyKey();
        return state.shipmentKeys[shipmentId];
    }

    // ====================================================================
    // RENDER - SECCIÓN DE CARGA
    // ====================================================================

    function renderLoadSection() {
        const root = container.querySelector('[data-load-section]');
        if (!root) return;

        if (!canLoad()) {
            root.innerHTML = '';
            return;
        }

        root.innerHTML = LOAD_TEMPLATE;
        renderOriginOptions();
        renderModePanel();
        renderKeyHint();
    }

    function renderOriginOptions() {
        const select = container.querySelector('select[name="origen_ubicacion_id"]');
        if (!select) return;

        const opciones = state.locations.filter(l => l.tipo !== 'TRANSITO');
        select.innerHTML = '<option value="">' + t('inventory.shipments.load.selects.sin_origen') + '</option>' +
            opciones.map(l => `<option value="${escapeHtml(l.id)}">${escapeHtml(l.codigo)}</option>`).join('');
    }

    function renderModePanel() {
        const root = container && container.querySelector('[data-mode-panel]');
        if (!root) return;

        container.querySelectorAll('[data-action="mode-lote"], [data-action="mode-unidades"]').forEach(btn => {
            btn.classList.toggle('btn-primary', btn.dataset.mode === state.mode);
            btn.classList.toggle('btn-ghost', btn.dataset.mode !== state.mode);
        });

        if (state.preparedLoading) {
            root.innerHTML = UI.loadingState();
            return;
        }

        if (state.mode === 'lote') {
            root.innerHTML = renderLotePanel();
            return;
        }

        root.innerHTML = renderUnitsPanel();
    }

    function renderLotePanel() {
        if (state.lotes.length === 0) {
            return UI.emptyState({
                title: t('inventory.shipments.load.lote.empty_title'),
                message: t('inventory.shipments.load.lote.empty_message')
            });
        }

        const opciones = state.lotes.map(l => {
            const listas = l.unidades.filter(id => preparedSet().has(id)).length;
            return `<option value="${escapeHtml(l.lote)}" ${l.lote === state.selectedLote ? 'selected' : ''}>
                ${escapeHtml(l.lote)} · ${escapeHtml(l.estado)} ${t('inventory.shipments.load.lote.ready_count', { listas: listas, total: l.unidades.length })}
            </option>`;
        }).join('');

        const lote = state.lotes.find(l => l.lote === state.selectedLote) || null;
        const unidades = lote ? lote.unidades.filter(id => preparedSet().has(id)) : [];

        const rows = unidades.map(id => {
            const unit = preparedMap().get(id);
            if (!unit) return '';
            return `
                <tr>
                    <td>
                        <input type="checkbox" data-action="toggle-select" data-id="${escapeHtml(id)}"
                               ${state.selected.has(id) ? 'checked' : ''}>
                    </td>
                    <td class="font-mono text-xs">${escapeHtml(unit.codigo_barcode)}</td>
                    <td>
                        <div class="font-medium">${escapeHtml(unit.producto_nombre)}</div>
                        <div class="text-xs text-muted font-mono">${escapeHtml(unit.ubicacion_codigo || '')}</div>
                    </td>
                    <td class="text-right font-medium">${formatNumber(unit.cantidad_fisica)}</td>
                </tr>
            `;
        }).join('');

        return `
            <div class="form-group">
                <label class="form-label">${t('inventory.shipments.load.lote.label')} <span class="required">*</span></label>
                <select class="select" data-lote-select>
                    <option value="">${t('inventory.shipments.load.lote.select')}</option>
                    ${opciones}
                </select>
                <span class="form-hint">${t('inventory.shipments.load.lote.hint')}</span>
                <span class="form-error hidden" data-error="lote"></span>
            </div>
            <div class="card p-0 mt-3 table-wrapper" data-lote-units>
                ${state.selectedLote === ''
                    ? `<div class="empty-state"><p class="text-muted">${t('inventory.shipments.load.lote.empty_selection')}</p></div>`
                    : (rows || `<div class="empty-state"><p class="text-muted">${t('inventory.shipments.load.lote.no_units')}</p></div>`)}
            </div>
        `;
    }

    function renderUnitsPanel() {
        if (state.prepared.length === 0) {
            return UI.emptyState({
                title: t('inventory.shipments.load.units.empty_title'),
                message: t('inventory.shipments.load.units.empty_message')
            });
        }

        const rows = state.prepared.map(u => `
            <tr>
                <td>
                    <input type="checkbox" data-action="toggle-select" data-id="${escapeHtml(u.unidad_fisica_id)}"
                           ${state.selected.has(u.unidad_fisica_id) ? 'checked' : ''}>
                </td>
                <td class="font-mono text-xs">${escapeHtml(u.codigo_barcode)}</td>
                <td>
                    <div class="font-medium">${escapeHtml(u.producto_nombre)}</div>
                    <div class="text-xs text-muted font-mono">${escapeHtml(u.sku || '')}</div>
                </td>
                <td class="font-mono text-xs">${escapeHtml(u.ubicacion_codigo || '—')}</td>
                <td class="text-right font-medium">${formatNumber(u.cantidad_fisica)}</td>
            </tr>
        `).join('');

        return `
            <div class="card p-0 table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:36px"></th>
                            <th>${t('inventory.shipments.columns.barcode')}</th>
                            <th>${t('inventory.shipments.columns.producto')}</th>
                            <th>${t('inventory.shipments.columns.zona')}</th>
                            <th class="text-right">${t('inventory.shipments.columns.cantidad')}</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    }

    function renderSelectionCount() {
        const el = container && container.querySelector('[data-selection-count]');
        if (el) el.textContent = String(state.selected.size);
    }

    function preparedSet() {
        return new Set(state.prepared.map(u => u.unidad_fisica_id));
    }

    function preparedMap() {
        const map = new Map();
        state.prepared.forEach(u => map.set(u.unidad_fisica_id, u));
        return map;
    }

    // ====================================================================
    // RENDER - LISTADO
    // ====================================================================

    function renderSummary() {
        const root = container.querySelector('[data-ship-summary]');
        if (!root) return;

        const s = state.summary;
        UI.summaryCards(root, [
            { label: t('inventory.shipments.summary.envios'), value: s.total },
            { label: t('inventory.shipments.summary.en_preparacion'), value: s.en_preparacion },
            { label: t('inventory.shipments.summary.en_ruta'), value: s.en_ruta },
            { label: t('inventory.shipments.summary.entregados'), value: s.entregados }
        ]);
    }

    function renderTable() {
        const root = container.querySelector('[data-ship-table]');
        if (!root) return;

        if (state.listLoading) {
            root.innerHTML = UI.tableSkeleton([
                { label: t('inventory.shipments.columns.envio') },
                { label: t('inventory.shipments.columns.destinatario') },
                { label: t('inventory.shipments.columns.tipo') },
                { label: t('inventory.shipments.columns.conductor') },
                { label: t('inventory.shipments.columns.items'), className: 'text-right' },
                { label: t('inventory.shipments.columns.estado') },
                { label: t('inventory.shipments.columns.creado') },
                { label: t('inventory.shipments.columns.acciones'), className: 'col-actions' }
            ], 6, [12, 22, 10, 14, 8, 10, 12, 12]);
            return;
        }

        if (state.listError) {
            root.innerHTML = UI.errorState({
                message: t('inventory.shipments.messages.list_load_error'),
                retryAction: 'retry-ship-list',
                retryLabel: t('ui.retry')
            });
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('inventory.shipments.empty.title'),
                message: t('inventory.shipments.empty.message')
            });
            return;
        }

        const rows = state.items.map(s => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(s.shipment_id)}</td>
                <td>
                    <div class="font-medium">${escapeHtml(s.destinatario_nombre || '—')}</div>
                    <div class="text-xs text-muted">${escapeHtml(s.destino_direccion || s.ubicacion_origen || '—')}</div>
                </td>
                <td>${escapeHtml(tipoLabel(s.tipo))}</td>
                <td class="text-xs">${escapeHtml(s.conductor_nombre || '—')}</td>
                <td class="text-right">${formatNumber(s.total_items)}</td>
                <td><span class="badge badge-${estadoColor(s.estado)}">${escapeHtml(estadoLabel(s.estado))}</span></td>
                <td class="text-xs">${escapeHtml(UI.formatDateTime(s.fecha_creacion))}</td>
                <td class="table-actions col-actions">${renderRowActions(s)}</td>
            </tr>
        `).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('inventory.shipments.columns.envio')}</th>
                        <th>${t('inventory.shipments.columns.destinatario')}</th>
                        <th>${t('inventory.shipments.columns.tipo')}</th>
                        <th>${t('inventory.shipments.columns.conductor')}</th>
                        <th class="text-right">${t('inventory.shipments.columns.items')}</th>
                        <th>${t('inventory.shipments.columns.estado')}</th>
                        <th>${t('inventory.shipments.columns.creado')}</th>
                        <th class="col-actions">${t('inventory.shipments.columns.acciones')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function renderRowActions(shipment) {
        return UI.rowActions({
            primary: {
                action: 'open-detail',
                id: shipment.shipment_id,
                label: t('inventory.shipments.actions.detail')
            },
            groups: [
                [canAuthorize() && shipment.estado === 'PREPARACION'
                    ? { action: 'authorize', id: shipment.shipment_id, label: t('inventory.shipments.actions.authorize') }
                    : null]
            ],
            ariaLabel: t('inventory.shipments.columns.acciones')
        });
    }

    function renderPagination() {
        const root = container.querySelector('[data-ship-pagination]');
        if (!root) return;

        UI.renderPagination(root, {
            pagination: state.pagination,
            shown: state.items.length,
            label: t('inventory.shipments.pagination_label')
        });
    }

    function renderAll() {
        renderSummary();
        renderTable();
        renderPagination();
    }

    // ====================================================================
    // MODAL DE DETALLE
    // ====================================================================

    function openDetail(shipmentId) {
        state.detail = null;
        state.detailItems = [];
        state.detailLoading = true;
        renderDetail();

        global.HttpClient.get(`/inventory/shipments/${encodeURIComponent(shipmentId)}`)
            .then(response => {
                const payload = response.data || response;
                state.detail = payload.shipment || null;
                state.detailItems = payload.items || [];
                state.detailLoading = false;
                renderDetail();
            })
            .catch(err => {
                state.detailLoading = false;
                state.detail = null;
                renderDetail();
                handleActionError(err, t('inventory.shipments.messages.detail_load_error'));
                if (err && err.status === 404) closeDetail();
            });
    }

    function renderDetail() {
        const root = container && container.querySelector('[data-modal-root]');
        if (!root) return;

        if (state.detailLoading) {
            root.innerHTML = `
                <div class="modal-overlay" data-modal>
                    <div class="modal">
                        <div class="modal-body">
                            ${UI.loadingState()}
                        </div>
                    </div>
                </div>`;
            return;
        }

        const s = state.detail;
        if (!s) {
            root.innerHTML = '';
            return;
        }

        const filas = state.detailItems.map(i => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(i.barcode)}</td>
                <td>${escapeHtml(i.producto)}</td>
                <td class="text-right font-medium">${formatNumber(i.cantidad)}</td>
                <td><span class="badge badge-${estadoColor(i.estado)}">${escapeHtml(itemEstadoLabel(i.estado))}</span></td>
            </tr>
        `).join('');

        const puedeAutorizar = canAuthorize() && s.estado === 'PREPARACION';

        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('inventory.shipments.detail.title', { id: escapeHtml(s.shipment_id) })}</h3>
                        <button class="modal-close" data-action="close-modal" aria-label="${t('ui.close')}">×</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">${t('inventory.shipments.labels.estado')}</label>
                                <span class="badge badge-${estadoColor(s.estado)}">${escapeHtml(estadoLabel(s.estado))}</span>
                            </div>
                            <div class="form-group">
                                <label class="form-label">${t('inventory.shipments.labels.tipo')}</label>
                                <div class="font-medium">${escapeHtml(tipoLabel(s.tipo))}</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">${t('inventory.shipments.labels.destinatario')}</label>
                                <div class="font-medium">${escapeHtml(s.destinatario_nombre || '—')}</div>
                                <div class="text-xs text-muted">${escapeHtml(s.destinatario_contacto || '')}</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">${t('inventory.shipments.labels.conductor')}</label>
                                <div class="font-medium">${escapeHtml(s.conductor_nombre || '—')}</div>
                                <div class="text-xs text-muted">${escapeHtml(s.conductor_contacto || '')}</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">${t('inventory.shipments.labels.vehiculo')}</label>
                                <div>${escapeHtml(s.vehiculo_referencia || '—')}</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">${t('inventory.shipments.labels.destino')}</label>
                                <div>${escapeHtml(s.destino_direccion || s.ubicacion_origen || '—')}</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">${t('inventory.shipments.labels.salida')}</label>
                                <div>${escapeHtml(s.hora_salida || '—')}</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">${t('inventory.shipments.labels.entrega_esperada')}</label>
                                <div>${escapeHtml(s.hora_entrega_esperada || '—')}</div>
                            </div>
                            ${s.observaciones ? `
                            <div class="form-group span-all">
                                <label class="form-label">${t('inventory.shipments.labels.observaciones')}</label>
                                <div class="text-sm">${escapeHtml(s.observaciones)}</div>
                            </div>` : ''}
                        </div>

                        <div class="mt-4">
                            <div class="font-medium mb-2">${t('inventory.shipments.detail.items_count', { count: state.detailItems.length })}</div>
                            ${state.detailItems.length === 0
                                ? `<p class="text-muted text-sm">${t('inventory.shipments.detail.items_empty')}</p>`
                                : `<table class="table">
                                    <thead>
                                        <tr>
                                            <th>${t('inventory.shipments.columns.barcode')}</th>
                                            <th>${t('inventory.shipments.columns.producto')}</th>
                                            <th class="text-right">${t('inventory.shipments.columns.cantidad')}</th>
                                            <th>${t('inventory.shipments.columns.estado')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>${filas}</tbody>
                                </table>`}
                        </div>

                        <div data-form-error class="alert alert-danger hidden mt-4"></div>
                    </div>
                    <div class="modal-footer">
                        <div class="text-xs text-muted mr-auto" data-detail-key></div>
                        <button type="button" class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                        ${puedeAutorizar
                            ? `<button type="button" class="btn btn-primary" data-action="authorize"
                                  data-id="${escapeHtml(s.shipment_id)}">${t('inventory.shipments.actions.authorize')}</button>`
                            : ''}
                    </div>
                </div>
            </div>
        `;
    }

    function closeDetail() {
        const root = container && container.querySelector('[data-modal-root]');
        if (root) root.innerHTML = '';
        state.detail = null;
        state.detailItems = [];
        state.detailLoading = false;
    }

    // ====================================================================
    // API - LISTADO Y CATÁLOGOS
    // ====================================================================

    async function loadCatalogs() {
        state.preparedLoading = true;
        renderModePanel();

        try {
            const [locationsRes, stockRes, pickingRes] = await Promise.all([
                global.HttpClient.get('/inventory/locations', { params: { activa: '1', page: 1, per_page: 100 } })
                    .catch(err => { reportCatalogError(err, 'locations'); return null; }),
                global.HttpClient.get('/inventory/stock', { params: { estado: 'EN_PREPARACION', page: 1, per_page: 100 } })
                    .catch(err => { reportCatalogError(err, 'stock'); return null; }),
                global.HttpClient.get('/inventory/picking', { params: { page: 1, per_page: 100 } })
                    .catch(err => { reportCatalogError(err, 'picking'); return null; })
            ]);

            const locationsPayload = locationsRes && (locationsRes.data || locationsRes);
            state.locations = (locationsPayload && locationsPayload.data) || [];

            const stockPayload = stockRes && (stockRes.data || stockRes);
            state.prepared = (stockPayload && stockPayload.data) || [];

            const pickingPayload = pickingRes && (pickingRes.data || pickingRes);
            state.lotes = groupCargables((pickingPayload && pickingPayload.data) || []);

            if (state.selectedLote && !state.lotes.some(l => l.lote === state.selectedLote)) {
                state.selectedLote = '';
            }

            syncSelection();
            renderOriginOptions();
            renderModePanel();
            renderSelectionCount();
            return locationsRes !== null && stockRes !== null && pickingRes !== null;
        } finally {
            state.preparedLoading = false;
            renderModePanel();
            renderSelectionCount();
        }
    }

    function reportCatalogError(err, origen) {
        console.error(`[Shipments] catalog load error (${origen}):`, err);
        if (err && err.status === 403) {
            showToast('warning', t('inventory.shipments.permission.title'),
                t('inventory.shipments.messages.catalog_forbidden'));
        } else if (err && err.status !== 404) {
            showToast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.shipments.messages.catalog_load_error'));
        }
    }

    // Lotes cuyo picking ya está COMPLETADA o VERIFICADA (spec CU-005).
    function groupCargables(rows) {
        const orden = new Map();

        rows.forEach(r => {
            const lote = r.id_lote_picking;
            if (!orden.has(lote)) {
                orden.set(lote, { lote, estado: r.estado, unidades: [] });
            }
            const grupo = orden.get(lote);
            if (grupo.unidades.indexOf(r.stock_unit_id) === -1) {
                grupo.unidades.push(r.stock_unit_id);
            }
        });

        return Array.from(orden.values()).filter(g => ESTADOS_PICKING_CARGABLES.includes(g.estado));
    }

    function syncSelection() {
        const vivas = preparedSet();
        state.selected.forEach(id => {
            if (!vivas.has(id)) state.selected.delete(id);
        });

        if (state.mode === 'lote') {
            const lote = state.lotes.find(l => l.lote === state.selectedLote);
            if (lote) {
                const validas = new Set(lote.unidades);
                state.selected.forEach(id => {
                    if (!validas.has(id)) state.selected.delete(id);
                });
            }
        }
    }

    async function loadShipments(page = 1) {
        state.listLoading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            if (state.filters.estado) params.estado = state.filters.estado;

            const response = await global.HttpClient.get('/inventory/shipments', { params });
            const payload = response.data || response;

            state.items = payload.data || [];
            state.summary = payload.summary || state.summary;
            state.pagination = payload.pagination || state.pagination;
            state.pagination.page = page;
            state.listError = false;
            return true;
        } catch (err) {
            console.error('[Shipments] load error:', err);
            state.items = [];
            state.listError = true;
            if (err && err.status === 403) {
                showToast('danger', t('inventory.shipments.permission.title'), t('inventory.shipments.messages.list_forbidden'));
            } else {
                showToast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.shipments.messages.list_load_error'));
            }
            return false;
        } finally {
            state.listLoading = false;
            renderAll();
        }
    }

    // ====================================================================
    // API - CARGA
    // ====================================================================

    function readLoadForm(form) {
        const raw = Object.fromEntries(new FormData(form));
        const shipment = {};

        const textos = [
            'tipo', 'referencia_externa', 'origen_ubicacion_id', 'destinatario_nombre',
            'destinatario_contacto', 'conductor_nombre', 'conductor_contacto',
            'vehiculo_referencia', 'destino_direccion', 'observaciones'
        ];

        textos.forEach(campo => {
            const valor = (raw[campo] || '').trim();
            if (valor !== '') shipment[campo] = valor;
        });

        // LoadShipmentUseCase normaliza hora_entrega_esperada a Y-m-d H:i:s
        // interpretando el string como UTC (createFromFormat bajo
        // date.timezone=UTC): el valor del datetime-local llega en hora local,
        // asi que se convierte el instante antes de enviar (v1.17.8).
        const entrega = (raw.hora_entrega_esperada || '').trim();
        if (entrega !== '') shipment.hora_entrega_esperada = UI.datetimeLocalToUtc(entrega);

        return {
            shipment,
            unidades: Array.from(state.selected).map(id => ({ unidad_fisica_id: id }))
        };
    }

    function validateLoad(payload) {
        if (!payload.shipment.tipo) {
            return { field: 'tipo', message: t('inventory.shipments.messages.error_tipo_required') };
        }
        if (!payload.shipment.destinatario_nombre) {
            return { field: 'destinatario_nombre', message: t('inventory.shipments.messages.error_destinatario_required') };
        }
        if (payload.unidades.length === 0) {
            return { field: 'unidades', message: t('inventory.shipments.messages.error_unidades_required') };
        }
        return null;
    }

    async function submitLoad(form) {
        if (state.sending) return;

        clearErrors();

        const payload = readLoadForm(form);
        const invalid = validateLoad(payload);
        if (invalid) {
            showFieldError(invalid.field, invalid.message);
            return;
        }

        setSending(true);

        try {
            const response = await global.HttpClient.post('/inventory/shipments/load', payload, {
                headers: { 'Idempotency-Key': state.loadKey }
            });
            const body = response.data || response;
            const shipment = (body && body.shipment) || {};
            const id = shipment.shipment_id || shipment.id || '';
            const unidades = (body && body.unidades && body.unidades.length) || payload.unidades.length;

            state.loadKey = newIdempotencyKey();
            state.selected.clear();

            const mensaje = t('inventory.shipments.messages.loaded_message', {
                unidades: unidades,
                id: id || t('inventory.shipments.messages.loaded_fallback_id')
            });

            renderKeyHint();
            renderSelectionCount();
            renderModePanel();

            // El env��o ya existe: la pesta��a de listado gana el foco y la
            // recarga de ambos paneles ocurre en el Promise.all de abajo.
            applyTab('list');
            const [recargadoLista, recargadoCatalogos] = await Promise.all([loadShipments(1), loadCatalogs()]);
            if (recargadoLista && recargadoCatalogos) {
                showToast('success', t('inventory.shipments.messages.loaded_title'), mensaje);
            }
        } catch (err) {
            handleActionError(err, t('inventory.shipments.messages.load_error'), () => {
                state.loadKey = newIdempotencyKey();
                renderKeyHint();
            }, true);
        } finally {
            setSending(false);
        }
    }

    async function authorizeShipment(shipmentId) {
        const confirmado = global.confirm(
            t('inventory.shipments.messages.authorize_confirm', { id: shipmentId })
        );
        if (!confirmado) return;

        try {
            const response = await global.HttpClient.post('/inventory/shipments/authorize', {
                shipment_id: shipmentId
            }, {
                headers: { 'Idempotency-Key': authorizeKey(shipmentId) }
            });

            const body = response.data || response;
            const transito = (body && body.ubicacion_transito && body.ubicacion_transito.codigo) || '';
            delete state.shipmentKeys[shipmentId];

            const mensaje = transito
                ? t('inventory.shipments.messages.authorized_transit', { codigo: transito })
                : t('inventory.shipments.messages.authorized_en_ruta');

            closeDetail();
            const [recargadoLista, recargadoCatalogos] = await Promise.all([
                loadShipments(state.pagination.page || 1), loadCatalogs()
            ]);
            if (recargadoLista && recargadoCatalogos) {
                showToast('success', t('inventory.shipments.messages.authorized_title'), mensaje);
            }
        } catch (err) {
            handleActionError(err, t('inventory.shipments.messages.authorize_error'), () => {
                state.shipmentKeys[shipmentId] = newIdempotencyKey();
            }, state.detail !== null);
        }
    }

    // 400/401/403/404/409/422 → toast (y error de campo cuando trae `field`).
    function handleActionError(err, fallbackMessage, onConflict, inline) {
        const status = err.status;
        const field = err.data && err.data.field ? err.data.field : null;
        const message = err.message || fallbackMessage;

        console.error('[Shipments] action error:', status, message);

        if (inline) {
            showFormError(message);
            if (field) showFieldError(field, message);
        }

        switch (status) {
            case 400:
                showToast('danger', t('ui.toast_invalid'), message);
                break;
            case 401:
                showToast('warning', t('ui.toast_session'), message);
                break;
            case 403:
                showToast('warning', t('ui.toast_forbidden'), message);
                break;
            case 404:
                showToast('danger', t('ui.toast_not_found'), message);
                loadShipments(state.pagination.page || 1);
                break;
            case 409:
                if (typeof onConflict === 'function') onConflict();
                showToast('warning', t('ui.toast_conflict'), message);
                loadShipments(state.pagination.page || 1);
                break;
            case 422:
                showToast('danger', t('ui.toast_rejected'), message);
                break;
            default:
                showToast('danger', t('ui.error_generic'), message);
        }
    }

    // ====================================================================
    // FORM HELPERS
    // ====================================================================

    function showFormError(message) {
        // Si el modal de detalle está abierto, el aviso va ahí (los errores
        // en línea sólo se muestran en un formulario visible).
        const scope = (container && container.querySelector('[data-modal-root] [data-form-error]'))
            || (container && container.querySelector('[data-form-error]'));
        const el = scope;
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
        el.textContent = message;
        el.classList.remove('hidden');
        el.scrollIntoView({ block: 'nearest' });
    }

    function clearErrors() {
        if (!container) return;
        container.querySelectorAll('[data-error]').forEach(el => {
            el.classList.add('hidden');
            el.textContent = '';
        });
        showFormError('');
    }

    function setSending(sending) {
        state.sending = sending;
        const btn = container && container.querySelector('[data-action="submit-load"]');
        if (!btn) return;
        btn.disabled = sending;
        btn.textContent = sending ? t('inventory.shipments.load.sending') : t('inventory.shipments.load.submit');
    }

    // ====================================================================
    // SELECCIÓN
    // ====================================================================

    function toggleSelection(id, checked) {
        if (checked) state.selected.add(id);
        else state.selected.delete(id);
        renderSelectionCount();
    }

    function selectAll() {
        if (state.mode === 'lote') {
            const lote = state.lotes.find(l => l.lote === state.selectedLote);
            if (lote) {
                const vivas = preparedSet();
                lote.unidades.forEach(id => {
                    if (vivas.has(id)) state.selected.add(id);
                });
            }
        } else {
            state.prepared.forEach(u => state.selected.add(u.unidad_fisica_id));
        }
        renderModePanel();
        renderSelectionCount();
    }

    function clearSelection() {
        state.selected.clear();
        renderModePanel();
        renderSelectionCount();
    }

    function setMode(mode) {
        if (state.mode === mode) return;
        state.mode = mode;
        state.selected.clear();
        renderModePanel();
        renderSelectionCount();
    }

    function setLote(lote) {
        state.selectedLote = lote;
        state.selected.clear();
        renderModePanel();
        renderSelectionCount();
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
        state.filters = { estado: '' };
        container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
        loadShipments(1);
    }

    // ====================================================================
    // EVENTS
    // ====================================================================

    function bindEvents() {
        if (!container) return;

        container.removeEventListener('click', handleClick);
        container.removeEventListener('submit', handleSubmit);
        container.removeEventListener('change', handleChange);

        container.addEventListener('click', handleClick);
        container.addEventListener('submit', handleSubmit);
        container.addEventListener('change', handleChange);
    }

    function handleClick(e) {
        const btn = e.target.closest('[data-action], [data-page]');
        if (!btn) return;

        const action = btn.dataset.action;
        const id = btn.dataset.id;
        const page = btn.dataset.page;

        switch (action) {
            case 'tab':
                switchTab(btn.dataset.tab);
                break;
            case 'retry-ship-list':
                loadShipments(state.pagination.page || 1);
                break;
            case 'apply-filters':
                readFilters();
                loadShipments(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
            case 'mode-lote':
            case 'mode-unidades':
                setMode(btn.dataset.mode);
                break;
            case 'select-all':
                selectAll();
                break;
            case 'clear-selection':
                clearSelection();
                break;
            case 'open-detail':
                openDetail(id);
                break;
            case 'close-modal':
                closeDetail();
                break;
            case 'authorize':
                authorizeShipment(id);
                break;
        }

        if (page !== undefined && page !== null && page !== '') {
            loadShipments(parseInt(page, 10));
        }
    }

    function handleChange(e) {
        const el = e.target;

        if (el.matches('[data-action="toggle-select"]')) {
            toggleSelection(el.dataset.id, el.checked);
            return;
        }
        if (el.matches('[data-lote-select]')) {
            setLote(el.value);
        }
    }

    function handleSubmit(e) {
        if (e.target.matches('[data-load-form]')) {
            e.preventDefault();
            submitLoad(e.target);
        }
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    function formatNumber(value) {
        const n = parseFloat(value);
        if (!isFinite(n)) return '0';
        return Number.isInteger(n) ? String(n) : n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showToast(type, title, message = '') {
        if (global.EventBus) {
            global.EventBus.emit('toast:show', { type, title, message, duration: 3500 });
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    function renderPermissionDenied() {
        container.innerHTML = UI.deniedState({
            title: t('inventory.shipments.permission.title'),
            message: t('inventory.shipments.permission.body', { permiso: 'inventory.shipping.view' }),
            ctaLabel: t('inventory.shipments.permission.dashboard')
        });
    }

    const InventoryShipmentsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            state.tab = UI.tabFromParams(params, TABS_SHIPMENTS, canLoad() ? 'load' : 'list');
            container.innerHTML = buildTemplate();
            bindEvents();
            state.loadKey = newIdempotencyKey();

            renderLoadSection();
            renderAll();

            if (canLoad()) loadCatalogs();
            loadShipments(1);
        },

        onActivate(params) {
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            if (!container.querySelector('[data-ship-table]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
                state.loadKey = newIdempotencyKey();
                renderLoadSection();
            }

            const tab = UI.tabFromParams(params, TABS_SHIPMENTS, state.tab);
            if (tab !== state.tab) applyTab(tab);

            if (canLoad()) loadCatalogs();
            loadShipments(state.pagination.page || 1);
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('submit', handleSubmit);
                container.removeEventListener('change', handleChange);
                container.innerHTML = '';
            }
            container = null;
            state = {
                tab: 'load',
                items: [],
                summary: { total: 0, en_preparacion: 0, en_ruta: 0, entregados: 0 },
                pagination: { total: 0, page: 1, per_page: 20, has_next: false },
                filters: { estado: '' },
                listLoading: false,
                listError: false,
                prepared: [],
                preparedLoading: false,
                lotes: [],
                locations: [],
                mode: 'lote',
                selectedLote: '',
                selected: new Set(),
                shipmentKeys: {},
                loadKey: '',
                detail: null,
                detailItems: [],
                detailLoading: false,
                sending: false
            };
        }
    };

    global.InventoryShipmentsModule = InventoryShipmentsModule;

})(window);
