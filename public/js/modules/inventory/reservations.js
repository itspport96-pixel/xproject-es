/**
 * Inventory Reservations Module
 * Pantalla "Reservas" (CU-003) - v1.6.2.
 *
 * Convención ModuleLoader: window.InventoryReservationsModule
 *
 * Fuente de datos: `GET /api/inventory/reservations` (v1.6.2), con
 * `reserva_id`, `estado` y `fecha_expiracion` reales: la columna
 * "Vencimiento" muestra el plazo de cada reserva y "Liberar" ejecuta
 * `POST /api/inventory/reservations/{id}/release`.
 *
 * Permisos: lectura con `inventory.reservation.view`; liberar con
 * `inventory.reservation.cancel` (403 del backend si no se tiene).
 *
 * v1.9.3: migrado a los componentes compartidos de `public/js/ui/*`
 * (escape, formato, permisos, resumen, tabla, badges y paginación) sin
 * cambiar el comportamiento ni los textos de los toasts de `release`
 * (mismo contrato que cubre smoke-v162). Requiere que `js/ui/*` se cargue
 * antes que este fichero (index.php y los smokes).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    let state = {
        items: [],
        summary: { total: 0, activas: 0, con_expiracion: 0 },
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        loading: false
    };

    // Mismo mapeo que v1.6.2: ACTIVA success, CANCELADA secondary y el
    // resto (COMPLETADA u otro) warning.
    const ESTADOS = [
        { value: 'ACTIVA', labelKey: 'inventory.reservations.states.activa', color: 'success' },
        { value: 'CANCELADA', labelKey: 'inventory.reservations.states.cancelada', color: 'secondary' }
    ];

    const estadoCatalog = () => ESTADOS.map(e => ({
        value: e.value,
        label: t(e.labelKey),
        color: e.color
    }));

    const TEMPLATE = `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.reservations.title'),
                subtitle: t('inventory.reservations.subtitle')
            })}

            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4" data-res-summary></div>

            <div class="card p-0">
                <div class="table-wrapper" data-res-table></div>
                <div data-res-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>
    `;

    // ====================================================================
    // PERMISOS
    // ====================================================================

    const canView = () =>
        UI.can('inventory.reservation.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER'])
        || UI.can('inventory.reservation.create', ['ADMIN', 'MANAGER', 'USER'])
        || UI.can('inventory.reservation.cancel', ['ADMIN', 'MANAGER']);

    const canRelease = () => UI.can('inventory.reservation.cancel', ['ADMIN', 'MANAGER']);

    // ====================================================================
    // RENDER
    // ====================================================================

    function renderPermissionDenied() {
        container.innerHTML = `
            <div class="card max-w-md mx-auto mt-8">
                <div class="card-header">
                    <h3 class="card-title">${t('inventory.reservations.permission.title')}</h3>
                </div>
                <div class="card-body">
                    <p>${t('inventory.reservations.permission.body', { permiso: 'inventory.reservation.view' })}</p>
                    <div class="mt-4">
                        <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                    </div>
                </div>
            </div>`;
    }

    function renderSummary() {
        const root = container.querySelector('[data-res-summary]');
        if (!root) return;

        UI.summaryCards(root, [
            { label: t('inventory.reservations.summary.total'), value: state.summary.total },
            { label: t('inventory.reservations.summary.activas'), value: state.summary.activas },
            { label: t('inventory.reservations.summary.con_vencimiento'), value: state.summary.con_expiracion }
        ]);
    }

    function renderExpiry(row) {
        if (!row.fecha_expiracion) return '<span class="text-muted">—</span>';

        const fecha = String(row.fecha_expiracion);
        let expired = false;
        if (row.estado === 'ACTIVA') {
            const ts = Date.parse(fecha.replace(' ', 'T'));
            expired = isFinite(ts) && ts < Date.now();
        }

        return `<span class="${expired ? 'text-danger font-medium' : ''}" title="${UI.escapeHtml(fecha)}">${UI.escapeHtml(fecha)}</span>`;
    }

    function renderTable() {
        const root = container.querySelector('[data-res-table]');
        if (!root) return;

        if (state.loading) {
            root.innerHTML = UI.tableSkeleton([
                { label: t('inventory.reservations.columns.barcode') },
                { label: t('inventory.reservations.columns.producto') },
                { label: t('inventory.reservations.columns.reservada'), className: 'text-right' },
                { label: t('inventory.reservations.columns.estado') },
                { label: t('inventory.reservations.columns.vencimiento') },
                { label: t('inventory.reservations.columns.acciones'), className: 'col-actions' }
            ], 6, [16, 30, 12, 12, 16, 14]);
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('inventory.reservations.empty.title'),
                message: t('inventory.reservations.empty.message')
            });
            return;
        }

        const releaseAllowed = canRelease();
        const catalog = estadoCatalog();

        const rows = state.items.map(r => `
            <tr>
                <td class="font-mono text-xs">${UI.escapeHtml(r.barcode)}</td>
                <td>
                    <div class="font-medium">${UI.escapeHtml(r.producto)}</div>
                    <div class="text-xs text-muted font-mono">${UI.escapeHtml(r.ubicacion)}</div>
                </td>
                <td class="text-right font-medium">${UI.formatNumber(r.cantidad)}</td>
                <td>${UI.estadoBadge(r.estado, catalog, 'warning')}</td>
                <td class="text-xs">${renderExpiry(r)}</td>
                <td class="table-actions col-actions">${UI.rowActions({
                    groups: [
                        [releaseAllowed && r.estado === 'ACTIVA'
                            ? { action: 'release', id: r.reserva_id, label: t('inventory.reservations.actions.release'), danger: true }
                            : null]
                    ],
                    ariaLabel: t('inventory.reservations.columns.acciones')
                })}</td>
            </tr>
        `).join('');

        root.innerHTML = UI.tableShell([
            { label: t('inventory.reservations.columns.barcode') },
            { label: t('inventory.reservations.columns.producto') },
            { label: t('inventory.reservations.columns.reservada'), className: 'text-right' },
            { label: t('inventory.reservations.columns.estado') },
            { label: t('inventory.reservations.columns.vencimiento') },
            { label: t('inventory.reservations.columns.acciones'), className: 'col-actions' }
        ], rows);
    }

    function renderPagination() {
        const root = container.querySelector('[data-res-pagination]');
        if (!root) return;

        UI.renderPagination(root, {
            pagination: state.pagination,
            shown: state.items.length,
            label: t('inventory.reservations.pagination_label')
        });
    }

    // ====================================================================
    // API
    // ====================================================================

    async function loadReservations(page = 1) {
        state.loading = true;
        renderTable();

        try {
            const response = await global.HttpClient.get('/inventory/reservations', {
                params: { page, per_page: state.pagination.per_page }
            });
            const payload = response && response.data ? response.data : response;

            state.items = (payload && payload.data) || [];
            state.summary = (payload && payload.summary) || { total: 0, activas: 0, con_expiracion: 0 };
            state.pagination = (payload && payload.pagination) || state.pagination;
            state.pagination.page = (payload && payload.pagination && payload.pagination.page) || page;
            return true;
        } catch (err) {
            console.error('[Reservations] load error:', err);
            state.items = [];
            state.summary = { total: 0, activas: 0, con_expiracion: 0 };

            if (err && err.status === 403) {
                UI.toast('danger', t('inventory.reservations.permission.title'), t('inventory.reservations.messages.forbidden_view', { permiso: 'inventory.reservation.view' }));
            } else {
                UI.toast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.reservations.messages.load_error'));
            }
            return false;
        } finally {
            state.loading = false;
            renderSummary();
            renderTable();
            renderPagination();
        }
    }

    async function releaseReservation(id) {
        const confirmed = global.confirm(t('inventory.reservations.messages.confirm_release'));
        if (!confirmed) return;

        try {
            await global.HttpClient.post('/inventory/reservations/' + encodeURIComponent(id) + '/release');
            const recargado = await loadReservations(state.pagination.page || 1);
            if (recargado) {
                UI.toast('success', t('inventory.reservations.messages.released_title'), t('inventory.reservations.messages.released_message'));
            }
        } catch (err) {
            const status = err && err.status;

            if (status === 403) {
                UI.toast('danger', t('inventory.reservations.permission.title'), t('inventory.reservations.messages.forbidden_release', { permiso: 'inventory.reservation.cancel' }));
            } else if (status === 404) {
                UI.toast('danger', t('inventory.reservations.messages.not_found_title'), t('inventory.reservations.messages.not_found_message'));
                await loadReservations(state.pagination.page || 1);
            } else if (status === 409) {
                UI.toast('warning', t('inventory.reservations.messages.release_conflict_title'), (err && err.message) || t('inventory.reservations.messages.release_conflict_message'));
                await loadReservations(state.pagination.page || 1);
            } else {
                UI.toast('danger', t('ui.error_generic'), (err && err.message) || t('inventory.reservations.messages.release_error'));
            }
        }
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
            case 'release':
                releaseReservation(btn.dataset.id);
                break;
        }

        if (page) {
            loadReservations(parseInt(page, 10));
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const InventoryReservationsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            container.innerHTML = TEMPLATE;
            bindEvents();
            renderSummary();
            loadReservations(1);
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            if (!container.querySelector('[data-res-table]')) {
                container.innerHTML = TEMPLATE;
                bindEvents();
            }
            loadReservations(state.pagination.page || 1);
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.innerHTML = '';
            }
            container = null;
            state = {
                items: [],
                summary: { total: 0, activas: 0, con_expiracion: 0 },
                pagination: { total: 0, page: 1, per_page: 20, has_next: false },
                loading: false
            };
        }
    };

    global.InventoryReservationsModule = InventoryReservationsModule;

})(window);
