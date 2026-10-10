/**
 * Roles Module (pantalla de control de roles) - v1.12.0.
 * GET/POST/PATCH/DELETE /api/roles, PATCH /roles/{id}/permissions y el
 * catalogo GET /api/permissions para la matriz agrupada modulo/recurso.
 *
 * Convencion ModuleLoader: window.RolesRolesModule (ruta roles/roles).
 *
 * Reglas de la UI (alineadas con el backend):
 *  - BUILT_IN: sin borrado ni edicion de metadatos; la matriz de permisos es
 *    de solo lectura (el backend protege ADMIN y el renombrado).
 *  - CUSTOM: alta, edicion de nombre/descripcion/activo y borrado.
 *
 * v1.13.0: GET /roles?include_deleted=true devuelve tambien los borrados
 * (mismo permiso que borrar) y la fila borrada ofrece POST /roles/{id}/restore.
 */
(function(global) {
    'use strict';

    let container = null;

    let state = {
        items: [],
        loading: false,
        includeInactive: false,
        includeDeleted: false,
        catalog: [],
        grouped: {},
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
                    ${canDelete() ? `
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" data-role-filter="deleted"${state.includeDeleted ? ' checked' : ''}>
                        ${t('roles.filter.deleted')}
                    </label>` : ''}
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

    function estadoBorradoBadge() {
        return `<span class="badge badge-danger">${t('roles.state.deleted')}</span>`;
    }

    function criticidadBadge(valor) {
        const color = valor === 'CRITICAL' ? 'danger'
            : valor === 'HIGH' ? 'warning'
            : valor === 'MEDIUM' ? 'info'
            : 'neutral';
        return `<span class="badge badge-${color}">${UI.escapeHtml(valor || '—')}</span>`;
    }

    function renderTable() {
        const root = container.querySelector('[data-roles-table]');
        if (!root) return;

        if (state.loading) {
            root.innerHTML = UI.loadingState();
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
            const esBorrado = !!r.deleted_at;
            const esBuiltIn = r.tipo === 'BUILT_IN';
            const permisos = Array.isArray(r.permisos) ? r.permisos.length : 0;
            const acciones = [];

            if (esBorrado) {
                if (canDelete()) acciones.push(actionBtn('open-restore', r.id, t('roles.actions.restore')));
            } else {
                if (canView()) acciones.push(actionBtn('open-permissions', r.id, t('roles.actions.permissions')));
                if (canEdit() && !esBuiltIn) acciones.push(actionBtn('open-edit', r.id, t('roles.actions.edit')));
                if (canDelete() && !esBuiltIn) acciones.push(actionBtn('open-delete', r.id, t('roles.actions.delete'), 'btn-danger'));
            }

            return `
                <tr>
                    <td>
                        <div class="font-medium">${UI.escapeHtml(r.nombre || '')}</div>
                        ${r.descripcion ? `<div class="text-xs text-muted">${UI.escapeHtml(r.descripcion)}</div>` : ''}
                    </td>
                    <td>${typeBadge(r.tipo)}</td>
                    <td>${esBorrado ? estadoBorradoBadge() : estadoBadge(!!r.activo)}</td>
                    <td class="text-right">${UI.formatNumber(permisos)}</td>
                    <td class="table-actions col-actions">${acciones.join('')}</td>
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

    function actionBtn(action, id, label, extraClass) {
        const clase = 'btn btn-ghost btn-sm' + (extraClass ? ' ' + extraClass : '');
        return `<button class="${clase}" data-action="${action}" data-id="${UI.escapeHtml(id)}">${UI.escapeHtml(label)}</button>`;
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
            if (state.includeDeleted) params.include_deleted = 'true';
            const response = await global.HttpClient.get('/roles', { params });
            const payload = response && response.data ? response.data : response;
            state.items = (payload && payload.roles) || [];
        } catch (err) {
            console.error('[Roles] load error:', err);
            UI.toast('danger', t('ui.error_generic'), err.message || t('roles.errors.load'));
            state.items = [];
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
            state.grouped = groupCatalog(state.catalog);
        } catch (err) {
            console.error('[Roles] catalog error:', err);
            UI.toast('danger', t('ui.error_generic'), err.message || t('roles.errors.catalog'));
            state.catalog = [];
            state.grouped = {};
        }
        return state.catalog;
    }

    function groupCatalog(permisos) {
        const grouped = {};
        permisos.forEach(p => {
            const modulo = p.modulo || 'otros';
            const recurso = p.recurso || 'general';
            if (!grouped[modulo]) grouped[modulo] = {};
            if (!grouped[modulo][recurso]) grouped[modulo][recurso] = [];
            grouped[modulo][recurso].push(p);
        });
        return grouped;
    }

    // ====================================================================
    // MATRIZ DE PERMISOS
    // ====================================================================

    function checkboxPermisos(p, marcados, soloLectura) {
        const checked = marcados.includes(p.identificador) ? ' checked' : '';
        const disabled = soloLectura ? ' disabled' : '';
        const mfa = p.requiere_mfa
            ? ` <span class="badge badge-warning">${t('roles.perms.mfa')}</span>`
            : '';
        return `
            <label class="flex items-start gap-2 text-sm py-0.5">
                <input type="checkbox" data-perm-check value="${UI.escapeHtml(p.identificador)}"${checked}${disabled}>
                <span class="flex-1">
                    <span class="font-mono text-xs">${UI.escapeHtml(p.identificador)}</span>${mfa}
                    <div class="text-xs text-muted">${UI.escapeHtml(p.nombre || '')} · ${criticidadBadge(p.criticidad)}</div>
                </span>
            </label>`;
    }

    function renderMatrix(marcados, soloLectura) {
        const modulos = Object.keys(state.grouped).sort();
        if (modulos.length === 0) {
            return `<p class="text-muted text-sm">${t('roles.perms.empty')}</p>`;
        }

        return `
            ${soloLectura ? `<div class="alert alert-info mb-3">${t('roles.perms.readonly')}</div>` : ''}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4" data-perm-matrix>
                ${modulos.map(modulo => {
                    const recursos = Object.keys(state.grouped[modulo]).sort();
                    return `
                        <div class="card p-3">
                            <label class="flex items-center gap-2 font-semibold text-sm mb-2">
                                <input type="checkbox" data-perm-all data-modulo="${UI.escapeHtml(modulo)}"${soloLectura ? ' disabled' : ''}>
                                ${UI.escapeHtml(modulo)}
                            </label>
                            ${recursos.map(recurso => `
                                <div class="mb-2">
                                    <div class="text-xs uppercase text-muted mb-1">${UI.escapeHtml(recurso)}</div>
                                    ${state.grouped[modulo][recurso].map(p => checkboxPermisos(p, marcados, soloLectura)).join('')}
                                </div>
                            `).join('')}
                        </div>`;
                }).join('')}
            </div>`;
    }

    function leerMatriz() {
        const root = getModalRoot();
        if (!root) return [];
        const valores = [];
        root.querySelectorAll('[data-perm-check]:checked').forEach(el => {
            if (el.value) valores.push(el.value);
        });
        return valores;
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

    // ---------------------------------------------------------------- Alta

    function openCreate() {
        if (!canCreate()) return;
        state.modal = { mode: 'create', errors: {}, formError: '', loading: state.grouped && Object.keys(state.grouped).length === 0 };
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
            ${UI.grid(`
                ${UI.field({ label: t('roles.fields.name'), name: 'nombre', type: 'text', required: true, maxlength: 100 })}
                ${UI.field({ label: t('roles.fields.descripcion'), name: 'descripcion', type: 'textarea', rows: 2 })}
            `, 1)}
            <div class="form-label">${t('roles.perms.title')}</div>
            ${renderMatrix([], false)}
        `;
    }

    async function submitCreate() {
        if (state.sending) return;
        const root = getModalRoot();
        UI.clearErrors(root);

        const nombre = fieldValue(root, 'nombre');
        const descripcion = fieldValue(root, 'descripcion');
        const permisos = leerMatriz();

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
            UI.toast('success', t('roles.create.success_title'), t('roles.create.success_body', { nombre: UI.escapeHtml(nombre) }));
            closeModal();
            loadRoles();
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
        state.modal = { mode: 'edit', id, role, errors: {}, formError: '' };
        renderModal();
    }

    function editBody(modal) {
        const r = modal.role;
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            ${UI.grid(`
                ${UI.field({ label: t('roles.fields.name'), name: 'nombre', type: 'text', value: r.nombre || '', required: true, maxlength: 100 })}
                ${UI.field({ label: t('roles.fields.descripcion'), name: 'descripcion', type: 'textarea', rows: 2, value: r.descripcion || '' })}
            `, 1)}
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="activo" data-modal-field="activo"${r.activo ? ' checked' : ''}>
                ${t('roles.fields.activo')}
            </label>
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
            UI.toast('success', t('roles.edit.success_title'), t('roles.edit.success_body', { nombre: UI.escapeHtml(nombre) }));
            closeModal();
            loadRoles();
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
            permisos: Array.isArray(role.permisos) ? role.permisos.slice() : null,
            errors: {},
            formError: ''
        };
        renderModal();

        if (state.grouped && Object.keys(state.grouped).length === 0) {
            loadCatalog().then(() => {
                if (state.modal && state.modal.mode === 'permissions') renderModal();
            });
        }

        loadRolePermissions(id);
    }

    async function loadRolePermissions(id) {
        const modal = state.modal;
        if (!modal || modal.mode !== 'permissions') return;

        try {
            const response = await global.HttpClient.get('/roles/' + encodeURIComponent(id) + '/permissions');
            const payload = response && response.data ? response.data : response;
            if (state.modal === modal) {
                modal.permisos = (payload && payload.permisos) || [];
                renderModal();
            }
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('roles.perms.load_error'),
                tag: 'Roles'
            });
        }
    }

    function permissionsBody(modal) {
        if (modal.permisos === null) return UI.loadingState();

        const soloLectura = modal.role.tipo === 'BUILT_IN' || !canEdit();
        const editable = !soloLectura;

        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <div class="flex items-center gap-2 mb-3">
                <span class="font-medium">${UI.escapeHtml(modal.role.nombre || '')}</span>
                ${typeBadge(modal.role.tipo)}
                ${estadoBadge(!!modal.role.activo)}
                ${editable ? `<button type="button" class="btn btn-ghost btn-sm ml-auto" data-action="perm-all-toggle">${t('roles.perms.select_all')}</button>` : ''}
            </div>
            ${renderMatrix(modal.permisos, !editable)}
        `;
    }

    async function submitPermissions() {
        if (state.sending) return;
        const modal = state.modal;
        if (!modal || modal.mode !== 'permissions') return;

        const permisos = leerMatriz();
        setSending(true);

        try {
            await global.HttpClient.patch('/roles/' + encodeURIComponent(modal.id) + '/permissions', { permisos });
            UI.toast('success', t('roles.perms.title'), t('roles.perms.saved', { count: UI.formatNumber(permisos.length) }));
            closeModal();
            loadRoles();
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

    function toggleModulo(modulo) {
        const root = getModalRoot();
        if (!root) return;
        const master = root.querySelector(`[data-perm-all][data-modulo="${modulo}"]`);
        const marcado = master ? master.checked : false;
        root.querySelectorAll('[data-perm-check]').forEach(el => {
            const partes = (el.value || '').split('.');
            if (partes[0] === modulo) el.checked = marcado;
        });
    }

    function toggleAll() {
        const root = getModalRoot();
        if (!root) return;
        const todos = [...root.querySelectorAll('[data-perm-check]')];
        const marcar = todos.some(el => !el.checked);
        todos.forEach(el => { el.checked = marcar; });
        root.querySelectorAll('[data-perm-all]').forEach(el => { el.checked = marcar; });
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
            UI.toast('success', t('roles.delete.success_title'), t('roles.delete.success_body'));
            closeModal();
            loadRoles();
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

    // ----------------------------------------------------------- Restauración

    function openRestore(id) {
        if (!canDelete()) return;
        const role = state.items.find(r => r.id === id);
        if (!role || !role.deleted_at) return;
        state.modal = { mode: 'restore', id, role, errors: {}, formError: '' };
        renderModal();
    }

    function restoreBody(modal) {
        return `
            <div class="form-error hidden mb-3" data-form-error></div>
            <p>${t('roles.restore.body', { nombre: UI.escapeHtml(modal.role.nombre || '') })}</p>
            <div class="alert alert-info mt-3">${t('roles.restore.hint')}</div>
        `;
    }

    async function submitRestore() {
        if (state.sending) return;
        const modal = state.modal;
        setSending(true);

        try {
            await global.HttpClient.post('/roles/' + encodeURIComponent(modal.id) + '/restore');
            UI.toast('success', t('roles.restore.success_title'), t('roles.restore.success_body'));
            closeModal();
            loadRoles();
        } catch (err) {
            UI.handleActionError(err, {
                fallbackMessage: t('roles.errors.restore'),
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

        switch (modal.mode) {
            case 'create':
                title = t('roles.create.title');
                body = createBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-create" data-modal-send${state.sending ? ' disabled' : ''}>${t('roles.create.submit')}</button>`;
                break;
            case 'edit':
                title = t('roles.edit.title');
                size = 'lg';
                body = editBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-edit" data-modal-send${state.sending ? ' disabled' : ''}>${t('roles.edit.submit')}</button>`;
                break;
            case 'permissions':
                title = t('roles.perms.title');
                body = permissionsBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    ${(modal.role.tipo !== 'BUILT_IN' && canEdit())
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
            case 'restore':
                title = t('roles.restore.title');
                size = 'sm';
                body = restoreBody(modal);
                footer = `
                    <button class="btn btn-secondary" data-action="close-modal">${t('ui.close')}</button>
                    <button class="btn btn-primary" data-action="submit-restore" data-modal-send${state.sending ? ' disabled' : ''}>${t('roles.restore.submit')}</button>`;
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
            case 'open-restore':
                openRestore(id);
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
            case 'submit-restore':
                submitRestore();
                break;
            case 'perm-all':
                break;
            case 'perm-all-toggle':
                toggleAll();
                break;
        }
    }

    function handleChange(e) {
        const el = e.target;

        if (el.matches('[data-role-filter="inactive"]')) {
            state.includeInactive = !!el.checked;
            loadRoles();
            return;
        }

        if (el.matches('[data-role-filter="deleted"]')) {
            state.includeDeleted = !!el.checked;
            loadRoles();
            return;
        }

        if (el.matches('[data-perm-all]')) {
            toggleModulo(el.dataset.modulo);
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
                includeDeleted: false,
                catalog: [],
                grouped: {},
                modal: null,
                sending: false
            };
        }
    };

    global.RolesRolesModule = RolesRolesModule;

})(window);
