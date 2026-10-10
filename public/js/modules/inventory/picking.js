/**
 * Inventory Picking Module
 * Pantalla "Picking" (CU-004) - v1.7.1.
 *
 * Convención ModuleLoader: window.InventoryPickingModule
 *
 * Dos bloques:
 *  1. Nuevo lote: reservas pendientes (`GET /api/inventory/reservations`
 *     con estado=ACTIVA) con selección múltiple y escaneo de barcode por
 *     Enter; se inicia con `POST /api/inventory/picking/start` (header
 *     Idempotency-Key obligatorio, spec R7).
 *  2. Operaciones de picking (`GET /api/inventory/picking`) agrupadas por
 *     lote, con Completar / Verificar según estado y permiso.
 *
 * Permisos (routes.php):
 *  - inventory.picking.view    → lectura
 *  - inventory.picking.create  → iniciar lote
 *  - inventory.picking.confirm → completar lote
 *  - inventory.picking.verify  → verificar lote
 *
 * Idempotencia (mismo patrón que stock.js): la clave se genera al montar el
 * formulario, se REUTILIZA en cada reintento del mismo envío y se renueva
 * tras un éxito y en 409 (clave ya quemada con otra carga útil).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    let state = {
        reservations: [],
        reservationSummary: { total: 0, activas: 0, con_expiracion: 0 },
        reservationPagination: { total: 0, page: 1, per_page: 100, has_next: false },
        reservationsLoading: false,
        pickings: [],
        pickingSummary: { total: 0, en_curso: 0, completadas: 0, verificadas: 0 },
        pickingPagination: { total: 0, page: 1, per_page: 20, has_next: false },
        pickingFilters: { estado: '', id_lote_picking: '' },
        pickingLoading: false,
        locations: [],
        selected: new Set(),
        expanded: new Set(),
        startKey: '',
        loteKeys: {},
        sending: false
    };

    const ESTADOS_PICKING = [
        { value: 'PENDIENTE', labelKey: 'inventory.picking.states.pendiente', color: 'neutral' },
        { value: 'EN_CURSO', labelKey: 'inventory.picking.states.en_curso', color: 'warning' },
        { value: 'COMPLETADA', labelKey: 'inventory.picking.states.completada', color: 'info' },
        { value: 'VERIFICADA', labelKey: 'inventory.picking.states.verificada', color: 'success' }
    ];

    const ORDEN_ESTADOS = ESTADOS_PICKING.map(e => e.value);

    const estadoColor = (v) => {
        const e = ESTADOS_PICKING.find(x => x.value === v);
        return e ? e.color : 'neutral';
    };

    const estadoLabel = (v) => {
        const e = ESTADOS_PICKING.find(x => x.value === v);
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

    const canView = () => can('inventory.picking.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
    const canCreate = () => can('inventory.picking.create', ['ADMIN', 'MANAGER', 'USER']);
    const canConfirm = () => can('inventory.picking.confirm', ['ADMIN', 'MANAGER', 'USER']);
    const canVerify = () => can('inventory.picking.verify', ['ADMIN', 'MANAGER']);

    const TEMPLATE = `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.picking.title'),
                subtitle: t('inventory.picking.subtitle')
            })}

            <div data-start-section></div>

            <div class="flex items-center justify-between mt-6 mb-3">
                <h3 class="text-lg font-bold">${t('inventory.picking.operations_title')}</h3>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4" data-picking-summary></div>

            <div class="card mb-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="form-group mb-0">
                        <select class="select" data-picking-filter="estado">
                            <option value="">${t('inventory.picking.filters.all_states')}</option>
                            ${ESTADOS_PICKING.map(e => `<option value="${e.value}">${t(e.labelKey)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <input type="text" class="input" data-picking-filter="id_lote_picking"
                               placeholder="${t('inventory.picking.filters.search_placeholder')}">
                    </div>
                    <div class="form-group mb-0 flex gap-2 md:col-span-2">
                        <button class="btn btn-secondary flex-1" data-action="apply-picking-filters">${t('inventory.picking.filters.apply')}</button>
                        <button class="btn btn-ghost" data-action="clear-picking-filters" title="${t('inventory.picking.filters.clear_title')}">${t('inventory.picking.filters.clear')}</button>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="table-wrapper" data-picking-table></div>
                <div data-picking-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>
    `;

    const START_TEMPLATE = `
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">${t('inventory.picking.start.title')}</h3>
            </div>
            <form data-start-form novalidate>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="form-group">
                        <label class="form-label">${t('inventory.picking.start.lote_label')} <span class="required">*</span></label>
                        <div class="flex gap-2">
                            <input type="text" class="input" name="id_lote_picking" maxlength="100"
                                   placeholder="LOTE-0001" autocomplete="off">
                            <button type="button" class="btn btn-ghost" data-action="regenerate-lote"
                                    title="${t('inventory.picking.start.generate_title')}">${t('inventory.picking.start.generate')}</button>
                        </div>
                        <span class="form-hint">${t('inventory.picking.start.lote_hint')}</span>
                        <span class="form-error hidden" data-error="id_lote_picking"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">${t('inventory.picking.start.location_label')} <span class="required">*</span></label>
                        <select class="select" name="ubicacion_preparacion_id" data-picking-locations required>
                            <option value="">${t('inventory.picking.start.location_loading')}</option>
                        </select>
                        <span class="form-hint">${t('inventory.picking.start.location_hint')}</span>
                        <span class="form-error hidden" data-error="ubicacion_preparacion_id"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">${t('inventory.picking.start.order_label')}</label>
                        <input type="text" class="input" name="referencia_orden" maxlength="255"
                               placeholder="${t('inventory.picking.start.order_placeholder')}" autocomplete="off">
                        <span class="form-error hidden" data-error="referencia_orden"></span>
                    </div>
                    <div class="form-group md:col-span-3">
                        <label class="form-label">${t('inventory.picking.start.scan_label')}</label>
                        <input type="text" class="input" data-scan autocomplete="off"
                               placeholder="${t('inventory.picking.start.scan_placeholder')}">
                        <span class="form-hint">${t('inventory.picking.start.scan_hint')}</span>
                        <span class="form-error hidden" data-error="scan"></span>
                    </div>
                </div>

                <div class="flex items-center justify-between mt-4 mb-2">
                    <div class="text-sm text-muted">
                        <span data-selection-count>0</span> ${t('inventory.picking.start.selection_count')}
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-ghost btn-sm" data-action="select-all">${t('inventory.picking.start.select_all')}</button>
                        <button type="button" class="btn btn-ghost btn-sm" data-action="clear-selection">${t('inventory.picking.start.clear_selection')}</button>
                    </div>
                </div>

                <div class="card p-0 table-wrapper" data-res-table></div>

                <div data-form-error class="alert alert-danger hidden mt-4"></div>

                <div class="flex items-center justify-between mt-4">
                    <div class="text-xs text-muted" data-key-hint></div>
                    <button type="submit" class="btn btn-primary" data-action="submit-start">${t('inventory.picking.start.submit')}</button>
                </div>
            </form>
        </div>
    `;

    const DENIED_TEMPLATE = `
        <div class="card max-w-md mx-auto mt-8">
            <div class="card-header">
                <h3 class="card-title">${t('inventory.picking.permission.title')}</h3>
            </div>
            <div class="card-body">
                <p>${t('inventory.picking.permission.body', { permiso: 'inventory.picking.view' })}</p>
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

    function renderKeyHint(root) {
        const scope = root || container;
        const el = scope && scope.querySelector('[data-key-hint]');
        if (!el) return;
        el.innerHTML = `Idempotency-Key: <span class="font-mono">${escapeHtml(state.startKey)}</span>`;
    }

    function loteKey(lote) {
        if (!state.loteKeys[lote]) state.loteKeys[lote] = newIdempotencyKey();
        return state.loteKeys[lote];
    }

    // ====================================================================
    // RENDER - SECCIÓN DE ARRANQUE
    // ====================================================================

    function renderPermissionDenied() {
        container.innerHTML = DENIED_TEMPLATE;
    }

    function renderStartSection() {
        const root = container.querySelector('[data-start-section]');
        if (!root) return;

        if (!canCreate()) {
            root.innerHTML = '';
            return;
        }

        root.innerHTML = START_TEMPLATE;
        renderLocationOptions();
        renderReservations();
        renderKeyHint(root);
    }

    function renderLocationOptions() {
        const select = container.querySelector('[data-picking-locations]');
        if (!select) return;

        // Sólo zonas activas (el filtro del backend puede no aplicarse si la
        // ubicación se desactivó después de cargar el catálogo).
        const activas = state.locations.filter(l =>
            l.activa === undefined || l.activa === null
            || l.activa === 1 || l.activa === true
        );

        if (activas.length === 0) {
            select.innerHTML = '<option value="">' + t('inventory.picking.start.location_empty') + '</option>';
            return;
        }

        select.innerHTML = '<option value="">' + t('inventory.picking.start.location_select') + '</option>' +
            activas.map(l => {
                const capacidad = (l.capacidad === null || l.capacidad === undefined)
                    ? t('inventory.picking.start.location_no_limit')
                    : `${l.capacidad_usada || 0}/${l.capacidad}`;
                return `<option value="${escapeHtml(l.id)}">${escapeHtml(l.codigo)} (${escapeHtml(capacidad)})</option>`;
            }).join('');
    }

    function renderSelectionCount() {
        const el = container.querySelector('[data-selection-count]');
        if (el) el.textContent = String(state.selected.size);
    }

    function renderReservations() {
        const root = container.querySelector('[data-res-table]');
        if (!root) return;

        if (state.reservationsLoading) {
            root.innerHTML = `
                <div class="flex items-center justify-center py-8">
                    <div class="spinner"></div>
                </div>`;
            return;
        }

        if (state.reservations.length === 0) {
            root.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-title">${t('inventory.picking.empty.reservations_title')}</div>
                    <p class="text-muted">${t('inventory.picking.empty.reservations_message')}</p>
                </div>`;
            renderSelectionCount();
            return;
        }

        const rows = state.reservations.map(r => {
            const checked = state.selected.has(r.stock_unit_id) ? 'checked' : '';
            return `
                <tr data-row-id="${escapeHtml(r.stock_unit_id)}">
                    <td>
                        <input type="checkbox" data-action="toggle-select"
                               data-id="${escapeHtml(r.stock_unit_id)}" ${checked}>
                    </td>
                    <td class="font-mono text-xs">${escapeHtml(r.barcode)}</td>
                    <td>
                        <div class="font-medium">${escapeHtml(r.producto)}</div>
                        <div class="text-xs text-muted font-mono">${escapeHtml(r.ubicacion)}</div>
                    </td>
                    <td class="text-right font-medium">${formatNumber(r.cantidad)}</td>
                    <td class="text-xs">${r.fecha_expiracion
                        ? `<span class="${expiryIsPast(r) ? 'text-danger font-medium' : ''}"
                               title="${escapeHtml(r.fecha_expiracion)}">${escapeHtml(r.fecha_expiracion)}</span>`
                        : '<span class="text-muted">—</span>'}</td>
                </tr>
            `;
        }).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:36px"></th>
                        <th>${t('inventory.picking.columns.barcode')}</th>
                        <th>${t('inventory.picking.columns.producto')}</th>
                        <th class="text-right">${t('inventory.picking.columns.reservada')}</th>
                        <th>${t('inventory.picking.columns.vencimiento')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
        renderSelectionCount();
    }

    function expiryIsPast(r) {
        if (r.estado !== 'ACTIVA' || !r.fecha_expiracion) return false;
        const ts = Date.parse(String(r.fecha_expiracion).replace(' ', 'T'));
        return isFinite(ts) && ts < Date.now();
    }

    // ====================================================================
    // RENDER - OPERACIONES
    // ====================================================================

    function renderSummary() {
        const root = container.querySelector('[data-picking-summary]');
        if (!root) return;

        const s = state.pickingSummary;
        const cards = [
            { label: t('inventory.picking.summary.operaciones'), value: s.total },
            { label: t('inventory.picking.summary.en_curso'), value: s.en_curso },
            { label: t('inventory.picking.summary.completadas'), value: s.completadas },
            { label: t('inventory.picking.summary.verificadas'), value: s.verificadas }
        ];

        root.innerHTML = cards.map(c => `
            <div class="card p-3">
                <div class="text-xs text-muted">${c.label}</div>
                <div class="text-xl font-bold">${formatNumber(c.value)}</div>
            </div>
        `).join('');
    }

    function groupByLote(rows) {
        const order = new Map();
        rows.forEach(r => {
            const lote = r.id_lote_picking;
            if (!order.has(lote)) {
                order.set(lote, {
                    lote,
                    referencia_orden: r.referencia_orden,
                    selector: r.selector,
                    supervisor: r.supervisor,
                    fecha_creacion: r.fecha_creacion,
                    estados: new Set(),
                    rows: []
                });
            }
            const grupo = order.get(lote);
            grupo.rows.push(r);
            grupo.estados.add(r.estado);
            if (!grupo.referencia_orden && r.referencia_orden) grupo.referencia_orden = r.referencia_orden;
            if (r.supervisor) grupo.supervisor = r.supervisor;
        });

        return Array.from(order.values()).map(g => ({
            ...g,
            estado: derivedEstado(g.estados),
            unidades: g.rows.length
        }));
    }

    // Un lote comparte estado en todas sus filas; si alguien mezclara filas,
    // se toma el estado MÁS AVANZADO del orden de la máquina de estados.
    function derivedEstado(estados) {
        let mejor = null;
        let mejorIdx = -1;
        estados.forEach(e => {
            const idx = ORDEN_ESTADOS.indexOf(e);
            if (idx > mejorIdx) {
                mejorIdx = idx;
                mejor = e;
            }
        });
        return mejor;
    }

    function renderTable() {
        const root = container.querySelector('[data-picking-table]');
        if (!root) return;

        if (state.pickingLoading) {
            root.innerHTML = `
                <div class="flex items-center justify-center py-12">
                    <div class="spinner"></div>
                </div>`;
            return;
        }

        if (state.pickings.length === 0) {
            root.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-title">${t('inventory.picking.empty.title')}</div>
                    <p class="text-muted">${t('inventory.picking.empty.message')}</p>
                </div>`;
            return;
        }

        const lotes = groupByLote(state.pickings);

        const rows = lotes.map(g => {
            const open = state.expanded.has(g.lote);
            const main = `
                <tr>
                    <td class="font-mono text-xs">${escapeHtml(g.lote)}</td>
                    <td class="text-right">${g.unidades}</td>
                    <td><span class="badge badge-${estadoColor(g.estado)}">${escapeHtml(estadoLabel(g.estado))}</span></td>
                    <td class="text-xs text-muted">${g.referencia_orden ? escapeHtml(g.referencia_orden) : '—'}</td>
                    <td class="text-xs">${escapeHtml(g.selector || '—')}</td>
                    <td class="text-xs">${escapeHtml(g.supervisor || '—')}</td>
                    <td class="text-xs">${escapeHtml(g.fecha_creacion || '—')}</td>
                    <td class="table-actions col-actions">
                        <button class="btn btn-ghost btn-sm" data-action="expand-lote"
                                data-lote="${escapeHtml(g.lote)}">${open ? t('inventory.picking.actions.collapse') : t('inventory.picking.actions.expand')}</button>
                        ${renderLoteActions(g)}
                    </td>
                </tr>
            `;

            if (!open) return main;

            const detalle = g.rows.map(r => `
                <tr>
                    <td></td>
                    <td class="font-mono text-xs">${escapeHtml(r.barcode)}</td>
                    <td colspan="2">
                        <div class="font-medium">${escapeHtml(r.producto)}</div>
                        <div class="text-xs text-muted">${t('inventory.picking.detail.route', { origen: escapeHtml(r.ubicacion_origen || '—'), destino: escapeHtml(r.ubicacion_preparacion || '—') })}</div>
                    </td>
                    <td class="text-right font-medium">${formatNumber(r.cantidad)}</td>
                    <td><span class="badge badge-${estadoColor(r.estado)}">${escapeHtml(estadoLabel(r.estado))}</span></td>
                    <td class="text-xs">${escapeHtml(r.fecha_creacion || '—')}</td>
                    <td></td>
                </tr>
            `).join('');

            return main + detalle;
        }).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('inventory.picking.columns.lote')}</th>
                        <th class="text-right">${t('inventory.picking.columns.unidades')}</th>
                        <th>${t('inventory.picking.columns.estado')}</th>
                        <th>${t('inventory.picking.columns.referencia')}</th>
                        <th>${t('inventory.picking.columns.selector')}</th>
                        <th>${t('inventory.picking.columns.supervisor')}</th>
                        <th>${t('inventory.picking.columns.creado')}</th>
                        <th class="col-actions">${t('inventory.picking.columns.acciones')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function renderLoteActions(g) {
        const buttons = [];

        if (canConfirm() && g.estado === 'EN_CURSO') {
            buttons.push(`<button class="btn btn-ghost btn-sm" data-action="complete-lote"
                data-lote="${escapeHtml(g.lote)}">${t('inventory.picking.actions.complete')}</button>`);
        }
        if (canVerify() && g.estado === 'COMPLETADA') {
            buttons.push(`<button class="btn btn-ghost btn-sm" data-action="verify-lote"
                data-lote="${escapeHtml(g.lote)}">${t('inventory.picking.actions.verify')}</button>`);
        }

        return buttons.length > 0 ? buttons.join('') : '<span class="text-xs text-muted">—</span>';
    }

    function renderPagination() {
        const root = container.querySelector('[data-picking-pagination]');
        if (!root) return;

        const { total, page, per_page, has_next } = state.pickingPagination;
        const totalPages = Math.max(1, Math.ceil(total / per_page));

        root.innerHTML = `
            <div class="pagination-info">
                ${t('ui.pagination', { shown: state.pickings.length, total: total, label: t('inventory.picking.pagination_label') })}
            </div>
            <div class="pagination-controls">
                <button class="page-btn" data-page-picking="${page - 1}" ${page <= 1 ? 'disabled' : ''}>‹</button>
                <span class="page-btn is-active">${page} / ${totalPages}</span>
                <button class="page-btn" data-page-picking="${page + 1}" ${!has_next ? 'disabled' : ''}>›</button>
            </div>
        `;
    }

    function renderAll() {
        renderSummary();
        renderTable();
        renderPagination();
    }

    // ====================================================================
    // API
    // ====================================================================

    async function loadCatalogs() {
        try {
            const response = await global.HttpClient.get('/inventory/locations', {
                params: { tipo: 'PICKING', activa: '1', page: 1, per_page: 100 }
            });
            const payload = response.data || response;
            state.locations = payload.data || [];
            renderLocationOptions();
        } catch (err) {
            console.error('[Picking] catalog load error:', err);
            state.locations = [];
            renderLocationOptions();
            if (err && err.status === 403) {
                showToast('warning', t('inventory.picking.permission.title'), t('inventory.picking.messages.forbidden_locations', { permiso: 'inventory.location.view' }));
            }
        }
    }

    async function loadReservations() {
        state.reservationsLoading = true;
        renderReservations();

        try {
            const response = await global.HttpClient.get('/inventory/reservations', {
                params: { estado: 'ACTIVA', page: 1, per_page: 100 }
            });
            const payload = response.data || response;

            state.reservations = payload.data || [];
            state.reservationSummary = payload.summary || state.reservationSummary;
            state.reservationPagination = payload.pagination || state.reservationPagination;

            // Descarta de la selección lo que ya no está activo.
            const vivas = new Set(state.reservations.map(r => r.stock_unit_id));
            state.selected.forEach(id => {
                if (!vivas.has(id)) state.selected.delete(id);
            });
        } catch (err) {
            console.error('[Picking] reservations load error:', err);
            state.reservations = [];
            if (err && err.status === 403) {
                showToast('warning', t('inventory.picking.permission.title'), t('inventory.picking.messages.forbidden_reservations', { permiso: 'inventory.reservation.view' }));
            } else {
                showToast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.picking.messages.reservations_load_error'));
            }
        } finally {
            state.reservationsLoading = false;
            renderReservations();
        }
    }

    async function loadPickings(page = 1) {
        state.pickingLoading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pickingPagination.per_page };
            Object.entries(state.pickingFilters).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) params[k] = v;
            });

            const response = await global.HttpClient.get('/inventory/picking', { params });
            const payload = response.data || response;

            state.pickings = payload.data || [];
            state.pickingSummary = payload.summary || state.pickingSummary;
            state.pickingPagination = payload.pagination || state.pickingPagination;
            state.pickingPagination.page = page;
        } catch (err) {
            console.error('[Picking] load error:', err);
            state.pickings = [];
            if (err && err.status === 403) {
                showToast('danger', t('inventory.picking.permission.title'), t('inventory.picking.messages.forbidden_view', { permiso: 'inventory.picking.view' }));
            } else {
                showToast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.picking.messages.pickings_load_error'));
            }
        } finally {
            state.pickingLoading = false;
            renderAll();
        }
    }

    function generateLote() {
        const fecha = new Date();
        const stamp = [
            fecha.getFullYear(),
            String(fecha.getMonth() + 1).padStart(2, '0'),
            String(fecha.getDate()).padStart(2, '0')
        ].join('');
        const suffix = fallbackKey().slice(0, 6).toUpperCase();
        return `LOTE-${stamp}-${suffix}`;
    }

    function readStartForm(form) {
        const raw = Object.fromEntries(new FormData(form));
        const payload = {
            id_lote_picking: (raw.id_lote_picking || '').trim(),
            ubicacion_preparacion_id: (raw.ubicacion_preparacion_id || '').trim(),
            unidades: Array.from(state.selected).map(id => ({ unidad_fisica_id: id }))
        };

        const referencia = (raw.referencia_orden || '').trim();
        if (referencia !== '') payload.referencia_orden = referencia;

        return payload;
    }

    function validateStart(payload) {
        if (!payload.id_lote_picking) {
            return { field: 'id_lote_picking', message: t('inventory.picking.messages.error_lote_required') };
        }
        if (!payload.ubicacion_preparacion_id) {
            return { field: 'ubicacion_preparacion_id', message: t('inventory.picking.messages.error_location_required') };
        }
        if (payload.unidades.length === 0) {
            return { field: 'scan', message: t('inventory.picking.messages.error_units_required') };
        }
        return null;
    }

    async function submitStart(form) {
        if (state.sending) return;

        clearErrors();

        const payload = readStartForm(form);
        const invalid = validateStart(payload);
        if (invalid) {
            showFieldError(invalid.field, invalid.message);
            return;
        }

        setSending(true);

        try {
            const response = await global.HttpClient.post('/inventory/picking/start', payload, {
                headers: { 'Idempotency-Key': state.startKey }
            });
            const body = response.data || response;
            const lote = (body && body.id_lote_picking) || payload.id_lote_picking;
            const unidades = (body && body.unidades && body.unidades.length) || payload.unidades.length;

            state.selected.clear();
            state.startKey = newIdempotencyKey();

            showToast('success', t('inventory.picking.messages.started_title'), t('inventory.picking.messages.started_message', { lote: lote, unidades: unidades }));

            form.reset();
            renderKeyHint(container.querySelector('[data-start-section]'));
            await Promise.all([loadReservations(), loadPickings(1)]);
        } catch (err) {
            handleActionError(err, t('inventory.picking.messages.start_error'), () => {
                // 409: clave ya usada con otra carga útil → se renueva.
                state.startKey = newIdempotencyKey();
                renderKeyHint(container.querySelector('[data-start-section]'));
            }, true);
        } finally {
            setSending(false);
        }
    }

    async function completeLote(lote) {
        try {
            const response = await global.HttpClient.post(
                `/inventory/picking/${encodeURIComponent(lote)}/complete`,
                {},
                { headers: { 'Idempotency-Key': loteKey(lote) } }
            );
            const body = response.data || response;
            showToast('success', t('inventory.picking.messages.completed_title'), (body && body.mensaje) || t('inventory.picking.messages.lote_message', { lote: lote }));
            delete state.loteKeys[lote];
            await loadPickings(state.pickingPagination.page || 1);
        } catch (err) {
            handleActionError(err, t('inventory.picking.messages.complete_error'), () => {
                state.loteKeys[lote] = newIdempotencyKey();
            }, true);
        }
    }

    async function verifyLote(lote) {
        try {
            const response = await global.HttpClient.post(
                `/inventory/picking/${encodeURIComponent(lote)}/verify`,
                {},
                { headers: { 'Idempotency-Key': loteKey(lote) } }
            );
            const body = response.data || response;
            showToast('success', t('inventory.picking.messages.verified_title'), (body && body.mensaje) || t('inventory.picking.messages.lote_message', { lote: lote }));
            delete state.loteKeys[lote];
            await loadPickings(state.pickingPagination.page || 1);
        } catch (err) {
            handleActionError(err, t('inventory.picking.messages.verify_error'), () => {
                state.loteKeys[lote] = newIdempotencyKey();
            }, true);
        }
    }

    // 400/401/403/404/409/422 → toast (y error de campo cuando trae `field`).
    function handleActionError(err, fallbackMessage, onConflict, inline) {
        const status = err.status;
        const field = err.data && err.data.field ? err.data.field : null;
        const message = err.message || fallbackMessage;

        console.error('[Picking] action error:', status, message);

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
                if (inline) loadPickings(state.pickingPagination.page || 1);
                break;
            case 409:
                if (typeof onConflict === 'function') onConflict();
                showToast('warning', t('ui.toast_conflict'), message);
                if (inline) loadPickings(state.pickingPagination.page || 1);
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

    function clearFieldError(field) {
        const el = container && container.querySelector(`[data-error="${field}"]`);
        if (!el) return;
        el.textContent = '';
        el.classList.add('hidden');
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
        const btn = container && container.querySelector('[data-action="submit-start"]');
        if (!btn) return;
        btn.disabled = sending;
        btn.textContent = sending ? t('inventory.picking.start.sending') : t('inventory.picking.start.submit');
    }

    // ====================================================================
    // SELECCIÓN MÚLTIPLE Y ESCANEO
    // ====================================================================

    function toggleSelection(id, checked) {
        if (checked) state.selected.add(id);
        else state.selected.delete(id);
        renderSelectionCount();
    }

    function selectAll() {
        state.reservations.forEach(r => state.selected.add(r.stock_unit_id));
        renderReservations();
    }

    function clearSelection() {
        state.selected.clear();
        renderReservations();
    }

    function handleScan(event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();

        const input = event.target;
        const codigo = (input.value || '').trim();

        if (codigo === '') return;

        const match = state.reservations.find(r =>
            String(r.barcode || '').trim().toUpperCase() === codigo.toUpperCase()
        );

        if (!match) {
            showFieldError('scan', t('inventory.picking.messages.scan_not_found', { codigo: codigo }));
            showToast('danger', t('inventory.picking.messages.barcode_not_found_title'), t('inventory.picking.messages.barcode_not_found_message'));
            return;
        }

        clearErrors();
        state.selected.add(match.stock_unit_id);
        renderReservations();

        const row = container.querySelector(`[data-row-id="${cssEscape(match.stock_unit_id)}"]`);
        if (row && typeof row.scrollIntoView === 'function') {
            row.scrollIntoView({ block: 'nearest' });
        }

        showToast('success', t('inventory.picking.messages.unit_selected_title'), t('inventory.picking.messages.unit_selected_message', { barcode: match.barcode, producto: match.producto }));
        input.value = '';
        input.focus();
    }

    function cssEscape(value) {
        if (global.CSS && typeof global.CSS.escape === 'function') {
            return global.CSS.escape(String(value));
        }
        return String(value).replace(/["\\]/g, '\\$&');
    }

    // ====================================================================
    // FILTROS
    // ====================================================================

    function readPickingFilters() {
        container.querySelectorAll('[data-picking-filter]').forEach(el => {
            state.pickingFilters[el.dataset.pickingFilter] = el.value.trim();
        });
    }

    function clearPickingFilters() {
        state.pickingFilters = { estado: '', id_lote_picking: '' };
        container.querySelectorAll('[data-picking-filter]').forEach(el => { el.value = ''; });
        loadPickings(1);
    }

    // ====================================================================
    // EVENTS
    // ====================================================================

    function bindEvents() {
        if (!container) return;

        container.removeEventListener('click', handleClick);
        container.removeEventListener('submit', handleSubmit);
        container.removeEventListener('change', handleChange);
        container.removeEventListener('keydown', handleKeydown);

        container.addEventListener('click', handleClick);
        container.addEventListener('submit', handleSubmit);
        container.addEventListener('change', handleChange);
        container.addEventListener('keydown', handleKeydown);
    }

    function handleClick(e) {
        const btn = e.target.closest('[data-action], [data-page-picking]');
        if (!btn) return;

        const action = btn.dataset.action;
        const lote = btn.dataset.lote;
        const page = btn.dataset.pagePicking;

        switch (action) {
            case 'apply-picking-filters':
                readPickingFilters();
                loadPickings(1);
                break;
            case 'clear-picking-filters':
                clearPickingFilters();
                break;
            case 'regenerate-lote': {
                const input = container.querySelector('input[name="id_lote_picking"]');
                if (input) {
                    input.value = generateLote();
                    clearFieldError('id_lote_picking');
                }
                break;
            }
            case 'select-all':
                selectAll();
                break;
            case 'clear-selection':
                clearSelection();
                break;
            case 'expand-lote':
                if (state.expanded.has(lote)) state.expanded.delete(lote);
                else state.expanded.add(lote);
                renderTable();
                break;
            case 'complete-lote':
                if (lote) completeLote(lote);
                break;
            case 'verify-lote':
                if (lote) verifyLote(lote);
                break;
        }

        if (page !== undefined && page !== null && page !== '') {
            loadPickings(parseInt(page, 10));
        }
    }

    function handleChange(e) {
        const el = e.target;
        if (el.matches('[data-action="toggle-select"]')) {
            toggleSelection(el.dataset.id, el.checked);
        }
    }

    function handleKeydown(e) {
        if (e.target.matches('[data-scan]')) handleScan(e);
    }

    function handleSubmit(e) {
        if (e.target.matches('[data-start-form]')) {
            e.preventDefault();
            submitStart(e.target);
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

    const InventoryPickingModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            container.innerHTML = TEMPLATE;
            bindEvents();
            state.startKey = newIdempotencyKey();

            renderStartSection();
            renderAll();

            if (canCreate()) loadCatalogs();
            loadReservations();
            loadPickings(1);
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            if (!container.querySelector('[data-picking-table]')) {
                container.innerHTML = TEMPLATE;
                bindEvents();
                state.startKey = newIdempotencyKey();
                renderStartSection();
                if (canCreate()) loadCatalogs();
            }
            loadReservations();
            loadPickings(state.pickingPagination.page || 1);
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('submit', handleSubmit);
                container.removeEventListener('change', handleChange);
                container.removeEventListener('keydown', handleKeydown);
                container.innerHTML = '';
            }
            container = null;
            state = {
                reservations: [],
                reservationSummary: { total: 0, activas: 0, con_expiracion: 0 },
                reservationPagination: { total: 0, page: 1, per_page: 100, has_next: false },
                reservationsLoading: false,
                pickings: [],
                pickingSummary: { total: 0, en_curso: 0, completadas: 0, verificadas: 0 },
                pickingPagination: { total: 0, page: 1, per_page: 20, has_next: false },
                pickingFilters: { estado: '', id_lote_picking: '' },
                pickingLoading: false,
                locations: [],
                selected: new Set(),
                expanded: new Set(),
                startKey: '',
                loteKeys: {},
                sending: false
            };
        }
    };

    global.InventoryPickingModule = InventoryPickingModule;

})(window);
