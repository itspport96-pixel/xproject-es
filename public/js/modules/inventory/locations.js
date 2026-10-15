/**
 * Inventory Locations Module
 * CRUD de ubicaciones con tabla paginada, filtros y modal.
 * Expuesto como window.InventoryLocationsModule (convención ModuleLoader).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;
    let state = {
        items: [],
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        filters: { search: '', tipo: '', activa: '' },
        loading: false,
        editing: null
    };

    const TIPOS = [
        { value: 'ALMACENAMIENTO', labelKey: 'inventory.locations.types.almacenamiento', color: 'primary' },
        { value: 'PICKING',        labelKey: 'inventory.locations.types.picking',        color: 'info' },
        { value: 'DAÑADOS',        labelKey: 'inventory.locations.types.danados',        color: 'danger' },
        { value: 'BLOQUEADOS',     labelKey: 'inventory.locations.types.bloqueados',     color: 'warning' },
        { value: 'TRANSITO',       labelKey: 'inventory.locations.types.transito',       color: 'neutral' },
        { value: 'DEVOLUCION',     labelKey: 'inventory.locations.types.devolucion',     color: 'success' }
    ];

    const tipoLabel = (v) => {
        const tipo = TIPOS.find(x => x.value === v);
        return tipo ? t(tipo.labelKey) : v;
    };

    const tipoColor = (v) => {
        const t = TIPOS.find(x => x.value === v);
        return t ? t.color : 'neutral';
    };

    const ESTADO_COLORS = {
        DISPONIBLE: 'success',
        RESERVADO: 'warning',
        EN_PREPARACION: 'info',
        CARGADO: 'info',
        EN_TRANSITO: 'info',
        ENTREGADO: 'neutral',
        DEVUELTO: 'neutral',
        'DAÑADO': 'danger',
        BLOQUEADO: 'danger',
        PERDIDO: 'danger'
    };

    const estadoColor = (v) => ESTADO_COLORS[v] || 'neutral';

    const formatNumber = (value) => {
        const n = parseFloat(value);
        if (!isFinite(n)) return '0';
        return Number.isInteger(n) ? String(n) : n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
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

    const canCreate = () => can('inventory.location.manage', ['ADMIN']);
    const canView = () => can('inventory.location.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
    const canEdit = () => can('inventory.location.manage', ['ADMIN']);
    const canDelete = () => can('inventory.location.manage', ['ADMIN']);

    // ====================================================================
    // PLANTILLA
    // ====================================================================

    function buildTemplate() {
        const createBtn = canCreate()
            ? `<button class="btn btn-primary btn-sm" data-action="create">${t('inventory.locations.actions.create')}</button>`
            : '';

        return `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.locations.title'),
                subtitle: t('inventory.locations.subtitle'),
                actionsHtml: createBtn
            })}

            <div class="card mb-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="form-group mb-0">
                        <input type="text" class="input" placeholder="${t('inventory.locations.filters.search_placeholder')}"
                               data-filter="search">
                    </div>
                    <div class="form-group mb-0">
                        <select class="select" data-filter="tipo">
                            <option value="">${t('inventory.locations.filters.all_types')}</option>
                            ${TIPOS.map(tipo => `<option value="${tipo.value}">${t(tipo.labelKey)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <select class="select" data-filter="activa">
                            <option value="">${t('inventory.locations.filters.all_states')}</option>
                            <option value="1">${t('inventory.locations.filters.active')}</option>
                            <option value="0">${t('inventory.locations.filters.inactive')}</option>
                        </select>
                    </div>
                    <div class="form-group mb-0 flex gap-2">
                        <button class="btn btn-secondary flex-1" data-action="apply-filters">${t('inventory.locations.filters.apply')}</button>
                        <button class="btn btn-ghost" data-action="clear-filters" title="${t('inventory.locations.filters.clear')}">✕</button>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="table-wrapper" data-locations-table></div>
                <div data-locations-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>
    `;
    }

    // ====================================================================
    // RENDER
    // ====================================================================

    function renderCapacityBar(location) {
        if (location.capacidad === null || location.capacidad === 0) {
            return `<span class="text-xs text-muted">${t('inventory.locations.states.no_limit')}</span>`;
        }
        const used = location.capacidad_usada || 0;
        const total = location.capacidad;
        const pct = Math.min(100, Math.round((used / total) * 100));
        const color = pct >= 90 ? 'danger' : pct >= 70 ? 'warning' : 'primary';

        return `
            <div class="flex flex-col gap-1">
                <div class="flex justify-between text-xs">
                    <span>${used} / ${total}</span>
                    <span class="text-${color}">${pct}%</span>
                </div>
                <div class="progress">
                    <div class="progress-bar ${color}" style="width: ${pct}%"></div>
                </div>
            </div>
        `;
    }

    function renderTable() {
        const root = container.querySelector('[data-locations-table]');
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
                    <div class="empty-state-icon">📍</div>
                    <div class="empty-state-title">${t('inventory.locations.states.empty_title')}</div>
                    <p class="text-muted">${t('inventory.locations.states.empty_body')}</p>
                </div>`;
            return;
        }

        const rows = state.items.map(l => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(l.codigo)}</td>
                <td>
                    <div class="font-medium">${escapeHtml(l.nombre || '—')}</div>
                    ${l.jerarquia ? `<div class="text-xs text-muted">${escapeHtml(l.jerarquia)}</div>` : ''}
                </td>
                <td><span class="chip chip-${tipoColor(l.tipo)}">${escapeHtml(tipoLabel(l.tipo))}</span></td>
                <td class="min-w-[120px]">${renderCapacityBar(l)}</td>
                <td>${l.activa ? `<span class="badge badge-success">${t('inventory.locations.states.active')}</span>` : `<span class="badge badge-neutral">${t('inventory.locations.states.inactive')}</span>`}</td>
                <td class="text-xs text-muted">${formatDate(l.created_at)}</td>
                <td class="table-actions col-actions">${UI.rowActions({
                    primary: canEdit()
                        ? { action: 'edit', id: l.id, label: t('inventory.locations.actions.edit') }
                        : null,
                    groups: [
                        [can('inventory.stock.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER'])
                            ? { action: 'units', id: l.id, label: t('inventory.locations.actions.units') }
                            : null],
                        [l.activa
                            ? { action: 'deactivate', id: l.id, label: t('inventory.locations.actions.deactivate') }
                            : { action: 'activate', id: l.id, label: t('inventory.locations.actions.activate') }],
                        [canDelete()
                            ? { action: 'delete', id: l.id, label: t('inventory.locations.actions.delete'), danger: true }
                            : null]
                    ],
                    ariaLabel: t('inventory.locations.columns.actions')
                })}</td>
            </tr>
        `).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('inventory.locations.columns.code')}</th>
                        <th>${t('inventory.locations.columns.name_hierarchy')}</th>
                        <th>${t('inventory.locations.columns.type')}</th>
                        <th>${t('inventory.locations.columns.capacity')}</th>
                        <th>${t('inventory.locations.columns.state')}</th>
                        <th>${t('inventory.locations.columns.created')}</th>
                        <th class="col-actions">${t('inventory.locations.columns.actions')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function renderPagination() {
        const root = container.querySelector('[data-locations-pagination]');
        if (!root) return;

        const { total, page, per_page, has_next } = state.pagination;
        const totalPages = Math.max(1, Math.ceil(total / per_page));

        root.innerHTML = `
            <div class="pagination-info">
                ${t('ui.pagination', { shown: state.items.length, total: total, label: t('inventory.locations.pagination_label') })}
            </div>
            <div class="pagination-controls">
                <button class="page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>‹</button>
                <span class="page-btn is-active">${page} / ${totalPages}</span>
                <button class="page-btn" data-page="${page + 1}" ${!has_next ? 'disabled' : ''}>›</button>
            </div>
        `;
    }

    // ====================================================================
    // MODAL
    // ====================================================================

    function openModal(location = null) {
        state.editing = location;
        const isEdit = !!location;
        const title = isEdit ? t('inventory.locations.modals.edit_title') : t('inventory.locations.modals.create_title');

        const root = container.querySelector('[data-modal-root]');
        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${title}</h3>
                        <button class="modal-close" data-action="close-modal">×</button>
                    </div>
                    <form data-location-form>
                        <div class="modal-body">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.locations.modals.fields.code')} <span class="required">*</span></label>
                                    <input type="text" class="input" name="codigo" required maxlength="100"
                                           value="${isEdit ? escapeHtml(location.codigo) : ''}"
                                           placeholder="${t('inventory.locations.modals.placeholders.code')}">
                                    <span class="form-hint">${t('inventory.locations.modals.hints.code')}</span>
                                    <span class="form-error hidden" data-error="codigo"></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.locations.modals.fields.type')} <span class="required">*</span></label>
                                    <select class="select" name="tipo" required>
                                        ${TIPOS.map(tipo => `<option value="${tipo.value}" ${isEdit && location.tipo === tipo.value ? 'selected' : ''}>${t(tipo.labelKey)}</option>`).join('')}
                                    </select>
                                    <span class="form-hint">${t('inventory.locations.modals.hints.type')}</span>
                                    <span class="form-error hidden" data-error="tipo"></span>
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label">${t('inventory.locations.modals.fields.name')}</label>
                                    <input type="text" class="input" name="nombre" maxlength="255"
                                           value="${isEdit && location.nombre ? escapeHtml(location.nombre) : ''}"
                                           placeholder="${t('inventory.locations.modals.placeholders.name')}">
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label">${t('inventory.locations.modals.fields.hierarchy')}</label>
                                    <input type="text" class="input" name="jerarquia" maxlength="255"
                                           value="${isEdit && location.jerarquia ? escapeHtml(location.jerarquia) : ''}"
                                           placeholder="${t('inventory.locations.modals.placeholders.hierarchy')}">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.locations.modals.fields.capacity')}</label>
                                    <input type="number" class="input" name="capacidad" min="0" step="1"
                                           value="${isEdit && location.capacidad !== null ? location.capacidad : ''}"
                                           placeholder="${t('inventory.locations.modals.placeholders.capacity')}">
                                    <span class="form-error hidden" data-error="capacidad"></span>
                                </div>
                            </div>
                            <div data-form-error class="alert alert-danger hidden mt-4"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-action="close-modal">${t('inventory.locations.modals.cancel')}</button>
                            <button type="submit" class="btn btn-primary" data-action="save-location">
                                ${isEdit ? t('inventory.locations.modals.save_changes') : t('inventory.locations.modals.create_submit')}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;
    }

    function closeModal() {
        const root = container.querySelector('[data-modal-root]');
        if (root) root.innerHTML = '';
        state.editing = null;
    }

    // ====================================================================
    // UNIDADES DE LA UBICACIÓN (GET /inventory/stock?ubicacion_id=)
    // ====================================================================

    async function openUnitsModal(location) {
        if (!can('inventory.stock.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER'])) {
            showToast('warning', t('inventory.locations.permission.title'),
                t('inventory.stock.permission.body', { permiso: 'inventory.stock.view' }));
            return;
        }

        const root = container.querySelector('[data-modal-root]');
        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('inventory.locations.modals.units_title', { codigo: escapeHtml(location.codigo) })}</h3>
                        <button class="modal-close" data-action="close-modal">×</button>
                    </div>
                    <div class="modal-body">
                        <div class="flex items-center justify-center py-10">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>
            </div>`;

        try {
            const response = await global.HttpClient.get('/inventory/stock', {
                params: { ubicacion_id: location.id, page: 1, per_page: 100 }
            });
            const payload = response.data || response;
            renderUnitsModal(location, payload);
        } catch (err) {
            closeModal();
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.locations.messages.load_units_error'));
        }
    }

    function renderUnitsModal(location, payload) {
        const root = container.querySelector('[data-modal-root]');
        if (!root) return;

        const rows = payload.data || [];
        const summary = payload.summary || {};
        const total = Number(summary.unidades || 0);
        const truncado = rows.length < total;
        const capacidad = (location.capacidad === null || location.capacidad === undefined)
            ? t('inventory.locations.messages.capacity_no_limit')
            : `${location.capacidad_usada || 0} / ${location.capacidad}`;

        const body = rows.length === 0
            ? `<div class="empty-state">
                   <div class="empty-state-title">${t('inventory.locations.states.units_empty_title')}</div>
                   <p class="text-muted">${t('inventory.locations.states.units_empty_body')}</p>
               </div>`
            : `
                <table class="table">
                    <thead>
                        <tr>
                            <th>${t('inventory.locations.columns.barcode')}</th>
                            <th>${t('inventory.locations.columns.product')}</th>
                            <th class="text-right">${t('inventory.locations.columns.quantity')}</th>
                            <th>${t('inventory.locations.columns.state')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.map(u => `
                            <tr>
                                <td class="font-mono text-xs">${escapeHtml(u.codigo_barcode)}</td>
                                <td>
                                    <div class="font-medium">${escapeHtml(u.producto_nombre)}</div>
                                    <div class="text-xs text-muted font-mono">${escapeHtml(u.sku)}</div>
                                </td>
                                <td class="text-right">${formatNumber(u.cantidad_fisica)}</td>
                                <td><span class="badge badge-${estadoColor(u.estado)}">${escapeHtml(u.estado || '')}</span></td>
                            </tr>`).join('')}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="text-muted text-xs">${t('inventory.locations.messages.total')}</td>
                            <td class="text-muted text-xs">${t('inventory.locations.messages.units_count', { total: total })}</td>
                            <td class="text-right font-bold">${formatNumber(summary.cantidad_fisica)}</td>
                            <td class="text-xs text-muted">${t('inventory.locations.messages.available_short')} ${formatNumber(summary.cantidad_disponible)}</td>
                        </tr>
                    </tfoot>
                </table>
                ${truncado ? `<p class="text-xs text-muted mt-3">${t('inventory.locations.messages.truncated')}</p>` : ''}`;

        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('inventory.locations.modals.units_title', { codigo: escapeHtml(location.codigo) })}</h3>
                        <button class="modal-close" data-action="close-modal">×</button>
                    </div>
                    <div class="modal-body">
                        <div class="flex flex-wrap gap-4 mb-3 text-sm">
                            <span class="text-muted">${t('inventory.locations.modals.fields.capacity')}: <strong>${escapeHtml(capacidad)}</strong></span>
                            ${location.tipo ? `<span class="text-muted">${t('inventory.locations.modals.fields.type')}: <strong>${escapeHtml(tipoLabel(location.tipo))}</strong></span>` : ''}
                        </div>
                        ${body}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    </div>
                </div>
            </div>`;
    }

    // ====================================================================
    // API
    // ====================================================================

    async function loadLocations(page = 1) {
        state.loading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            Object.entries(state.filters).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) params[k] = v;
            });

            const response = await global.HttpClient.get('/inventory/locations', { params });
            const data = response.data || response;

            state.items = data.data || [];
            state.pagination = data.pagination || state.pagination;
            state.pagination.page = page;
            return true;
        } catch (err) {
            console.error('[Locations] load error:', err);
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.locations.messages.load_error'));
            state.items = [];
            return false;
        } finally {
            state.loading = false;
            renderTable();
            renderPagination();
        }
    }

    async function saveLocation(formData) {
        const isEdit = !!state.editing;
        const url = isEdit ? `/inventory/locations/${state.editing.id}` : '/inventory/locations';
        const method = isEdit ? 'patch' : 'post';

        // Normalizar capacidad: vacío = sin límite (null explícito permite limpiarla al editar)
        const capacidadVacia = formData.capacidad === '' || formData.capacidad === null || formData.capacidad === undefined;

        // Limpiar campos vacíos
        Object.keys(formData).forEach(k => {
            if (formData[k] === '' || formData[k] === null) delete formData[k];
        });

        formData.capacidad = capacidadVacia ? null : parseInt(formData.capacidad, 10);

        try {
            await global.HttpClient[method](url, formData);
            closeModal();
            const recargado = await loadLocations(state.pagination.page);
            if (recargado) {
                showToast('success', isEdit ? t('inventory.locations.messages.saved_updated') : t('inventory.locations.messages.saved_created'));
            }
        } catch (err) {
            const formError = container.querySelector('[data-form-error]');
            if (formError) {
                formError.textContent = err.message || t('inventory.locations.messages.save_error');
                formError.classList.remove('hidden');
                formError.scrollIntoView({ block: 'nearest' });
            }
            if (err.data && err.data.field) {
                const fieldError = container.querySelector(`[data-error="${err.data.field}"]`);
                if (fieldError) {
                    fieldError.textContent = err.message;
                    fieldError.classList.remove('hidden');
                    fieldError.scrollIntoView({ block: 'nearest' });
                }
            }
        }
    }

    async function toggleActive(id, activate) {
        try {
            const action = activate ? 'activate' : 'deactivate';
            await global.HttpClient.post(`/inventory/locations/${id}/${action}`);
            const recargado = await loadLocations(state.pagination.page);
            if (recargado) {
                showToast('success', activate ? t('inventory.locations.messages.activated') : t('inventory.locations.messages.deactivated'));
            }
        } catch (err) {
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.locations.messages.toggle_error'));
        }
    }

    async function deleteLocation(id) {
        if (!confirm(t('inventory.locations.messages.delete_confirm'))) return;

        try {
            await global.HttpClient.delete(`/inventory/locations/${id}`);
            const recargado = await loadLocations(state.pagination.page);
            if (recargado) {
                showToast('success', t('inventory.locations.messages.deleted'));
            }
        } catch (err) {
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.locations.messages.delete_error'));
        }
    }

    // ====================================================================
    // EVENTS
    // ====================================================================

    function bindEvents() {
        if (!container) return;

        container.removeEventListener('click', handleClick);
        container.removeEventListener('submit', handleSubmit);
        container.removeEventListener('input', handleInput);
        container.addEventListener('click', handleClick);
        container.addEventListener('submit', handleSubmit);
        container.addEventListener('input', handleInput);
    }

    function handleClick(e) {
        const btn = e.target.closest('[data-action], [data-page]');
        if (!btn) return;

        const action = btn.dataset.action;
        const id = btn.dataset.id;
        const page = btn.dataset.page;

        switch (action) {
            case 'create':
                openModal();
                break;
            case 'edit':
                const location = state.items.find(l => l.id === id);
                if (location) openModal(location);
                break;
            case 'units':
                const unitsLocation = state.items.find(l => l.id === id);
                if (unitsLocation) openUnitsModal(unitsLocation);
                break;
            case 'close-modal':
                closeModal();
                break;
            case 'activate':
                toggleActive(id, true);
                break;
            case 'deactivate':
                toggleActive(id, false);
                break;
            case 'delete':
                deleteLocation(id);
                break;
            case 'apply-filters':
                readFilters();
                loadLocations(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
        }

        if (page) {
            loadLocations(parseInt(page, 10));
        }
    }

    function handleSubmit(e) {
        if (e.target.matches('[data-location-form]')) {
            e.preventDefault();
            const form = e.target;
            const data = Object.fromEntries(new FormData(form));
            saveLocation(data);
        }
    }

    function handleInput(e) {
        if (e.target.matches('[data-filter]')) {
            const field = e.target.dataset.filter;
            state.filters[field] = e.target.value;
        }
    }

    function readFilters() {
        container.querySelectorAll('[data-filter]').forEach(el => {
            state.filters[el.dataset.filter] = el.value;
        });
    }

    function clearFilters() {
        state.filters = { search: '', tipo: '', activa: '' };
        container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
        loadLocations(1);
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

    function formatDate(iso) {
        if (!iso) return '';
        return UI.formatDate(iso);
    }

    function showToast(type, title, message = '') {
        if (global.EventBus) {
            global.EventBus.emit('toast:show', { type, title, message, duration: 3000 });
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const DENIED_TEMPLATE = `
        <div class="card max-w-md mx-auto mt-8">
            <div class="card-header">
                <h3 class="card-title">${t('inventory.locations.permission.title')}</h3>
            </div>
            <div class="card-body">
                <p>${t('inventory.locations.permission.body', { permiso: 'inventory.location.view' })}</p>
                <div class="mt-4">
                    <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                </div>
            </div>
        </div>`;

    const InventoryLocationsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            container.innerHTML = buildTemplate();
            bindEvents();
            loadLocations(1);
        },

        onActivate(params) {
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            if (container && !container.querySelector('[data-locations-table]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
            }
            loadLocations(1);
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('submit', handleSubmit);
                container.removeEventListener('input', handleInput);
                container.innerHTML = '';
            }
            container = null;
            state = {
                items: [],
                pagination: { total: 0, page: 1, per_page: 20, has_next: false },
                filters: { search: '', tipo: '', activa: '' },
                loading: false,
                editing: null
            };
        }
    };

    global.InventoryLocationsModule = InventoryLocationsModule;

})(window);
