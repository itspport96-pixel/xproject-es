/**
 * Inventory Returns Module
 * Pantalla "Devoluciones" (CU-009) - v1.8.4.
 *
 * Convención ModuleLoader: window.InventoryReturnsModule
 *
 * Bloques:
 *  1. Listado paginado `GET /api/inventory/returns` con filtros por estado al
 *     devolver, motivo, fechas y envío, más el resumen de la respuesta
 *     (total, ok, dañadas, deterioradas, perdidas).
 *  2. Alta `POST /api/inventory/returns/register` con Idempotency-Key
 *     (crypto.randomUUID al abrir el modal, reutilizada en reintentos y
 *     renovada tras éxito o 409):
 *       - unidad: selector de `GET /api/inventory/stock?estado=` con estado
 *         ENTREGADO o DEVUELTO (los dos que acepta el backend); el buscador
 *         filtra en cliente.
 *       - motivo: CAMBIO_CLIENTE, DAÑO, FALLO_ENTREGA, NO_APLICA, OTRO.
 *       - estado_al_devolver: OK, DAÑADO, DETERIORADO, PERDIDO.
 *       - ubicacion_destino_id: `GET /api/inventory/locations?activa=1`; sólo
 *         se exige cuando el estado es OK (DAÑADO/DETERIORADO van a la zona
 *         DAÑADOS y PERDIDO no guarda ubicación: el backend lo ignora).
 *       - observaciones opcionales.
 *
 * Permisos (routes.php):
 *  - inventory.return.view   → listado y resumen
 *  - inventory.return.create → alta de devolución
 *
 * Errores del backend → toast + error de campo cuando traen `field`:
 * 400 (filtro o fecha inválido), 401, 403, 404 (unidad o ubicación), 409
 * (conflicto de idempotencia), 422 (unidad no devolvible, destino inválido,
 * ubicacion_destino_id requerido, motivo o estado desconocido).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    let state = {
        items: [],
        summary: { total: 0, ok: 0, danadas: 0, deterioradas: 0, perdidas: 0 },
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        filters: { estado: '', motivo: '', fecha_desde: '', fecha_hasta: '', shipment_id: '' },
        listLoading: false,
        modal: null,
        sending: false
    };

    const MOTIVOS = [
        { value: 'CAMBIO_CLIENTE', labelKey: 'inventory.returns.reasons.cambio_cliente' },
        { value: 'DAÑO', labelKey: 'inventory.returns.reasons.dano' },
        { value: 'FALLO_ENTREGA', labelKey: 'inventory.returns.reasons.fallo_entrega' },
        { value: 'NO_APLICA', labelKey: 'inventory.returns.reasons.no_aplica' },
        { value: 'OTRO', labelKey: 'inventory.returns.reasons.otro' }
    ];

    const ESTADOS_AL_DEVOLVER = [
        { value: 'OK', labelKey: 'inventory.returns.states.ok', color: 'success' },
        { value: 'DAÑADO', labelKey: 'inventory.returns.states.danado', color: 'danger' },
        { value: 'DETERIORADO', labelKey: 'inventory.returns.states.deteriorado', color: 'warning' },
        { value: 'PERDIDO', labelKey: 'inventory.returns.states.perdido', color: 'neutral' }
    ];

    // Estados de unidad que RegisterReturnUseCase acepta.
    const ESTADOS_UNIDAD_DEVOLVIBLE = ['ENTREGADO', 'DEVUELTO'];

    const estadoColor = (v) => {
        const e = ESTADOS_AL_DEVOLVER.find(x => x.value === v);
        return e ? e.color : 'neutral';
    };

    const estadoLabel = (v) => {
        const e = ESTADOS_AL_DEVOLVER.find(x => x.value === v);
        return e ? t(e.labelKey) : v;
    };

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

    const canView = () => can('inventory.return.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
    const canCreate = () => can('inventory.return.create', ['ADMIN', 'MANAGER']);

    const TEMPLATE = `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.returns.title'),
                subtitle: t('inventory.returns.subtitle'),
                actionsHtml: `<button class="btn btn-primary btn-sm" data-action="open-register" data-create-perm>${t('inventory.returns.actions.register')}</button>`
            })}

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4" data-ret-summary></div>

            <div class="card mb-4">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.returns.filters.estado')}</label>
                        <select class="select" data-filter="estado">
                            <option value="">${t('inventory.returns.filters.all')}</option>
                            ${ESTADOS_AL_DEVOLVER.map(e => `<option value="${e.value}">${t(e.labelKey)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.returns.filters.motivo')}</label>
                        <select class="select" data-filter="motivo">
                            <option value="">${t('inventory.returns.filters.all')}</option>
                            ${MOTIVOS.map(m => `<option value="${m.value}">${t(m.labelKey)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.returns.filters.shipment')}</label>
                        <input type="text" class="input" data-filter="shipment_id" maxlength="36"
                               placeholder="${t('inventory.returns.placeholders.opcional')}" autocomplete="off">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.returns.filters.fecha_desde')}</label>
                        <input type="date" class="input" data-filter="fecha_desde">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.returns.filters.fecha_hasta')}</label>
                        <input type="date" class="input" data-filter="fecha_hasta">
                    </div>
                    <div class="form-group mb-0 md:col-span-5 flex gap-2">
                        <button class="btn btn-secondary flex-1" data-action="apply-filters">${t('inventory.returns.filters.apply')}</button>
                        <button class="btn btn-ghost" data-action="clear-filters" title="${t('inventory.returns.filters.clear_title')}">${t('inventory.returns.filters.clear')}</button>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="table-wrapper" data-ret-table></div>
                <div data-ret-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>
    `;

    const DENIED_TEMPLATE = `
        <div class="card max-w-md mx-auto mt-8">
            <div class="card-header">
                <h3 class="card-title">${t('inventory.returns.permission.title')}</h3>
            </div>
            <div class="card-body">
                <p>${t('inventory.returns.permission.body', { permiso: 'inventory.return.view' })}</p>
                <div class="mt-4">
                    <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                </div>
            </div>
        </div>
    `;

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
    // RENDER - LISTADO
    // ====================================================================

    function renderSummary() {
        const root = container.querySelector('[data-ret-summary]');
        if (!root) return;

        const s = state.summary;
        const cards = [
            { label: t('inventory.returns.summary.devoluciones'), value: s.total },
            { label: t('inventory.returns.summary.ok'), value: s.ok },
            { label: t('inventory.returns.summary.danadas'), value: s.danadas },
            { label: t('inventory.returns.summary.deterioradas'), value: s.deterioradas },
            { label: t('inventory.returns.summary.perdidas'), value: s.perdidas }
        ];

        root.innerHTML = cards.map(c => `
            <div class="card p-3">
                <div class="text-xs text-muted">${c.label}</div>
                <div class="text-xl font-bold">${formatNumber(c.value)}</div>
            </div>
        `).join('');
    }

    function renderTable() {
        const root = container.querySelector('[data-ret-table]');
        if (!root) return;

        if (state.listLoading) {
            root.innerHTML = `
                <div class="flex items-center justify-center py-12">
                    <div class="spinner"></div>
                </div>`;
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-title">${t('inventory.returns.empty.title')}</div>
                    <p class="text-muted">${t('inventory.returns.empty.message')}</p>
                </div>`;
            return;
        }

        const rows = state.items.map(r => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(r.return_id)}</td>
                <td class="font-mono text-xs">${escapeHtml(r.referencia_externa || r.shipment_id || '—')}</td>
                <td class="font-mono text-xs">${escapeHtml(r.codigo_barcode || '—')}</td>
                <td class="text-right">${formatNumber(r.cantidad)}</td>
                <td>${escapeHtml(labelMotivo(r.motivo))}</td>
                <td><span class="badge badge-${estadoColor(r.estado_al_devolver)}">${escapeHtml(estadoLabel(r.estado_al_devolver))}</span></td>
                <td class="font-mono text-xs">${escapeHtml(r.ubicacion_destino || '—')}</td>
                <td class="text-xs">${escapeHtml(r.descripcion_motivo || '—')}</td>
                <td class="text-xs">${escapeHtml(r.timestamp_devolucion || '—')}</td>
            </tr>
        `).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('inventory.returns.columns.devolucion')}</th>
                        <th>${t('inventory.returns.columns.envio')}</th>
                        <th>${t('inventory.returns.columns.barcode')}</th>
                        <th class="text-right">${t('inventory.returns.columns.cantidad')}</th>
                        <th>${t('inventory.returns.columns.motivo')}</th>
                        <th>${t('inventory.returns.columns.estado')}</th>
                        <th>${t('inventory.returns.columns.destino')}</th>
                        <th>${t('inventory.returns.columns.detalle')}</th>
                        <th>${t('inventory.returns.columns.fecha')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function labelMotivo(value) {
        const m = MOTIVOS.find(x => x.value === value);
        return m ? t(m.labelKey) : (value || '—');
    }

    function renderPagination() {
        const root = container.querySelector('[data-ret-pagination]');
        if (!root) return;

        const { total, page, per_page, has_next } = state.pagination;
        const totalPages = Math.max(1, Math.ceil(total / per_page));

        root.innerHTML = `
            <div class="pagination-info">
                ${t('ui.pagination', { shown: state.items.length, total, label: t('inventory.returns.pagination_label') })}
            </div>
            <div class="pagination-controls">
                <button class="page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>‹</button>
                <span class="page-btn is-active">${page} / ${totalPages}</span>
                <button class="page-btn" data-page="${page + 1}" ${!has_next ? 'disabled' : ''}>›</button>
            </div>
        `;
    }

    function renderAll() {
        renderSummary();
        renderTable();
        renderPagination();
        applyCreatePermission();
    }

    function applyCreatePermission() {
        const btn = container && container.querySelector('[data-create-perm]');
        if (!btn) return;
        btn.classList.toggle('hidden', !canCreate());
    }

    // ====================================================================
    // MODAL DE ALTA
    // ====================================================================

    function openRegister() {
        state.modal = {
            stock: [],
            stockLoading: true,
            stockEstado: 'ENTREGADO',
            search: '',
            unidad_fisica_id: '',
            motivo: '',
            estado_al_devolver: '',
            ubicacion_destino_id: '',
            observaciones: '',
            locations: [],
            locationsLoading: true,
            key: newIdempotencyKey(),
            formError: '',
            errors: {}
        };
        renderModal();

        loadStock();
        loadLocations();
    }

    function closeModal() {
        const root = container && container.querySelector('[data-modal-root]');
        if (root) root.innerHTML = '';
        state.modal = null;
        state.sending = false;
    }

    async function loadStock() {
        const modal = state.modal;
        if (!modal) return;

        modal.stockLoading = true;
        // Se vacía la lista al recargar: si no, durante la carga (o si
        // falla) el selector seguiría mostrando unidades del estado anterior.
        modal.stock = [];
        renderModal();

        try {
            const response = await global.HttpClient.get('/inventory/stock', {
                params: { estado: modal.stockEstado, page: 1, per_page: 100 }
            });
            const payload = response.data || response;
            modal.stock = (payload.data || []).filter(u =>
                ESTADOS_UNIDAD_DEVOLVIBLE.includes(u.estado || u.estado_actual)
            );
        } catch (err) {
            modal.stock = [];
            handleActionError(err, t('inventory.returns.messages.stock_load_error'), null, false);
        } finally {
            if (state.modal === modal) {
                modal.stockLoading = false;
                renderModal();
            }
        }
    }

    async function loadLocations() {
        const modal = state.modal;
        if (!modal) return;

        modal.locationsLoading = true;
        renderModal();

        try {
            const response = await global.HttpClient.get('/inventory/locations', {
                params: { activa: 'true', page: 1, per_page: 100 }
            });
            const payload = response.data || response;
            modal.locations = payload.data || [];
        } catch (err) {
            modal.locations = [];
            handleActionError(err, t('inventory.returns.messages.locations_load_error'), null, false);
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

        const term = (modal.search || '').trim().toLowerCase();
        if (!term) return modal.stock;

        return modal.stock.filter(u =>
            (u.codigo_barcode || '').toLowerCase().includes(term)
            || (u.producto_nombre || '').toLowerCase().includes(term)
            || (u.sku || '').toLowerCase().includes(term)
            || (u.lote || '').toLowerCase().includes(term)
            || (u.ubicacion_codigo || '').toLowerCase().includes(term)
        );
    }

    function unitOptions(unidades) {
        return `<option value="">${t('inventory.returns.modal.select_unit')}</option>` + unidades.map(u => `
            <option value="${escapeHtml(u.unidad_fisica_id)}"
                ${u.unidad_fisica_id === state.modal.unidad_fisica_id ? 'selected' : ''}>
                ${escapeHtml(u.codigo_barcode || u.unidad_fisica_id)}
                · ${escapeHtml(u.producto_nombre || u.sku || '')}
                ${u.ubicacion_codigo ? '· ' + escapeHtml(u.ubicacion_codigo) : ''}
            </option>`).join('');
    }

    function renderModal() {
        const root = container && container.querySelector('[data-modal-root]');
        if (!root) return;

        const modal = state.modal;
        if (!modal) {
            root.innerHTML = '';
            return;
        }

        const unidades = filteredStock();
        const requiereDestino = modal.estado_al_devolver === 'OK';

        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('inventory.returns.modal.title')}</h3>
                        <button class="modal-close" data-action="close-modal" aria-label="${t('ui.close')}">×</button>
                    </div>
                    <div class="modal-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.returns.modal.estado_unidad')}</label>
                                    <select class="select" data-modal-field="stockEstado">
                                        ${ESTADOS_UNIDAD_DEVOLVIBLE.map(e => `<option value="${e}"
                                            ${e === modal.stockEstado ? 'selected' : ''}>${e}</option>`).join('')}
                                    </select>
                                    <span class="form-hint">${t('inventory.returns.modal.estado_unidad_hint')}</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">${t('inventory.returns.modal.buscar_unidad')}</label>
                                    <input type="text" class="input" data-modal-field="search"
                                           value="${escapeHtml(modal.search)}"
                                           placeholder="${t('inventory.returns.placeholders.unit_search')}" autocomplete="off">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">${t('inventory.returns.modal.unidad')} <span class="required">*</span></label>
                                    <select class="select" data-modal-field="unidad_fisica_id"
                                            ${modal.stockLoading ? 'disabled' : ''}>
                                        ${unitOptions(unidades)}
                                    </select>
                                    <span class="form-error hidden" data-error="unidad_fisica_id"></span>
                                    ${modal.stockLoading
                                        ? `<span class="form-hint">${t('inventory.returns.modal.loading_units')}</span>`
                                        : `<span class="form-hint">${t('inventory.returns.modal.units_available', { count: unidades.length })}</span>`}
                                </div>
                            </div>

                            <div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.returns.modal.motivo')} <span class="required">*</span></label>
                                    <select class="select" data-modal-field="motivo">
                                        <option value="">${t('inventory.returns.modal.select_motivo')}</option>
                                        ${MOTIVOS.map(m => `<option value="${m.value}"
                                            ${m.value === modal.motivo ? 'selected' : ''}>${t(m.labelKey)}</option>`).join('')}
                                    </select>
                                    <span class="form-error hidden" data-error="motivo"></span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">${t('inventory.returns.modal.estado')} <span class="required">*</span></label>
                                    <select class="select" data-modal-field="estado_al_devolver">
                                        <option value="">${t('inventory.returns.modal.select_estado')}</option>
                                        ${ESTADOS_AL_DEVOLVER.map(e => `<option value="${e.value}"
                                            ${e.value === modal.estado_al_devolver ? 'selected' : ''}>${t(e.labelKey)}</option>`).join('')}
                                    </select>
                                    <span class="form-error hidden" data-error="estado_al_devolver"></span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">${t('inventory.returns.modal.ubicacion_destino')}
                                        ${requiereDestino ? '<span class="required">*</span>' : ''}</label>
                                    <select class="select" data-modal-field="ubicacion_destino_id"
                                            ${requiereDestino ? '' : 'disabled'}>
                                        <option value="">${t('inventory.returns.modal.no_destination')}</option>
                                        ${modal.locations.map(l => `
                                            <option value="${escapeHtml(l.id)}"
                                                ${l.id === modal.ubicacion_destino_id ? 'selected' : ''}>
                                                ${escapeHtml(l.codigo)}${l.nombre ? ' · ' + escapeHtml(l.nombre) : ''}
                                            </option>`).join('')}
                                    </select>
                                    <span class="form-error hidden" data-error="ubicacion_destino_id"></span>
                                    <span class="form-hint">${requiereDestino
                                        ? t('inventory.returns.modal.destination_required_hint')
                                        : t('inventory.returns.modal.destination_optional_hint')}</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">${t('inventory.returns.modal.observaciones')}</label>
                                    <textarea class="input" data-modal-field="observaciones" rows="3" maxlength="2000"
                                              placeholder="${t('inventory.returns.placeholders.opcional')}">${escapeHtml(modal.observaciones)}</textarea>
                                    <span class="form-error hidden" data-error="observaciones"></span>
                                </div>
                            </div>
                        </div>

                        <div data-form-error class="alert alert-danger hidden mt-4"></div>
                    </div>
                    <div class="modal-footer">
                        <div class="text-xs text-muted mr-auto" data-key-hint>Idempotency-Key:
                            <span class="font-mono">${escapeHtml(modal.key)}</span></div>
                        <button type="button" class="btn btn-secondary" data-action="close-modal">${t('inventory.returns.actions.cancel')}</button>
                        <button type="button" class="btn btn-primary" data-action="submit-register"
                                ${state.sending ? 'disabled' : ''}>
                            ${state.sending ? t('inventory.returns.actions.sending') : t('inventory.returns.actions.register')}
                        </button>
                    </div>
                </div>
            </div>
        `;

        applyErrors();
    }

    // ====================================================================
    // API - LISTADO
    // ====================================================================

    async function loadHistory(page = 1) {
        state.listLoading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            ['estado', 'motivo', 'fecha_desde', 'fecha_hasta', 'shipment_id'].forEach(campo => {
                if (state.filters[campo]) params[campo] = state.filters[campo];
            });

            const response = await global.HttpClient.get('/inventory/returns', { params });
            const payload = response.data || response;

            state.items = payload.data || [];
            state.summary = payload.summary || state.summary;
            state.pagination = payload.pagination || state.pagination;
            state.pagination.page = page;
            return true;
        } catch (err) {
            console.error('[Returns] load error:', err);
            state.items = [];
            if (err && err.status === 403) {
                showToast('danger', t('inventory.returns.permission.title'), t('inventory.returns.messages.forbidden_view', { permiso: 'inventory.return.view' }));
            } else {
                showToast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.returns.messages.load_error'));
            }
            return false;
        } finally {
            state.listLoading = false;
            renderAll();
        }
    }

    // ====================================================================
    // API - ALTA
    // ====================================================================

    function readPayload() {
        const modal = state.modal;
        const payload = {
            unidad_fisica_id: modal.unidad_fisica_id,
            motivo: modal.motivo,
            estado_al_devolver: modal.estado_al_devolver,
            observaciones: (modal.observaciones || '').trim()
        };

        if (payload.observaciones === '') delete payload.observaciones;

        if (modal.estado_al_devolver === 'OK' && modal.ubicacion_destino_id) {
            payload.ubicacion_destino_id = modal.ubicacion_destino_id;
        }

        return payload;
    }

    function validate(payload) {
        if (!payload.unidad_fisica_id) {
            return { field: 'unidad_fisica_id', message: t('inventory.returns.validation.unidad') };
        }
        if (!payload.motivo) {
            return { field: 'motivo', message: t('inventory.returns.validation.motivo') };
        }
        if (!payload.estado_al_devolver) {
            return { field: 'estado_al_devolver', message: t('inventory.returns.validation.estado') };
        }
        if (payload.estado_al_devolver === 'OK' && !payload.ubicacion_destino_id) {
            return { field: 'ubicacion_destino_id', message: t('inventory.returns.validation.destino') };
        }
        return null;
    }

    async function submit() {
        if (state.sending || !state.modal) return;

        clearErrors();

        const payload = readPayload();
        const invalid = validate(payload);
        if (invalid) {
            showFieldError(invalid.field, invalid.message);
            return;
        }

        setSending(true);

        try {
            const response = await global.HttpClient.post('/inventory/returns/register', payload, {
                headers: { 'Idempotency-Key': state.modal.key }
            });
            const body = response.data || response;
            const devolucion = (body && body.devolucion) || {};
            const destino = body && body.ubicacion_destino ? body.ubicacion_destino.codigo : '';

            state.modal.key = newIdempotencyKey();

            closeModal();
            const recargado = await loadHistory(1);
            if (recargado) {
                showToast('success', t('inventory.returns.messages.registered_title'),
                    devolucion.return_id ? `${devolucion.return_id}${destino ? '  → ' + destino : ''}` : '');
            }
        } catch (err) {
            handleActionError(err, t('inventory.returns.messages.register_error'), () => {
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

        console.error('[Returns] action error:', status, message);

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
                break;
            case 409:
                if (typeof onConflict === 'function') onConflict();
                showToast('warning', t('ui.toast_conflict'), message);
                loadHistory(state.pagination.page || 1);
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

        if (state.modal.formError) showFormError(state.modal.formError);

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
        state.filters = { estado: '', motivo: '', fecha_desde: '', fecha_hasta: '', shipment_id: '' };
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
        const page = btn.dataset.page;

        switch (action) {
            case 'open-register':
                if (canCreate()) openRegister();
                break;
            case 'close-modal':
                closeModal();
                break;
            case 'submit-register':
                submit();
                break;
            case 'apply-filters':
                readFilters();
                loadHistory(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
        }

        if (page !== undefined && page !== null && page !== '') {
            loadHistory(parseInt(page, 10));
        }
    }

    function handleChange(e) {
        const el = e.target;
        if (!state.modal) return;

        if (el.matches('[data-modal-field="stockEstado"]')) {
            state.modal.stockEstado = el.value;
            state.modal.unidad_fisica_id = '';
            loadStock();
            return;
        }

        if (el.matches('[data-modal-field="unidad_fisica_id"]')) {
            state.modal.unidad_fisica_id = el.value;
            return;
        }

        if (el.matches('[data-modal-field="motivo"]')) {
            state.modal.motivo = el.value;
            return;
        }

        if (el.matches('[data-modal-field="estado_al_devolver"]')) {
            state.modal.estado_al_devolver = el.value;
            renderModal();
            return;
        }

        if (el.matches('[data-modal-field="ubicacion_destino_id"]')) {
            state.modal.ubicacion_destino_id = el.value;
        }
    }

    function handleInput(e) {
        const el = e.target;
        if (!state.modal) return;

        if (el.matches('[data-modal-field="search"]')) {
            state.modal.search = el.value;
            const unidades = filteredStock();
            // Si la unidad seleccionada queda fuera del filtro, se limpia
            // la selección: si no, el alta enviaría un id que no se ve.
            if (state.modal.unidad_fisica_id
                && !unidades.some(u => u.unidad_fisica_id === state.modal.unidad_fisica_id)) {
                state.modal.unidad_fisica_id = '';
            }
            const select = container.querySelector('[data-modal-field="unidad_fisica_id"]');
            if (select) select.innerHTML = unitOptions(unidades);
            return;
        }

        if (el.matches('[data-modal-field="observaciones"]')) {
            state.modal.observaciones = el.value;
        }
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

    const InventoryReturnsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            container.innerHTML = TEMPLATE;
            bindEvents();

            renderAll();
            loadHistory(1);
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            if (!container.querySelector('[data-ret-table]')) {
                container.innerHTML = TEMPLATE;
                bindEvents();
            }
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
            state.modal = null;
            state.sending = false;
        }
    };

    global.InventoryReturnsModule = InventoryReturnsModule;

})(window);
