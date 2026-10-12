/**
 * Inventory Stock Module
 * Stock actual por unidad (R1) contra GET /api/inventory/stock.
 *
 * Convención ModuleLoader: window.InventoryStockModule
 *
 * Filtros soportados por el backend: producto_id, ubicacion_id, estado,
 * search, page, per_page (máx. 100 por página).
 *
 * Acciones (v1.6.1):
 *  - Mover   → POST /api/inventory/movements   (inventory.movement.create)
 *  - Reservar → POST /api/inventory/reservations (inventory.reservation.create)
 * Ambas con Idempotency-Key generada al abrir el modal, reutilizada en cada
 * reintento del MISMO envío y renovada sólo tras un éxito (y en 409, porque
 * la clave queda quemada con otra carga útil: mismo patrón que receiving.js).
 * Las acciones se ocultan cuando el estado de la unidad no las permite
 * (StateTransitionService: EN_TRANSITO/ENTREGADO/PERDIDO no se mueven;
 * reservar sólo desde DISPONIBLE).
 *
 * v1.10.0:
 *  - R3: "Historial" abre la línea de tiempo de la unidad contra
 *    GET /api/inventory/stock/{id}/history (eventos + movimientos).
 *  - E4: "Bloquear"/"Desbloquear" contra POST /api/inventory/stock/{id}/block
 *    y /unblock (inventory.stock.block), sólo si la matriz admite el paso.
 *  - CU-002: el backend devuelve data.warnings al mover; cada aviso se
 *    muestra como toast de aviso sin bloquear la operación.
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    let state = {
        items: [],
        summary: { unidades: 0, cantidad_fisica: 0, cantidad_reservada: 0, cantidad_en_transito: 0, cantidad_disponible: 0 },
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        filters: { producto_id: '', ubicacion_id: '', estado: '' },
        products: [],
        locations: [],
        loading: false,
        modal: null,
        history: null,
        blocking: false
    };

    const ESTADOS = [
        { value: 'DISPONIBLE', color: 'success' },
        { value: 'RESERVADO', color: 'warning' },
        { value: 'EN_PREPARACION', color: 'info' },
        { value: 'CARGADO', color: 'info' },
        { value: 'EN_TRANSITO', color: 'info' },
        { value: 'ENTREGADO', color: 'neutral' },
        { value: 'DEVUELTO', color: 'neutral' },
        { value: 'DAÑADO', color: 'danger' },
        { value: 'BLOQUEADO', color: 'danger' },
        { value: 'PERDIDO', color: 'danger' }
    ];

    const ESTADO_KEYS = {
        DISPONIBLE: 'inventory.stock.states.disponible',
        RESERVADO: 'inventory.stock.states.reservado',
        EN_PREPARACION: 'inventory.stock.states.en_preparacion',
        CARGADO: 'inventory.stock.states.cargado',
        EN_TRANSITO: 'inventory.stock.states.en_transito',
        ENTREGADO: 'inventory.stock.states.entregado',
        DEVUELTO: 'inventory.stock.states.devuelto',
        DAÑADO: 'inventory.stock.states.dannado',
        BLOQUEADO: 'inventory.stock.states.bloqueado',
        PERDIDO: 'inventory.stock.states.perdido'
    };

    const estadoColor = (v) => {
        const e = ESTADOS.find(x => x.value === v);
        return e ? e.color : 'neutral';
    };

    const estadoLabel = (v) => ESTADO_KEYS[v] ? t(ESTADO_KEYS[v]) : v;

    // Estados que NO admiten movimiento interno (StateTransitionService).
    const NOT_MOVABLE_STATES = ['EN_TRANSITO', 'ENTREGADO', 'PERDIDO'];

    const isMovable = (estado) => !NOT_MOVABLE_STATES.includes(estado);

    // Sólo DISPONIBLE → RESERVADO (matriz §4; DEVUELTO no es reservable).
    const canReserveUnit = (u) => u.estado === 'DISPONIBLE' && parseFloat(u.cantidad_disponible) > 0;

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

    const canMove = () => can('inventory.movement.create', ['ADMIN', 'MANAGER', 'USER']);
    const canReserve = () => can('inventory.reservation.create', ['ADMIN', 'MANAGER', 'USER']);
    const canBlock = () => can('inventory.stock.block', ['ADMIN', 'MANAGER']);
    const canView = () => can('inventory.stock.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);

    // Estados desde los que la matriz admite el paso a BLOQUEADO
    // (StateTransitionService §4; ENTREGADO/DEVUELTO/PERDIDO no).
    const BLOCKABLE_STATES = [
        'DISPONIBLE', 'RESERVADO', 'EN_PREPARACION', 'CARGADO', 'EN_TRANSITO', 'DAÑADO'
    ];

    const canBlockUnit = (u) => BLOCKABLE_STATES.includes(u.estado);
    const canUnblockUnit = (u) => u.estado === 'BLOQUEADO';

    const DENIED_TEMPLATE = `
        <div class="card max-w-md mx-auto mt-8">
            <div class="card-header">
                <h3 class="card-title">${t('inventory.stock.permission.title')}</h3>
            </div>
            <div class="card-body">
                <p>${t('inventory.stock.permission.body', { permiso: 'inventory.stock.view' })}</p>
                <div class="mt-4">
                    <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                </div>
            </div>
        </div>`;

    const TEMPLATE = `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.stock.title'),
                subtitle: t('inventory.stock.subtitle')
            })}

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4" data-stock-summary></div>

            <div class="card mb-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="form-group mb-0">
                        <select class="select" data-filter="producto_id">
                            <option value="">${t('inventory.stock.filters.all_products')}</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <select class="select" data-filter="ubicacion_id">
                            <option value="">${t('inventory.stock.filters.all_locations')}</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <select class="select" data-filter="estado">
                            <option value="">${t('inventory.stock.filters.all_states')}</option>
                            ${ESTADOS.map(e => `<option value="${e.value}">${t(ESTADO_KEYS[e.value])}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group mb-0 flex gap-2">
                        <button class="btn btn-secondary flex-1" data-action="apply-filters">${t('inventory.stock.filters.apply')}</button>
                        <button class="btn btn-ghost" data-action="clear-filters" title="${t('inventory.stock.filters.clear_title')}">${t('inventory.stock.filters.clear')}</button>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="table-wrapper" data-stock-table></div>
                <div data-stock-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>
    `;

    // ====================================================================
    // RENDER
    // ====================================================================

    function formatNumber(value) {
        const n = parseFloat(value);
        if (!isFinite(n)) return '0';
        return Number.isInteger(n) ? String(n) : n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    }

    function renderSummary() {
        const root = container.querySelector('[data-stock-summary]');
        if (!root) return;

        const s = state.summary;
        const cards = [
            { label: t('inventory.stock.summary.unidades'), value: formatNumber(s.unidades) },
            { label: t('inventory.stock.summary.fisica'), value: formatNumber(s.cantidad_fisica) },
            { label: t('inventory.stock.summary.reservada'), value: formatNumber(s.cantidad_reservada) },
            { label: t('inventory.stock.summary.en_transito'), value: formatNumber(s.cantidad_en_transito) },
            { label: t('inventory.stock.summary.disponible'), value: formatNumber(s.cantidad_disponible) }
        ];

        root.innerHTML = cards.map(c => `
            <div class="card p-3">
                <div class="text-xs text-muted">${c.label}</div>
                <div class="text-xl font-bold">${c.value}</div>
            </div>
        `).join('');
    }

    function renderTable() {
        const root = container.querySelector('[data-stock-table]');
        if (!root) return;

        if (state.loading) {
            root.innerHTML = `
                <div class="flex items-center justify-center py-12">
                    <div class="spinner"></div>
                </div>`;
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-title">${t('inventory.stock.empty.title')}</div>
                    <p class="text-muted">${t('inventory.stock.empty.message')}</p>
                </div>`;
            return;
        }

        const rows = state.items.map(u => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(u.codigo_barcode)}</td>
                <td>
                    <div class="font-medium">${escapeHtml(u.producto_nombre)}</div>
                    <div class="text-xs text-muted font-mono">${escapeHtml(u.sku)}</div>
                </td>
                <td class="font-mono text-xs">${u.ubicacion_codigo ? escapeHtml(u.ubicacion_codigo) : '<span class="text-muted">—</span>'}</td>
                <td>
                    <div class="font-medium">${formatNumber(u.cantidad_fisica)}</div>
                    <div class="text-xs text-muted">
                        ${t('inventory.stock.row.disp', { value: formatNumber(u.cantidad_disponible) })}
                        ${t('inventory.stock.row.res', { value: formatNumber(u.cantidad_reservada) })}
                        ${t('inventory.stock.row.tran', { value: formatNumber(u.cantidad_en_transito) })}
                    </div>
                </td>
                <td><span class="badge badge-${estadoColor(u.estado)}">${escapeHtml(estadoLabel(u.estado))}</span></td>
                <td class="table-actions col-actions">${renderActionsCell(u)}</td>
            </tr>
        `).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('inventory.stock.columns.barcode')}</th>
                        <th>${t('inventory.stock.columns.producto')}</th>
                        <th>${t('inventory.stock.columns.ubicacion')}</th>
                        <th>${t('inventory.stock.columns.cantidad')}</th>
                        <th>${t('inventory.stock.columns.estado')}</th>
                        <th class="col-actions">${t('inventory.stock.columns.acciones')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    // Acciones de la fila: sólo si hay permiso Y el estado las admite.
    function renderActionsCell(u) {
        const move = canMove() && isMovable(u.estado);
        const reserve = canReserve() && canReserveUnit(u);
        const block = canBlock() && canBlockUnit(u);
        const unblock = canBlock() && canUnblockUnit(u);

        return UI.rowActions({
            primary: move
                ? { action: 'move', id: u.unidad_fisica_id, label: t('inventory.stock.actions.move') }
                : null,
            groups: [
                [reserve ? { action: 'reserve', id: u.unidad_fisica_id, label: t('inventory.stock.actions.reserve') } : null],
                [block
                    ? { action: 'block', id: u.unidad_fisica_id, label: t('inventory.stock.actions.block') }
                    : unblock
                        ? { action: 'unblock', id: u.unidad_fisica_id, label: t('inventory.stock.actions.unblock') }
                        : null],
                [{ action: 'history', id: u.unidad_fisica_id, label: t('inventory.stock.actions.history') }]
            ],
            ariaLabel: t('inventory.stock.columns.acciones')
        });
    }

    function renderPagination() {
        const root = container.querySelector('[data-stock-pagination]');
        if (!root) return;

        const { total, page, per_page, has_next } = state.pagination;
        const totalPages = Math.max(1, Math.ceil(total / per_page));

        root.innerHTML = `
            <div class="pagination-info">
                ${t('ui.pagination', { shown: state.items.length, total, label: t('inventory.stock.units') })}
            </div>
            <div class="pagination-controls">
                <button class="page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>‹</button>
                <span class="page-btn is-active">${page} / ${totalPages}</span>
                <button class="page-btn" data-page="${page + 1}" ${!has_next ? 'disabled' : ''}>›</button>
            </div>
        `;
    }

    function renderFilters() {
        if (!container) return;

        const productSelect = container.querySelector('select[data-filter="producto_id"]');
        const locationSelect = container.querySelector('select[data-filter="ubicacion_id"]');

        if (productSelect) {
            const selected = state.filters.producto_id;
            productSelect.innerHTML = `<option value="">${t('inventory.stock.filters.all_products')}</option>` +
                state.products.map(p => `
                    <option value="${escapeHtml(p.id)}" ${p.id === selected ? 'selected' : ''}>
                        ${escapeHtml(p.sku)} - ${escapeHtml(p.nombre)}
                    </option>`).join('');
        }

        if (locationSelect) {
            const selected = state.filters.ubicacion_id;
            locationSelect.innerHTML = `<option value="">${t('inventory.stock.filters.all_locations')}</option>` +
                state.locations.map(l => `
                    <option value="${escapeHtml(l.id)}" ${l.id === selected ? 'selected' : ''}>
                        ${escapeHtml(l.codigo)}
                    </option>`).join('');
        }

        container.querySelectorAll('select[data-filter]').forEach(el => {
            const key = el.dataset.filter;
            if (state.filters[key] !== undefined) el.value = state.filters[key];
        });
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

    function renderKeyHint(root) {
        const el = root && root.querySelector('[data-key-hint]');
        if (!el || !state.modal) return;
        el.innerHTML = `Idempotency-Key: <span class="font-mono">${escapeHtml(state.modal.idempotencyKey)}</span>`;
    }

    // ====================================================================
    // MODALES (Mover / Reservar)
    // ====================================================================

    // Destinos válidos: activos, con espacio, distintos de la ubicación actual
    // y sin tipo TRANSITO (ubicación virtual que rechaza el backend).
    function availableDestinations(unit) {
        return state.locations.filter(l =>
            l.id !== unit.ubicacion_id
            && (l.activa === 1 || l.activa === true)
            && l.tipo !== 'TRANSITO'
            && (l.capacidad === null || l.capacidad === undefined
                || (l.capacidad_usada || 0) < l.capacidad)
        );
    }

    function destinationOptions(unit) {
        const options = availableDestinations(unit);

        if (options.length === 0) {
            return `<option value="">${t('inventory.stock.modals.move.no_destinations')}</option>`;
        }

        return `<option value="">${t('inventory.stock.modals.move.select_destination')}</option>` +
            options.map(l => {
                const capacidad = (l.capacidad === null || l.capacidad === undefined)
                    ? t('inventory.stock.modals.move.no_limit')
                    : `${l.capacidad_usada || 0}/${l.capacidad}`;
                return `<option value="${escapeHtml(l.id)}">${escapeHtml(l.codigo)} (${escapeHtml(capacidad)})</option>`;
            }).join('');
    }

    function openMoveModal(unit) {
        state.modal = {
            type: 'move',
            unit,
            idempotencyKey: newIdempotencyKey(),
            sending: false
        };

        const root = container.querySelector('[data-modal-root]');
        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('inventory.stock.modals.move.title')}</h3>
                        <button class="modal-close" data-action="close-modal" aria-label="${t('ui.close')}">×</button>
                    </div>
                    <form data-move-form novalidate>
                        <div class="modal-body">
                            <div class="form-grid">
                                <div class="form-group span-all">
                                    <label class="form-label">${t('inventory.stock.modals.unit')}</label>
                                    <div class="font-mono text-sm">${escapeHtml(unit.codigo_barcode)}</div>
                                    <div class="text-xs text-muted">${escapeHtml(unit.producto_nombre)} · ${escapeHtml(unit.sku || '')}</div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.stock.modals.move.current_location')}</label>
                                    <div class="font-mono text-sm">${unit.ubicacion_codigo ? escapeHtml(unit.ubicacion_codigo) : '—'}</div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.stock.modals.state')}</label>
                                    <span class="badge badge-${estadoColor(unit.estado)}">${escapeHtml(estadoLabel(unit.estado))}</span>
                                </div>
                                <div class="form-group span-all">
                                    <label class="form-label">${t('inventory.stock.modals.move.destination_label')} <span class="required">*</span></label>
                                    <select class="select" name="ubicacion_destino_id" required>${destinationOptions(unit)}</select>
                                    <span class="form-hint">${t('inventory.stock.modals.move.destination_hint')}</span>
                                    <span class="form-error hidden" data-error="ubicacion_destino_id"></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.stock.modals.move.reason_label')}</label>
                                    <input type="text" class="input" name="motivo" maxlength="50"
                                           placeholder="${t('inventory.stock.modals.move.reason_placeholder')}">
                                    <span class="form-error hidden" data-error="motivo"></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.stock.modals.move.external_ref_label')}</label>
                                    <input type="text" class="input" name="referencia_externa" maxlength="255" placeholder="${t('inventory.stock.modals.optional_placeholder')}">
                                    <span class="form-error hidden" data-error="referencia_externa"></span>
                                </div>
                            </div>
                            <div data-form-error class="alert alert-danger hidden mt-4"></div>
                        </div>
                        <div class="modal-footer">
                            <div class="text-xs text-muted mr-auto" data-key-hint></div>
                            <button type="button" class="btn btn-secondary" data-action="close-modal">${t('inventory.stock.modals.cancel')}</button>
                            <button type="submit" class="btn btn-primary" data-action="submit-move">${t('inventory.stock.actions.move')}</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        renderKeyHint(root);
    }

    function openReserveModal(unit) {
        state.modal = {
            type: 'reserve',
            unit,
            idempotencyKey: newIdempotencyKey(),
            sending: false
        };

        const root = container.querySelector('[data-modal-root]');
        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('inventory.stock.modals.reserve.title')}</h3>
                        <button class="modal-close" data-action="close-modal" aria-label="${t('ui.close')}">×</button>
                    </div>
                    <form data-reserve-form novalidate>
                        <div class="modal-body">
                            <div class="form-grid">
                                <div class="form-group span-all">
                                    <label class="form-label">${t('inventory.stock.modals.unit')}</label>
                                    <div class="font-mono text-sm">${escapeHtml(unit.codigo_barcode)}</div>
                                    <div class="text-xs text-muted">${escapeHtml(unit.producto_nombre)} · ${escapeHtml(unit.sku || '')}</div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.stock.modals.reserve.location_label')}</label>
                                    <div class="font-mono text-sm">${unit.ubicacion_codigo ? escapeHtml(unit.ubicacion_codigo) : '—'}</div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.stock.modals.reserve.available_label')}</label>
                                    <div class="font-medium">${formatNumber(unit.cantidad_disponible)}</div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.stock.modals.reserve.quantity_label')}</label>
                                    <input type="number" class="input" name="cantidad" min="0.01" step="any"
                                           inputmode="decimal" placeholder="${t('inventory.stock.modals.reserve.quantity_placeholder')}">
                                    <span class="form-hint">${t('inventory.stock.modals.reserve.quantity_hint', { max: formatNumber(unit.cantidad_disponible) })}</span>
                                    <span class="form-error hidden" data-error="cantidad"></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.stock.modals.reserve.expiration_label')}</label>
                                    <input type="datetime-local" class="input" name="fecha_expiracion">
                                    <span class="form-hint">${t('inventory.stock.modals.reserve.expiration_hint')}</span>
                                    <span class="form-error hidden" data-error="fecha_expiracion"></span>
                                </div>
                                <div class="form-group span-all">
                                    <label class="form-label">${t('inventory.stock.modals.reserve.order_ref_label')}</label>
                                    <input type="text" class="input" name="referencia_orden" maxlength="255" placeholder="${t('inventory.stock.modals.optional_placeholder')}">
                                    <span class="form-error hidden" data-error="referencia_orden"></span>
                                </div>
                            </div>
                            <div data-form-error class="alert alert-danger hidden mt-4"></div>
                        </div>
                        <div class="modal-footer">
                            <div class="text-xs text-muted mr-auto" data-key-hint></div>
                            <button type="button" class="btn btn-secondary" data-action="close-modal">${t('inventory.stock.modals.cancel')}</button>
                            <button type="submit" class="btn btn-primary" data-action="submit-reserve">${t('inventory.stock.actions.reserve')}</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        renderKeyHint(root);
    }

    function closeModal() {
        const root = container.querySelector('[data-modal-root]');
        if (root) root.innerHTML = '';
        state.modal = null;
        state.history = null;
    }

    // ====================================================================
    // HISTORIAL (R3: línea de tiempo de la unidad)
    // ====================================================================

    // Colores de badge por tipo de evento/movimiento (catálogo pequeño; lo
    // que no esté listo queda neutral).
    const TIPO_HISTORY_COLORS = {
        RECIBIDO: 'info',
        MOVIDO: 'neutral',
        BLOQUEADO: 'danger',
        DESBLOQUEADO: 'success',
        DISPONIBLE: 'success',
        PERDIDO: 'danger',
        DAÑADO: 'danger',
        INCIDENCIA: 'warning'
    };

    function historyEntryHtml(entry) {
        const esEvento = entry.fuente === 'evento';
        const color = TIPO_HISTORY_COLORS[entry.tipo] || 'neutral';
        const fuente = esEvento
            ? '<span class="badge badge-info">' + t('inventory.stock.history.event') + '</span>'
            : '<span class="badge badge-neutral">' + t('inventory.stock.history.movement') + '</span>';

        const meta = [];
        if (entry.estado_anterior || entry.estado_posterior) {
            meta.push(`${estadoLabel(entry.estado_anterior)} → ${estadoLabel(entry.estado_posterior)}`);
        }
        if (entry.cantidad_anterior !== null && entry.cantidad_anterior !== undefined
            && entry.cantidad_posterior !== null && entry.cantidad_posterior !== undefined) {
            meta.push(t('inventory.stock.history.cant', {
                from: formatNumber(entry.cantidad_anterior),
                to: formatNumber(entry.cantidad_posterior)
            }));
        }
        if (!esEvento) {
            const origen = entry.ubicacion_origen_codigo || 'SIN_UBICACION';
            const destino = entry.ubicacion_destino_codigo || '—';
            meta.push(`${origen} → ${destino}`);
            if (entry.actor_id) meta.push(t('inventory.stock.history.by', { actor: entry.actor_id }));
        }

        const correccion = entry.es_correccion
            ? '<span class="badge badge-warning" title="' + escapeHtml(entry.movimiento_referencia_id || '') + '">' + t('inventory.stock.history.correction') + '</span>'
            : '';

        const hash = entry.hash_integridad
            ? `<div class="text-[10px] text-muted font-mono mt-1" title="${escapeHtml(entry.hash_integridad)}">hash ${escapeHtml(String(entry.hash_integridad).substring(0, 16))}…</div>`
            : '';

        return `
            <li class="py-2 border-b border-default last:border-0">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        ${fuente}
                        <span class="badge badge-${color}">${escapeHtml(entry.tipo || '')}</span>
                        ${correccion}
                    </div>
                    <span class="text-xs text-muted font-mono">${escapeHtml(entry.timestamp || '')}</span>
                </div>
                <div class="text-sm mt-1">${escapeHtml(entry.descripcion || '')}</div>
                ${meta.length ? `<div class="text-xs text-muted mt-1">${escapeHtml(meta.join(' · '))}</div>` : ''}
                ${hash}
            </li>`;
    }

    function renderHistoryModal() {
        const root = container.querySelector('[data-modal-root]');
        const h = state.history;
        if (!root) return;
        if (!h) {
            root.innerHTML = '';
            return;
        }

        let body;
        if (h.loading) {
            body = '<div class="flex items-center justify-center py-8"><div class="spinner"></div></div>';
        } else if (h.data.length === 0) {
            body = `<div class="empty-state"><div class="empty-state-title">${t('inventory.stock.history.empty_title')}</div>`
                + `<p class="text-muted">${t('inventory.stock.history.empty_message')}</p></div>`;
        } else {
            body = `<ul class="list-none" data-history-list>${h.data.map(historyEntryHtml).join('')}</ul>`;
        }

        const total = Number(h.pagination.total || 0);
        const perPage = Number(h.pagination.per_page || 50);
        const page = Number(h.pagination.page || 1);
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        const unit = h.unidad || {};

        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('inventory.stock.history.title')}</h3>
                        <button class="modal-close" data-action="close-modal" aria-label="${t('ui.close')}">×</button>
                    </div>
                    <div class="modal-body">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <div>
                                <div class="font-mono text-sm">${escapeHtml(unit.codigo_barcode || '')}</div>
                                <div class="text-xs text-muted">${escapeHtml(unit.unidad_fisica_id || '')}</div>
                            </div>
                            <span class="badge badge-${estadoColor(unit.estado)}">${escapeHtml(estadoLabel(unit.estado))}</span>
                        </div>
                        ${body}
                    </div>
                    <div class="modal-footer">
                        <div class="text-xs text-muted mr-auto">${t('inventory.stock.history.footer_info', { total })}</div>
                        <button type="button" class="btn btn-secondary" data-action="history-prev" ${page <= 1 || h.loading ? 'disabled' : ''}>&lsaquo;</button>
                        <span class="text-xs px-2">${page} / ${totalPages}</span>
                        <button type="button" class="btn btn-secondary" data-action="history-next" ${!h.pagination.has_next || h.loading ? 'disabled' : ''}>&rsaquo;</button>
                        <button type="button" class="btn btn-primary" data-action="close-modal">${t('ui.close')}</button>
                    </div>
                </div>
            </div>`;
    }

    async function loadHistory(page = 1) {
        const h = state.history;
        if (!h) return;

        h.loading = true;
        renderHistoryModal();

        try {
            const response = await global.HttpClient.get(
                '/inventory/stock/' + encodeURIComponent(h.unidad.unidad_fisica_id) + '/history',
                { params: { page, per_page: 50 } }
            );
            const payload = response && response.data ? response.data : response;

            h.data = (payload && payload.data) || [];
            h.pagination = (payload && payload.pagination) || h.pagination;
            h.pagination.page = page;
            h.unidad = (payload && payload.unidad) || h.unidad;
        } catch (err) {
            h.data = [];

            if (err && err.status === 404) {
                showToast('danger', t('inventory.stock.messages.unit_not_found_title'), t('inventory.stock.messages.unit_not_found_message'));
                closeModal();
                return;
            }
            if (err && err.status === 403) {
                showToast('warning', t('ui.toast_forbidden'), t('inventory.stock.messages.history_forbidden'));
            } else {
                showToast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.stock.messages.history_load_error'));
            }
        } finally {
            h.loading = false;
            if (state.history === h) renderHistoryModal();
        }
    }

    function openHistory(unit) {
        state.history = {
            unidad: unit,
            data: [],
            pagination: { total: 0, page: 1, per_page: 50, has_next: false },
            loading: true
        };
        renderHistoryModal();
        loadHistory(1);
    }

    // ====================================================================
    // BLOQUEAR / DESBLOQUEAR (E4)
    // ====================================================================

    async function toggleBlock(unit, accion) {
        if (state.blocking) return;

        const esBloquear = accion === 'BLOQUEAR';
        const confirmed = global.confirm(esBloquear
            ? t('inventory.stock.messages.confirm_block')
            : t('inventory.stock.messages.confirm_unblock'));
        if (!confirmed) return;

        state.blocking = true;

        try {
            const response = await global.HttpClient.post(
                '/inventory/stock/' + encodeURIComponent(unit.unidad_fisica_id)
                    + (esBloquear ? '/block' : '/unblock'),
                { accion },
                { headers: { 'Idempotency-Key': newIdempotencyKey() } }
            );
            const body = response && response.data ? response.data : response;
            const estado = body && body.estado ? body.estado : (esBloquear ? 'BLOQUEADO' : 'DISPONIBLE');

            const recargadoLista = await loadStock(state.pagination.page || 1);
            const recargadoCatalogos = await loadCatalogs();
            if (recargadoLista && recargadoCatalogos) {
                showToast('success',
                    esBloquear ? t('inventory.stock.messages.blocked_title') : t('inventory.stock.messages.unblocked_title'),
                    t('inventory.stock.messages.state_with', { estado: estadoLabel(estado) }));
            }
        } catch (err) {
            handleActionError(err, esBloquear
                ? t('inventory.stock.messages.block_error')
                : t('inventory.stock.messages.unblock_error'));

            if (err && (err.status === 422 || err.status === 404)) {
                // Estado ya cambiado o unidad fuera: refrescar la lista.
                await loadStock(state.pagination.page || 1);
            }
        } finally {
            state.blocking = false;
        }
    }

    function showFormError(message) {
        const el = container && container.querySelector('[data-form-error]');
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
        if (!state.modal) return;
        state.modal.sending = sending;
        const type = state.modal.type;
        const btn = container.querySelector(`[data-action="submit-${type === 'move' ? 'move' : 'reserve'}"]`);
        if (!btn) return;
        btn.disabled = sending;
        const label = type === 'move' ? t('inventory.stock.actions.move') : t('inventory.stock.actions.reserve');
        btn.textContent = sending ? t('inventory.stock.messages.sending') : label;
    }

    // ====================================================================
    // ENVIOS (movimiento / reserva)
    // ====================================================================

    function readMoveForm(form) {
        const raw = Object.fromEntries(new FormData(form));
        const payload = {
            unidad_fisica_id: state.modal.unit.unidad_fisica_id,
            ubicacion_destino_id: (raw.ubicacion_destino_id || '').trim()
        };

        const motivo = (raw.motivo || '').trim();
        if (motivo !== '') payload.motivo = motivo;

        const referencia = (raw.referencia_externa || '').trim();
        if (referencia !== '') payload.referencia_externa = referencia;

        return payload;
    }

    function readReserveForm(form) {
        const raw = Object.fromEntries(new FormData(form));
        const payload = { unidad_fisica_id: state.modal.unit.unidad_fisica_id };

        const cantidad = (raw.cantidad || '').trim();
        if (cantidad !== '') payload.cantidad = cantidad;

        const expiracion = (raw.fecha_expiracion || '').trim();
        if (expiracion !== '') payload.fecha_expiracion = expiracion;

        const referencia = (raw.referencia_orden || '').trim();
        if (referencia !== '') payload.referencia_orden = referencia;

        return payload;
    }

    function validateMove(payload) {
        if (!payload.ubicacion_destino_id) {
            return { field: 'ubicacion_destino_id', message: t('inventory.stock.messages.destination_required') };
        }
        if (payload.ubicacion_destino_id === state.modal.unit.ubicacion_id) {
            return { field: 'ubicacion_destino_id', message: t('inventory.stock.messages.destination_same') };
        }
        return null;
    }

    function validateReserve(payload) {
        const unit = state.modal.unit;

        if (payload.cantidad !== undefined) {
            const cantidad = parseFloat(payload.cantidad);
            if (!isFinite(cantidad) || cantidad <= 0) {
                return { field: 'cantidad', message: t('inventory.stock.messages.quantity_positive') };
            }
            if (cantidad > parseFloat(unit.cantidad_disponible)) {
                return {
                    field: 'cantidad',
                    message: t('inventory.stock.messages.quantity_exceeds', { available: formatNumber(unit.cantidad_disponible) })
                };
            }
        }

        if (payload.fecha_expiracion) {
            const expira = new Date(payload.fecha_expiracion);
            if (isNaN(expira.getTime())) {
                return { field: 'fecha_expiracion', message: t('inventory.stock.messages.expiration_invalid') };
            }
            if (expira.getTime() <= Date.now()) {
                return { field: 'fecha_expiracion', message: t('inventory.stock.messages.expiration_future') };
            }
        }

        return null;
    }

    async function submitMove(form) {
        if (!state.modal || state.modal.sending) return;

        clearErrors();

        const payload = readMoveForm(form);
        const invalid = validateMove(payload);
        if (invalid) {
            showFieldError(invalid.field, invalid.message);
            return;
        }

        setSending(true);

        try {
            const response = await global.HttpClient.post('/inventory/movements', payload, {
                headers: { 'Idempotency-Key': state.modal.idempotencyKey }
            });
            const body = response && response.data ? response.data : response;
            const destino = state.locations.find(l => l.id === payload.ubicacion_destino_id);
            const esCorreccion = !!(body && body.movimiento && body.movimiento.es_correccion);
            closeModal();

            const recargadoLista = await loadStock(state.pagination.page || 1);
            const recargadoCatalogos = await loadCatalogs();
            if (recargadoLista && recargadoCatalogos) {
                showToast('success', t('inventory.stock.messages.moved_title'),
                    t('inventory.stock.messages.moved_message', { destino: destino ? destino.codigo : '' })
                    + (esCorreccion ? '  → ' + t('inventory.stock.messages.moved_correction') : ''));
            }

            // v1.10.0 (CU-002): avisos informativos del backend (no bloquean).
            const warnings = (body && Array.isArray(body.warnings)) ? body.warnings : [];
            warnings.forEach(w => {
                showToast('warning', t('inventory.stock.messages.warning_title'), (w && w.mensaje) || t('inventory.stock.messages.reserved_warning'));
            });
        } catch (err) {
            handleActionError(err, t('inventory.stock.messages.move_error'));
        } finally {
            setSending(false);
        }
    }

    async function submitReserve(form) {
        if (!state.modal || state.modal.sending) return;

        clearErrors();

        const payload = readReserveForm(form);
        const invalid = validateReserve(payload);
        if (invalid) {
            showFieldError(invalid.field, invalid.message);
            return;
        }

        setSending(true);

        try {
            await global.HttpClient.post('/inventory/reservations', payload, {
                headers: { 'Idempotency-Key': state.modal.idempotencyKey }
            });

            const barcode = state.modal.unit.codigo_barcode;
            closeModal();
            const recargado = await loadStock(state.pagination.page || 1);
            if (recargado) {
                showToast('success', t('inventory.stock.messages.reserve_created_title'),
                    t('inventory.stock.messages.reserve_created_message', { barcode }));
            }
        } catch (err) {
            handleActionError(err, t('inventory.stock.messages.reserve_error'));
        } finally {
            setSending(false);
        }
    }

    // 400/401/403/404/409/422 → toast (y error de campo cuando trae `field`).
    function handleActionError(err, fallbackMessage) {
        const status = err.status;
        const field = err.data && err.data.field ? err.data.field : null;
        const message = err.message || fallbackMessage;

        console.error('[Stock] action error:', status, message);

        showFormError(message);
        if (field) showFieldError(field, message);

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
                // Clave ya usada con otra carga útil: queda inservible para
                // este envío, se renueva para permitir el reintento.
                if (state.modal) {
                    state.modal.idempotencyKey = newIdempotencyKey();
                    renderKeyHint(container.querySelector('[data-modal-root]'));
                }
                showToast('warning', t('ui.toast_conflict'), message);
                break;
            case 422:
                showToast('danger', field === 'capacidad' ? t('inventory.stock.messages.capacity_exceeded') : t('ui.toast_rejected'), message);
                break;
            default:
                showToast('danger', t('ui.error_generic'), message);
        }
    }

    // ====================================================================
    // CATALOGOS (para los filtros)
    // ====================================================================

    async function loadCatalogs() {
        try {
            const [productsRes, locationsRes] = await Promise.all([
                global.HttpClient.get('/inventory/products', { params: { page: 1, per_page: 100 } }),
                global.HttpClient.get('/inventory/locations', { params: { page: 1, per_page: 100 } })
            ]);

            const productsPayload = productsRes.data || productsRes;
            const locationsPayload = locationsRes.data || locationsRes;

            state.products = productsPayload.data || [];
            state.locations = locationsPayload.data || [];
            renderFilters();
            return true;
        } catch (err) {
            console.error('[Stock] catalog load error:', err);
            return false;
        }
    }

    // ====================================================================
    // API
    // ====================================================================

    async function loadStock(page = 1) {
        state.loading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            Object.entries(state.filters).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) params[k] = v;
            });

            const response = await global.HttpClient.get('/inventory/stock', { params });
            const payload = response.data || response;

            state.items = payload.data || [];
            state.summary = payload.summary || state.summary;
            state.pagination = payload.pagination || state.pagination;
            state.pagination.page = page;
            return true;
        } catch (err) {
            console.error('[Stock] load error:', err);
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.stock.messages.stock_load_error'));
            state.items = [];
            return false;
        } finally {
            state.loading = false;
            renderSummary();
            renderTable();
            renderPagination();
        }
    }

    // ====================================================================
    // EVENTS
    // ====================================================================

    function bindEvents() {
        if (!container) return;

        container.removeEventListener('click', handleClick);
        container.removeEventListener('submit', handleSubmit);
        container.addEventListener('click', handleClick);
        container.addEventListener('submit', handleSubmit);
    }

    function handleClick(e) {
        const btn = e.target.closest('[data-action], [data-page]');
        if (!btn) return;

        const action = btn.dataset.action;
        const id = btn.dataset.id;
        const page = btn.dataset.page;

        switch (action) {
            case 'apply-filters':
                readFilters();
                loadStock(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
            case 'move': {
                const unit = state.items.find(u => u.unidad_fisica_id === id);
                if (unit) openMoveModal(unit);
                break;
            }
            case 'reserve': {
                const unit = state.items.find(u => u.unidad_fisica_id === id);
                if (unit) openReserveModal(unit);
                break;
            }
            case 'history': {
                const unit = state.items.find(u => u.unidad_fisica_id === id);
                if (unit) openHistory(unit);
                break;
            }
            case 'block': {
                const unit = state.items.find(u => u.unidad_fisica_id === id);
                if (unit) toggleBlock(unit, 'BLOQUEAR');
                break;
            }
            case 'unblock': {
                const unit = state.items.find(u => u.unidad_fisica_id === id);
                if (unit) toggleBlock(unit, 'DESBLOQUEAR');
                break;
            }
            case 'history-prev':
                if (state.history) loadHistory(Math.max(1, Number(state.history.pagination.page || 1) - 1));
                break;
            case 'history-next':
                if (state.history) loadHistory(Number(state.history.pagination.page || 1) + 1);
                break;
            case 'close-modal':
                closeModal();
                break;
        }

        if (page) {
            loadStock(parseInt(page, 10));
        }
    }

    function handleSubmit(e) {
        if (e.target.matches('[data-move-form]')) {
            e.preventDefault();
            submitMove(e.target);
            return;
        }
        if (e.target.matches('[data-reserve-form]')) {
            e.preventDefault();
            submitReserve(e.target);
        }
    }

    function readFilters() {
        container.querySelectorAll('[data-filter]').forEach(el => {
            state.filters[el.dataset.filter] = el.value;
        });
    }

    function clearFilters() {
        state.filters = { producto_id: '', ubicacion_id: '', estado: '' };
        container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
        loadStock(1);
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

    function showToast(type, title, message = '') {
        if (global.EventBus) {
            global.EventBus.emit('toast:show', { type, title, message, duration: 3000 });
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const InventoryStockModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            container.innerHTML = TEMPLATE;
            bindEvents();
            renderSummary();
            renderFilters();
            loadStock(1);
            loadCatalogs();
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            if (container && !container.querySelector('[data-stock-table]')) {
                container.innerHTML = TEMPLATE;
                bindEvents();
            }
            loadStock(state.pagination.page || 1);
            loadCatalogs();
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('submit', handleSubmit);
                container.innerHTML = '';
            }
            container = null;
            state = {
                items: [],
                summary: { unidades: 0, cantidad_fisica: 0, cantidad_reservada: 0, cantidad_en_transito: 0, cantidad_disponible: 0 },
                pagination: { total: 0, page: 1, per_page: 20, has_next: false },
                filters: { producto_id: '', ubicacion_id: '', estado: '' },
                products: [],
                locations: [],
                loading: false,
                modal: null,
                history: null,
                blocking: false
            };
        }
    };

    global.InventoryStockModule = InventoryStockModule;

})(window);
