/**
 * Users Module (pantalla de control de usuarios) - v1.12.0.
 * GET/POST/PATCH/DELETE /api/users, POST /users/{id}/status,
 * POST /users/{id}/password/reset, roles por usuario y la pestana de
 * permisos temporales (GET/POST /api/temporary-permissions, DELETE .../revoke).
 *
 * Convencion ModuleLoader: window.UsersUsersModule (ruta users/users).
 *
 * Politica de borrado (v1.13.9): no hay restauracion; el borrado lógico
 * deja el registro en BD (auditoria) pero no se lista nunca. Un email
 * borrado puede volver a usarse en un usuario nuevo.
 */
(function(global) {
    'use strict';

    let container = null;

    let state = {
        tab: 'users',
        items: [],
        pagination: { page: 1, per_page: 20, total: 0, has_next: false },
        filters: { estado: '', search: '' },
        loading: false,
        roles: [],
        users: [],
        temp: { items: [], total: 0, loading: false, user_id: '', user_label: '', page: 1, per_page: 20, pagination: null },
        modal: null,
        sending: false
    };

    const ESTADOS = ['ACTIVO', 'INACTIVO', 'SUSPENDIDO', 'PENDIENTE_CONFIRMACION'];
    const ESTADOS_EDICION = ['ACTIVO', 'INACTIVO', 'SUSPENDIDO'];

    // Etiquetas con claves estaticas (check-i18n no resuelve claves dinamicas).
    function estadoLabel(valor) {
        switch (valor) {
            case 'ACTIVO': return t('users.status.ACTIVO');
            case 'INACTIVO': return t('users.status.INACTIVO');
            case 'SUSPENDIDO': return t('users.status.SUSPENDIDO');
            case 'PENDIENTE_CONFIRMACION': return t('users.status.PENDIENTE_CONFIRMACION');
            default: return valor || '';
        }
    }

    // ====================================================================
    // PERMISOS
    // ====================================================================

    function can(permission, fallbackRoles) {
        if (global.UI && typeof global.UI.can === 'function') {
            return global.UI.can(permission, fallbackRoles);
        }
        return false;
    }

    const canView = () => can('users.user.view', ['ADMIN']);
    const canCreate = () => can('users.user.create', ['ADMIN']);
    const canEdit = () => can('users.user.edit', ['ADMIN']);
    const canStatus = () => can('users.user.status', ['ADMIN', 'MANAGER']);
    const canReset = () => can('users.password.reset', ['ADMIN', 'MANAGER']);
    const canDelete = () => can('users.user.delete', ['ADMIN']);
    const canViewTemp = () => can('temporary.permission.view', ['ADMIN', 'MANAGER']);
    const canGrant = () => can('temporary.permission.grant', ['ADMIN']);
    const canRevoke = () => can('temporary.permission.revoke', ['ADMIN', 'MANAGER']);
    const canManageMembers = () => can('groups.group.manage_members', ['ADMIN', 'MANAGER']);

    // ====================================================================
    // PLANTILLA
    // ====================================================================

    function estadoCatalogo() {
        return ESTADOS.map(e => ({
            value: e,
            label: estadoLabel(e),
            color: e === 'ACTIVO' ? 'success'
                : e === 'SUSPENDIDO' ? 'danger'
                : e === 'PENDIENTE_CONFIRMACION' ? 'warning'
                : 'neutral'
        }));
    }

    function buildTemplate() {
        const createBtn = canCreate()
            ? `<button class="btn btn-primary btn-sm" data-action="open-create">${t('users.actions.create')}</button>`
            : '';

        const tempTab = canViewTemp()
            ? `<button class="tab${state.tab === 'temp' ? ' is-active' : ''}" data-action="tab" data-tab="temp">${t('users.tabs.temp')}</button>`
            : '';

        return `
            <div class="max-w-7xl mx-auto">
                ${UI.pageHeader({
                    title: t('users.title'),
                    subtitle: t('users.subtitle'),
                    actionsHtml: createBtn
                })}

                <div class="tabs mb-4">
                    <button class="tab${state.tab === 'users' ? ' is-active' : ''}" data-action="tab" data-tab="users">${t('users.tabs.users')}</button>
                    ${tempTab}
                </div>

                <div data-panel="users" class="${state.tab === 'users' ? '' : 'hidden'}">
                    <div class="card mb-4">
                        <form data-users-filters novalidate>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div class="form-group mb-0">
                                    <label class="form-label">${t('users.filters.estado')}</label>
                                    <select class="select" name="estado" data-filter="estado">
                                        <option value="">${t('users.filters.all')}</option>
                                        ${ESTADOS.map(e => `<option value="${e}"${state.filters.estado === e ? ' selected' : ''}>${estadoLabel(e)}</option>`).join('')}
                                    </select>
                                </div>
                                <div class="form-group mb-0">
                                    <label class="form-label">${t('users.filters.search')}</label>
                                    <input type="text" class="input" name="search" data-filter="search"
                                           value="${UI.escapeHtml(state.filters.search)}"
                                           placeholder="${t('users.filters.search_placeholder')}" maxlength="100">
                                </div>
                            </div>
                            <div class="flex items-center gap-4 mt-3 flex-wrap">
                                <button type="button" class="btn btn-secondary" data-action="apply-filters">${t('users.filters.apply')}</button>
                                <button type="button" class="btn btn-ghost" data-action="clear-filters">${t('users.filters.clear')}</button>
                            </div>
                        </form>
                    </div>

                    <div class="card p-0">
                        <div class="table-wrapper" data-users-table></div>
                        <div data-users-pagination class="pagination"></div>
                    </div>
                </div>

                <div data-panel="temp" class="${state.tab === 'temp' ? '' : 'hidden'}">
                    ${canViewTemp() ? `
                        <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                            <p class="text-muted text-sm">${t('users.temp.subtitle')}</p>
                            ${canGrant() ? `<button class="btn btn-primary btn-sm" data-action="open-grant">${t('users.temp.grant')}</button>` : ''}
                        </div>
                        <div data-temp-filter-user class="mb-3"></div>
                        <div class="card p-0">
                            <div class="table-wrapper" data-temp-table></div>
                            <div data-temp-pagination class="pagination"></div>
                        </div>
                    ` : ''}
                </div>
            </div>

            <div data-modal-root></div>
        `;
    }

    function renderPermissionDenied() {
        container.innerHTML = `
            <div class="card max-w-md mx-auto mt-8">
                <div class="card-header">
                    <h3 class="card-title">${t('users.denied.title')}</h3>
                </div>
                <div class="card-body">
                    <p>${t('users.denied.body')}</p>
                    <div class="mt-4">
                        <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                    </div>
                </div>
            </div>`;
    }

    // ====================================================================
    // TABLA DE USUARIOS
    // ====================================================================

    /** Badge de procedencia del rol (v1.13.3; origen direct|group|temporary). */
    function roleOriginBadge(rol) {
        if (rol.origen !== 'group') return '';
        // Con group_name (modal de roles) el chip nombra el grupo; en la
        // tabla de la lista el endpoint recorta los campos y dice "Por grupo".
        const texto = rol.group_name
            ? t('users.roles.from_group_named', { group: UI.escapeHtml(rol.group_name) })
            : t('users.roles.from_group');
        return `<span class="badge badge-info">${texto}</span>`;
    }

    /** Celda Roles de la tabla (v1.13.3): chips con badge de origen. */
    function rolesCell(u) {
        const roles = u.roles || [];
        if (roles.length === 0) return '<span class="text-muted">—</span>';

        // Si falta espacio la celda se trunca (overflow) y el title muestra
        // el listado completo: los chips nunca se parten en dos lineas.
        const title = roles.map(r =>
            r.nombre + (r.origen === 'group' ? ' (' + t('users.roles.from_group') + ')' : '')
        ).join(', ');

        return `<div class="cell-clip" title="${UI.escapeHtml(title)}"><div class="flex gap-1">${roles.map(r => `
            <span class="inline-flex items-center gap-1">
                <span class="badge badge-neutral">${UI.escapeHtml(r.nombre)}</span>${roleOriginBadge(r)}
            </span>`).join('')}</div></div>`;
    }

    // ------------------------------------------------------------------
    // Acciones de fila (UI.rowActions) - v1.13.17.
    //
    // Reglas:
    //  - Principal: Editar.
    //  - Menu en 3 grupos: [Roles, Ver permisos temporales] |
    //    [Cambiar estado, Restablecer contrasena] | [Eliminar (rojo)].
    //  - Escalada: sobre el propio usuario no se ofrecen Eliminar ni
    //    Cambiar estado; y un actor que no es ADMIN no actua sobre ADMIN.
    //  - Cada opcion sólo si tiene el permiso; sin opciones, sin menu.

    function actorRoles() {
        if (global.AuthState && typeof global.AuthState.getRoles === 'function') {
            return global.AuthState.getRoles();
        }
        return [];
    }

    function isAdmin() {
        return actorRoles().indexOf('ADMIN') !== -1;
    }

    /** Escalada: no ofrecer acciones peligrosas sobre ADMIN salvo ADMIN. */
    function canActOnUser(user, dangerous) {
        if (!user) return false;
        const targetIsAdmin = (user.roles || []).some(r => r.nombre === 'ADMIN');
        if (targetIsAdmin && !isAdmin()) return false;
        if (dangerous && user.id === currentUserId()) return false;
        return true;
    }

    function currentUserId() {
        if (global.AuthState && typeof global.AuthState.getUserId === 'function') {
            return global.AuthState.getUserId();
        }
        return null;
    }

    function rowActionsFor(u) {
        const verRoles = canView() ? { action: 'open-roles', id: u.id, label: t('users.actions.roles') } : null;
        const verTemp = canViewTemp() ? { action: 'open-temp-user', id: u.id, label: t('users.actions.temp_view') } : null;
        const estado = canStatus() && canActOnUser(u, true)
            ? { action: 'open-status', id: u.id, label: t('users.actions.status') }
            : null;
        const reset = canReset() && canActOnUser(u, false)
            ? { action: 'open-reset', id: u.id, label: t('users.actions.reset') }
            : null;
        const eliminar = canDelete() && canActOnUser(u, true)
            ? { action: 'open-delete', id: u.id, label: t('users.actions.delete'), danger: true }
            : null;

        return UI.rowActions({
            primary: canEdit() ? { action: 'open-edit', id: u.id, label: t('users.actions.edit') } : null,
            groups: [
                [verRoles, verTemp],
                [estado, reset],
                [eliminar]
            ],
            ariaLabel: t('users.columns.actions')
        });
    }

    function renderUsersTable() {
        const root = container.querySelector('[data-users-table]');
        if (!root) return;

        if (state.loading) {
            root.innerHTML = UI.tableSkeleton([
                { label: t('users.columns.email') },
                { label: t('users.columns.name') },
                { label: t('users.columns.status') },
                { label: t('users.columns.roles') },
                { label: t('users.columns.last_login') },
                { label: t('users.columns.actions'), className: 'col-actions' }
            ], 8, [30, 20, 12, 18, 12, 8]);
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('users.empty.title'),
                message: t('users.empty.message')
            });
            return;
        }

        const catalogo = estadoCatalogo();
        const rows = state.items.map(u => {
            const acciones = rowActionsFor(u);

            const estado = UI.estadoBadge(u.estado, catalogo);

            return `
                <tr>
                    <td>
                        <div class="font-medium">${UI.escapeHtml(u.email || '')}</div>
                        ${u.telefono ? `<div class="text-xs text-muted">${UI.escapeHtml(u.telefono)}</div>` : ''}
                    </td>
                    <td>${UI.escapeHtml(((u.nombre || '') + ' ' + (u.apellido || '')).trim())}</td>
                    <td>${estado}</td>
                    <td class="nowrap-cell">${rolesCell(u)}</td>
                    <td class="text-xs nowrap-cell">${u.ultimo_login
                        ? `<span title="${UI.escapeHtml(u.ultimo_login)}">${UI.escapeHtml(UI.formatDateTime(u.ultimo_login))}</span>`
                        : '<span class="text-muted">—</span>'}</td>
                    <td class="table-actions col-actions">${acciones}</td>
                </tr>`;
        }).join('');

        root.innerHTML = UI.tableShell([
            { label: t('users.columns.email') },
            { label: t('users.columns.name') },
            { label: t('users.columns.status') },
            { label: t('users.columns.roles') },
            { label: t('users.columns.last_login') },
            { label: t('users.columns.actions'), className: 'col-actions' }
        ], rows);
    }

    function renderUsersPagination() {
        const root = container.querySelector('[data-users-pagination]');
        if (!root) return;
        UI.renderPagination(root, {
            pagination: state.pagination,
            shown: state.items.length,
            label: t('users.records')
        });
    }

    // ====================================================================
    // TABLA DE PERMISOS TEMPORALES
    // ====================================================================

    function criticidadBadge(valor) {
        const color = valor === 'CRITICAL' ? 'danger'
            : valor === 'HIGH' ? 'warning'
            : valor === 'MEDIUM' ? 'info'
            : 'neutral';
        return `<span class="badge badge-${color}">${UI.escapeHtml(valor || '—')}</span>`;
    }

    function renderTempFilterUser() {
        const root = container.querySelector('[data-temp-filter-user]');
        if (!root) return;
        if (!state.temp.user_id) {
            root.innerHTML = '';
            return;
        }
        root.innerHTML = `
            <span class="chip chip-primary">
                ${t('users.temp.filtering', { usuario: UI.escapeHtml(state.temp.user_label || state.temp.user_id) })}
                <button type="button" class="chip-close" data-action="temp-clear-user" aria-label="${t('ui.close')}">&times;</button>
            </span>`;
    }

    function renderTempTable() {
        const root = container.querySelector('[data-temp-table]');
        if (!root) return;

        if (state.temp.loading) {
            root.innerHTML = UI.tableSkeleton([
                { label: t('users.temp.columns.user') },
                { label: t('users.temp.columns.permission') },
                { label: t('users.temp.columns.motive') },
                { label: t('users.temp.columns.expires') },
                { label: t('users.temp.columns.granted_by') },
                { label: t('users.temp.columns.state') },
                { label: t('users.columns.actions'), className: 'col-actions' }
            ], 6, [25, 20, 18, 15, 12, 8, 5]);
            return;
        }

        if (state.temp.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('users.temp.empty.title'),
                message: t('users.temp.empty.message')
            });
            return;
        }

        const rows = state.temp.items.map(row => {
            const vigente = !!row.activo && String(row.fecha_expiracion || '') > new Date().toISOString().slice(0, 19).replace('T', ' ');
            const acciones = UI.rowActions({
                primary: null,
                groups: [[
                    (canRevoke() && row.activo)
                        ? { action: 'open-revoke', id: row.id, label: t('users.temp.revoke'), danger: true }
                        : null
                ]],
                ariaLabel: t('users.columns.actions')
            });
            const estado = row.activo
                ? (vigente
                    ? `<span class="badge badge-success">${t('users.temp.active')}</span>`
                    : `<span class="badge badge-warning">${t('users.temp.expired')}</span>`)
                : `<span class="badge badge-neutral">${t('users.temp.revoked')}</span>`;

            return `
                <tr>
                    <td>
                        <div class="font-medium">${UI.escapeHtml(row.user_email || row.user_id || '')}</div>
                    </td>
                    <td>
                        <div class="font-mono text-xs">${UI.escapeHtml(row.permission_identificador || row.permission_id || '')}</div>
                        <div>${criticidadBadge(row.permission_criticidad)}</div>
                    </td>
                    <td class="text-xs">${UI.escapeHtml(row.motivo || '')}</td>
                    <td class="text-xs">${UI.escapeHtml(row.fecha_expiracion ? UI.formatDateTime(row.fecha_expiracion) : '')}</td>
                    <td class="text-xs">${UI.escapeHtml(row.granted_by_email || row.granted_by_user_id || '—')}</td>
                    <td>${estado}</td>
                    <td class="table-actions col-actions">${acciones}</td>
                </tr>`;
        }).join('');

        root.innerHTML = UI.tableShell([
            { label: t('users.temp.columns.user') },
            { label: t('users.temp.columns.permission') },
            { label: t('users.temp.columns.motive') },
            { label: t('users.temp.columns.expires') },
            { label: t('users.temp.columns.granted_by') },
            { label: t('users.temp.columns.state') },
            { label: t('users.columns.actions'), className: 'col-actions' }
        ], rows);
    }

    function renderTempPagination() {
        const root = container.querySelector('[data-temp-pagination]');
        if (!root) return;
        UI.renderPagination(root, {
            pagination: state.temp.pagination || { page: 1, per_page: state.temp.per_page, total: state.temp.total, has_next: false },
            shown: state.temp.items.length,
            label: t('users.records')
        });
    }

    // ====================================================================
    // CARGA DE DATOS
    // ====================================================================

    async function loadUsers(page = 1) {
        state.loading = true;
        renderUsersTable();

        try {
            const params = { page, per_page: state.pagination.per_page };
            if (state.filters.estado) params.estado = state.filters.estado;
            if (state.filters.search) params.search = state.filters.search;

            const response = await global.HttpClient.get('/users', { params });
            const payload = response && response.data ? response.data : response;

            state.items = (payload && payload.data) || [];
            state.pagination = (payload && payload.pagination) || state.pagination;
            state.pagination.page = page;
            return true;
        } catch (err) {
            console.error('[Users] load error:', err);
            UI.toast('danger', t('ui.error_generic'), UI.errorMessage(err) || t('users.errors.load'));
            state.items = [];
            return false;
        } finally {
            state.loading = false;
            renderUsersTable();
            renderUsersPagination();
        }
    }

    // Recarga automática cuando la expiración más próxima de la lista pase
    // (v1.17.6): la fila vigente que caduca desaparece sola sin esperar a
    // que el admin recargue la pestaña. Un sólo timer; se limpia al salir.
    let tempRefreshTimer = null;

    function clearTempAutoRefresh() {
        if (tempRefreshTimer !== null) {
            clearTimeout(tempRefreshTimer);
            tempRefreshTimer = null;
        }
    }

    function scheduleTempAutoRefresh() {
        clearTempAutoRefresh();
        if (state.tab !== 'temp') return;
        const now = Date.now();
        let nearest = null;
        (state.temp.items || []).forEach(row => {
            if (!row.activo || !row.fecha_expiracion) return;
            // fecha_expiracion es naive 'Y-m-d H:i:s' en UTC (la escribe PHP en
            // UTC; misma convencion que el badge de la fila y que ui/dom.js):
            // se anade la Z para que el epoch no dependa de la zona del
            // navegador. Sin ella, un navegador con desfase horario calculaba
            // el instante equivocado y el timer no se programaba.
            const raw = String(row.fecha_expiracion).replace(' ', 'T');
            const ts = Date.parse(/[zZ]|[+\-]\d{2}:?\d{2}$/.test(raw) ? raw : raw + 'Z');
            if (Number.isNaN(ts) || ts <= now) return;
            if (nearest === null || ts < nearest) nearest = ts;
        });
        if (nearest === null) return;
        const delay = Math.min(nearest - now + 1000, 2147483000);
        tempRefreshTimer = setTimeout(() => {
            tempRefreshTimer = null;
            if (state.tab === 'temp') loadTemp(state.temp.page || 1);
        }, delay);
    }

    async function loadTemp(page = 1) {
        state.temp.loading = true;
        state.temp.page = page;
        renderTempTable();

        try {
            const params = { page, per_page: state.temp.per_page };
            if (state.temp.user_id) params.user_id = state.temp.user_id;

            const response = await global.HttpClient.get('/temporary-permissions', { params });
            const payload = response && response.data ? response.data : response;

            state.temp.items = (payload && payload.temporary_permissions) || [];
            state.temp.total = (payload && payload.total) || state.temp.items.length;
            if (payload && payload.pagination) state.temp.pagination = payload.pagination;
            return true;
        } catch (err) {
            console.error('[Users] temp load error:', err);
            UI.toast('danger', t('ui.error_generic'), UI.errorMessage(err) || t('users.temp.load_error'));
            state.temp.items = [];
            return false;
        } finally {
            state.temp.loading = false;
            renderTempFilterUser();
            renderTempTable();
            renderTempPagination();
            scheduleTempAutoRefresh();
        }
    }

    async function loadRolesCatalog() {
        try {
            const response = await global.HttpClient.get('/roles');
            const payload = response && response.data ? response.data : response;
            state.roles = (payload && payload.roles) || [];
        } catch (err) {
            console.warn('[Users] roles catalog not loaded:', err.message);
            state.roles = [];
        }
    }

    async function loadUsersDirectory() {
        try {
            const response = await global.HttpClient.get('/users', { params: { page: 1, per_page: 100 } });
            const payload = response && response.data ? response.data : response;
            state.users = (payload && payload.data) || [];
        } catch (err) {
            console.warn('[Users] directory not loaded:', err.message);
            state.users = [];
        }
    }

    async function loadPermissionsCatalog() {
        try {
            const response = await global.HttpClient.get('/permissions', {
                params: { include_inactive: 'false' }
            });
            const payload = response && response.data ? response.data : response;
            state.permissions = (payload && payload.permissions) || [];
        } catch (err) {
            console.warn('[Users] permissions catalog not loaded:', err.message);
            state.permissions = [];
        }
    }

    // ====================================================================
    // PESTANAS
    // ====================================================================

    function switchTab(tab) {
        if (tab === 'temp' && !canViewTemp()) return;
        state.tab = tab;
        UI.syncTabParam(tab);

        const panelUsers = container.querySelector('[data-panel="users"]');
        const panelTemp = container.querySelector('[data-panel="temp"]');
        if (panelUsers) panelUsers.classList.toggle('hidden', tab !== 'users');
        if (panelTemp) panelTemp.classList.toggle('hidden', tab !== 'temp');

        container.querySelectorAll('[data-action="tab"]').forEach(btn => {
            btn.classList.toggle('is-active', btn.dataset.tab === tab);
        });

        if (tab === 'users') {
            clearTempAutoRefresh();
            return loadUsers(state.pagination.page || 1);
        }
        return loadTemp();
    }

    // ====================================================================
    // MODALES
    // ====================================================================

    function getModalRoot() {
        return container ? container.querySelector('[data-modal-root]') : null;
    }

    function closeModal() {
        state.modal = null;
        state.sending = false;
        const root = getModalRoot();
        if (root) UI.closeModal(root);
    }

    function setSending(sending) {
        state.sending = sending;
        const root = getModalRoot();
        if (!root) return;
        root.querySelectorAll('[data-modal-send]').forEach(btn => { btn.disabled = sending; });
    }

    function applyErrors() {
        const modal = state.modal;
        const root = getModalRoot();
        if (!modal || !root) return;
        if (modal.formError) UI.showFormError(root, modal.formError);
        Object.keys(modal.errors || {}).forEach(field => {
            UI.showFieldError(root, field, modal.errors[field]);
        });
    }

    function generatePassword() {
        const mayus = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        const minus = 'abcdefghijkmnpqrstuvwxyz';
        const digitos = '23456789';
        const todos = mayus + minus + digitos;
        const rand = (max) => {
            if (global.crypto && typeof global.crypto.getRandomValues === 'function') {
                const buf = new Uint32Array(1);
                global.crypto.getRandomValues(buf);
                return buf[0] % max;
            }
            return Math.floor(Math.random() * max);
        };
        let out = mayus[rand(mayus.length)] + minus[rand(minus.length)] + digitos[rand(digitos.length)];
        for (let i = 0; i < 9; i++) out += todos[rand(todos.length)];
        return out.split('').sort(() => Math.random() - 0.5).join('');
    }

    // ---------------------------------------------------------------- Alta

    // ---------------------------------------------------------- Escalada
    // v1.12.1: el actor sólo puede asignar roles cuyos permisos estén
    // contenidos en sus permisos efectivos (misma regla que valida el
    // backend con assertCanAssignRole). Se aplica al selector del modal
    // de roles y al select de rol del alta.

    function actorPermissions() {
        if (global.AuthState && typeof global.AuthState.getPermissions === 'function') {
            return global.AuthState.getPermissions();
        }
        return [];
    }

    function rolePermissionList(role) {
        let p = role ? role.permisos : null;
        if (typeof p === 'string') {
            try { p = JSON.parse(p); } catch (e) { p = []; }
        }
        return Array.isArray(p) ? p.filter(x => typeof x === 'string') : [];
    }

    function permissionGranted(codigo, granted) {
        if (granted.includes(codigo)) return true;
        return granted.some(g => {
            if (typeof g !== 'string' || g.indexOf('*') === -1) return false;
            const rx = '^' + g.split('*')
                .map(part => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
                .join('[a-z0-9_]+') + '$';
            return new RegExp(rx).test(codigo);
        });
    }

    function canAssignRole(role) {
        const needed = rolePermissionList(role);
        if (needed.length === 0) return true;
        const actor = actorPermissions();
        if (actor.length === 0) return false;
        return needed.every(p => permissionGranted(p, actor));
    }

    function assignableRoles() {
        return state.roles.filter(r => canAssignRole(r));
    }

    function openCreate() {
        if (!canCreate()) return;
        state.modal = { mode: 'create', errors: {}, formError: '' };
        renderModal();
        ensureRolesAndUsers().then(() => {
            if (state.modal && state.modal.mode === 'create') renderModal();
        });
    }

    function createBody() {
        const opciones = [{ value: '', label: t('users.create.no_role') }].concat(
            assignableRoles().map(r => ({ value: r.id, label: r.nombre }))
        );

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${UI.grid(`
                ${UI.field({ label: t('users.fields.email'), name: 'email', type: 'email', required: true, placeholder: 'user@empresa.com' })}
                ${UI.field({ label: t('users.fields.nombre'), name: 'nombre', type: 'text', required: true })}
            `, 2)}
            ${UI.grid(`
                ${UI.field({ label: t('users.fields.apellido'), name: 'apellido', type: 'text', required: true })}
                ${UI.field({ label: t('users.fields.telefono'), name: 'telefono', type: 'text' })}
            `, 2)}
            ${UI.grid(`
                ${UI.field({
                    label: t('users.fields.password'),
                    name: 'password',
                    type: 'text',
                    hint: t('users.fields.password_hint')
                })}
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" class="btn btn-secondary" data-action="gen-password">${t('users.gen_password')}</button>
                </div>
            `, 2)}
            ${UI.grid(`
                ${UI.field({
                    label: t('users.fields.role'),
                    name: 'role_id',
                    type: 'select',
                    options: opciones,
                    hint: t('users.create.role_hint')
                })}
            `, 1)}
        `;
    }

    async function submitCreate() {
        if (state.sending) return;
        const root = getModalRoot();
        UI.clearErrors(root);

        const email = fieldValue(root, 'email');
        const nombre = fieldValue(root, 'nombre');
        const apellido = fieldValue(root, 'apellido');
        const telefono = fieldValue(root, 'telefono');
        const password = fieldValue(root, 'password');
        const roleId = fieldValue(root, 'role_id');

        const errors = {};
        if (!email) errors.email = t('users.errors.required');
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = t('users.errors.email');
        if (!nombre) errors.nombre = t('users.errors.required');
        if (!apellido) errors.apellido = t('users.errors.required');

        if (Object.keys(errors).length > 0) {
            Object.entries(errors).forEach(([f, msg]) => UI.showFieldError(root, f, msg));
            return;
        }

        const payload = { email, nombre, apellido };
        if (telefono) payload.telefono = telefono;
        if (password) payload.password = password;

        setSending(true);

        try {
            const response = await global.HttpClient.post('/users', payload, {
                headers: { 'Idempotency-Key': global.UI.newIdempotencyKey() }
            });
            const data = response && response.data ? response.data : response;
            const user = (data && data.user) || null;

            if (roleId && user && user.id) {
                try {
                    await global.HttpClient.post('/users/' + encodeURIComponent(user.id) + '/roles', {
                        role_id: roleId
                    });
                } catch (roleErr) {
                    UI.handleActionError(roleErr, {
                        fallbackMessage: t('users.roles.assign_error'),
                        tag: 'Users'
                    });
                }
            }

            const temporal = user && user.password_temporal ? user.password_temporal : null;

            closeModal();
            const recargado = await loadUsers(1);
            if (recargado) {
                UI.toast('success', t('users.create.success_title'), t('users.create.success_body', { email: email }));
            }

            if (temporal) {
                openTempPassword(temporal, t('users.create.temp_title'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('users.errors.create'),
                root: getModalRoot(),
                inline: true,
                tag: 'Users'
            });
        } finally {
            setSending(false);
        }
    }

    // -------------------------------------------------------------- Edicion

    function openEdit(id) {
        if (!canEdit()) return;
        const user = state.items.find(u => u.id === id);
        if (!user) return;
        state.modal = { mode: 'edit', id, user, errors: {}, formError: '' };
        renderModal();
    }

    function editBody(modal) {
        const u = modal.user;
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${UI.grid(`
                ${UI.field({ label: t('users.fields.email'), name: 'email', type: 'email', value: u.email || '', required: true })}
                ${UI.field({ label: t('users.fields.nombre'), name: 'nombre', type: 'text', value: u.nombre || '', required: true })}
            `, 2)}
            ${UI.grid(`
                ${UI.field({ label: t('users.fields.apellido'), name: 'apellido', type: 'text', value: u.apellido || '', required: true })}
                ${UI.field({ label: t('users.fields.telefono'), name: 'telefono', type: 'text', value: u.telefono || '' })}
            `, 2)}
        `;
    }

    async function submitEdit() {
        if (state.sending) return;
        const modal = state.modal;
        const root = getModalRoot();
        UI.clearErrors(root);

        const email = fieldValue(root, 'email');
        const nombre = fieldValue(root, 'nombre');
        const apellido = fieldValue(root, 'apellido');
        const telefono = fieldValue(root, 'telefono');

        const errors = {};
        if (!email) errors.email = t('users.errors.required');
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = t('users.errors.email');
        if (!nombre) errors.nombre = t('users.errors.required');
        if (!apellido) errors.apellido = t('users.errors.required');

        if (Object.keys(errors).length > 0) {
            Object.entries(errors).forEach(([f, msg]) => UI.showFieldError(root, f, msg));
            return;
        }

        setSending(true);

        try {
            await global.HttpClient.patch('/users/' + encodeURIComponent(modal.id), {
                email, nombre, apellido,
                telefono: telefono || null
            });
            closeModal();
            const recargado = await loadUsers(state.pagination.page || 1);
            if (recargado) {
                UI.toast('success', t('users.edit.success_title'), t('users.edit.success_body', { email: email }));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('users.errors.edit'),
                root: getModalRoot(),
                inline: true,
                tag: 'Users'
            });
        } finally {
            setSending(false);
        }
    }

    // -------------------------------------------------------------- Estado

    function openStatus(id) {
        if (!canStatus()) return;
        const user = state.items.find(u => u.id === id);
        if (!user) return;
        state.modal = { mode: 'status', id, user, errors: {}, formError: '' };
        renderModal();
    }

    function statusBody(modal) {
        const opciones = ESTADOS_EDICION.map(e => ({
            value: e,
            label: estadoLabel(e),
            selected: e === modal.user.estado
        }));

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <div class="alert alert-info mb-3">${t('users.status.current', {
                email: UI.escapeHtml(modal.user.email || ''),
                estado: UI.escapeHtml(estadoLabel(modal.user.estado))
            })}</div>
            ${UI.grid(`
                ${UI.field({
                    label: t('users.status.label'),
                    name: 'estado',
                    type: 'select',
                    options: opciones
                })}
            `, 1)}
            ${UI.grid(`
                ${UI.field({
                    label: t('users.status.motivo'),
                    name: 'motivo',
                    type: 'textarea',
                    rows: 3,
                    required: true,
                    hint: t('users.status.motivo_hint')
                })}
            `, 1)}
        `;
    }

    async function submitStatus() {
        if (state.sending) return;
        const modal = state.modal;
        const root = getModalRoot();
        UI.clearErrors(root);

        const estado = fieldValue(root, 'estado');
        const motivo = fieldValue(root, 'motivo');

        const errors = {};
        if (!estado) errors.estado = t('users.errors.required');
        if (!motivo) errors.motivo = t('users.errors.required');

        if (Object.keys(errors).length > 0) {
            Object.entries(errors).forEach(([f, msg]) => UI.showFieldError(root, f, msg));
            return;
        }

        setSending(true);

        try {
            await global.HttpClient.post('/users/' + encodeURIComponent(modal.id) + '/status', {
                estado, motivo
            });
            closeModal();
            const recargado = await loadUsers(state.pagination.page || 1);
            if (recargado) {
                UI.toast('success', t('users.status.success_title'), t('users.status.success_body', {
                    email: UI.escapeHtml(modal.user.email || ''),
                    estado: UI.escapeHtml(estadoLabel(estado))
                }));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('users.errors.status'),
                root: getModalRoot(),
                inline: true,
                tag: 'Users'
            });
        } finally {
            setSending(false);
        }
    }

    // ------------------------------------------------------ Reset password

    function openReset(id) {
        if (!canReset()) return;
        const user = state.items.find(u => u.id === id);
        if (!user) return;
        state.modal = { mode: 'reset', id, user, errors: {}, formError: '' };
        renderModal();
    }

    function resetBody(modal) {
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <p>${t('users.reset.body', { email: UI.escapeHtml(modal.user.email || '') })}</p>
            <div class="alert alert-warning mt-3">${t('users.reset.warning')}</div>
        `;
    }

    async function submitReset() {
        if (state.sending) return;
        const modal = state.modal;
        setSending(true);

        try {
            const response = await global.HttpClient.post(
                '/users/' + encodeURIComponent(modal.id) + '/password/reset'
            );
            const data = response && response.data ? response.data : response;
            const temporal = data && data.password_temporal;

            closeModal();
            const recargado = await loadUsers(state.pagination.page || 1);
            if (recargado) {
                UI.toast('success', t('users.reset.success_title'), t('users.reset.success_body'));
            }

            if (temporal) {
                openTempPassword(temporal, t('users.reset.temp_title'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('users.errors.reset'),
                root: getModalRoot(),
                inline: true,
                tag: 'Users'
            });
        } finally {
            setSending(false);
        }
    }

    // ------------------------------------------- Password temporal (aviso)

    function openTempPassword(password, titleKey) {
        state.modal = { mode: 'temp-password', password, titleKey: titleKey || 'users.temp_password.title' };
        renderModal();
    }

    function tempPasswordBody(modal) {
        return `
            <div class="alert alert-warning mb-3">${t('users.temp_password.warning')}</div>
            <div class="form-group">
                <label class="form-label">${t('users.temp_password.label')}</label>
                <input type="text" class="input font-mono" data-modal-field="password_display"
                       value="${UI.escapeHtml(modal.password)}" readonly>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" data-action="copy-password">${t('users.temp_password.copy')}</button>
        `;
    }

    async function copyPassword() {
        const modal = state.modal;
        if (!modal || !modal.password) return;
        const valor = modal.password;

        try {
            if (global.navigator && global.navigator.clipboard && global.navigator.clipboard.writeText) {
                await global.navigator.clipboard.writeText(valor);
            } else {
                const input = getModalRoot().querySelector('[data-modal-field="password_display"]');
                if (input) {
                    input.focus();
                    input.select();
                    document.execCommand('copy');
                }
            }
            UI.toast('success', t('users.temp_password.title'), t('users.temp_password.copied'));
        } catch (err) {
            UI.toast('warning', t('users.temp_password.title'), t('users.temp_password.copy_failed'));
        }
    }

    // ------------------------------------------------- Roles del usuario

    function openRoles(id) {
        if (!canView()) return;
        const user = state.items.find(u => u.id === id);
        if (!user) return;
        state.modal = {
            mode: 'roles', id, user, roles: null, confirmGroup: null,
            errors: {}, formError: ''
        };
        renderModal();
        // v1.12.1: el selector de roles disponibles carga el catálogo
        // GET /roles aquí (antes sólo se cargaba al abrir el alta); sin él
        // el select salía vacío. Se re-pinta cuando el catálogo llega.
        const catalogo = state.roles.length === 0 ? loadRolesCatalog() : Promise.resolve();
        catalogo.then(() => {
            if (state.modal && state.modal.mode === 'roles') renderModal();
        });
        loadUserRoles(id);
    }

    async function loadUserRoles(id) {
        const modal = state.modal;
        if (!modal || modal.mode !== 'roles') return true;

        try {
            const response = await global.HttpClient.get('/users/' + encodeURIComponent(id) + '/roles');
            const payload = response && response.data ? response.data : response;
            if (state.modal === modal) {
                modal.roles = (payload && payload.roles) || [];
                renderModal();
            }
            return true;
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('users.roles.load_error'),
                tag: 'Users'
            });
            if (state.modal === modal) {
                modal.roles = [];
                renderModal();
            }
            return false;
        }
    }

    /**
     * Accion de una fila de roles del modal (v1.13.20):
     *  - directo/temporary: "Quitar" la asignacion directa (como hasta ahora);
     *  - group: "Quitar del grupo" (borrar la membresia con el endpoint
     *    existente DELETE /groups/{id}/members/{userId}), sólo con
     *    groups.group.manage_members y con escalada (nunca sobre un usuario
     *    que el actor no puede gestionar ni sobre uno mismo).
     */
    function roleRowActions(modal, rol) {
        if (rol.origen === 'group') {
            if (!canManageMembers() || !canActOnUser(modal.user, true)) return '';
            return `<button type="button" class="btn btn-ghost btn-sm btn-danger"
                data-action="ask-remove-group"
                data-group-id="${UI.escapeHtml(rol.group_id || '')}"
                data-group-name="${UI.escapeHtml(rol.group_name || '')}">${t('users.roles.remove_group')}</button>`;
        }

        return canEdit() ? `<button type="button" class="btn btn-ghost btn-sm btn-danger"
            data-action="remove-role" data-role-id="${UI.escapeHtml(rol.id)}">${t('users.roles.remove')}</button>` : '';
    }

    function rolesBody(modal) {
        if (modal.confirmGroup) return confirmGroupBody(modal);

        const actuales = modal.roles === null
            ? UI.loadingState()
            : (modal.roles.length === 0
                ? `<p class="text-muted text-sm">${t('users.roles.none')}</p>`
                : `<ul class="mb-3">${modal.roles.map(r => `
                    <li class="flex items-center justify-between gap-2 py-1 border-b border-default last:border-0">
                        <span>
                            <span class="font-medium">${UI.escapeHtml(r.nombre)}</span>
                            <span class="badge badge-neutral">${UI.escapeHtml(r.tipo || '')}</span>${roleOriginBadge(r)}
                        </span>
                        ${roleRowActions(modal, r)}
                    </li>`).join('')}</ul>`);

        const disponibles = state.roles
            .filter(r => canAssignRole(r))
            .filter(r => !(modal.roles || []).some(a => a.id === r.id));
        const opciones = [{ value: '', label: t('users.roles.select_placeholder') }].concat(
            disponibles.map(r => ({ value: r.id, label: r.nombre }))
        );

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <div class="text-xs text-muted mb-1">${UI.escapeHtml(modal.user.email || '')}</div>
            <div class="form-label mt-2">${t('users.roles.current')}</div>
            ${actuales}
            ${canEdit() ? `
                <div class="flex gap-2 mt-3 items-end">
                    <div class="form-group mb-0 flex-1">
                        <label class="form-label">${t('users.roles.assign_label')}</label>
                        <select class="select" data-modal-field="role_id">${opciones.map(op => `<option value="${UI.escapeHtml(op.value)}">${UI.escapeHtml(op.label)}</option>`).join('')}</select>
                    </div>
                    <button type="button" class="btn btn-primary" data-action="assign-role">${t('users.roles.assign')}</button>
                </div>
            ` : ''}
        `;
    }

    /** Confirmacion de baja del grupo (v1.13.20): que se pierde y por que. */
    function confirmGroupBody(modal) {
        const c = modal.confirmGroup;
        const perdidos = (modal.roles || [])
            .filter(r => r.origen === 'group' && r.group_id === c.groupId)
            .map(r => r.nombre);
        const lista = perdidos.length > 0 ? perdidos.join(', ') : t('users.roles.none');

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <div class="text-xs text-muted mb-1">${UI.escapeHtml(modal.user.email || '')}</div>
            <div class="alert alert-danger mt-2">${t('users.roles.remove_group_confirm', {
                group: UI.escapeHtml(c.groupName || ''),
                roles: UI.escapeHtml(lista)
            })}</div>
        `;
    }

    /** Abrir la confirmacion de baja del grupo (sólo con permiso y escalada). */
    function askRemoveGroup(groupId, groupName) {
        const modal = state.modal;
        if (!modal || modal.mode !== 'roles' || state.sending) return;
        if (!canManageMembers() || !canActOnUser(modal.user, true)) return;
        if (!groupId) return;

        modal.confirmGroup = { groupId, groupName };
        renderModal();
    }

    function cancelRemoveGroup() {
        const modal = state.modal;
        if (!modal || modal.mode !== 'roles' || state.sending) return;
        modal.confirmGroup = null;
        renderModal();
    }

    /**
     * Baja del grupo con el endpoint existente de miembros
     * (DELETE /groups/{id}/members/{userId}): no se crea ninguno nuevo.
     * El backend invalida la cache de permisos; aquí se recarga desde el
     * servidor antes de dar por bueno el cambio.
     */
    async function submitRemoveGroup() {
        const modal = state.modal;
        if (!modal || modal.mode !== 'roles' || !modal.confirmGroup || state.sending) return;
        if (!canManageMembers() || !canActOnUser(modal.user, true)) return;

        const { groupId, groupName } = modal.confirmGroup;
        setSending(true);
        try {
            await global.HttpClient.delete(
                '/groups/' + encodeURIComponent(groupId) + '/members/' + encodeURIComponent(modal.id)
            );
            refreshSelfIfNeeded(modal.id);
            modal.confirmGroup = null;
            modal.roles = null;
            renderModal();
            const recargado = await loadUserRoles(modal.id);
            refreshUserRow(modal.id, modal.roles);
            if (recargado) {
                UI.toast('success', t('users.roles.title'),
                    t('users.roles.remove_group_success', { group: UI.escapeHtml(groupName || '') }));
            }
        } catch (err) {
            modal.confirmGroup = null;
            await loadUserRoles(modal.id);
            UI.handleActionError(err, {
                fallbackMessage: t('users.roles.remove_group_error'),
                root: getModalRoot(),
                inline: true,
                tag: 'Users'
            });
        } finally {
            setSending(false);
        }
    }

    /** data.error_code del envelope de error ('' si no trae). */
    function errorCodeOf(err) {
        const body = (err && err.data && typeof err.data === 'object') ? err.data : null;
        const inner = (body && body.data && typeof body.data === 'object') ? body.data : null;
        return (inner && inner.error_code) || (body && body.error_code) || '';
    }

    /**
     * Tras mutar roles de un usuario, refresca el estado global
     * AuthState si el afectado es el usuario actual de la sesion:
     * el backend invalida la cache Redis y /auth/me devuelve los
     * permisos nuevos, pero sin este refresh el sidebar y la UI
     * conservan los roles viejos hasta recargar la pagina.
     */
    function refreshSelfIfNeeded(userId) {
        if (!global.AuthState || typeof global.AuthState.refreshUser !== 'function') return;
        const currentId = global.AuthState.getUserId && global.AuthState.getUserId();
        if (currentId && String(currentId) === String(userId)) {
            global.AuthState.refreshUser().catch(function () {});
        }
    }

    /**
     * Tras mutar roles, actualiza la fila del usuario en la tabla
     * (state.items) con los roles frescos del servidor y re-renderiza.
     * Sin esto la columna Roles de la tabla conserva los datos viejos
     * hasta recargar la pagina completa.
     */
    function refreshUserRow(userId, rolesFromModal) {
        const idx = state.items.findIndex(function (u) { return String(u.id) === String(userId); });
        if (idx === -1) return;
        if (rolesFromModal && Array.isArray(rolesFromModal)) {
            state.items[idx].roles = rolesFromModal.map(function (r) {
                return { id: r.id, nombre: r.nombre, tipo: r.tipo, origen: r.origen };
            });
        }
        renderUsersTable();
    }

    async function assignRole() {
        const modal = state.modal;
        const root = getModalRoot();
        if (!modal || modal.mode !== 'roles' || state.sending) return;
        UI.clearErrors(root);

        const roleId = fieldValue(root, 'role_id');
        if (!roleId) {
            UI.showFieldError(root, 'role_id', t('users.errors.required'));
            return;
        }

        setSending(true);
        try {
            await global.HttpClient.post('/users/' + encodeURIComponent(modal.id) + '/roles', {
                role_id: roleId
            });
            refreshSelfIfNeeded(modal.id);
            const recargado = await loadUserRoles(modal.id);
            refreshUserRow(modal.id, modal.roles);
            if (recargado) {
                UI.toast('success', t('users.roles.title'), t('users.roles.assigned'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('users.roles.assign_error'),
                root: getModalRoot(),
                inline: true,
                tag: 'Users'
            });
        } finally {
            setSending(false);
        }
    }

    async function removeRole(roleId) {
        const modal = state.modal;
        if (!modal || modal.mode !== 'roles' || state.sending) return;

        setSending(true);
        try {
            await global.HttpClient.delete(
                '/users/' + encodeURIComponent(modal.id) + '/roles/' + encodeURIComponent(roleId)
            );
            refreshSelfIfNeeded(modal.id);
            // Estado real desde el servidor antes de confirmar el exito.
            const recargado = await loadUserRoles(modal.id);
            refreshUserRow(modal.id, modal.roles);
            if (recargado) {
                UI.toast('success', t('users.roles.title'), t('users.roles.removed'));
            }
        } catch (err) {
            // 409 ROLE_NOT_DIRECT: el rol no esta asignado directamente
            // (heredado de grupo). Sin exito; se recarga y se avisa.
            const recargar = errorCodeOf(err) === 'ROLE_NOT_DIRECT';
            await loadUserRoles(modal.id);
            if (recargar) {
                UI.toast('warning', t('users.roles.title'), t('users.roles.not_direct'));
                return;
            }
            UI.handleActionError(err, {
                fallbackMessage: t('users.roles.remove_error'),
                root: getModalRoot(),
                inline: true,
                tag: 'Users'
            });
        } finally {
            setSending(false);
        }
    }

    // ------------------------------------------------------------- Borrado

    function openDelete(id) {
        if (!canDelete()) return;
        const user = state.items.find(u => u.id === id);
        if (!user) return;
        state.modal = { mode: 'delete', id, user, errors: {}, formError: '' };
        renderModal();
    }

    function deleteBody(modal) {
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <p>${t('users.delete.body', { email: UI.escapeHtml(modal.user.email || '') })}</p>
            <div class="alert alert-danger mt-3">${t('users.delete.warning')}</div>
        `;
    }

    async function submitDelete() {
        if (state.sending) return;
        const modal = state.modal;
        setSending(true);

        try {
            await global.HttpClient.delete('/users/' + encodeURIComponent(modal.id));
            closeModal();
            const recargado = await loadUsers(state.pagination.page || 1);
            if (recargado) {
                UI.toast('success', t('users.delete.success_title'), t('users.delete.success_body'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('users.errors.delete'),
                root: getModalRoot(),
                inline: true,
                tag: 'Users'
            });
        } finally {
            setSending(false);
        }
    }

    // ------------------------------------------------ Permiso temporal

    function openGrant() {
        if (!canGrant()) return;
        state.modal = {
            mode: 'grant',
            errors: {},
            formError: '',
            expiry_mode: 'horas'
        };
        renderModal();
        Promise.all([ensurePermissions(), searchUsersDirectory('')]).then(() => {
            if (state.modal && state.modal.mode === 'grant') {
                renderModal();
            }
        });
    }

    async function searchUsersDirectory(search) {
        try {
            const params = { page: 1, per_page: 50 };
            if (search) params.search = search;
            const response = await global.HttpClient.get('/users', { params });
            const payload = response && response.data ? response.data : response;
            state.users = (payload && payload.data) || [];
        } catch (err) {
            console.warn('[Users] directory search error:', err.message);
            state.users = [];
        }
        return state.users;
    }

    function grantUsersField(selectedId) {
        const userOpciones = [{ value: '', label: t('users.grant.user_placeholder') }].concat(
            state.users.map(u => ({
                value: u.id,
                label: ((u.nombre || '') + ' ' + (u.apellido || '')).trim()
                    ? `${u.email} (${((u.nombre || '') + ' ' + (u.apellido || '')).trim()})`
                    : u.email,
                selected: selectedId === u.id
            }))
        );

        return UI.field({ label: t('users.grant.user'), name: 'user_id', type: 'select', options: userOpciones, required: true });
    }

    async function grantUserSearch() {
        const root = getModalRoot();
        if (!root) return;
        const input = root.querySelector('[data-grant-user-search]');
        const term = input ? String(input.value || '').trim() : '';
        const holder = root.querySelector('[data-grant-users]');
        if (!holder) return;

        holder.innerHTML = UI.loadingState();
        const actual = fieldValue(root, 'user_id');
        await searchUsersDirectory(term);
        if (getModalRoot() !== root || !state.modal || state.modal.mode !== 'grant') return;
        holder.innerHTML = grantUsersField(actual);
        applyErrors();
    }

    function grantBody(modal) {
        const permOpciones = [{ value: '', label: t('users.grant.permission_placeholder') }].concat(
            (state.permissions || []).map(p => ({
                value: p.id,
                label: p.identificador + ' · ' + p.criticidad
            }))
        );

        // datetime-local en hora UTC: la convencion de la plataforma (ui/dom.js)
        // es mostrar los naive 'Y-m-d H:i:s' de la BD tal cual (PHP escribe en
        // UTC y el backend parsea fecha_expiracion con DateTimeImmutable en UTC).
        // Con el valor local, un navegador con desfase horario guardaba la
        // expiracion corrida su offset (17:00 locales se almacenaban 17:00 UTC
        // = 18:00 locales).
        const ahora = new Date().toISOString().slice(0, 16);

        // Layout (M2): una columna para campo a campo (sin columnas
        // fantasma en un UI.grid de un solo hijo), ritmo uniforme de
        // form-group y el par Duración|Fecha como .segmented (los radios
        // nativos y su handler change no cambian: sólo presentación).
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${UI.grid(`
                <div class="form-group">
                    <label class="form-label">${t('users.grant.user_search_label')}</label>
                    <div class="flex gap-2 items-center">
                        <input type="text" class="input" data-grant-user-search
                               placeholder="${t('users.grant.user_search_placeholder')}" maxlength="100">
                        <button type="button" class="btn btn-secondary" data-action="grant-search">${t('users.grant.user_search')}</button>
                    </div>
                </div>
                <div data-grant-users>${grantUsersField(modal.user_id || '')}</div>
            `, 2)}
            ${UI.field({ label: t('users.grant.permission'), name: 'permission_id', type: 'select', options: permOpciones, required: true })}
            ${UI.field({ label: t('users.grant.motive'), name: 'motivo', type: 'textarea', rows: 2, required: true })}
            <div class="form-group">
                <label class="form-label">${t('users.grant.expiry')} <span class="required">*</span></label>
                <div class="segmented">
                    <label class="segment">
                        <input type="radio" name="expiry_mode" value="horas" data-expiry-mode${modal.expiry_mode === 'horas' ? ' checked' : ''}>
                        ${t('users.grant.mode_hours')}
                    </label>
                    <label class="segment">
                        <input type="radio" name="expiry_mode" value="minutos" data-expiry-mode${modal.expiry_mode === 'minutos' ? ' checked' : ''}>
                        ${t('users.grant.mode_minutes')}
                    </label>
                    <label class="segment">
                        <input type="radio" name="expiry_mode" value="fecha" data-expiry-mode${modal.expiry_mode === 'fecha' ? ' checked' : ''}>
                        ${t('users.grant.mode_date')}
                    </label>
                </div>
            </div>
            <div data-expiry-hours class="${modal.expiry_mode === 'horas' ? '' : 'hidden'}">
                ${UI.field({ label: t('users.grant.hours'), name: 'duracion_horas', type: 'number', value: '24' })}
            </div>
            <div data-expiry-minutes class="${modal.expiry_mode === 'minutos' ? '' : 'hidden'}">
                ${UI.field({ label: t('users.grant.minutes'), name: 'duracion_minutos', type: 'number', value: '5' })}
            </div>
            <div data-expiry-date class="${modal.expiry_mode === 'fecha' ? '' : 'hidden'}">
                ${UI.field({ label: t('users.grant.date'), name: 'fecha_expiracion', type: 'datetime-local', value: ahora })}
            </div>
            <div class="text-xs text-muted">${t('users.grant.max_hint')}</div>
        `;
    }

    async function submitGrant() {
        if (state.sending) return;
        const root = getModalRoot();
        UI.clearErrors(root);

        const userId = fieldValue(root, 'user_id');
        const permissionId = fieldValue(root, 'permission_id');
        const motivo = fieldValue(root, 'motivo');
        const modo = (root.querySelector('[data-expiry-mode]:checked') || {}).value || 'horas';
        const horas = fieldValue(root, 'duracion_horas');
        const minutos = fieldValue(root, 'duracion_minutos');
        const fecha = fieldValue(root, 'fecha_expiracion');

        const errors = {};
        if (!userId) errors.user_id = t('users.errors.required');
        if (!permissionId) errors.permission_id = t('users.errors.required');
        if (!motivo) errors.motivo = t('users.errors.required');
        if (modo === 'horas' && (!horas || parseInt(horas, 10) <= 0)) {
            errors.duracion_horas = t('users.errors.required');
        }
        if (modo === 'minutos' && (!minutos || parseInt(minutos, 10) <= 0)) {
            errors.duracion_minutos = t('users.errors.required');
        }
        if (modo === 'fecha' && !fecha) errors.fecha_expiracion = t('users.errors.required');

        if (Object.keys(errors).length > 0) {
            Object.entries(errors).forEach(([f, msg]) => UI.showFieldError(root, f, msg));
            return;
        }

        const payload = { user_id: userId, permission_id: permissionId, motivo };
        if (modo === 'horas') payload.duracion_horas = parseInt(horas, 10);
        else if (modo === 'minutos') payload.duracion_minutos = parseInt(minutos, 10);
        else payload.fecha_expiracion = fecha;

        setSending(true);

        try {
            await global.HttpClient.post('/temporary-permissions', payload);
            closeModal();
            const recargado = await switchTab('temp');
            if (recargado) {
                UI.toast('success', t('users.grant.success_title'), t('users.grant.success_body'));
            }
        } catch (err) {
            if (err && err.status === 429) {
                UI.toast('warning', t('users.temp.rate_title'), UI.errorMessage(err) || t('users.temp.rate_body'));
            } else {
                UI.handleActionError(err, {
                    fallbackMessage: t('users.grant.error'),
                    root: getModalRoot(),
                    inline: true,
                    tag: 'Users'
                });
            }
        } finally {
            setSending(false);
        }
    }

    function openRevoke(id) {
        if (!canRevoke()) return;
        const row = state.temp.items.find(r => r.id === id);
        if (!row) return;
        state.modal = { mode: 'revoke', id, row, errors: {}, formError: '' };
        renderModal();
    }

    function revokeBody(modal) {
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <p>${t('users.temp.revoke_body', {
                usuario: UI.escapeHtml(modal.row.user_email || ''),
                permiso: UI.escapeHtml(modal.row.permission_identificador || '')
            })}</p>
        `;
    }

    async function submitRevoke() {
        if (state.sending) return;
        const modal = state.modal;
        setSending(true);

        try {
            await global.HttpClient.delete('/temporary-permissions/' + encodeURIComponent(modal.id));
            closeModal();
            const recargado = await loadTemp();
            if (recargado) {
                UI.toast('success', t('users.temp.revoke_title'), t('users.temp.revoked'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('users.temp.revoke_error'),
                root: getModalRoot(),
                inline: true,
                tag: 'Users'
            });
        } finally {
            setSending(false);
        }
    }

    // ====================================================================
    // RENDER DEL MODAL
    // ====================================================================

    function renderModal() {
        const root = getModalRoot();
        if (!root) return;

        const modal = state.modal;
        if (!modal) {
            UI.closeModal(root);
            return;
        }

        let title = '';
        let size = '';
        let body = '';
        let footer = '';

        switch (modal.mode) {
            case 'create':
                title = t('users.create.title');
                body = createBody();
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-create" data-modal-send${state.sending ? ' disabled' : ''}>${t('users.create.submit')}</button>`;
                break;
            case 'edit':
                title = t('users.edit.title');
                body = editBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-edit" data-modal-send${state.sending ? ' disabled' : ''}>${t('users.edit.submit')}</button>`;
                break;
            case 'status':
                title = t('users.status.title');
                body = statusBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-status" data-modal-send${state.sending ? ' disabled' : ''}>${t('users.status.submit')}</button>`;
                break;
            case 'reset':
                title = t('users.reset.title');
                size = 'sm';
                body = resetBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-danger" data-action="submit-reset" data-modal-send${state.sending ? ' disabled' : ''}>${t('users.reset.submit')}</button>`;
                break;
            case 'temp-password':
                title = t(modal.titleKey);
                size = 'sm';
                body = tempPasswordBody(modal);
                footer = `
                    <button class="btn btn-primary" data-action="close-modal">${t('users.temp_password.close')}</button>`;
                break;
            case 'roles':
                title = t('users.roles.title');
                body = rolesBody(modal);
                footer = modal.confirmGroup
                    ? `<button class="btn btn-secondary" data-action="cancel-remove-group"${state.sending ? ' disabled' : ''}>${t('ui.cancel')}</button>
                       <button class="btn btn-danger" data-action="submit-remove-group" data-modal-send${state.sending ? ' disabled' : ''}>${t('users.roles.remove_group')}</button>`
                    : `<button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>`;
                break;
            case 'delete':
                title = t('users.delete.title');
                size = 'sm';
                body = deleteBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-danger" data-action="submit-delete" data-modal-send${state.sending ? ' disabled' : ''}>${t('users.delete.submit')}</button>`;
                break;
            case 'grant':
                title = t('users.grant.title');
                body = grantBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-grant" data-modal-send${state.sending ? ' disabled' : ''}>${t('users.grant.submit')}</button>`;
                break;
            case 'revoke':
                title = t('users.temp.revoke_title');
                size = 'sm';
                body = revokeBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-danger" data-action="submit-revoke" data-modal-send${state.sending ? ' disabled' : ''}>${t('users.temp.revoke')}</button>`;
                break;
        }

        UI.mountModal(root, UI.modalShell({ title, size, bodyHtml: body, footerHtml: footer }));
        applyErrors();
    }

    function fieldValue(root, name) {
        if (!root) return '';
        const el = root.querySelector(`[data-modal-field="${name}"]`);
        return el ? String(el.value || '').trim() : '';
    }

    // ====================================================================
    // CATALOGOS (perezosos)
    // ====================================================================

    function ensureRolesAndUsers() {
        const tareas = [];
        if (state.roles.length === 0) tareas.push(loadRolesCatalog());
        if (state.users.length === 0 && canView()) tareas.push(loadUsersDirectory());
        if (tareas.length === 0) return Promise.resolve(false);
        return Promise.all(tareas).then(() => true);
    }

    function ensurePermissions() {
        if (!state.permissions || state.permissions.length === 0) {
            return loadPermissionsCatalog().then(() => true);
        }
        return Promise.resolve(false);
    }

    // ====================================================================
    // EVENTOS
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
        if (!btn || btn.disabled) return;

        const action = btn.dataset.action;
        const id = btn.dataset.id;
        const page = btn.dataset.page;

        switch (action) {
            case 'tab':
                switchTab(btn.dataset.tab);
                break;
            case 'apply-filters':
                readFilters();
                loadUsers(1);
                break;
            case 'clear-filters':
                clearFilters();
                break;
            case 'open-create':
                openCreate();
                break;
            case 'open-edit':
                openEdit(id);
                break;
            case 'open-status':
                openStatus(id);
                break;
            case 'open-reset':
                openReset(id);
                break;
            case 'open-roles':
                openRoles(id);
                break;
            case 'open-delete':
                openDelete(id);
                break;
            case 'open-temp-user':
                openTempUser(id);
                break;
            case 'temp-clear-user':
                state.temp.user_id = '';
                state.temp.user_label = '';
                loadTemp(1);
                break;
            case 'open-grant':
                openGrant();
                break;
            case 'open-revoke':
                openRevoke(id);
                break;
            case 'close-modal':
                closeModal();
                break;
            case 'submit-create':
                submitCreate();
                break;
            case 'submit-edit':
                submitEdit();
                break;
            case 'submit-status':
                submitStatus();
                break;
            case 'submit-reset':
                submitReset();
                break;
            case 'submit-delete':
                submitDelete();
                break;
            case 'grant-search':
                grantUserSearch();
                break;
            case 'submit-grant':
                submitGrant();
                break;
            case 'submit-revoke':
                submitRevoke();
                break;
            case 'gen-password': {
                const root = getModalRoot();
                const input = root ? root.querySelector('[data-modal-field="password"]') : null;
                if (input) {
                    input.value = generatePassword();
                    input.type = 'text';
                }
                break;
            }
            case 'copy-password':
                copyPassword();
                break;
            case 'assign-role':
                assignRole();
                break;
            case 'remove-role':
                removeRole(btn.dataset.roleId);
                break;
            case 'ask-remove-group':
                askRemoveGroup(btn.dataset.groupId, btn.dataset.groupName);
                break;
            case 'cancel-remove-group':
                cancelRemoveGroup();
                break;
            case 'submit-remove-group':
                submitRemoveGroup();
                break;
        }

        if (page !== undefined && page !== null && page !== '' && !btn.disabled) {
            if (state.tab === 'temp') loadTemp(parseInt(page, 10));
            else if (state.tab === 'users') loadUsers(parseInt(page, 10));
        }
    }

    function handleSubmit(e) {
        if (e.target.matches('[data-users-filters]')) {
            e.preventDefault();
            readFilters();
            loadUsers(1);
        }
    }

    function handleChange(e) {
        const el = e.target;
        if (el.matches('[data-expiry-mode]')) {
            const modo = el.value;
            if (state.modal) state.modal.expiry_mode = modo;
            const root = getModalRoot();
            if (!root) return;
            const horas = root.querySelector('[data-expiry-hours]');
            const minutos = root.querySelector('[data-expiry-minutes]');
            const fecha = root.querySelector('[data-expiry-date]');
            if (horas) horas.classList.toggle('hidden', modo !== 'horas');
            if (minutos) minutos.classList.toggle('hidden', modo !== 'minutos');
            if (fecha) fecha.classList.toggle('hidden', modo !== 'fecha');
        }
    }

    function openTempUser(id) {
        if (!canViewTemp()) return;
        const user = state.items.find(u => u.id === id);
        state.temp.user_id = id;
        state.temp.user_label = user ? user.email : id;
        switchTab('temp');
    }

    function readFilters() {
        container.querySelectorAll('[data-filter]').forEach(el => {
            if (el.type === 'checkbox') state.filters[el.dataset.filter] = !!el.checked;
            else state.filters[el.dataset.filter] = el.value;
        });
    }

    function clearFilters() {
        state.filters = { estado: '', search: '' };
        container.querySelectorAll('[data-filter]').forEach(el => {
            if (el.type === 'checkbox') el.checked = false;
            else el.value = '';
        });
        loadUsers(1);
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const UsersUsersModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView() && !canViewTemp()) {
                renderPermissionDenied();
                return;
            }

            state.tab = UI.tabFromParams(params, ['users', 'temp'], 'users');
            if (state.tab === 'temp' && !canViewTemp()) state.tab = 'users';
            container.innerHTML = buildTemplate();
            bindEvents();

            if (state.tab === 'temp') {
                loadTemp();
            } else {
                loadUsers(1);
            }
        },

        onActivate(params) {
            if (!container) return;

            if (!canView() && !canViewTemp()) {
                renderPermissionDenied();
                return;
            }

            if (!container.querySelector('[data-users-table]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
            }

            const tab = UI.tabFromParams(params, ['users', 'temp'], state.tab);
            if (tab !== state.tab) switchTab(tab);

            if (state.tab === 'temp') loadTemp();
            else loadUsers(state.pagination.page || 1);
        },

        destroy() {
            clearTempAutoRefresh();
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('submit', handleSubmit);
                container.removeEventListener('change', handleChange);
                container.innerHTML = '';
            }
            container = null;
            state = {
                tab: 'users',
                items: [],
                pagination: { page: 1, per_page: 20, total: 0, has_next: false },
        filters: { estado: '', search: '' },
                loading: false,
                roles: [],
                users: [],
                temp: { items: [], total: 0, loading: false, user_id: '', user_label: '', page: 1, per_page: 20, pagination: null },
                modal: null,
                sending: false
            };
        }
    };

    global.UsersUsersModule = UsersUsersModule;

})(window);
