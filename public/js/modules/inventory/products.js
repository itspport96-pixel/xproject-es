/**
 * Inventory Products Module
 * CRUD de productos con tabla paginada, filtros y modales.
 * Expuesto como window.InventoryProductsModule (convención ModuleLoader).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;
    let state = {
        items: [],
        pagination: { total: 0, page: 1, per_page: 20, has_next: false },
        filters: { search: '', unidad_medida: '', categoria: '', activo: '' },
        loading: false,
        editing: null,
        stockTotals: {}
    };

    const UNIDADES = ['UNIDAD', 'KG', 'LT', 'M', 'M2', 'M3'];

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

    // Resumen de stock por producto: se cachea poco tiempo para no agotar el
    // rate limit (api: 100 req/min) con una petición por fila.
    const STOCK_TTL_MS = 15000;
    const stockCache = new Map();

    // ====================================================================
    // PERMISOS
    // ====================================================================

    function can(permission, fallbackRoles) {
        if (global.UI && typeof global.UI.can === 'function') {
            return global.UI.can(permission, fallbackRoles);
        }
        return false;
    }

    const canCreate = () => can('inventory.item.create', ['ADMIN', 'MANAGER']);
    const canView = () => can('inventory.item.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);

    // ====================================================================
    // PLANTILLA
    // ====================================================================

    function buildTemplate() {
        const createBtn = canCreate()
            ? `<button class="btn btn-primary btn-sm" data-action="create">${t('inventory.products.actions.create')}</button>`
            : '';

        return `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.products.title'),
                subtitle: t('inventory.products.subtitle'),
                actionsHtml: createBtn
            })}

            <div class="card mb-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="form-group mb-0">
                        <input type="text" class="input" placeholder="${t('inventory.products.filters.search_placeholder')}"
                               data-filter="search">
                    </div>
                    <div class="form-group mb-0">
                        <select class="select" data-filter="unidad_medida">
                            <option value="">${t('inventory.products.filters.all_units')}</option>
                            <option value="UNIDAD">UNIDAD</option>
                            <option value="KG">KG</option>
                            <option value="LT">LT</option>
                            <option value="M">M</option>
                            <option value="M2">M2</option>
                            <option value="M3">M3</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <select class="select" data-filter="activo">
                            <option value="">${t('inventory.products.filters.all_states')}</option>
                            <option value="1">${t('inventory.products.filters.active')}</option>
                            <option value="0">${t('inventory.products.filters.inactive')}</option>
                        </select>
                    </div>
                    <div class="form-group mb-0 flex gap-2">
                        <button class="btn btn-secondary flex-1" data-action="apply-filters">${t('inventory.products.filters.apply')}</button>
                        <button class="btn btn-ghost" data-action="clear-filters" title="${t('inventory.products.filters.clear')}">✕</button>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="table-wrapper" data-products-table></div>
                <div data-products-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>
    `;
    }

    // ====================================================================
    // RENDER
    // ====================================================================

    function renderTable() {
        const root = container.querySelector('[data-products-table]');
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
                    <div class="empty-state-icon">📦</div>
                    <div class="empty-state-title">${t('inventory.products.empty.title')}</div>
                    <p class="text-muted">${t('inventory.products.empty.message')}</p>
                </div>`;
            return;
        }

        const rows = state.items.map(p => `
            <tr>
                <td class="font-mono text-xs">${escapeHtml(p.sku)}</td>
                <td>
                    <div class="font-medium">${escapeHtml(p.nombre)}</div>
                    ${p.categoria ? `<div class="text-xs text-muted">${escapeHtml(p.categoria)}</div>` : ''}
                </td>
                <td><span class="chip">${escapeHtml(p.unidad_medida)}</span></td>
                <td>${renderStockCell(p)}</td>
                <td>${p.activo ? `<span class="badge badge-success">${t('inventory.products.states.active')}</span>` : `<span class="badge badge-neutral">${t('inventory.products.states.inactive')}</span>`}</td>
                <td class="text-xs text-muted">${formatDate(p.created_at)}</td>
                <td class="table-actions col-actions">${UI.rowActions({
                    primary: { action: 'edit', id: p.id, label: t('inventory.products.actions.edit') },
                    groups: [
                        [can('inventory.stock.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER'])
                            ? { action: 'stock', id: p.id, label: t('inventory.products.actions.stock') }
                            : null],
                        [p.activo
                            ? { action: 'deactivate', id: p.id, label: t('inventory.products.actions.deactivate') }
                            : { action: 'activate', id: p.id, label: t('inventory.products.actions.activate') }],
                        [{ action: 'delete', id: p.id, label: t('inventory.products.actions.delete'), danger: true }]
                    ],
                    ariaLabel: t('inventory.products.columns.acciones')
                })}</td>
            </tr>
        `).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('inventory.products.columns.sku')}</th>
                        <th>${t('inventory.products.columns.nombre')}</th>
                        <th>${t('inventory.products.columns.unidad')}</th>
                        <th>${t('inventory.products.columns.stock_total')}</th>
                        <th>${t('inventory.products.columns.estado')}</th>
                        <th>${t('inventory.products.columns.creado')}</th>
                        <th class="col-actions">${t('inventory.products.columns.acciones')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function renderStockCell(product) {
        const total = state.stockTotals[product.id];

        if (!total) {
            return '<span class="text-xs text-muted">—</span>';
        }

        return `
            <div class="font-medium">${formatNumber(total.cantidad_fisica)}</div>
            <div class="text-xs text-muted">${t('inventory.products.stock.available', { cantidad: formatNumber(total.cantidad_disponible) })}</div>
        `;
    }

    function renderPagination() {
        const root = container.querySelector('[data-products-pagination]');
        if (!root) return;

        const { total, page, per_page, has_next } = state.pagination;
        const totalPages = Math.max(1, Math.ceil(total / per_page));

        root.innerHTML = `
            <div class="pagination-info">
                ${t('ui.pagination', { shown: state.items.length, total: total, label: t('inventory.products.pagination_label') })}
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

    function openModal(product = null) {
        state.editing = product;
        const isEdit = !!product;
        const title = isEdit ? t('inventory.products.modals.edit_title') : t('inventory.products.modals.create_title');

        const root = container.querySelector('[data-modal-root]');
        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${title}</h3>
                        <button class="modal-close" data-action="close-modal">×</button>
                    </div>
                    <form data-product-form>
                        <div class="modal-body">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.products.modals.labels.sku')} <span class="required">*</span></label>
                                    <input type="text" class="input" name="sku" required maxlength="100"
                                           value="${isEdit ? escapeHtml(product.sku) : ''}"
                                           placeholder="${t('inventory.products.modals.placeholders.sku')}">
                                    <span class="form-error hidden" data-error="sku"></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.products.modals.labels.nombre')} <span class="required">*</span></label>
                                    <input type="text" class="input" name="nombre" required maxlength="255"
                                           value="${isEdit ? escapeHtml(product.nombre) : ''}"
                                           placeholder="${t('inventory.products.modals.placeholders.nombre')}">
                                    <span class="form-error hidden" data-error="nombre"></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.products.modals.labels.unidad')} <span class="required">*</span></label>
                                    <select class="select" name="unidad_medida" required>
                                        ${UNIDADES.map(u => `<option value="${u}" ${isEdit && product.unidad_medida === u ? 'selected' : ''}>${u}</option>`).join('')}
                                    </select>
                                    <span class="form-hint">${t('inventory.products.modals.unit_hint')}</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.products.modals.labels.categoria')} <span class="required">*</span></label>
                                    <input type="text" class="input" name="categoria" maxlength="100"
                                           value="${isEdit && product.categoria ? escapeHtml(product.categoria) : ''}"
                                           placeholder="${t('inventory.products.modals.placeholders.categoria')}">
                                    <span class="form-error hidden" data-error="categoria"></span>
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label">${t('inventory.products.modals.labels.descripcion')}</label>
                                    <textarea class="textarea" name="descripcion" rows="2"
                                              maxlength="1000">${isEdit && product.descripcion ? escapeHtml(product.descripcion) : ''}</textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.products.modals.labels.peso')}</label>
                                    <input type="number" class="input" name="peso" step="0.01" min="0"
                                           value="${isEdit && product.peso ? product.peso : ''}">
                                    <span class="form-error hidden" data-error="peso"></span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">${t('inventory.products.modals.labels.volumen')}</label>
                                    <input type="number" class="input" name="volumen" step="0.0001" min="0"
                                           value="${isEdit && product.volumen ? product.volumen : ''}">
                                    <span class="form-error hidden" data-error="volumen"></span>
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label">${t('inventory.products.modals.labels.dimensiones')}</label>
                                    <input type="text" class="input" name="dimensiones" maxlength="100"
                                           value="${isEdit && product.dimensiones ? escapeHtml(product.dimensiones) : ''}"
                                           placeholder="${t('inventory.products.modals.placeholders.dimensiones')}">
                                </div>
                            </div>
                            <div data-form-error class="alert alert-danger hidden mt-4"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-action="close-modal">${t('inventory.products.modals.cancel')}</button>
                            <button type="submit" class="btn btn-primary" data-action="save-product">
                                ${isEdit ? t('inventory.products.modals.submit_edit') : t('inventory.products.modals.submit_create')}
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
    // API
    // ====================================================================

    async function loadProducts(page = 1) {
        state.loading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            Object.entries(state.filters).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) params[k] = v;
            });

            const response = await global.HttpClient.get('/inventory/products', { params });
            const data = response.data || response;

            state.items = data.data || [];
            state.pagination = data.pagination || state.pagination;
            state.pagination.page = page;

            if (can('inventory.stock.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER'])) {
              loadStockTotals(state.items);
            }
            return true;
        } catch (err) {
            console.error('[Products] load error:', err);
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.products.messages.load_error'));
            state.items = [];
            return false;
        } finally {
            state.loading = false;
            renderTable();
            renderPagination();
        }
    }

    // ====================================================================
    // STOCK (resumen por producto + desglose por ubicación)
    // ====================================================================

    async function fetchStockSummary(productoId) {
        const now = Date.now();
        const cached = stockCache.get(productoId);
        if (cached && (now - cached.at) < STOCK_TTL_MS) return cached.summary;

        const response = await global.HttpClient.get('/inventory/stock', {
            params: { producto_id: productoId, page: 1, per_page: 1 }
        });
        const payload = response.data || response;
        const summary = payload.summary || null;

        stockCache.set(productoId, { at: now, summary });
        return summary;
    }

    async function loadStockTotals(items) {
        const ids = items.map(p => p.id);
        const snapshot = ids.join(',');
        const CONCURRENCY = 5;

        for (let i = 0; i < ids.length; i += CONCURRENCY) {
            const chunk = ids.slice(i, i + CONCURRENCY);
            await Promise.all(chunk.map(async (id) => {
                try {
                    state.stockTotals[id] = await fetchStockSummary(id);
                } catch (err) {
                    console.warn('[Products] inventory summary error:', err.message);
                }
            }));

            // La página cambió mientras cargaba: descartar el render.
            if (!container || snapshot !== state.items.map(p => p.id).join(',')) return;
        }

        if (container && snapshot === state.items.map(p => p.id).join(',')) {
            renderTable();
        }
    }

    async function openStockModal(product) {
        if (!can('inventory.stock.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER'])) {
            showToast('warning', t('inventory.products.permission.title'),
                t('inventory.stock.permission.body', { permiso: 'inventory.stock.view' }));
            return;
        }

        const root = container.querySelector('[data-modal-root]');
        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('inventory.products.stock.title', { sku: escapeHtml(product.sku) })}</h3>
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
                params: { producto_id: product.id, page: 1, per_page: 100 }
            });
            const payload = response.data || response;
            renderStockModal(product, payload);
        } catch (err) {
            closeModal();
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.products.messages.stock_load_error'));
        }
    }

    function renderStockModal(product, payload) {
        const root = container.querySelector('[data-modal-root]');
        if (!root) return;

        const rows = payload.data || [];
        const summary = payload.summary || {};
        const total = Number(summary.unidades || 0);
        const truncado = rows.length < total;

        const groups = new Map();
        rows.forEach(r => {
            const key = `${r.ubicacion_id || 'sin-ubicacion'}|${r.estado || ''}`;
            const current = groups.get(key) || {
                ubicacion_codigo: r.ubicacion_codigo,
                estado: r.estado,
                cantidad_fisica: 0,
                cantidad_disponible: 0
            };
            current.cantidad_fisica += Number(r.cantidad_fisica || 0);
            current.cantidad_disponible += Number(r.cantidad_disponible || 0);
            groups.set(key, current);
        });

        const grouped = Array.from(groups.values()).sort((a, b) =>
            String(a.ubicacion_codigo || '').localeCompare(String(b.ubicacion_codigo || ''))
        );

        const body = grouped.length === 0
            ? `<div class="empty-state">
                   <div class="empty-state-title">${t('inventory.products.stock.empty_title')}</div>
                   <p class="text-muted">${t('inventory.products.stock.empty_message')}</p>
               </div>`
            : `
                <table class="table">
                    <thead>
                        <tr>
                            <th>${t('inventory.products.stock.columns.ubicacion')}</th>
                            <th class="text-right">${t('inventory.products.stock.columns.cantidad')}</th>
                            <th>${t('inventory.products.stock.columns.disponible')}</th>
                            <th>${t('inventory.products.stock.columns.estado')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${grouped.map(g => `
                            <tr>
                                <td class="font-mono text-xs">${g.ubicacion_codigo ? escapeHtml(g.ubicacion_codigo) : '—'}</td>
                                <td class="text-right">${formatNumber(g.cantidad_fisica)}</td>
                                <td>${formatNumber(g.cantidad_disponible)}</td>
                                <td><span class="badge badge-${estadoColor(g.estado)}">${escapeHtml(g.estado || '')}</span></td>
                            </tr>`).join('')}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="text-muted text-xs">${t('inventory.products.stock.total_label')}</td>
                            <td class="text-right font-bold">${formatNumber(summary.cantidad_fisica)}</td>
                            <td class="font-bold">${formatNumber(summary.cantidad_disponible)}</td>
                            <td class="text-xs text-muted">${t('inventory.products.stock.units', { total: total })}</td>
                        </tr>
                    </tfoot>
                </table>
                ${truncado ? `<p class="text-xs text-muted mt-3">${t('inventory.products.stock.truncated')}</p>` : ''}`;

        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('inventory.products.stock.title', { sku: escapeHtml(product.sku) })}</h3>
                        <button class="modal-close" data-action="close-modal">×</button>
                    </div>
                    <div class="modal-body">
                        <p class="text-sm text-muted mb-3">${escapeHtml(product.nombre)}</p>
                        ${body}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    </div>
                </div>
            </div>`;
    }

    async function saveProduct(formData) {
        const isEdit = !!state.editing;
        const url = isEdit ? `/inventory/products/${state.editing.id}` : '/inventory/products';
        const method = isEdit ? 'patch' : 'post';

        try {
            await global.HttpClient[method](url, formData);
            closeModal();
            const recargado = await loadProducts(state.pagination.page);
            if (recargado) {
                showToast('success', isEdit ? t('inventory.products.messages.updated') : t('inventory.products.messages.created'));
            }
        } catch (err) {
            const formError = container.querySelector('[data-form-error]');
            if (formError) {
                formError.textContent = err.message || t('inventory.products.messages.save_error');
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
            await global.HttpClient.post(`/inventory/products/${id}/${action}`);
            const recargado = await loadProducts(state.pagination.page);
            if (recargado) {
                showToast('success', activate ? t('inventory.products.messages.activated') : t('inventory.products.messages.deactivated'));
            }
        } catch (err) {
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.products.messages.toggle_error'));
        }
    }

    async function deleteProduct(id) {
        if (!confirm(t('inventory.products.messages.confirm_delete'))) return;

        try {
            await global.HttpClient.delete(`/inventory/products/${id}`);
            const recargado = await loadProducts(state.pagination.page);
            if (recargado) {
                showToast('success', t('inventory.products.messages.deleted'));
            }
        } catch (err) {
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.products.messages.delete_error'));
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
                const product = state.items.find(p => p.id === id);
                if (product) openModal(product);
                break;
            case 'stock':
                const stockProduct = state.items.find(p => p.id === id);
                if (stockProduct) openStockModal(stockProduct);
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
                deleteProduct(id);
                break;
            case 'apply-filters':
                readFilters();
                loadProducts(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
        }

        if (page) {
            loadProducts(parseInt(page, 10));
        }
    }

    function handleSubmit(e) {
        if (e.target.matches('[data-product-form]')) {
            e.preventDefault();
            const form = e.target;
            const data = Object.fromEntries(new FormData(form));

            // Limpiar campos vacíos y convertir numéricos
            Object.keys(data).forEach(k => {
                if (data[k] === '' || data[k] === null) {
                    delete data[k];
                } else if (k === 'peso' || k === 'volumen') {
                    data[k] = parseFloat(data[k]);
                }
            });

            saveProduct(data);
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
        state.filters = { search: '', unidad_medida: '', categoria: '', activo: '' };
        container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
        loadProducts(1);
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
        try {
            const d = new Date(iso);
            return d.toLocaleDateString(i18n.getLocale(), { day: '2-digit', month: 'short', year: 'numeric' });
        } catch { return iso; }
    }

    function estadoColor(value) {
        return ESTADO_COLORS[value] || 'neutral';
    }

    function formatNumber(value) {
        const n = parseFloat(value);
        if (!isFinite(n)) return '0';
        return Number.isInteger(n) ? String(n) : n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
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
                <h3 class="card-title">${t('inventory.products.permission.title')}</h3>
            </div>
            <div class="card-body">
                <p>${t('inventory.products.permission.body', { permiso: 'inventory.item.view' })}</p>
                <div class="mt-4">
                    <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                </div>
            </div>
        </div>`;

    const InventoryProductsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            container.innerHTML = buildTemplate();
            bindEvents();
            loadProducts(1);
        },

        onActivate(params) {
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            if (container && !container.querySelector('[data-products-table]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
            }
            loadProducts(1);
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('submit', handleSubmit);
                container.removeEventListener('input', handleInput);
                container.innerHTML = '';
            }
            container = null;
            stockCache.clear();
            state = {
                items: [],
                pagination: { total: 0, page: 1, per_page: 20, has_next: false },
                filters: { search: '', unidad_medida: '', categoria: '', activo: '' },
                loading: false,
                editing: null,
                stockTotals: {}
            };
        }
    };

    global.InventoryProductsModule = InventoryProductsModule;

})(window);
