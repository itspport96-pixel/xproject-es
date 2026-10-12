/**
 * Inventory Deliveries Module
 * Pantalla "Entregas" (CU-007 confirmar entrega / CU-008 registrar fallo) - v1.8.4.
 *
 * Convención ModuleLoader: window.InventoryDeliveriesModule
 *
 * Bloques:
 *  1. Envíos EN_RUTA (`GET /api/inventory/shipments?estado=EN_RUTA`) con las
 *     acciones "Confirmar entrega" y "Registrar fallo" (sólo con
 *     inventory.delivery.confirm).
 *  2. Confirmar entrega: modal con las unidades del envío
 *     (`GET /api/inventory/shipments/{id}`); por unidad un check de
 *     entregada, `firma_comprobante` (URL http/https), `observaciones` y
 *     `destinatario_real` opcional. `POST /api/inventory/deliveries/confirm`
 *     con Idempotency-Key (crypto.randomUUID al abrir el modal, reutilizada en
 *     reintentos, renovada tras éxito o 409). Avisa que las unidades no
 *     marcadas se procesan como fallo de entrega (`motivo_fallo`).
 *  3. Registrar fallo: modal con motivo (NO_DISPONIBLE, RECHAZO,
 *     ACCESO_DENEGADO, OTRO), observaciones y unidades afectadas.
 *     `POST /api/inventory/deliveries/failed` con Idempotency-Key.
 *  4. Historial: `GET /api/inventory/deliveries` con filtros por estado, fecha
 *     y envío, y paginación.
 *
 * Permisos (routes.php):
 *  - inventory.delivery.view    → listado, historial y modales
 *  - inventory.delivery.confirm → confirmar entrega y registrar fallo
 *
 * Hueco declarado: la tabla de envíos EN_RUTA se lee con
 * `GET /api/inventory/shipments`, que exige `inventory.shipping.view`; no
 * existe un endpoint equivalente que dependa sólo de `inventory.delivery.view`,
 * así que sin ese permiso la tabla se muestra vacía con un aviso.
 *
 * Idempotencia (mismo patrón que shipments.js): la clave se genera al abrir el
 * modal, se reutiliza en cada reintento del mismo envío y se renueva tras un
 * éxito y en un 409.
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    let state = {
        tab: 'en_ruta',
        items: [],
        summary: { total: 0, entregadas: 0, rechazadas: 0, pendientes: 0, parciales: 0 },
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        filters: { estado: '', fecha_desde: '', fecha_hasta: '', shipment_id: '' },
        listLoading: false,
        listError: false,
        enRuta: [],
        enRutaLoading: false,
        enRutaDenied: false,
        enRutaError: false,
        modal: null,
        sending: false
    };

    const ESTADOS_ENTREGA = [
        { value: 'PENDIENTE', color: 'warning' },
        { value: 'ENTREGADA', color: 'success' },
        { value: 'RECHAZADA', color: 'danger' },
        { value: 'PARCIAL', color: 'info' }
    ];

    const ESTADO_KEYS = {
        PENDIENTE: 'inventory.deliveries.states.pendiente',
        ENTREGADA: 'inventory.deliveries.states.entregada',
        RECHAZADA: 'inventory.deliveries.states.rechazada',
        PARCIAL: 'inventory.deliveries.states.parcial'
    };

    const MOTIVOS_FALLO = [
        { value: 'NO_DISPONIBLE' },
        { value: 'RECHAZO' },
        { value: 'ACCESO_DENEGADO' },
        { value: 'OTRO' }
    ];

    const MOTIVO_KEYS = {
        NO_DISPONIBLE: 'inventory.deliveries.reasons.no_disponible',
        RECHAZO: 'inventory.deliveries.reasons.rechazo',
        ACCESO_DENEGADO: 'inventory.deliveries.reasons.acceso_denegado',
        OTRO: 'inventory.deliveries.reasons.otro'
    };

    const estadoColor = (v) => {
        const e = ESTADOS_ENTREGA.find(x => x.value === v);
        return e ? e.color : 'neutral';
    };

    const estadoLabel = (v) => ESTADO_KEYS[v] ? t(ESTADO_KEYS[v]) : v;

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

    const canView = () => can('inventory.delivery.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
    const canConfirm = () => can('inventory.delivery.confirm', ['ADMIN', 'MANAGER']);

    // Unidad pendiente de entregar en un envío EN_RUTA.
    const isPendingItem = (estado) => estado === 'CARGADA' || estado === 'EN_TRANSITO';

    // Pestañas de la vista: "En ruta" (repartos abiertos) e "Historial".
    const TABS_DELIVERIES = ['en_ruta', 'history'];

    function buildTemplate() {
        const panels = [
            {
                id: 'en_ruta',
                html: `
            <div class="flex items-center justify-end mb-3">
                <button class="btn btn-ghost btn-sm" data-action="reload-en-ruta">${t('inventory.deliveries.actions.reload')}</button>
            </div>
            <div class="card mb-6">
                <div data-en-ruta-table></div>
            </div>`
            },
            {
                id: 'history',
                html: `
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4" data-del-summary></div>

            ${UI.filtersCard(`
                <div class="form-group mb-0">
                    <label class="form-label">${t('inventory.deliveries.filters.estado')}</label>
                    <select class="select" data-filter="estado">
                        <option value="">${t('inventory.deliveries.filters.all_states')}</option>
                        ${ESTADOS_ENTREGA.map(e => `<option value="${e.value}">${t(ESTADO_KEYS[e.value])}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">${t('inventory.deliveries.filters.shipment')}</label>
                    <input type="text" class="input" data-filter="shipment_id" maxlength="36"
                           placeholder="${t('inventory.deliveries.placeholders.optional')}" autocomplete="off">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">${t('inventory.deliveries.filters.date_from')}</label>
                    <input type="date" class="input" data-filter="fecha_desde">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">${t('inventory.deliveries.filters.date_to')}</label>
                    <input type="date" class="input" data-filter="fecha_hasta">
                </div>`, {
                cols: 4,
                applyLabel: t('inventory.deliveries.actions.filter'),
                clearLabel: t('inventory.deliveries.actions.clear'),
                clearTitle: t('inventory.deliveries.actions.clear_title')
            })}

            <div class="card p-0">
                <div class="table-wrapper" data-del-table></div>
                <div data-del-pagination class="pagination"></div>
            </div>`
            }
        ];

        return UI.viewShell({
            title: t('inventory.deliveries.title'),
            subtitle: t('inventory.deliveries.subtitle'),
            tabs: [
                { id: 'en_ruta', label: t('inventory.deliveries.sections.en_ruta') },
                { id: 'history', label: t('inventory.deliveries.sections.history') }
            ],
            activeTab: state.tab,
            panels: panels
        }) + `

        <div data-modal-root></div>
    `;
    }

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
        if (TABS_DELIVERIES.indexOf(tab) === -1) return;

        applyTab(tab);
        if (tab === 'en_ruta') loadEnRuta();
        else loadHistory(state.pagination.page || 1);
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

    // ====================================================================
    // RENDER - EN RUTA
    // ====================================================================

    function renderEnRuta() {
        const root = container.querySelector('[data-en-ruta-table]');
        if (!root) return;

        if (state.enRutaLoading) {
            root.innerHTML = UI.loadingState();
            return;
        }

        if (state.enRutaDenied) {
            // Este aviso se inserta a mano (no pasa por emptyState): la
            // traducción lleva <code>{permiso}</code> y el smoke lo exige
            // en crudo dentro de [data-en-ruta-table].
            root.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-title">${t('inventory.deliveries.permission.en_ruta_title')}</div>
                    <p class="text-muted">${t('inventory.deliveries.permission.en_ruta_body', { permiso: 'inventory.shipping.view' })}</p>
                </div>`;
            return;
        }

        if (state.enRutaError) {
            root.innerHTML = UI.errorState({
                message: t('inventory.deliveries.messages.load_en_ruta_error'),
                retryAction: 'retry-en-ruta',
                retryLabel: t('ui.retry')
            });
            return;
        }

        if (state.enRuta.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('inventory.deliveries.empty.en_ruta_title'),
                message: t('inventory.deliveries.empty.en_ruta_message')
            });
            return;
        }

        const rows = state.enRuta.map(s => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(s.shipment_id)}</td>
                <td>
                    <div class="font-medium">${escapeHtml(s.referencia_externa || '—')}</div>
                    <div class="text-xs text-muted">${escapeHtml(s.destino_direccion || s.ubicacion_origen || '—')}</div>
                </td>
                <td>
                    <div>${escapeHtml(s.destinatario_nombre || '—')}</div>
                    <div class="text-xs text-muted">${escapeHtml(s.destinatario_contacto || '')}</div>
                </td>
                <td class="text-xs">${escapeHtml(s.conductor_nombre || '—')}</td>
                <td class="text-right">${formatNumber(s.total_items)}</td>
                <td class="text-xs">${escapeHtml(s.fecha_creacion || '—')}</td>
                <td class="table-actions col-actions">${renderRowActions(s)}</td>
            </tr>
        `).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('inventory.deliveries.columns.envio')}</th>
                        <th>${t('inventory.deliveries.columns.referencia')}</th>
                        <th>${t('inventory.deliveries.columns.destinatario')}</th>
                        <th>${t('inventory.deliveries.columns.conductor')}</th>
                        <th class="text-right">${t('inventory.deliveries.columns.items')}</th>
                        <th>${t('inventory.deliveries.columns.creado')}</th>
                        <th class="col-actions">${t('inventory.deliveries.columns.acciones')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function renderRowActions(shipment) {
        if (!canConfirm()) return '';

        return UI.rowActions({
            primary: {
                action: 'open-confirm',
                id: shipment.shipment_id,
                label: t('inventory.deliveries.actions.confirm')
            },
            groups: [
                [{ action: 'open-failed', id: shipment.shipment_id, label: t('inventory.deliveries.actions.failed') }]
            ],
            ariaLabel: t('inventory.deliveries.columns.acciones')
        });
    }

    // ====================================================================
    // RENDER - HISTORIAL
    // ====================================================================

    function renderSummary() {
        const root = container.querySelector('[data-del-summary]');
        if (!root) return;

        const s = state.summary;
        UI.summaryCards(root, [
            { label: t('inventory.deliveries.summary.entregas'), value: s.total },
            { label: t('inventory.deliveries.summary.entregadas'), value: s.entregadas },
            { label: t('inventory.deliveries.summary.rechazadas'), value: s.rechazadas },
            { label: t('inventory.deliveries.summary.pendientes'), value: s.pendientes }
        ]);
    }

    function renderTable() {
        const root = container.querySelector('[data-del-table]');
        if (!root) return;

        if (state.listLoading) {
            root.innerHTML = UI.loadingState();
            return;
        }

        if (state.listError) {
            root.innerHTML = UI.errorState({
                message: t('inventory.deliveries.messages.load_history_error'),
                retryAction: 'retry-history',
                retryLabel: t('ui.retry')
            });
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('inventory.deliveries.empty.history_title'),
                message: t('inventory.deliveries.empty.history_message')
            });
            return;
        }

        const rows = state.items.map(d => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(d.delivery_id)}</td>
                <td class="font-mono text-xs">${escapeHtml(d.referencia_externa || d.shipment_id || '—')}</td>
                <td class="font-mono text-xs">${escapeHtml(d.codigo_barcode || '—')}</td>
                <td class="text-right">${formatNumber(d.cantidad)}</td>
                <td><span class="badge badge-${estadoColor(d.estado)}">${escapeHtml(estadoLabel(d.estado))}</span></td>
                <td>${escapeHtml(d.destinatario_real || '—')}</td>
                <td class="text-xs">${d.firma_comprobante
                    ? `<a href="${escapeHtml(d.firma_comprobante)}" target="_blank" rel="noopener">${t('inventory.deliveries.actions.view_signature')}</a>`
                    : '—'}</td>
                <td class="text-xs">${escapeHtml(d.timestamp_entrega || '—')}</td>
            </tr>
        `).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('inventory.deliveries.columns.entrega')}</th>
                        <th>${t('inventory.deliveries.columns.envio')}</th>
                        <th>${t('inventory.deliveries.columns.barcode')}</th>
                        <th class="text-right">${t('inventory.deliveries.columns.cantidad')}</th>
                        <th>${t('inventory.deliveries.columns.estado')}</th>
                        <th>${t('inventory.deliveries.columns.destinatario')}</th>
                        <th>${t('inventory.deliveries.columns.firma')}</th>
                        <th>${t('inventory.deliveries.columns.entregado')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function renderPagination() {
        const root = container.querySelector('[data-del-pagination]');
        if (!root) return;

        UI.renderPagination(root, {
            pagination: state.pagination,
            shown: state.items.length,
            label: t('inventory.deliveries.pagination_label')
        });
    }

    function renderAll() {
        renderSummary();
        renderTable();
        renderPagination();
    }

    // ====================================================================
    // MODALES (confirmar / fallo)
    // ====================================================================

    function openModal(tipo, shipmentId) {
        state.modal = {
            tipo,
            shipmentId,
            shipment: null,
            items: [],
            rows: {},
            marcadas: new Set(),
            motivoFallo: 'OTRO',
            motivo: '',
            observaciones: '',
            loading: true,
            error: null,
            key: newIdempotencyKey(),
            formError: '',
            errors: {}
        };
        renderModal();

        global.HttpClient.get(`/inventory/shipments/${encodeURIComponent(shipmentId)}`)
            .then(response => {
                if (!state.modal || state.modal.shipmentId !== shipmentId) return;
                const payload = response.data || response;
                state.modal.shipment = payload.shipment || null;

                const items = (payload.items || []).filter(i => isPendingItem(i.estado));
                state.modal.items = items;
                items.forEach(i => {
                    state.modal.rows[i.stock_unit_id] = {
                        firma_comprobante: '',
                        observaciones: '',
                        destinatario_real: ''
                    };
                    state.modal.marcadas.add(i.stock_unit_id);
                });
                state.modal.loading = false;
                renderModal();
            })
            .catch(err => {
                if (!state.modal || state.modal.shipmentId !== shipmentId) return;
                state.modal.loading = false;
                state.modal.error = (err && err.message) || t('inventory.deliveries.messages.load_shipment_error');
                renderModal();
                handleActionError(err, t('inventory.deliveries.messages.load_shipment_error'), null, false);
                if (err && err.status === 404) closeModal();
            });
    }

    function closeModal() {
        const root = container && container.querySelector('[data-modal-root]');
        if (root) root.innerHTML = '';
        state.modal = null;
        state.sending = false;
    }

    function renderModal() {
        const root = container && container.querySelector('[data-modal-root]');
        if (!root) return;

        const modal = state.modal;
        if (!modal) {
            root.innerHTML = '';
            return;
        }

        if (modal.loading) {
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

        if (modal.error) {
            root.innerHTML = `
                <div class="modal-overlay" data-modal>
                    <div class="modal">
                        <div class="modal-header">
                            <h3 class="modal-title">${t('inventory.deliveries.modals.open_error')}</h3>
                            <button class="modal-close" data-action="close-modal" aria-label="${t('ui.close')}">×</button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger">${escapeHtml(modal.error)}</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                        </div>
                    </div>
                </div>`;
            return;
        }

        const shipment = modal.shipment || {};
        const esConfirm = modal.tipo === 'confirm';

        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-xl">
                    <div class="modal-header">
                        <h3 class="modal-title">${esConfirm ? t('inventory.deliveries.modals.confirm_title') : t('inventory.deliveries.modals.failed_title')}
                            · ${escapeHtml(shipment.referencia_externa || modal.shipmentId)}</h3>
                        <button class="modal-close" data-action="close-modal" aria-label="${t('ui.close')}">×</button>
                    </div>
                    <div class="modal-body">
                        ${renderModalContext(shipment)}
                        ${esConfirm ? renderConfirmBody() : renderFailedBody()}
                        <div data-form-error class="alert alert-danger hidden mt-4"></div>
                    </div>
                    <div class="modal-footer">
                        <div class="text-xs text-muted mr-auto" data-key-hint>Idempotency-Key:
                            <span class="font-mono">${escapeHtml(modal.key)}</span></div>
                        <button type="button" class="btn btn-secondary" data-action="close-modal">${t('inventory.deliveries.modals.cancel')}</button>
                        <button type="button" class="btn btn-primary" data-action="${esConfirm ? 'submit-confirm' : 'submit-failed'}"
                                ${state.sending ? 'disabled' : ''}>
                            ${state.sending ? t('inventory.deliveries.modals.sending') : (esConfirm ? t('inventory.deliveries.actions.confirm') : t('inventory.deliveries.actions.failed'))}
                        </button>
                    </div>
                </div>
            </div>
        `;

        applyErrors();
    }

    function renderModalContext(shipment) {
        return `
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                <div>
                    <div class="text-xs text-muted">${t('inventory.deliveries.modals.context_destinatario')}</div>
                    <div class="font-medium">${escapeHtml(shipment.destinatario_nombre || '—')}</div>
                </div>
                <div>
                    <div class="text-xs text-muted">${t('inventory.deliveries.modals.context_destino')}</div>
                    <div>${escapeHtml(shipment.destino_direccion || shipment.ubicacion_origen || '—')}</div>
                </div>
                <div>
                    <div class="text-xs text-muted">${t('inventory.deliveries.modals.context_salida')}</div>
                    <div>${escapeHtml(shipment.hora_salida || '—')}</div>
                </div>
            </div>
        `;
    }

    function renderConfirmBody() {
        const modal = state.modal;

        if (modal.items.length === 0) {
            return UI.emptyState({
                title: t('inventory.deliveries.empty.units_title'),
                message: t('inventory.deliveries.empty.confirm_message')
            });
        }

        const rows = modal.items.map(i => {
            const row = modal.rows[i.stock_unit_id] || {};
            const checked = modal.marcadas.has(i.stock_unit_id) ? 'checked' : '';
            return `
                <tr>
                    <td>
                        <input type="checkbox" data-action="toggle-unit" data-id="${escapeHtml(i.stock_unit_id)}"
                               ${checked}>
                    </td>
                    <td class="font-mono text-xs">${escapeHtml(i.barcode)}</td>
                    <td>${escapeHtml(i.producto)}</td>
                    <td class="text-right">${formatNumber(i.cantidad)}</td>
                    <td>
                        <input type="url" class="input" data-field="firma_comprobante"
                               data-id="${escapeHtml(i.stock_unit_id)}" maxlength="2000"
                               value="${escapeHtml(row.firma_comprobante || '')}"
                               placeholder="${t('inventory.deliveries.placeholders.signature')}" autocomplete="off">
                        <span class="form-error hidden" data-error="firma_comprobante:${escapeHtml(i.stock_unit_id)}"></span>
                    </td>
                    <td>
                        <input type="text" class="input" data-field="observaciones"
                               data-id="${escapeHtml(i.stock_unit_id)}" maxlength="2000"
                               value="${escapeHtml(row.observaciones || '')}"
                               placeholder="${t('inventory.deliveries.placeholders.optional')}" autocomplete="off">
                    </td>
                    <td>
                        <input type="text" class="input" data-field="destinatario_real"
                               data-id="${escapeHtml(i.stock_unit_id)}" maxlength="255"
                               value="${escapeHtml(row.destinatario_real || '')}"
                               placeholder="${t('inventory.deliveries.placeholders.optional')}" autocomplete="off">
                    </td>
                </tr>
            `;
        }).join('');

        return `
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                <div class="font-medium">${t('inventory.deliveries.modals.units_count', { count: modal.items.length })}</div>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-ghost btn-sm" data-action="mark-all">${t('inventory.deliveries.actions.mark_all')}</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-action="unmark-all">${t('inventory.deliveries.actions.unmark_all')}</button>
                </div>
            </div>

            <div class="card p-0 table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:36px"></th>
                            <th>${t('inventory.deliveries.columns.barcode')}</th>
                            <th>${t('inventory.deliveries.columns.producto')}</th>
                            <th class="text-right">${t('inventory.deliveries.columns.cantidad')}</th>
                            <th>${t('inventory.deliveries.columns.firma_url')}</th>
                            <th>${t('inventory.deliveries.columns.observaciones')}</th>
                            <th>${t('inventory.deliveries.columns.destinatario_real')}</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <span class="form-error hidden" data-error="unidades"></span>

            <div class="mt-3" data-confirm-notice>${renderConfirmNotice()}</div>

            <div class="form-group mt-3">
                <label class="form-label">${t('inventory.deliveries.form.motivo_fallo')}</label>
                <select class="select" data-field="motivo_fallo">
                    ${MOTIVOS_FALLO.map(m => `<option value="${m.value}"
                        ${m.value === modal.motivoFallo ? 'selected' : ''}>${t(MOTIVO_KEYS[m.value])}</option>`).join('')}
                </select>
                <span class="form-hint">${t('inventory.deliveries.form.hint_unmarked')}</span>
            </div>
        `;
    }

    function renderConfirmNotice() {
        const modal = state.modal;
        const marcadas = modal.items.filter(i => modal.marcadas.has(i.stock_unit_id)).length;
        const noMarcadas = modal.items.length - marcadas;
        const motivo = MOTIVOS_FALLO.find(m => m.value === modal.motivoFallo);

        if (noMarcadas === 0) {
            return `<div class="alert alert-success">${t('inventory.deliveries.messages.notice_all_marked', { marcadas: marcadas })}</div>`;
        }

        return `<div class="alert alert-warning">${t('inventory.deliveries.messages.notice_partial', {
            marcadas: marcadas,
            sin_marcar: noMarcadas,
            motivo: escapeHtml(motivo ? t(MOTIVO_KEYS[motivo.value]) : modal.motivoFallo)
        })}</div>`;
    }

    function renderFailedBody() {
        const modal = state.modal;

        if (modal.items.length === 0) {
            return UI.emptyState({
                title: t('inventory.deliveries.empty.units_title'),
                message: t('inventory.deliveries.empty.failed_message')
            });
        }

        const rows = modal.items.map(i => {
            const checked = modal.marcadas.has(i.stock_unit_id) ? 'checked' : '';
            return `
                <tr>
                    <td>
                        <input type="checkbox" data-action="toggle-unit" data-id="${escapeHtml(i.stock_unit_id)}"
                               ${checked}>
                    </td>
                    <td class="font-mono text-xs">${escapeHtml(i.barcode)}</td>
                    <td>${escapeHtml(i.producto)}</td>
                    <td class="text-right">${formatNumber(i.cantidad)}</td>
                </tr>
            `;
        }).join('');

        return `
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                    <label class="form-label">${t('inventory.deliveries.form.motivo')} <span class="required">*</span></label>
                    <select class="select" data-field="motivo" required>
                        <option value="">${t('inventory.deliveries.form.select_motivo')}</option>
                        ${MOTIVOS_FALLO.map(m => `<option value="${m.value}"
                            ${m.value === modal.motivo ? 'selected' : ''}>${t(MOTIVO_KEYS[m.value])}</option>`).join('')}
                    </select>
                    <span class="form-error hidden" data-error="motivo"></span>
                </div>
                <div class="form-group">
                    <label class="form-label">${t('inventory.deliveries.form.observaciones')}</label>
                    <textarea class="input" data-field="observaciones" rows="2" maxlength="2000"
                              placeholder="${t('inventory.deliveries.placeholders.optional')}">${escapeHtml(modal.observaciones)}</textarea>
                    <span class="form-error hidden" data-error="observaciones"></span>
                </div>
            </div>

            <div class="font-medium mb-2" data-fail-count>${t('inventory.deliveries.form.affected', { marcadas: modal.marcadas.size, total: modal.items.length })}</div>
            <div class="card p-0 table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:36px"></th>
                            <th>${t('inventory.deliveries.columns.barcode')}</th>
                            <th>${t('inventory.deliveries.columns.producto')}</th>
                            <th class="text-right">${t('inventory.deliveries.columns.cantidad')}</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <p class="form-hint mt-2">${t('inventory.deliveries.form.hint_no_units')}</p>
        `;
    }

    // ====================================================================
    // API - LISTADOS
    // ====================================================================

    async function loadEnRuta() {
        state.enRutaLoading = true;
        state.enRutaDenied = false;
        state.enRutaError = false;
        renderEnRuta();

        try {
            const response = await global.HttpClient.get('/inventory/shipments', {
                params: { estado: 'EN_RUTA', page: 1, per_page: 100 }
            });
            const payload = response.data || response;
            state.enRuta = payload.data || [];
            return true;
        } catch (err) {
            state.enRuta = [];
            if (err && err.status === 403) {
                state.enRutaDenied = true;
            } else {
                console.error('[Deliveries] en-ruta load error:', err);
                state.enRutaError = true;
                showToast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.deliveries.messages.load_en_ruta_error'));
            }
            return false;
        } finally {
            state.enRutaLoading = false;
            renderEnRuta();
        }
    }

    async function loadHistory(page = 1) {
        state.listLoading = true;
        state.listError = false;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            ['estado', 'fecha_desde', 'fecha_hasta', 'shipment_id'].forEach(campo => {
                if (state.filters[campo]) params[campo] = state.filters[campo];
            });

            const response = await global.HttpClient.get('/inventory/deliveries', { params });
            const payload = response.data || response;

            state.items = payload.data || [];
            state.summary = payload.summary || state.summary;
            state.pagination = payload.pagination || state.pagination;
            state.pagination.page = page;
            state.listError = false;
            return true;
        } catch (err) {
            console.error('[Deliveries] history load error:', err);
            state.items = [];
            state.listError = true;
            if (err && err.status === 403) {
                showToast('danger', t('inventory.deliveries.permission.title'), t('inventory.deliveries.messages.forbidden_view', { permiso: 'inventory.delivery.view' }));
            } else {
                showToast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.deliveries.messages.load_history_error'));
            }
            return false;
        } finally {
            state.listLoading = false;
            renderAll();
        }
    }

    // ====================================================================
    // API - ESCRITURAS
    // ====================================================================

    function readConfirmPayload() {
        const modal = state.modal;
        const unidades = [];

        modal.items.forEach(i => {
            if (!modal.marcadas.has(i.stock_unit_id)) return;
            const row = modal.rows[i.stock_unit_id] || {};
            const entrada = { unidad_fisica_id: i.stock_unit_id };

            if ((row.firma_comprobante || '').trim() !== '') entrada.firma_comprobante = row.firma_comprobante.trim();
            if ((row.observaciones || '').trim() !== '') entrada.observaciones = row.observaciones.trim();
            if ((row.destinatario_real || '').trim() !== '') entrada.destinatario_real = row.destinatario_real.trim();

            unidades.push(entrada);
        });

        return {
            shipment_id: modal.shipmentId,
            unidades,
            motivo_fallo: modal.motivoFallo
        };
    }

    function validateConfirm(payload) {
        if (payload.unidades.length === 0) {
            return { field: 'unidades', message: t('inventory.deliveries.validations.unit_required') };
        }

        for (const entrada of payload.unidades) {
            if (!isHttpUrl(entrada.firma_comprobante)) {
                return {
                    field: `firma_comprobante:${entrada.unidad_fisica_id}`,
                    message: t('inventory.deliveries.validations.signature_url')
                };
            }
        }

        return null;
    }

    function isHttpUrl(value) {
        if (value === undefined || value === null || value === '') return true;
        try {
            const url = new URL(value);
            return url.protocol === 'http:' || url.protocol === 'https:';
        } catch (e) {
            return false;
        }
    }

    async function submitConfirm() {
        if (state.sending || !state.modal) return;

        clearErrors();

        const payload = readConfirmPayload();
        const invalid = validateConfirm(payload);
        if (invalid) {
            showFieldError(invalid.field, invalid.message);
            return;
        }

        const marcadas = payload.unidades.length;
        const total = state.modal.items.length;
        if (marcadas < total) {
            const confirmado = global.confirm(t('inventory.deliveries.messages.confirm_partial', {
                marcadas: marcadas,
                total: total,
                sin_marcar: total - marcadas
            }));
            if (!confirmado) return;
        }

        setSending(true);

        try {
            const response = await global.HttpClient.post('/inventory/deliveries/confirm', payload, {
                headers: { 'Idempotency-Key': state.modal.key }
            });
            const body = response.data || response;
            const shipment = (body && body.shipment) || {};
            const mensaje = shipment.shipment_id
                ? t('inventory.deliveries.messages.shipment_ref', { id: shipment.shipment_id })
                : t('inventory.deliveries.messages.units_delivered', { count: marcadas });

            state.modal.key = newIdempotencyKey();

            closeModal();
            const [recargadoHistorial, recargadoEnRuta] = await Promise.all([loadHistory(1), loadEnRuta()]);
            if (recargadoHistorial && recargadoEnRuta) {
                showToast('success', t('inventory.deliveries.messages.confirmed_title'), mensaje);
            }
        } catch (err) {
            handleActionError(err, t('inventory.deliveries.messages.confirm_error'), () => {
                if (state.modal) state.modal.key = newIdempotencyKey();
                renderModal();
            }, true);
        } finally {
            setSending(false);
        }
    }

    async function submitFailed() {
        if (state.sending || !state.modal) return;

        clearErrors();

        const modal = state.modal;

        if (!modal.motivo) {
            showFieldError('motivo', t('inventory.deliveries.validations.reason_required'));
            return;
        }

        const payload = {
            shipment_id: modal.shipmentId,
            motivo: modal.motivo,
            observaciones: (modal.observaciones || '').trim()
        };

        if (payload.observaciones === '') delete payload.observaciones;

        if (modal.marcadas.size > 0) {
            payload.unidades = modal.items
                .filter(i => modal.marcadas.has(i.stock_unit_id))
                .map(i => ({ unidad_fisica_id: i.stock_unit_id }));
        }

        setSending(true);

        try {
            const response = await global.HttpClient.post('/inventory/deliveries/failed', payload, {
                headers: { 'Idempotency-Key': modal.key }
            });
            const body = response.data || response;
            const shipment = (body && body.shipment) || {};
            const mensaje = shipment.shipment_id
                ? t('inventory.deliveries.messages.shipment_ref', { id: shipment.shipment_id })
                : t('inventory.deliveries.messages.shipment_failed');

            state.modal.key = newIdempotencyKey();

            closeModal();
            const [recargadoHistorial, recargadoEnRuta] = await Promise.all([loadHistory(1), loadEnRuta()]);
            if (recargadoHistorial && recargadoEnRuta) {
                showToast('success', t('inventory.deliveries.messages.failed_title'), mensaje);
            }
        } catch (err) {
            handleActionError(err, t('inventory.deliveries.messages.failed_error'), () => {
                if (state.modal) state.modal.key = newIdempotencyKey();
                renderModal();
            }, true);
        } finally {
            setSending(false);
        }
    }

    // 400/401/403/404/409/422 → toast (y error de campo cuando trae `field`).
    function handleActionError(err, fallbackMessage, onConflict, inline) {
        const status = err.status;
        const field = err.data && err.data.field ? err.data.field : null;
        const message = err.message || fallbackMessage;

        console.error('[Deliveries] action error:', status, message);

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
                if (state.modal) closeModal();
                loadHistory(state.pagination.page || 1);
                break;
            case 409:
                if (typeof onConflict === 'function') onConflict();
                showToast('warning', t('ui.toast_conflict'), message);
                loadHistory(state.pagination.page || 1);
                loadEnRuta();
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
        if (state.modal) state.modal.formError = message || '';
        const el = container && container.querySelector('[data-modal-root] [data-form-error]');
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
        if (state.modal) state.modal.errors[field] = message;
        const el = container && container.querySelector(`[data-error="${field}"]`);
        if (!el) return;
        el.textContent = message;
        el.classList.remove('hidden');
        el.scrollIntoView({ block: 'nearest' });
    }

    // Repinta los errores guardados en estado: renderModal reconstruye el
    // modal (p. ej. al pasar a "Enviando...") y sin esto se perderían.
    function applyErrors() {
        if (!state.modal || !container) return;

        const formError = state.modal.formError;
        if (formError) showFormError(formError);

        Object.keys(state.modal.errors).forEach(field => {
            showFieldError(field, state.modal.errors[field]);
        });
    }

    function clearErrors() {
        if (state.modal) {
            state.modal.formError = '';
            state.modal.errors = {};
        }
        if (!container) return;
        container.querySelectorAll('[data-error]').forEach(el => {
            el.classList.add('hidden');
            el.textContent = '';
        });
        const el = container.querySelector('[data-modal-root] [data-form-error]');
        if (el) {
            el.classList.add('hidden');
            el.textContent = '';
        }
    }

    function setSending(sending) {
        state.sending = sending;
        renderModal();
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
        state.filters = { estado: '', fecha_desde: '', fecha_hasta: '', shipment_id: '' };
        container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
        loadHistory(1);
    }

    // ====================================================================
    // EVENTS
    // ====================================================================

    function bindEvents() {
        if (!container) return;

        container.removeEventListener('click', handleClick);
        container.removeEventListener('submit', handleSubmit);
        container.removeEventListener('change', handleChange);
        container.removeEventListener('input', handleInput);

        container.addEventListener('click', handleClick);
        container.addEventListener('submit', handleSubmit);
        container.addEventListener('change', handleChange);
        container.addEventListener('input', handleInput);
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
            case 'reload-en-ruta':
            case 'retry-en-ruta':
                loadEnRuta();
                break;
            case 'retry-history':
                loadHistory(state.pagination.page || 1);
                break;
            case 'apply-filters':
                readFilters();
                loadHistory(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
            case 'open-confirm':
                openModal('confirm', id);
                break;
            case 'open-failed':
                openModal('failed', id);
                break;
            case 'close-modal':
                closeModal();
                break;
            case 'submit-confirm':
                submitConfirm();
                break;
            case 'submit-failed':
                submitFailed();
                break;
            case 'mark-all':
                (state.modal ? state.modal.items : []).forEach(i => state.modal.marcadas.add(i.stock_unit_id));
                renderModal();
                break;
            case 'unmark-all':
                if (state.modal) state.modal.marcadas.clear();
                renderModal();
                break;
        }

        if (page !== undefined && page !== null && page !== '') {
            loadHistory(parseInt(page, 10));
        }
    }

    function handleChange(e) {
        const el = e.target;

        if (el.matches('[data-action="toggle-unit"]') && state.modal) {
            const id = el.dataset.id;
            if (el.checked) state.modal.marcadas.add(id);
            else state.modal.marcadas.delete(id);

            const notice = container.querySelector('[data-confirm-notice]');
            if (notice && state.modal.tipo === 'confirm') {
                notice.innerHTML = renderConfirmNotice();
            }

            const counter = container.querySelector('[data-fail-count]');
            if (counter) {
                counter.textContent = t('inventory.deliveries.form.affected', { marcadas: state.modal.marcadas.size, total: state.modal.items.length });
            }
            return;
        }

        if (el.matches('[data-field="motivo_fallo"]') && state.modal) {
            state.modal.motivoFallo = el.value;
            const notice = container.querySelector('[data-confirm-notice]');
            if (notice) notice.innerHTML = renderConfirmNotice();
            return;
        }

        if (el.matches('[data-field="motivo"]') && state.modal) {
            state.modal.motivo = el.value;
        }
    }

    function handleInput(e) {
        const el = e.target;
        if (!state.modal || !el.matches('[data-field]')) return;

        const field = el.dataset.field;
        const id = el.dataset.id;

        if (id && state.modal.rows[id]) {
            state.modal.rows[id][field] = el.value;
            return;
        }

        if (field === 'observaciones') state.modal.observaciones = el.value;
    }

    function handleSubmit(e) {
        e.preventDefault();
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
            title: t('inventory.deliveries.permission.title'),
            message: t('inventory.deliveries.permission.body', { permiso: 'inventory.delivery.view' })
        });
    }

    const InventoryDeliveriesModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            state.tab = UI.tabFromParams(params, TABS_DELIVERIES, 'en_ruta');
            container.innerHTML = buildTemplate();
            bindEvents();

            renderEnRuta();
            renderAll();

            loadEnRuta();
            loadHistory(1);
        },

        onActivate(params) {
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            if (!container.querySelector('[data-del-table]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
            }

            const tab = UI.tabFromParams(params, TABS_DELIVERIES, state.tab);
            if (tab !== state.tab) applyTab(tab);

            loadEnRuta();
            loadHistory(state.pagination.page || 1);
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('submit', handleSubmit);
                container.removeEventListener('change', handleChange);
                container.removeEventListener('input', handleInput);
                container.innerHTML = '';
            }
            container = null;
            state.tab = 'en_ruta';
            state.modal = null;
            state.sending = false;
        }
    };

    global.InventoryDeliveriesModule = InventoryDeliveriesModule;

})(window);
