/**
 * Groups Module (pantalla de control de grupos) - v1.12.0.
 * GET/POST/PATCH/DELETE /api/groups, detalle con miembros y roles:
 * GET/POST/DELETE /groups/{id}/members[/{userId}] y
 * GET/POST/DELETE /groups/{id}/roles[/{roleId}].
 *
 * Convencion ModuleLoader: window.GroupsGroupsModule (ruta groups/groups).
 *
 * v1.13.9 (politica de borrado): no hay restauracion; los borrados no se
 * listan y un nombre borrado puede reutilizarse en un grupo nuevo.
 *  - GET /groups devuelve ya el conteo de miembros en `miembros`
 *    (se mantiene el N+1 solo como fallback para respuestas sin la clave).
 */
(function(global) {
    'use strict';

    let container = null;

    let state = {
        items: [],
        loading: false,
        includeInactive: false,
        memberCounts: {},
        roles: [],
        rolesLoaded: false,
        rolesError: false,
        detail: null,
        modal: null,
        sending: false
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

    const canView = () => can('groups.group.view', ['ADMIN', 'MANAGER', 'USER', 'VIEWER']);
    const canCreate = () => can('groups.group.create', ['ADMIN']);
    const canEdit = () => can('groups.group.edit', ['ADMIN']);
    const canDelete = () => can('groups.group.delete', ['ADMIN']);
    const canManageMembers = () => can('groups.group.manage_members', ['ADMIN', 'MANAGER']);
    const canManageRoles = () => can('groups.group.manage_roles', ['ADMIN', 'MANAGER']);

    // ====================================================================
    // PLANTILLA
    // ====================================================================

    function buildTemplate() {
        const createBtn = canCreate()
            ? `<button class="btn btn-primary btn-sm" data-action="open-create">${t('groups.actions.create')}</button>`
            : '';

        return `
            <div class="max-w-7xl mx-auto">
                ${UI.pageHeader({
                    title: t('groups.title'),
                    subtitle: t('groups.subtitle'),
                    actionsHtml: createBtn
                })}

                <div class="card mb-4 flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" data-group-filter="inactive"${state.includeInactive ? ' checked' : ''}>
                        ${t('groups.filter.inactive')}
                    </label>
                </div>

                <div class="card p-0">
                    <div class="table-wrapper" data-groups-table></div>
                </div>
            </div>

            <div data-modal-root></div>
        `;
    }

    function renderPermissionDenied() {
        container.innerHTML = `
            <div class="card max-w-md mx-auto mt-8">
                <div class="card-header">
                    <h3 class="card-title">${t('groups.denied.title')}</h3>
                </div>
                <div class="card-body">
                    <p>${t('groups.denied.body')}</p>
                    <div class="mt-4">
                        <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                    </div>
                </div>
            </div>`;
    }

    // ====================================================================
    // TABLA
    // ====================================================================

    function estadoBadge(activo) {
        return activo
            ? `<span class="badge badge-success">${t('groups.state.active')}</span>`
            : `<span class="badge badge-warning">${t('groups.state.inactive')}</span>`;
    }

    function estadoUsuarioBadge(estado) {
        const color = estado === 'ACTIVO' ? 'success'
            : estado === 'SUSPENDIDO' ? 'danger'
            : estado === 'PENDIENTE_CONFIRMACION' ? 'warning'
            : 'neutral';
        return `<span class="badge badge-${color}">${UI.escapeHtml(estado || '—')}</span>`;
    }

    function renderTable() {
        const root = container.querySelector('[data-groups-table]');
        if (!root) return;

        if (state.loading) {
            root.innerHTML = UI.tableSkeleton([
                { label: t('groups.columns.name') },
                { label: t('groups.columns.state') },
                { label: t('groups.columns.members'), className: 'text-right' },
                { label: t('groups.columns.actions'), className: 'col-actions' }
            ], 6, [45, 15, 20, 20]);
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('groups.empty.title'),
                message: t('groups.empty.message')
            });
            return;
        }

        const rows = state.items.map(g => {
            const count = typeof g.miembros === 'number' ? g.miembros : state.memberCounts[g.id];
            const miembros = typeof count === 'number'
                ? UI.formatNumber(count)
                : '<span class="text-muted">…</span>';

            const acciones = UI.rowActions({
                primary: { action: 'open-detail', id: g.id, label: t('groups.actions.detail') },
                groups: [
                    [canEdit() ? { action: 'open-edit', id: g.id, label: t('groups.actions.edit') } : null],
                    [canDelete() ? { action: 'open-delete', id: g.id, label: t('groups.actions.delete'), danger: true } : null]
                ],
                ariaLabel: t('groups.columns.actions')
            });

            return `
                <tr>
                    <td>
                        <div class="font-medium">${UI.escapeHtml(g.nombre || '')}</div>
                        ${g.descripcion ? `<div class="text-xs text-muted">${UI.escapeHtml(g.descripcion)}</div>` : ''}
                    </td>
                    <td>${estadoBadge(!!g.activo)}</td>
                    <td class="text-right">${miembros}</td>
                    <td class="table-actions col-actions">${acciones}</td>
                </tr>`;
        }).join('');

        root.innerHTML = UI.tableShell([
            { label: t('groups.columns.name') },
            { label: t('groups.columns.state') },
            { label: t('groups.columns.members'), className: 'text-right' },
            { label: t('groups.columns.actions'), className: 'col-actions' }
        ], rows);
    }

    // ====================================================================
    // CARGA
    // ====================================================================

    async function loadGroups() {
        state.loading = true;
        renderTable();

        try {
            const params = {};
            if (state.includeInactive) params.include_inactive = 'true';
            const response = await global.HttpClient.get('/groups', { params });
            const payload = response && response.data ? response.data : response;
            state.items = (payload && payload.groups) || [];
            return true;
        } catch (err) {
            console.error('[Groups] load error:', err);
            UI.toast('danger', t('ui.error_generic'), err.message || t('groups.errors.load'));
            state.items = [];
            return false;
        } finally {
            state.loading = false;
            renderTable();
            // El conteo viene en `miembros` (v1.13.0); el N+1 queda como
            // fallback solo si alguna respuesta no lo trae.
            const faltaConteo = state.items.some(g => typeof g.miembros !== 'number');
            if (faltaConteo) loadMemberCounts();
        }
    }

    async function loadMemberCounts() {
        const grupos = state.items.slice();
        if (grupos.length === 0) return;

        await Promise.all(grupos.map(async g => {
            try {
                const response = await global.HttpClient.get('/groups/' + encodeURIComponent(g.id) + '/members');
                const payload = response && response.data ? response.data : response;
                state.memberCounts[g.id] = Number((payload && payload.total) || 0);
            } catch (err) {
                console.warn('[Groups] members count error:', g.id, err);
                state.memberCounts[g.id] = state.memberCounts[g.id] || null;
            }
        }));

        renderTable();
    }

    async function loadRolesCatalog() {
        if (state.rolesLoaded) return state.roles;
        state.rolesError = false;
        try {
            const response = await global.HttpClient.get('/roles');
            const payload = response && response.data ? response.data : response;
            state.roles = (payload && payload.roles) || [];
        } catch (err) {
            console.error('[Groups] roles catalog error:', err);
            state.roles = [];
            state.rolesError = true;
        }
        state.rolesLoaded = true;
        return state.roles;
    }

    // Candidatos del combobox de añadir miembro: GET /users existente, que
    // routes/api.php acepta con users.user.view O groups.group.manage_members
    // (no exige la pantalla de Usuarios). Hueco: no existe aún
    // GET /groups/member-candidates (reportado, ver informe M4).
    async function fetchUsers(search) {
        const params = { per_page: 50, estado: 'ACTIVO' };
        if (search) params.search = search;
        const response = await global.HttpClient.get('/users', { params });
        const payload = response && response.data ? response.data : response;
        const items = (payload && payload.data) || [];
        return items.map(u => ({
            value: String(u.id),
            label: (u.nombre || '') + ' ' + (u.apellido || '') + ' (' + (u.email || '') + ')'
        }));
    }

    // ====================================================================
    // DETALLE (miembros / roles)
    // ====================================================================

    async function loadDetail() {
        const detail = state.detail;
        if (!detail) return true;

        detail.loading = true;
        renderModal();

        try {
            const [membersRes, rolesRes] = await Promise.all([
                global.HttpClient.get('/groups/' + encodeURIComponent(detail.id) + '/members'),
                global.HttpClient.get('/groups/' + encodeURIComponent(detail.id) + '/roles')
            ]);
            const membersPayload = membersRes && membersRes.data ? membersRes.data : membersRes;
            const rolesPayload = rolesRes && rolesRes.data ? rolesRes.data : rolesRes;

            if (state.detail === detail) {
                detail.members = (membersPayload && membersPayload.members) || [];
                detail.roles = (rolesPayload && rolesPayload.roles) || [];
                detail.loading = false;
                state.memberCounts[detail.id] = detail.members.length;
                renderModal();
            }
            return true;
        } catch (err) {
            console.error('[Groups] detail error:', err);
            if (state.detail === detail) {
                detail.loading = false;
                detail.error = true;
                renderModal();
            }
            return false;
        }
    }

    function detailBody(modal) {
        const detail = state.detail;
        if (!detail) return '';

        const tabs = `
            <div class="tabs mb-4">
                <button type="button" class="tab${detail.tab === 'members' ? ' is-active' : ''}" data-action="detail-tab" data-tab="members">${t('groups.members.title')}</button>
                <button type="button" class="tab${detail.tab === 'roles' ? ' is-active' : ''}" data-action="detail-tab" data-tab="roles">${t('groups.roles.title')}</button>
            </div>`;

        const header = `
            <div class="flex items-center gap-2 mb-3">
                <span class="font-medium">${UI.escapeHtml(detail.group.nombre || '')}</span>
                ${estadoBadge(!!detail.group.activo)}
            </div>`;

        if (detail.loading) return header + tabs + UI.loadingState();
        if (detail.error) return header + tabs + UI.emptyState({ title: t('groups.errors.detail'), message: '' });

        const contenido = detail.tab === 'members' ? membersTab(detail) : rolesTab(detail);
        return header + tabs + contenido;
    }

    function membersTab(detail) {
        const members = detail.members || [];
        const addBtn = canManageMembers()
            ? `<button class="btn btn-primary btn-sm" data-action="open-add-member">${t('groups.members.add')}</button>`
            : '';

        if (members.length === 0) {
            return `
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm text-muted">${t('groups.members.empty')}</span>
                    ${addBtn}
                </div>`;
        }

        const rows = members.map(m => {
            const quitar = canManageMembers()
                ? `<button class="btn btn-ghost btn-sm btn-danger" data-action="remove-member" data-user-id="${UI.escapeHtml(m.user_id)}">${t('groups.members.remove')}</button>`
                : '';

            return `
                <tr>
                    <td>${UI.escapeHtml((m.nombre || '') + ' ' + (m.apellido || ''))}</td>
                    <td>${UI.escapeHtml(m.email || '')}</td>
                    <td>${estadoUsuarioBadge(m.estado)}</td>
                    <td>${UI.escapeHtml(m.added_at || '—')}</td>
                    <td class="table-actions col-actions">${quitar}</td>
                </tr>`;
        }).join('');

        return `
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm text-muted">${t('groups.members.count', { total: UI.formatNumber(members.length) })}</span>
                ${addBtn}
            </div>
            ${UI.tableShell([
                { label: t('groups.members.user') },
                { label: t('groups.members.email') },
                { label: t('groups.members.state') },
                { label: t('groups.members.since') },
                { label: t('groups.members.actions'), className: 'col-actions' }
            ], rows)}`;
    }

    function rolesTab(detail) {
        const roles = detail.roles || [];
        const assignBtn = canManageRoles()
            ? `<button class="btn btn-primary btn-sm" data-action="open-assign-role">${t('groups.roles.assign')}</button>`
            : '';

        if (roles.length === 0) {
            return `
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm text-muted">${t('groups.roles.empty')}</span>
                    ${assignBtn}
                </div>`;
        }

        const rows = roles.map(r => {
            const borrado = r.role_deleted_at
                ? ` <span class="badge badge-danger">${t('groups.roles.deleted_role')}</span>`
                : '';
            const quitar = canManageRoles()
                ? `<button class="btn btn-ghost btn-sm btn-danger" data-action="remove-role" data-role-id="${UI.escapeHtml(r.role_id)}">${t('groups.roles.unassign')}</button>`
                : '';

            return `
                <tr>
                    <td>${UI.escapeHtml(r.nombre || '')}${borrado}</td>
                    <td><span class="badge badge-${r.tipo === 'BUILT_IN' ? 'neutral' : 'info'}">${UI.escapeHtml(r.tipo || '')}</span></td>
                    <td>${UI.escapeHtml(r.assigned_at || '—')}</td>
                    <td class="table-actions col-actions">${quitar}</td>
                </tr>`;
        }).join('');

        return `
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm text-muted">${t('groups.roles.count', { total: UI.formatNumber(roles.length) })}</span>
                ${assignBtn}
            </div>
            ${UI.tableShell([
                { label: t('groups.roles.role') },
                { label: t('groups.roles.type') },
                { label: t('groups.roles.assigned_at') },
                { label: t('groups.members.actions'), className: 'col-actions' }
            ], rows)}`;
    }

    // ====================================================================
    // MODALES
    // ====================================================================

    function getModalRoot() {
        return container ? container.querySelector('[data-modal-root]') : null;
    }

    function closeModal() {
        state.modal = null;
        state.detail = null;
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

    // ---------------------------------------------------------------- Alta

    function openCreate() {
        if (!canCreate()) return;
        state.modal = { mode: 'create', errors: {}, formError: '' };
        renderModal();
    }

    function createBody() {
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${UI.grid(`
                ${UI.field({ label: t('groups.fields.nombre'), name: 'nombre', type: 'text', required: true, maxlength: 150 })}
                ${UI.field({ label: t('groups.fields.descripcion'), name: 'descripcion', type: 'textarea', rows: 2 })}
            `, 1)}`;
    }

    async function submitCreate() {
        if (state.sending) return;
        const root = getModalRoot();
        UI.clearErrors(root);

        const nombre = fieldValue(root, 'nombre');
        const descripcion = fieldValue(root, 'descripcion');

        if (!nombre) {
            UI.showFieldError(root, 'nombre', t('groups.errors.required'));
            return;
        }

        setSending(true);

        try {
            const payload = { nombre };
            if (descripcion) payload.descripcion = descripcion;

            await global.HttpClient.post('/groups', payload, {
                headers: { 'Idempotency-Key': UI.newIdempotencyKey() }
            });
            closeModal();
            const recargado = await loadGroups();
            if (recargado) {
                UI.toast('success', t('groups.create.success_title'), t('groups.create.success_body', { nombre: UI.escapeHtml(nombre) }));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('groups.errors.create'),
                root: getModalRoot(),
                inline: true,
                tag: 'Groups'
            });
        } finally {
            setSending(false);
        }
    }

    // -------------------------------------------------------------- Edicion

    function openEdit(id) {
        if (!canEdit()) return;
        const group = state.items.find(g => g.id === id);
        if (!group) return;
        state.modal = { mode: 'edit', id, group, errors: {}, formError: '' };
        renderModal();
    }

    function editBody(modal) {
        const g = modal.group;
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${UI.grid(`
                ${UI.field({ label: t('groups.fields.nombre'), name: 'nombre', type: 'text', value: g.nombre || '', required: true, maxlength: 150 })}
                ${UI.field({ label: t('groups.fields.descripcion'), name: 'descripcion', type: 'textarea', rows: 2, value: g.descripcion || '' })}
            `, 1)}
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="activo" data-modal-field="activo"${g.activo ? ' checked' : ''}>
                ${t('groups.fields.activo')}
            </label>`;
    }

    async function submitEdit() {
        if (state.sending) return;
        const modal = state.modal;
        const root = getModalRoot();
        UI.clearErrors(root);

        const nombre = fieldValue(root, 'nombre');
        const descripcion = fieldValue(root, 'descripcion');
        const activoEl = root.querySelector('[data-modal-field="activo"]');

        if (!nombre) {
            UI.showFieldError(root, 'nombre', t('groups.errors.required'));
            return;
        }

        setSending(true);

        try {
            const payload = {
                nombre,
                descripcion: descripcion || null,
                activo: activoEl ? !!activoEl.checked : true
            };

            await global.HttpClient.patch('/groups/' + encodeURIComponent(modal.id), payload);
            closeModal();
            const recargado = await loadGroups();
            if (recargado) {
                UI.toast('success', t('groups.edit.success_title'), t('groups.edit.success_body', { nombre: UI.escapeHtml(nombre) }));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('groups.errors.edit'),
                root: getModalRoot(),
                inline: true,
                tag: 'Groups'
            });
        } finally {
            setSending(false);
        }
    }

    // ------------------------------------------------------------- Detalle

    function openDetail(id) {
        const group = state.items.find(g => g.id === id);
        if (!group) return;

        state.detail = { id, group, tab: 'members', members: null, roles: null, loading: true };
        state.modal = { mode: 'detail' };
        renderModal();
        loadDetail();
    }

    // -------------------------------------------------------- Add miembro

    function openAddMember() {
        if (!canManageMembers() || !state.detail) return;
        state.modal = { mode: 'add-member', selected: [], errors: {}, formError: '' };
        renderModal();
        // Foco explícito: el observer M0 no re-enfoca porque la raíz ya está
        // en openedRoots (venimos del detalle). Este focus dispara el primer
        // load('') del combobox.
        const root = getModalRoot();
        const input = root ? root.querySelector('[data-combo-input]') : null;
        if (input) input.focus();
    }

    function addMemberBody() {
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${UI.combobox({
                name: 'user_id',
                label: t('groups.members.combo_label'),
                placeholder: t('groups.members.combo_placeholder'),
                required: true,
                hint: t('groups.members.add_hint')
            })}`;
    }

    function selectedMembers() {
        return (state.modal && state.modal.selected) || [];
    }

    function renderChips(root) {
        const box = root ? root.querySelector('[data-combo-chips]') : null;
        if (!box) return;
        const etiquetas = selectedMembers().map(s => `
            <span class="chip">
                ${UI.escapeHtml(s.label)}
                <button type="button" class="chip-remove" data-action="combo-remove" data-value="${UI.escapeHtml(s.value)}" aria-label="${UI.escapeHtml(t('groups.members.remove'))}">&times;</button>
            </span>`);
        box.innerHTML = etiquetas.join('');
    }

    function selectMember(root, item) {
        if (!state.modal || state.modal.mode !== 'add-member' || !item || !item.value) return;
        if (selectedMembers().some(s => s.value === item.value)) return;
        state.modal.selected.push({ value: item.value, label: item.label });
        if (root) UI.clearErrors(root);
        renderChips(root);
    }

    function bindAddMemberCombo(root) {
        UI.bindCombobox(root, {
            fetch: fetchUsers,
            onSelect: (item) => selectMember(root, item),
            isExcluded: (item) =>
                selectedMembers().some(s => s.value === item.value) ||
                (((state.detail && state.detail.members) || [])
                    .some(m => String(m.user_id) === String(item.value))),
            loadingLabel: t('groups.members.combo_loading'),
            emptyLabel: t('groups.members.combo_empty'),
            errorLabel: t('groups.members.search_error')
        });
    }

    async function submitAddMember() {
        if (state.sending) return;
        const detail = state.detail;
        if (!detail) return;
        const root = getModalRoot();
        UI.clearErrors(root);

        const seleccionados = selectedMembers();
        if (seleccionados.length === 0) {
            UI.showFieldError(root, 'user_id', t('groups.members.add_required'));
            return;
        }

        setSending(true);
        let anadidos = 0;
        let primerError = null;

        try {
            for (const u of seleccionados) {
                try {
                    await global.HttpClient.post('/groups/' + encodeURIComponent(detail.id) + '/members', { user_id: u.value });
                    anadidos++;
                } catch (err) {
                    primerError = err;
                    break;
                }
            }

            if (anadidos > 0 && global.AuthState && typeof global.AuthState.refreshUser === 'function') {
                const cur = global.AuthState.getUserId && global.AuthState.getUserId();
                const ids = seleccionados.slice(0, anadidos).map(u => String(u.value));
                if (cur && ids.indexOf(String(cur)) !== -1) global.AuthState.refreshUser().catch(function () {});
            }

            if (!primerError) {
                state.modal = { mode: 'detail' };
                renderModal();
                const recargado = await loadDetail();
                if (recargado) {
                    UI.toast('success', t('groups.members.add'),
                        anadidos > 1 ? t('groups.members.added_multi') : t('groups.members.added'));
                }
                return;
            }

            // Error parcial: los ya añadidos salen de los chips y el detalle
            // queda marcado para recargar al volver (back-detail).
            state.modal.selected = seleccionados.slice(anadidos);
            if (state.detail) state.detail.members = null;
            renderChips(root);
            UI.handleActionError(primerError, {
                fallbackMessage: t('groups.errors.add_member'),
                root: getModalRoot(),
                inline: true,
                tag: 'Groups'
            });
        } finally {
            setSending(false);
        }
    }

    async function removeMember(userId) {
        const detail = state.detail;
        if (!detail || !canManageMembers()) return;

        try {
            await global.HttpClient.delete('/groups/' + encodeURIComponent(detail.id) + '/members/' + encodeURIComponent(userId));
            if (global.AuthState && typeof global.AuthState.refreshUser === 'function') {
                const cur = global.AuthState.getUserId && global.AuthState.getUserId();
                if (cur && String(cur) === String(userId)) global.AuthState.refreshUser().catch(function () {});
            }
            const recargado = await loadDetail();
            if (recargado) {
                UI.toast('success', t('groups.members.remove'), t('groups.members.removed'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('groups.errors.remove_member'),
                tag: 'Groups'
            });
        }
    }

    // -------------------------------------------------------- Asignar rol

    function openAssignRole() {
        if (!canManageRoles() || !state.detail) return;
        state.roles = [];
        state.rolesLoaded = false;
        state.rolesError = false;
        state.modal = { mode: 'assign-role', errors: {}, formError: '' };
        renderModal();

        loadRolesCatalog().then(() => {
            if (state.modal && state.modal.mode === 'assign-role') renderModal();
        });
    }

    function assignRoleBody(modal) {
        const detail = state.detail;
        if (!detail) return '';

        if (state.rolesError) {
            return `<p class="text-sm text-danger">${t('groups.roles.catalog_error')}</p>`;
        }

        if (!state.rolesLoaded) return UI.loadingState();

        const asignados = (detail.roles || []).map(r => r.role_id);
        const disponibles = state.roles.filter(r => r.activo && asignados.indexOf(r.id) === -1);

        if (disponibles.length === 0) {
            return `<p class="text-muted text-sm">${t('groups.roles.all_assigned')}</p>`;
        }

        const opciones = disponibles.map(r => ({
            value: r.id,
            label: (r.nombre || '') + ' (' + (r.tipo || '') + ')'
        }));

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${UI.field({
                label: t('groups.roles.select'),
                name: 'role_id',
                type: 'select',
                required: true,
                options: [{ value: '', label: t('groups.roles.select_placeholder') }].concat(opciones)
            })}
            <div class="alert alert-warning mt-3">${t('groups.roles.escalation_hint')}</div>`;
    }

    async function submitAssignRole() {
        if (state.sending) return;
        const detail = state.detail;
        if (!detail) return;
        const root = getModalRoot();
        UI.clearErrors(root);

        const roleId = fieldValue(root, 'role_id');
        if (!roleId) {
            UI.showFieldError(root, 'role_id', t('groups.errors.required'));
            return;
        }

        setSending(true);

        try {
            await global.HttpClient.post('/groups/' + encodeURIComponent(detail.id) + '/roles', { role_id: roleId });
            state.modal = { mode: 'detail' };
            renderModal();
            const recargado = await loadDetail();
            if (recargado) {
                UI.toast('success', t('groups.roles.assign'), t('groups.roles.assigned'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('groups.errors.assign_role'),
                root: getModalRoot(),
                inline: true,
                tag: 'Groups'
            });
        } finally {
            setSending(false);
        }
    }

    async function removeRole(roleId) {
        const detail = state.detail;
        if (!detail || !canManageRoles()) return;

        try {
            await global.HttpClient.delete('/groups/' + encodeURIComponent(detail.id) + '/roles/' + encodeURIComponent(roleId));
            const recargado = await loadDetail();
            if (recargado) {
                UI.toast('success', t('groups.roles.unassign'), t('groups.roles.unassigned'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('groups.errors.unassign_role'),
                tag: 'Groups'
            });
        }
    }

    // ------------------------------------------------------------- Borrado

    function openDelete(id) {
        if (!canDelete()) return;
        const group = state.items.find(g => g.id === id);
        if (!group) return;
        state.modal = { mode: 'delete', id, group, errors: {}, formError: '' };
        renderModal();
    }

    function deleteBody(modal) {
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <p>${t('groups.delete.body', { nombre: UI.escapeHtml(modal.group.nombre || '') })}</p>
            <div class="alert alert-danger mt-3">${t('groups.delete.warning')}</div>`;
    }

    async function submitDelete() {
        if (state.sending) return;
        const modal = state.modal;
        setSending(true);

        try {
            await global.HttpClient.delete('/groups/' + encodeURIComponent(modal.id));
            closeModal();
            const recargado = await loadGroups();
            if (recargado) {
                UI.toast('success', t('groups.delete.success_title'), t('groups.delete.success_body'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('groups.errors.delete'),
                root: getModalRoot(),
                inline: true,
                tag: 'Groups'
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
        let size = 'xl';
        let body = '';
        let footer = '';

        switch (modal.mode) {
            case 'create':
                title = t('groups.create.title');
                size = 'lg';
                body = createBody();
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-create" data-modal-send${state.sending ? ' disabled' : ''}>${t('groups.create.submit')}</button>`;
                break;
            case 'edit':
                title = t('groups.edit.title');
                size = 'lg';
                body = editBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-edit" data-modal-send${state.sending ? ' disabled' : ''}>${t('groups.edit.submit')}</button>`;
                break;
            case 'detail':
                title = t('groups.detail.title');
                body = detailBody(modal);
                footer = `<button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>`;
                break;
            case 'add-member':
                title = t('groups.members.add_title');
                size = 'md';
                body = addMemberBody();
                footer = UI.modalFooter({
                    cancelLabel: t('ui.cancel'),
                    cancelAction: 'back-detail',
                    submitLabel: t('groups.members.add_submit'),
                    submitAction: 'submit-add-member',
                    disabled: state.sending
                });
                break;
            case 'assign-role':
                title = t('groups.roles.assign_title');
                size = 'lg';
                body = assignRoleBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="back-detail">${t('ui.close')}</button>
                    ${(state.rolesLoaded && !state.rolesError && state.roles.length > 0)
                        ? `<button class="btn btn-primary" data-action="submit-assign-role" data-modal-send${state.sending ? ' disabled' : ''}>${t('groups.roles.assign_submit')}</button>`
                        : ''}`;
                break;
            case 'delete':
                title = t('groups.delete.title');
                size = 'sm';
                body = deleteBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-danger" data-action="submit-delete" data-modal-send${state.sending ? ' disabled' : ''}>${t('groups.delete.submit')}</button>`;
                break;
        }

        UI.mountModal(root, UI.modalShell({ title, size, bodyHtml: body, footerHtml: footer }));

        if (modal.mode === 'add-member') {
            bindAddMemberCombo(root);
            renderChips(root);
        }
    }

    function fieldValue(root, name) {
        if (!root) return '';
        const el = root.querySelector(`[data-modal-field="${name}"]`);
        return el ? String(el.value || '').trim() : '';
    }

    // ====================================================================
    // EVENTOS
    // ====================================================================

    function bindEvents() {
        if (!container) return;
        container.removeEventListener('click', handleClick);
        container.removeEventListener('change', handleChange);
        container.addEventListener('click', handleClick);
        container.addEventListener('change', handleChange);
    }

    function handleClick(e) {
        const btn = e.target.closest('[data-action]');
        if (!btn || btn.disabled) return;

        const action = btn.dataset.action;
        const id = btn.dataset.id;

        switch (action) {
            case 'open-create':
                openCreate();
                break;
            case 'open-edit':
                openEdit(id);
                break;
            case 'open-delete':
                openDelete(id);
                break;
            case 'open-detail':
                openDetail(id);
                break;
            case 'detail-tab': {
                const tab = btn.dataset.tab;
                if (state.detail && tab) {
                    state.detail.tab = tab;
                    renderModal();
                }
                break;
            }
            case 'open-add-member':
                openAddMember();
                break;
            case 'open-assign-role':
                openAssignRole();
                break;
            case 'back-detail':
                state.modal = { mode: 'detail' };
                renderModal();
                if (state.detail && state.detail.members === null) loadDetail();
                break;
            case 'combo-remove': {
                const modal = state.modal;
                if (modal && modal.selected) {
                    modal.selected = modal.selected.filter(s => s.value !== btn.dataset.value);
                    const root = getModalRoot();
                    renderChips(root);
                    const input = root ? root.querySelector('[data-combo-input]') : null;
                    if (input) {
                        // blur+focus garantiza el evento focus (y con él la
                        // recarga de sugerencias) aunque el foco no hubiera
                        // salido del input al pulsar el chip.
                        input.blur();
                        input.focus();
                    }
                }
                break;
            }
            case 'remove-member':
                removeMember(btn.dataset.userId);
                break;
            case 'remove-role':
                removeRole(btn.dataset.roleId);
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
            case 'submit-add-member':
                submitAddMember();
                break;
            case 'submit-assign-role':
                submitAssignRole();
                break;
            case 'submit-delete':
                submitDelete();
                break;
        }
    }

    function handleChange(e) {
        const el = e.target;
        if (el.matches('[data-group-filter="inactive"]')) {
            state.includeInactive = !!el.checked;
            loadGroups();
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const GroupsGroupsModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            container.innerHTML = buildTemplate();
            bindEvents();
            loadGroups();
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            if (!container.querySelector('[data-groups-table]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
            }
            loadGroups();
        },

        destroy() {
            if (container) {
                container.removeEventListener('click', handleClick);
                container.removeEventListener('change', handleChange);
                container.innerHTML = '';
            }
            container = null;
            state = {
                items: [],
                loading: false,
                includeInactive: false,
                memberCounts: {},
                roles: [],
                rolesLoaded: false,
                rolesError: false,
                detail: null,
                modal: null,
                sending: false
            };
        }
    };

    global.GroupsGroupsModule = GroupsGroupsModule;

})(window);
