/**
 * Inventory Movements Module
 * Pantalla "Movimientos" (R3 / CU-002) - v1.10.0.
 *
 * Convención ModuleLoader: window.InventoryMovementsModule
 *
 * Fuente de datos: `GET /api/inventory/movements` (v1.10.0) con filtros
 * `unidad_fisica_id`, `ubicacion_origen_id`, `ubicacion_destino_id`, `motivo`,
 * `es_correccion`, `fecha_desde` y `fecha_hasta`, más `summary` con el total
 * de movimientos y el de correcciones (E8).
 *
 * Permisos: lectura con `inventory.stock.view` (mismo permiso que declara la
 * ruta backend; los permisos `inventory.movement.*` siguen operando la
 * creación desde Stock).
 *
 * La línea de tiempo por unidad (R3) vive en la pantalla de Stock:
 * `GET /api/inventory/stock/{id}/history`.
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    let state = {
        items: [],
        summary: { total: 0, correcciones: 0 },
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        filters: {
            unidad_fisica_id: '',
            ubicacion_origen_id: '',
            ubicacion_destino_id: '',
            motivo: '',
            es_correccion: '',
            fecha_desde: '',
            fecha_hasta: ''
        },
        locations: [],
        loading: false
    };

    const TEMPLATE = `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.movements.title'),
                subtitle: t('inventory.movements.subtitle')
            })}

            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4" data-mov-summary></div>

            <div class="card mb-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.movements.filters.unit')}</label>
                        <input type="text" class="input" data-filter="unidad_fisica_id"
                               placeholder="${t('inventory.movements.filters.unit_placeholder')}" autocomplete="off">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.movements.filters.origin')}</label>
                        <select class="select" data-filter="ubicacion_origen_id">
                            <option value="">${t('inventory.movements.filters.all')}</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.movements.filters.destination')}</label>
                        <select class="select" data-filter="ubicacion_destino_id">
                            <option value="">${t('inventory.movements.filters.all')}</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.movements.filters.reason')}</label>
                        <input type="text" class="input" data-filter="motivo"
                               placeholder="${t('inventory.movements.filters.reason_placeholder')}" autocomplete="off">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.movements.filters.correction')}</label>
                        <select class="select" data-filter="es_correccion">
                            <option value="">${t('inventory.movements.filters.all')}</option>
                            <option value="1">${t('inventory.movements.filters.only_corrections')}</option>
                            <option value="0">${t('inventory.movements.filters.no_correction')}</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.movements.filters.date_from')}</label>
                        <input type="date" class="input" data-filter="fecha_desde">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">${t('inventory.movements.filters.date_to')}</label>
                        <input type="date" class="input" data-filter="fecha_hasta">
                    </div>
                    <div class="form-group mb-0 flex gap-2 items-end">
                        <button class="btn btn-secondary flex-1" data-action="apply-filters">${t('inventory.movements.filters.apply')}</button>
                        <button class="btn btn-ghost" data-action="clear-filters" title="${t('inventory.movements.filters.clear_title')}">${t('inventory.movements.filters.clear')}</button>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="table-wrapper" data-mov-table></div>
                <div data-mov-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>
    `;

    const DENIED_TEMPLATE = `
        <div class="card max-w-md mx-auto mt-8">
            <div class="card-header">
                <h3 class="card-title">${t('inventory.movements.permission.title')}</h3>
            </div>
            <div class="card-body">
                <p>${t('inventory.movements.permission.body', { permiso: 'inventory.stock.view' })}</p>
                <div class="mt-4">
                    <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                </div>
            </div>
        </div>`;

    // ====================================================================
    // PERMISOS
    // ====================================================================

    const canView = () => UI.can('inventory.stock.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);

    // ====================================================================
    // RENDER
    // ====================================================================

    function renderSummary() {
        const root = container.querySelector('[data-mov-summary]');
        if (!root) return;

        UI.summaryCards(root, [
            { label: t('inventory.movements.title'), value: state.summary.total },
            { label: t('inventory.movements.summary.correcciones'), value: state.summary.correcciones },
            { label: t('inventory.movements.summary.en_pantalla'), value: state.items.length }
        ]);
    }

    function renderTable() {
        const root = container.querySelector('[data-mov-table]');
        if (!root) return;

        if (state.loading) {
            root.innerHTML = UI.loadingState();
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('inventory.movements.empty.title'),
                message: t('inventory.movements.empty.message')
            });
            return;
        }

        const rows = state.items.map(m => {
            const correccion = m.es_correccion
                ? `<span class="badge badge-warning" title="${UI.escapeHtml(m.movimiento_referencia_id || '')}">${t('inventory.movements.columns.correccion')}</span>`
                : '<span class="text-muted">—</span>';

            const origen = m.ubicacion_origen_codigo
                ? UI.escapeHtml(m.ubicacion_origen_codigo)
                : '<span class="text-muted">SIN_UBICACION</span>';
            const destino = m.ubicacion_destino_codigo
                ? UI.escapeHtml(m.ubicacion_destino_codigo)
                : '<span class="text-muted">—</span>';

            return `
            <tr>
                <td class="font-mono text-xs">${UI.escapeHtml(m.movimiento_id)}</td>
                <td class="text-xs">${UI.escapeHtml(m.timestamp_movimiento || '—')}</td>
                <td class="font-mono text-xs">${UI.escapeHtml(m.codigo_barcode || m.unidad_fisica_id)}</td>
                <td class="text-xs">${origen} → ${destino}</td>
                <td class="text-xs">${UI.escapeHtml(m.motivo || '—')}</td>
                <td class="text-xs font-mono">${UI.escapeHtml(m.actor_id || '—')}</td>
                <td>${correccion}</td>
            </tr>`;
        }).join('');

        root.innerHTML = UI.tableShell([
            { label: t('inventory.movements.columns.movimiento') },
            { label: t('inventory.movements.columns.fecha') },
            { label: t('inventory.movements.columns.unidad') },
            { label: t('inventory.movements.columns.ruta') },
            { label: t('inventory.movements.columns.motivo') },
            { label: t('inventory.movements.columns.actor') },
            { label: t('inventory.movements.columns.correccion') }
        ], rows);
    }

    function renderPagination() {
        const root = container.querySelector('[data-mov-pagination]');
        if (!root) return;

        UI.renderPagination(root, {
            pagination: state.pagination,
            shown: state.items.length,
            label: t('inventory.movements.pagination_label')
        });
    }

    function renderFilters() {
        if (!container) return;

        container.querySelectorAll('select[data-filter="ubicacion_origen_id"], select[data-filter="ubicacion_destino_id"]').forEach(select => {
            const key = select.dataset.filter;
            const selected = state.filters[key];
            select.innerHTML = '<option value="">' + t('inventory.movements.filters.all') + '</option>' +
                state.locations.map(l => `
                    <option value="${UI.escapeHtml(l.id)}" ${l.id === selected ? 'selected' : ''}>
                        ${UI.escapeHtml(l.codigo)}
                    </option>`).join('');
        });

        container.querySelectorAll('[data-filter]').forEach(el => {
            const key = el.dataset.filter;
            if (state.filters[key] !== undefined && el.tagName === 'INPUT') {
                el.value = state.filters[key];
            }
        });
    }

    // ====================================================================
    // API
    // ====================================================================

    async function loadMovements(page = 1) {
        state.loading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            Object.entries(state.filters).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) params[key] = value;
            });

            const response = await global.HttpClient.get('/inventory/movements', { params });
            const payload = response && response.data ? response.data : response;

            state.items = (payload && payload.data) || [];
            state.summary = (payload && payload.summary) || state.summary;
            state.pagination = (payload && payload.pagination) || state.pagination;
            state.pagination.page = (payload && payload.pagination && payload.pagination.page) || page;
        } catch (err) {
            console.error('[Movements] load error:', err);
            state.items = [];
            state.summary = { total: 0, correcciones: 0 };

            if (err && err.status === 403) {
                UI.toast('danger', t('inventory.movements.permission.title'), t('inventory.movements.messages.forbidden_view', { permiso: 'inventory.stock.view' }));
            } else {
                UI.toast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.movements.messages.load_error'));
            }
        } finally {
            state.loading = false;
            renderSummary();
            renderTable();
            renderPagination();
        }
    }

    async function loadLocations() {
        try {
            const response = await global.HttpClient.get('/inventory/locations', {
                params: { page: 1, per_page: 100 }
            });
            const payload = response && response.data ? response.data : response;
            state.locations = (payload && payload.data) || [];
            renderFilters();
        } catch (err) {
            console.warn('[Movements] locations load error:', err && err.status);
            state.locations = [];
        }
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
        state.filters = {
            unidad_fisica_id: '',
            ubicacion_origen_id: '',
            ubicacion_destino_id: '',
            motivo: '',
            es_correccion: '',
            fecha_desde: '',
            fecha_hasta: ''
        };
        container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
        loadMovements(1);
    }

    // ====================================================================
    // EVENTS
    // ====================================================================

    function bindEvents() {
        if (!container) return;

        container.removeEventListener('click', handleClick);
        container.addEventListener('click', handleClick);
    }

    function handleClick(e) {
        const btn = e.target.closest('[data-action], [data-page]');
        if (!btn || btn.disabled) return;

        const action = btn.dataset.action;
        const page = btn.dataset.page;

        switch (action) {
            case 'apply-filters':
                readFilters();
                loadMovements(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
        }

        if (page !== undefined && page !== null && page !== '') {
            loadMovements(parseInt(page, 10));
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const InventoryMovementsModule = {
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
            loadLocations();
            loadMovements(1);
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            if (!container.querySelector('[data-mov-table]')) {
                container.innerHTML = TEMPLATE;
                bindEvents();
                renderFilters();
            }
            loadMovements(state.pagination.page || 1);
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.innerHTML = '';
            }
            container = null;
            state = {
                items: [],
                summary: { total: 0, correcciones: 0 },
                pagination: { total: 0, page: 1, per_page: 20, has_next: false },
                filters: {
                    unidad_fisica_id: '',
                    ubicacion_origen_id: '',
                    ubicacion_destino_id: '',
                    motivo: '',
                    es_correccion: '',
                    fecha_desde: '',
                    fecha_hasta: ''
                },
                locations: [],
                loading: false
            };
        }
    };

    global.InventoryMovementsModule = InventoryMovementsModule;

})(window);
