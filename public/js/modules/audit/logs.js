/**
 * Audit Logs Module (visor de auditoría, sólo lectura) - v1.6.1.
 * GET /api/audit/logs (permiso audit.log.view, spec §7.1).
 *
 * Convención ModuleLoader: window.AuditLogsModule
 *
 * Filtros del backend: fecha_desde, fecha_hasta, usuario_id, accion
 * (= operacion exacta), entidad (= recurso exacto), resultado (SUCCESS|FAILURE),
 * page y per_page (máx. 100). El tenant siempre lo fija la sesión.
 *
 * No existe endpoint de escritura (append-only) ni de exportación
 * (audit.log.export no está sembrado): la pantalla es de consulta.
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    let state = {
        items: [],
        summary: { total: 0, exitosos: 0, fallidos: 0 },
        pagination: { page: 1, per_page: 25, total: 0, last_page: 1 },
        filters: { fecha_desde: '', fecha_hasta: '', usuario_id: '', accion: '', entidad: '', resultado: '' },
        users: {},
        loading: false,
        detail: null
    };

    const TEMPLATE = `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('audit.title'),
                subtitle: t('audit.subtitle')
            })}

            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4" data-audit-summary></div>

            <div class="card mb-4">
                <form data-audit-filters novalidate>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="form-group mb-0">
                            <label class="form-label">${t('audit.filters.fecha_desde')}</label>
                            <input type="date" class="input" name="fecha_desde" data-filter="fecha_desde">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">${t('audit.filters.fecha_hasta')}</label>
                            <input type="date" class="input" name="fecha_hasta" data-filter="fecha_hasta">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">${t('audit.filters.usuario')}</label>
                            <input type="text" class="input" name="usuario_id" data-filter="usuario_id"
                                   placeholder="${t('audit.filters.usuario_placeholder')}" maxlength="36">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">${t('audit.filters.accion')}</label>
                            <input type="text" class="input" name="accion" data-filter="accion"
                                   placeholder="${t('audit.filters.accion_placeholder')}" maxlength="100">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">${t('audit.filters.entidad')}</label>
                            <input type="text" class="input" name="entidad" data-filter="entidad"
                                   placeholder="products, locations, users..." maxlength="100">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">${t('audit.filters.resultado')}</label>
                            <select class="select" name="resultado" data-filter="resultado">
                                <option value="">${t('audit.results.all')}</option>
                                <option value="SUCCESS">${t('audit.results.success')}</option>
                                <option value="FAILURE">${t('audit.results.failure')}</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-2 mt-3">
                        <button type="button" class="btn btn-secondary" data-action="apply-filters">${t('audit.filters.apply')}</button>
                        <button type="button" class="btn btn-ghost" data-action="clear-filters">${t('audit.filters.clear')}</button>
                    </div>
                </form>
            </div>

            <div class="card p-0">
                <div class="table-wrapper" data-audit-table></div>
                <div data-audit-pagination class="pagination"></div>
            </div>
        </div>

        <div data-modal-root></div>
    `;

    // ====================================================================
    // PERMISOS
    // ====================================================================

    function getPermissions() {
        if (global.AuthState && typeof global.AuthState.getPermissions === 'function') {
            return global.AuthState.getPermissions();
        }
        return [];
    }

    function getRoles() {
        if (global.AuthState && typeof global.AuthState.getRoles === 'function') {
            return global.AuthState.getRoles();
        }
        return [];
    }

    function can(permission, fallbackRoles) {
        const permissions = getPermissions();
        if (permissions.length > 0) return permissions.includes(permission);
        const roles = getRoles();
        if (roles.length > 0) return fallbackRoles.some(r => roles.includes(r));
        return false;
    }

    const canView = () => can('audit.log.view', ['ADMIN', 'MANAGER']);
    const canViewUsers = () => can('users.user.view', ['ADMIN']);

    // ====================================================================
    // RENDER
    // ====================================================================

    function renderPermissionDenied() {
        container.innerHTML = `
            <div class="card max-w-md mx-auto mt-8">
                <div class="card-header">
                    <h3 class="card-title">${t('audit.permission.title')}</h3>
                </div>
                <div class="card-body">
                    <p>${t('audit.permission.body')}</p>
                    <div class="mt-4">
                        <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                    </div>
                </div>
            </div>`;
    }

    function renderSummary() {
        const root = container.querySelector('[data-audit-summary]');
        if (!root) return;

        const cards = [
            { label: t('audit.summary.total'), value: state.summary.total },
            { label: t('audit.summary.success'), value: state.summary.exitosos },
            { label: t('audit.summary.failure'), value: state.summary.fallidos }
        ];

        root.innerHTML = cards.map(c => `
            <div class="card p-3">
                <div class="text-xs text-muted">${c.label}</div>
                <div class="text-xl font-bold">${c.value}</div>
            </div>
        `).join('');
    }

    function userLabel(userId) {
        if (!userId) return '<span class="text-muted">—</span>';
        const user = state.users[userId];
        if (user) {
            const name = `${user.nombre || ''} ${user.apellido || ''}`.trim();
            const label = name || user.email || userId;
            return `<div class="font-medium">${escapeHtml(label)}</div>
                    <div class="text-xs text-muted font-mono">${escapeHtml(userId)}</div>`;
        }
        return `<span class="font-mono text-xs" title="${escapeHtml(userId)}">${escapeHtml(shortId(userId))}</span>`;
    }

    function shortId(id) {
        return String(id).length > 12 ? String(id).substring(0, 8) + '…' : String(id);
    }

    function renderTable() {
        const root = container.querySelector('[data-audit-table]');
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
                    <div class="empty-state-title">${t('audit.empty.title')}</div>
                    <p class="text-muted">${t('audit.empty.message')}</p>
                </div>`;
            return;
        }

        const rows = state.items.map((row, index) => `
            <tr>
                <td class="text-xs font-mono">${escapeHtml(row.timestamp || '')}</td>
                <td>${userLabel(row.usuario_id)}</td>
                <td class="text-xs font-mono">${escapeHtml(row.operacion || '')}</td>
                <td class="text-xs">
                    ${row.recurso ? escapeHtml(row.recurso) : '<span class="text-muted">—</span>'}
                    ${row.entidad_id ? `<div class="text-muted font-mono">${escapeHtml(shortId(row.entidad_id))}</div>` : ''}
                </td>
                <td>${row.resultado === 'FAILURE'
                    ? `<span class="badge badge-danger">${t('audit.results.failure_badge')}</span>`
                    : `<span class="badge badge-success">${t('audit.results.success')}</span>`}</td>
                <td class="table-actions col-actions">
                    <button class="btn btn-ghost btn-sm" data-action="detail" data-index="${index}">${t('audit.columns.detail')}</button>
                </td>
            </tr>
        `).join('');

        root.innerHTML = `
            <table class="table">
                <thead>
                    <tr>
                        <th>${t('audit.columns.date')}</th>
                        <th>${t('audit.columns.user')}</th>
                        <th>${t('audit.columns.action')}</th>
                        <th>${t('audit.columns.entity')}</th>
                        <th>${t('audit.columns.result')}</th>
                        <th class="col-actions">${t('audit.columns.detail')}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function renderPagination() {
        const root = container.querySelector('[data-audit-pagination]');
        if (!root) return;

        const { page, per_page, total, last_page } = state.pagination;
        const totalPages = Math.max(1, last_page || 1);

        root.innerHTML = `
            <div class="pagination-info">
                ${t('ui.pagination', { shown: state.items.length, total: total, label: t('ui.records') })} · ${per_page} ${t('audit.pagination.per_page')}
            </div>
            <div class="pagination-controls">
                <button class="page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>‹</button>
                <span class="page-btn is-active">${page} / ${totalPages}</span>
                <button class="page-btn" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>›</button>
            </div>
        `;
    }

    // ====================================================================
    // DETALLE
    // ====================================================================

    function prettyJson(value) {
        if (value === null || value === undefined || value === '') return '';
        if (typeof value === 'string') {
            try {
                return JSON.stringify(JSON.parse(value), null, 2);
            } catch (e) {
                return value;
            }
        }
        try {
            return JSON.stringify(value, null, 2);
        } catch (e) {
            return String(value);
        }
    }

    function valueBlock(label, value) {
        const json = prettyJson(value);
        if (!json) {
            return `
                <div>
                    <div class="text-xs text-muted mb-1">${label}</div>
                    <div class="text-muted text-sm">${t('audit.detail.no_changes')}</div>
                </div>`;
        }
        return `
            <div>
                <div class="text-xs text-muted mb-1">${label}</div>
                <pre class="text-xs p-2 rounded" style="white-space: pre-wrap; word-break: break-word;">${escapeHtml(json)}</pre>
            </div>`;
    }

    function openDetail(row) {
        state.detail = row;

        const root = container.querySelector('[data-modal-root]');
        root.innerHTML = `
            <div class="modal-overlay" data-modal>
                <div class="modal modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">${t('audit.detail.title')}</h3>
                        ${row.resultado === 'FAILURE'
                            ? `<span class="badge badge-danger">${t('audit.results.failure_badge')}</span>`
                            : `<span class="badge badge-success">${t('audit.results.success')}</span>`}
                        <button class="modal-close" data-action="close-modal" aria-label="${t('ui.close')}">×</button>
                    </div>
                    <div class="modal-body">
                        <div class="text-sm font-medium mb-1">${escapeHtml(row.operacion || '')}</div>
                        <div class="text-xs text-muted mb-3 font-mono">${escapeHtml(row.id || '')}</div>

                        ${row.resultado === 'FAILURE' && row.razon_fallo
                            ? `<div class="alert alert-danger">${escapeHtml(row.razon_fallo)}</div>`
                            : ''}

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <div class="text-xs text-muted mb-1">${t('audit.columns.date')}</div>
                                <div class="text-sm font-mono">${escapeHtml(row.timestamp || '')}</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted mb-1">${t('audit.columns.user')}</div>
                                <div class="text-sm font-mono">${escapeHtml(row.usuario_id || '—')}</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted mb-1">${t('audit.detail.module_resource')}</div>
                                <div class="text-sm">${escapeHtml(row.modulo || '—')} / ${escapeHtml(row.recurso || '—')}</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted mb-1">${t('audit.detail.entity_id')}</div>
                                <div class="text-sm font-mono">${escapeHtml(row.entidad_id || '—')}</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted mb-1">${t('audit.detail.change_type')}</div>
                                <div class="text-sm">${escapeHtml(row.tipo_cambio || '—')}</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted mb-1">${t('audit.detail.ip_duration')}</div>
                                <div class="text-sm">${escapeHtml(row.ip_address || '—')}
                                    ${row.duracion_operacion !== null && row.duracion_operacion !== undefined
                                        ? ` · ${escapeHtml(String(row.duracion_operacion))} ms`
                                        : ''}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-muted mb-1">Request id</div>
                                <div class="text-sm font-mono">${escapeHtml(row.request_id || '—')}</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted mb-1">User agent</div>
                                <div class="text-xs">${escapeHtml(row.user_agent || '—')}</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            ${valueBlock(t('audit.detail.values_before'), row.valores_anteriores)}
                            ${valueBlock(t('audit.detail.values_after'), row.valores_nuevos)}
                        </div>

                        <div class="mb-2">
                            ${valueBlock(t('audit.detail.extra_details'), row.detalles_adicionales)}
                        </div>

                        <div class="text-xs text-muted">
                            <div>hash_integridad: <span class="font-mono">${escapeHtml(row.hash_integridad || '—')}</span></div>
                            <div>prev_hash: <span class="font-mono">${escapeHtml(row.prev_hash || '—')}</span></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    </div>
                </div>
            </div>
        `;
    }

    function closeModal() {
        const root = container.querySelector('[data-modal-root]');
        if (root) root.innerHTML = '';
        state.detail = null;
    }

    // ====================================================================
    // API
    // ====================================================================

    async function loadLogs(page = 1) {
        state.loading = true;
        renderTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            Object.entries(state.filters).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) params[k] = v;
            });

            const response = await global.HttpClient.get('/audit/logs', { params });
            const payload = response.data || response;

            state.items = payload.data || [];
            state.summary = payload.summary || state.summary;
            state.pagination = payload.pagination || state.pagination;
            state.pagination.page = page;
        } catch (err) {
            console.error('[Audit] load error:', err);
            showToast('danger', t('ui.error_generic'), err.message || t('audit.messages.load_error'));
            state.items = [];
        } finally {
            state.loading = false;
            renderSummary();
            renderTable();
            renderPagination();
        }
    }

    // Directorio de usuarios opcional (requiere users.user.view) para
    // mostrar nombre/email en lugar del UUID. Silencioso si no hay permiso.
    async function loadUsers() {
        if (!canViewUsers()) return;

        try {
            const response = await global.HttpClient.get('/users', {
                params: { page: 1, per_page: 100 }
            });
            const payload = response.data || response;
            (payload.data || []).forEach(u => {
                if (u && u.id) state.users[u.id] = u;
            });
        } catch (err) {
            console.warn('[Audit] users directory not loaded:', err.message);
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
        const page = btn.dataset.page;
        const index = btn.dataset.index;

        switch (action) {
            case 'apply-filters':
                readFilters();
                loadLogs(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
            case 'detail': {
                const row = state.items[parseInt(index, 10)];
                if (row) openDetail(row);
                break;
            }
            case 'close-modal':
                closeModal();
                break;
        }

        if (page && !btn.disabled) {
            loadLogs(parseInt(page, 10));
        }
    }

    function handleSubmit(e) {
        if (e.target.matches('[data-audit-filters]')) {
            e.preventDefault();
            readFilters();
            loadLogs(1);
        }
    }

    function readFilters() {
        container.querySelectorAll('[data-filter]').forEach(el => {
            state.filters[el.dataset.filter] = el.value;
        });
    }

    function clearFilters() {
        state.filters = { fecha_desde: '', fecha_hasta: '', usuario_id: '', accion: '', entidad: '', resultado: '' };
        container.querySelectorAll('[data-filter]').forEach(el => { el.value = ''; });
        loadLogs(1);
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
            .replace(/'/g, '&#039;');
    }

    function showToast(type, title, message = '') {
        if (global.EventBus) {
            global.EventBus.emit('toast:show', { type, title, message, duration: 4000 });
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const AuditLogsModule = {
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
            loadUsers();
            loadLogs(1);
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            if (!container.querySelector('[data-audit-table]')) {
                container.innerHTML = TEMPLATE;
                bindEvents();
            }
            loadLogs(state.pagination.page || 1);
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
                summary: { total: 0, exitosos: 0, fallidos: 0 },
                pagination: { page: 1, per_page: 25, total: 0, last_page: 1 },
                filters: { fecha_desde: '', fecha_hasta: '', usuario_id: '', accion: '', entidad: '', resultado: '' },
                users: {},
                loading: false,
                detail: null
            };
        }
    };

    global.AuditLogsModule = AuditLogsModule;

})(window);
