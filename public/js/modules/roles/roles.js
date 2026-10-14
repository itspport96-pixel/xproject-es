/**
 * Roles Module (pantalla de control de roles) - v1.13.25.
 * GET/POST/PATCH/DELETE /api/roles, PATCH /roles/{id}/permissions y el
 * catalogo GET /api/permissions para el selector maestro-detalle
 * (UI.permissionPicker).
 *
 * Convencion ModuleLoader: window.RolesRolesModule (ruta roles/roles).
 *
 * Reglas de la UI (alineadas con el backend):
 *  - BUILT_IN: sin borrado ni edicion de metadatos; la matriz de permisos es
 *    de solo lectura (el backend protege ADMIN y el renombrado).
 *  - CUSTOM: alta, edicion de nombre/descripcion/activo y borrado.
 *
 * v1.13.9 (politica de borrado): no hay restauracion; los borrados no se
 * listan y un nombre borrado puede reutilizarse en un rol nuevo.
 *
 * v1.13.25 (BLOQUE F): el modal de crear/editar/ver usa UI.permissionPicker
 * (maestro-detalle con buscador, escalada, basar-en). La descripcion va
 * sola en su propia fila (nunca al par de otro campo) con textarea que
 * crece con el contenido, sin scrollbars propios.
 */
(function(global) {
    'use strict';

    let container = null;

    let state = {
        items: [],
        loading: false,
        includeInactive: false,
        catalog: [],
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

    const canView = () => can('roles.role.view', ['ADMIN']);
    const canCreate = () => can('roles.role.create', ['ADMIN']);
    const canEdit = () => can('roles.role.edit', ['ADMIN']);
    const canDelete = () => can('roles.role.delete', ['ADMIN']);

    function grantedPermissions() {
        try {
            if (global.AuthState && typeof global.AuthState.getPermissions === 'function') {
                const perms = global.AuthState.getPermissions();
                return Array.isArray(perms) && perms.length ? perms : null;
            }
        } catch (err) { /* sin escalada */ }
        return null;
    }

    // ====================================================================
    // PLANTILLA
    // ====================================================================

    function buildTemplate() {
        const createBtn = canCreate()
            ? `<button class="btn btn-primary btn-sm" data-action="open-create">${t('roles.actions.create')}</button>`
            : '';

        return `
            <div class="max-w-7xl mx-auto">
                ${UI.pageHeader({
                    title: t('roles.title'),
                    subtitle: t('roles.subtitle'),
                    actionsHtml: createBtn
                })}

                <div class="card mb-4 flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" data-role-filter="inactive"${state.includeInactive ? ' checked' : ''}>
                        ${t('roles.filter.inactive')}
                    </label>
                </div>

                <div class="card p-0">
                    <div class="table-wrapper" data-roles-table></div>
                </div>
            </div>

            <div data-modal-root></div>
        `;
    }

    function renderPermissionDenied() {
        container.innerHTML = `
            <div class="card max-w-md mx-auto mt-8">
                <div class="card-header">
                    <h3 class="card-title">${t('roles.denied.title')}</h3>
                </div>
                <div class="card-body">
                    <p>${t('roles.denied.body')}</p>
                    <div class="mt-4">
                        <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                    </div>
                </div>
            </div>`;
    }

    // ====================================================================
    // TABLA
    // ====================================================================

    function typeBadge(tipo) {
        const esBuiltIn = tipo === 'BUILT_IN';
        const label = esBuiltIn ? t('roles.type.BUILT_IN') : t('roles.type.CUSTOM');
        return `<span class="badge badge-${esBuiltIn ? 'neutral' : 'info'}">${label}</span>`;
    }

    function estadoBadge(activo) {
        return activo
            ? `<span class="badge badge-success">${t('roles.state.active')}</span>`
            : `<span class="badge badge-warning">${t('roles.state.inactive')}</span>`;
    }

    function renderTable() {
        const root = container.querySelector('[data-roles-table]');
        if (!root) return;

        if (state.loading) {
            root.innerHTML = UI.tableSkeleton([
                { label: t('roles.columns.name') },
                { label: t('roles.columns.type') },
                { label: t('roles.columns.state') },
                { label: t('roles.columns.permissions'), className: 'text-right' },
                { label: t('roles.columns.actions'), className: 'col-actions' }
            ], 6, [40, 15, 15, 15, 15]);
            return;
        }

        if (state.items.length === 0) {
            root.innerHTML = UI.emptyState({
                title: t('roles.empty.title'),
                message: t('roles.empty.message')
            });
            return;
        }

        const rows = state.items.map(r => {
            const esBuiltIn = r.tipo === 'BUILT_IN';
            const permisos = Array.isArray(r.permisos) ? r.permisos.length : 0;
            const acciones = UI.rowActions({
                primary: canView()
                    ? { action: 'open-permissions', id: r.id, label: t('roles.actions.permissions') }
                    : null,
                groups: [
                    [canEdit() && !esBuiltIn ? { action: 'open-edit', id: r.id, label: t('roles.actions.edit') } : null],
                    [canDelete() && !esBuiltIn ? { action: 'open-delete', id: r.id, label: t('roles.actions.delete'), danger: true } : null]
                ],
                ariaLabel: t('roles.columns.actions')
            });

            return `
                <tr>
                    <td>
                        <div class="font-medium">${UI.escapeHtml(r.nombre || '')}</div>
                        ${r.descripcion ? `<div class="text-xs text-muted">${UI.escapeHtml(r.descripcion)}</div>` : ''}
                    </td>
                    <td>${typeBadge(r.tipo)}</td>
                    <td>${estadoBadge(!!r.activo)}</td>
                    <td class="text-right">${UI.formatNumber(permisos)}</td>
                    <td class="table-actions col-actions">${acciones}</td>
                </tr>`;
        }).join('');

        root.innerHTML = UI.tableShell([
            { label: t('roles.columns.name') },
            { label: t('roles.columns.type') },
            { label: t('roles.columns.state') },
            { label: t('roles.columns.permissions'), className: 'text-right' },
            { label: t('roles.columns.actions'), className: 'col-actions' }
        ], rows);
    }

    // ====================================================================
    // CARGA
    // ====================================================================

    async function loadRoles() {
        state.loading = true;
        renderTable();

        try {
            const params = {};
            if (state.includeInactive) params.include_inactive = 'true';
            const response = await global.HttpClient.get('/roles', { params });
            const payload = response && response.data ? response.data : response;
            state.items = (payload && payload.roles) || [];
            return true;
        } catch (err) {
            console.error('[Roles] load error:', err);
            UI.toast('danger', t('ui.error_generic'), err.message || t('roles.errors.load'));
            state.items = [];
            return false;
        } finally {
            state.loading = false;
            renderTable();
        }
    }

    async function loadCatalog() {
        try {
            const response = await global.HttpClient.get('/permissions', {
                params: { include_inactive: 'false' }
            });
            const payload = response && response.data ? response.data : response;
            state.catalog = (payload && payload.permissions) || [];
        } catch (err) {
            console.error('[Roles] catalog error:', err);
            UI.toast('danger', t('ui.error_generic'), err.message || t('roles.errors.catalog'));
            state.catalog = [];
        }
        return state.catalog;
    }

    async function loadRolePermissionCodes(id) {
        const response = await global.HttpClient.get('/roles/' + encodeURIComponent(id) + '/permissions');
        const payload = response && response.data ? response.data : response;
        return (payload && payload.permisos) || [];
    }

    async function loadRolePermissions(id) {
        const modal = state.modal;
        if (!modal || modal.mode !== 'permissions') return;

        try {
            modal.permisos = await loadRolePermissionCodes(id);
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('roles.perms.load_error'),
                tag: 'Roles'
            });
            modal.permisos = [];
        }
    }

    // ====================================================================
    // PICKER (selector maestro-detalle)
    // ====================================================================

    function pickerReadonly(modal) {
        if (modal.mode === 'permissions') {
            return modal.role.tipo === 'BUILT_IN' || !canEdit();
        }
        return false;
    }

    function countCritical(codes) {
        const byCode = new Map(state.catalog.map(p => [p.identificador, p]));
        let n = 0;
        (codes || []).forEach(c => {
            const p = byCode.get(c);
            if (p && p.criticidad === 'CRITICAL') n++;
        });
        return n;
    }

    function updatePickerSummary(codes) {
        const root = getModalRoot();
        if (!root) return;
        const lista = Array.isArray(codes) ? codes : [];
        const criticos = countCritical(lista);

        const summary = root.querySelector('[data-pp-summary]');
        if (summary) {
            summary.textContent = t('roles.perms.summary', {
                count: UI.formatNumber(lista.length),
                critical: UI.formatNumber(criticos)
            });
        }

        const alertEl = root.querySelector('[data-pp-critical]');
        if (alertEl) {
            if (criticos > 0) {
                alertEl.textContent = t('roles.perms.critical_alert', { count: UI.formatNumber(criticos) });
                alertEl.classList.remove('hidden');
            } else {
                alertEl.classList.add('hidden');
            }
        }
    }

    function bindAutogrow(root) {
        root.querySelectorAll('.textarea-autogrow').forEach(el => {
            const fit = () => {
                el.style.height = 'auto';
                el.style.height = el.scrollHeight + 'px';
            };
            el.addEventListener('input', fit);
            fit();
        });
    }

    function postMountPicker() {
        const root = getModalRoot();
        const modal = state.modal;
        if (!root || !modal) return;
        if (modal.mode !== 'create' && modal.mode !== 'edit' && modal.mode !== 'permissions') return;
        if (modal.loading || (modal.mode === 'permissions' && modal.permisos === null)) return;

        const panel = root.querySelector('[data-pp-panel]');
        if (!panel) return;

        const readonly = pickerReadonly(modal);
        const selected = Array.isArray(modal.permisos) ? modal.permisos : [];
        const baseRoles = readonly
            ? null
            : state.items
                .filter(r => String(r.id) !== String(modal.id))
                .map(r => ({ id: r.id, nombre: r.nombre }));

        UI.permissionPicker.mount({
            toolbar: root.querySelector('[data-pp-toolbar]'),
            panel: panel,
            catalog: state.catalog,
            selected: selected,
            readonly: readonly,
            granted: grantedPermissions(),
            baseRoles: baseRoles,
            onBaseRole: loadRolePermissionCodes,
            onChange: updatePickerSummary
        });

        bindAutogrow(root);
        updatePickerSummary(selected);
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

    function pickerSelected() {
        const root = getModalRoot();
        if (!root) return [];
        const panel = root.querySelector('[data-pp-panel]');
        return UI.permissionPicker.getSelected(panel);
    }

    // ---------------------------------------------------------------- Alta

    function openCreate() {
        if (!canCreate()) return;
        state.modal = {
            mode: 'create',
            permisos: [],
            loading: !state.catalog.length,
            errors: {},
            formError: ''
        };
        renderModal();

        if (state.modal.loading) {
            loadCatalog().then(() => {
                if (state.modal && state.modal.mode === 'create') {
                    state.modal.loading = false;
                    renderModal();
                }
            });
        }
    }

    function createBody(modal) {
        if (modal.loading) return UI.loadingState();

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <div class="pp-head-row has-toolbar">
                ${UI.field({ label: t('roles.fields.name'), name: 'nombre', type: 'text', required: true, maxlength: 100 })}
                <div class="pp-toolbar" data-pp-toolbar></div>
            </div>
            ${UI.field({ label: t('roles.fields.descripcion'), name: 'descripcion', type: 'textarea', rows: 2, maxlength: 500, controlClass: 'textarea-autogrow' })}
            <div class="form-label mt-4">${t('roles.perms.title')}</div>
            <div class="pp-mount" data-pp-panel></div>
            <div class="alert alert-danger pp-critical hidden mt-2" data-pp-critical></div>
        `;
    }

    async function submitCreate() {
        if (state.sending) return;
        const root = getModalRoot();
        UI.clearErrors(root);

        const nombre = fieldValue(root, 'nombre');
        const descripcion = fieldValue(root, 'descripcion');
        const permisos = pickerSelected();

        const errors = {};
        if (!nombre) errors.nombre = t('roles.errors.required');

        if (Object.keys(errors).length > 0) {
            Object.entries(errors).forEach(([f, msg]) => UI.showFieldError(root, f, msg));
            return;
        }

        setSending(true);

        try {
            const payload = { nombre, permisos };
            if (descripcion) payload.descripcion = descripcion;

            await global.HttpClient.post('/roles', payload);
            closeModal();
            const recargado = await loadRoles();
            if (recargado) {
                UI.toast('success', t('roles.create.success_title'), t('roles.create.success_body', { nombre: UI.escapeHtml(nombre) }));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('roles.errors.create'),
                root: getModalRoot(),
                inline: true,
                tag: 'Roles'
            });
        } finally {
            setSending(false);
        }
    }

    // -------------------------------------------------------------- Edicion

    function openEdit(id) {
        if (!canEdit()) return;
        const role = state.items.find(r => r.id === id);
        if (!role || role.tipo === 'BUILT_IN') return;
        const permisos = Array.isArray(role.permisos) ? role.permisos.slice() : null;

        state.modal = {
            mode: 'edit',
            id,
            role,
            permisos,
            loading: !state.catalog.length || permisos === null,
            errors: {},
            formError: ''
        };
        renderModal();

        const tareas = [];
        if (!state.catalog.length) tareas.push(loadCatalog());
        if (permisos === null) tareas.push(loadRolePermissions(id));
        Promise.all(tareas).then(() => {
            if (state.modal && state.modal.mode === 'edit') {
                state.modal.loading = false;
                renderModal();
            }
        });
    }

    function editBody(modal) {
        if (modal.loading) return UI.loadingState();
        const r = modal.role;

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <div class="pp-head-row has-toolbar">
                ${UI.field({ label: t('roles.fields.name'), name: 'nombre', type: 'text', value: r.nombre || '', required: true, maxlength: 100 })}
                <div class="pp-toolbar" data-pp-toolbar></div>
            </div>
            ${UI.field({ label: t('roles.fields.descripcion'), name: 'descripcion', type: 'textarea', rows: 2, maxlength: 500, value: r.descripcion || '', controlClass: 'textarea-autogrow' })}
            <label class="flex items-center gap-2 text-sm mt-3">
                <input type="checkbox" name="activo" data-modal-field="activo"${r.activo ? ' checked' : ''}>
                ${t('roles.fields.activo')}
            </label>
            <div class="form-label mt-4">${t('roles.perms.title')}</div>
            <div class="pp-mount" data-pp-panel></div>
            <div class="alert alert-danger pp-critical hidden mt-2" data-pp-critical></div>
        `;
    }

    async function submitEdit() {
        if (state.sending) return;
        const modal = state.modal;
        const root = getModalRoot();
        UI.clearErrors(root);

        const nombre = fieldValue(root, 'nombre');
        const descripcion = fieldValue(root, 'descripcion');
        const activoEl = root.querySelector('[data-modal-field="activo"]');
        const permisos = pickerSelected();

        const errors = {};
        if (!nombre) errors.nombre = t('roles.errors.required');

        if (Object.keys(errors).length > 0) {
            Object.entries(errors).forEach(([f, msg]) => UI.showFieldError(root, f, msg));
            return;
        }

        setSending(true);

        try {
            const payload = {
                nombre,
                descripcion: descripcion || null,
                activo: activoEl ? !!activoEl.checked : true
            };

            await global.HttpClient.patch('/roles/' + encodeURIComponent(modal.id), payload);
            await global.HttpClient.patch('/roles/' + encodeURIComponent(modal.id) + '/permissions', { permisos });
            closeModal();
            const recargado = await loadRoles();
            if (recargado) {
                UI.toast('success', t('roles.edit.success_title'), t('roles.edit.success_body', { nombre: UI.escapeHtml(nombre) }));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('roles.errors.edit'),
                root: getModalRoot(),
                inline: true,
                tag: 'Roles'
            });
        } finally {
            setSending(false);
        }
    }

    // ----------------------------------------------------------- Permisos

    function openPermissions(id) {
        if (!canView()) return;
        const role = state.items.find(r => r.id === id);
        if (!role) return;

        state.modal = {
            mode: 'permissions',
            id,
            role,
            permisos: null,
            loading: !state.catalog.length,
            errors: {},
            formError: ''
        };
        renderModal();

        const tareas = [];
        if (!state.catalog.length) tareas.push(loadCatalog());
        tareas.push(loadRolePermissions(id));
        Promise.all(tareas).then(() => {
            if (state.modal && state.modal.mode === 'permissions') {
                state.modal.loading = false;
                renderModal();
            }
        });
    }

    function permissionsBody(modal) {
        if (modal.loading || modal.permisos === null) return UI.loadingState();

        const readonly = pickerReadonly(modal);

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <div class="pp-head-row${readonly ? '' : ' has-toolbar'}">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-medium">${UI.escapeHtml(modal.role.nombre || '')}</span>
                    ${typeBadge(modal.role.tipo)}
                    ${estadoBadge(!!modal.role.activo)}
                </div>
                <div class="pp-toolbar" data-pp-toolbar></div>
            </div>
            <div class="pp-mount" data-pp-panel></div>
            <div class="alert alert-danger pp-critical hidden mt-2" data-pp-critical></div>
        `;
    }

    async function submitPermissions() {
        if (state.sending) return;
        const modal = state.modal;
        if (!modal || modal.mode !== 'permissions') return;

        const permisos = pickerSelected();
        setSending(true);

        try {
            await global.HttpClient.patch('/roles/' + encodeURIComponent(modal.id) + '/permissions', { permisos });
            closeModal();
            const recargado = await loadRoles();
            if (recargado) {
                UI.toast('success', t('roles.perms.title'), t('roles.perms.saved', { count: UI.formatNumber(permisos.length) }));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('roles.errors.perms'),
                root: getModalRoot(),
                inline: true,
                tag: 'Roles'
            });
        } finally {
            setSending(false);
        }
    }

    // ------------------------------------------------------------- Borrado

    function openDelete(id) {
        if (!canDelete()) return;
        const role = state.items.find(r => r.id === id);
        if (!role || role.tipo === 'BUILT_IN') return;
        state.modal = { mode: 'delete', id, role, errors: {}, formError: '' };
        renderModal();
    }

    function deleteBody(modal) {
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <p>${t('roles.delete.body', { nombre: UI.escapeHtml(modal.role.nombre || '') })}</p>
            <div class="alert alert-danger mt-3">${t('roles.delete.warning')}</div>
        `;
    }

    async function submitDelete() {
        if (state.sending) return;
        const modal = state.modal;
        setSending(true);

        try {
            await global.HttpClient.delete('/roles/' + encodeURIComponent(modal.id));
            closeModal();
            const recargado = await loadRoles();
            if (recargado) {
                UI.toast('success', t('roles.delete.success_title'), t('roles.delete.success_body'));
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('roles.errors.delete'),
                root: getModalRoot(),
                inline: true,
                tag: 'Roles'
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
        const readonly = modal.mode === 'permissions' ? pickerReadonly(modal) : false;

        switch (modal.mode) {
            case 'create':
                title = t('roles.create.title');
                size = 'pp';
                body = createBody(modal);
                footer = `
                    <span class="pp-summary" data-pp-summary></span>
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-create" data-modal-send${state.sending ? ' disabled' : ''}>${t('roles.create.submit')}</button>`;
                break;
            case 'edit':
                title = t('roles.edit.title');
                size = 'pp';
                body = editBody(modal);
                footer = `
                    <span class="pp-summary" data-pp-summary></span>
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-edit" data-modal-send${state.sending ? ' disabled' : ''}>${t('roles.edit.submit')}</button>`;
                break;
            case 'permissions':
                title = t('roles.perms.title');
                size = 'pp';
                body = permissionsBody(modal);
                footer = `
                    <span class="pp-summary" data-pp-summary></span>
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    ${!readonly
                        ? `<button class="btn btn-primary" data-action="submit-permissions" data-modal-send${state.sending ? ' disabled' : ''}>${t('roles.perms.save')}</button>`
                        : ''}`;
                break;
            case 'delete':
                title = t('roles.delete.title');
                size = 'sm';
                body = deleteBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-danger" data-action="submit-delete" data-modal-send${state.sending ? ' disabled' : ''}>${t('roles.delete.submit')}</button>`;
                break;
        }

        UI.mountModal(root, UI.modalShell({ title, size, bodyHtml: body, footerHtml: footer }));
        applyErrors();
        postMountPicker();
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
            case 'open-permissions':
                openPermissions(id);
                break;
            case 'open-delete':
                openDelete(id);
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
            case 'submit-permissions':
                submitPermissions();
                break;
            case 'submit-delete':
                submitDelete();
                break;
        }
    }

    function handleChange(e) {
        const el = e.target;

        if (el.matches('[data-role-filter="inactive"]')) {
            state.includeInactive = !!el.checked;
            loadRoles();
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const RolesRolesModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            container.innerHTML = buildTemplate();
            bindEvents();
            loadRoles();
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                renderPermissionDenied();
                return;
            }

            if (!container.querySelector('[data-roles-table]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
            }
            loadRoles();
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
                catalog: [],
                modal: null,
                sending: false
            };
        }
    };

    global.RolesRolesModule = RolesRolesModule;

})(window);
