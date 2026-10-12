/**
 * UI Components - Selector de permisos maestro-detalle (v1.13.25)
 *
 * UI.permissionPicker: panel para elegir los permisos de un rol con patrón
 * maestro-detalle: a la izquierda la lista de modulos con contador
 * seleccionados/total; a la derecha los permisos del modulo activo,
 * agrupados por recurso, con checkbox, codigo, descripcion y badge de riesgo.
 *
 * Uso (dos nodos, para poder colocar el toolbar en la cabecera del modal):
 *
 *   UI.permissionPicker.mount({
 *     toolbar: nodoOpcional,   // fila cabecera: Basar en + Solo lectura + Limpiar
 *     panel: nodo,             // barra + maestro-detalle (hace scroll interno)
 *     catalog: [...],          // GET /permissions
 *     selected: [...],         // codigos marcados
 *     readonly: false,         // true = ver rol (todo deshabilitado)
 *     granted: [...] | null,   // permisos del actor; null = sin escalada
 *     baseRoles: [{id,nombre}] | null,  // opciones "Basar en" (null = sin toolbar)
 *     onBaseRole: async id => codigos,
 *     onChange: codes => {}    // cambia la seleccion
 *   });
 *   UI.permissionPicker.getSelected(panel);
 *   UI.permissionPicker.setSelected(panel, codes);
 *
 * Reglas del diseno:
 *  - Escalada: los permisos que el actor no posee quedan deshabilitados con
 *    tooltip (si `granted` es null no se bloquea nada).
 *  - Al marcar crear/editar/eliminar de un recurso se marca tambien su
 *    .view (si existe y es otorgable) con un aviso breve (solo UI).
 *  - El buscador filtra por codigo y descripcion en todos los modulos; el
 *    toggle "solo seleccionados" y el filtro de riesgo afectan al panel.
 *  - Los headers de modulo y recurso tienen estado indeterminado si la
 *    seleccion es parcial.
 *  - Solo los paneles (modulos y detalle) hacen scroll; la pagina no.
 *  - Accesibilidad: aria en grupos, foco en el buscador al montar,
 *    checkboxes nativos (navegacion por teclado).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    // Acciones que arrastran la .view del mismo recurso (solo UI).
    const AUTO_VIEW_ACCIONS = ['create', 'edit', 'delete'];

    function esc(value) {
        return UI.escapeHtml(String(value == null ? '' : value));
    }

    function tr(key, params) {
        return typeof t === 'function' ? t(key, params) : key;
    }

    function riskBadge(valor) {
        const color = valor === 'CRITICAL' ? 'danger'
            : valor === 'HIGH' ? 'warning'
            : valor === 'MEDIUM' ? 'info'
            : 'neutral';
        return `<span class="badge badge-${color}">${esc(valor || '—')}</span>`;
    }

    function canGrant(state, code) {
        return !state.granted || state.granted.has(code);
    }

    function isSelected(state, code) {
        return state.selected.has(code);
    }

    function countSelected(codes, state) {
        let n = 0;
        codes.forEach(c => { if (state.selected.has(c)) n++; });
        return n;
    }

    function codesOf(state, modulo, recurso) {
        const out = [];
        const grupos = state.grouped[modulo] || {};
        const recursos = recurso ? [recurso] : Object.keys(grupos);
        recursos.forEach(r => (grupos[r] || []).forEach(p => out.push(p.identificador)));
        return out;
    }

    function matches(state, p) {
        if (state.risk !== 'ALL' && p.criticidad !== state.risk) return false;
        if (state.onlySelected && !state.selected.has(p.identificador)) return false;
        if (state.query) {
            const q = state.query;
            const hay = (p.identificador + ' ' + (p.descripcion || p.nombre || '') + ' ' + (p.recurso || '')).toLowerCase();
            if (hay.indexOf(q) === -1) return false;
        }
        return true;
    }

    function visibleModules(state) {
        if (!state.query && !state.onlySelected && state.risk === 'ALL') return state.modules.slice();
        return state.modules.filter(modulo => {
            const grupos = state.grouped[modulo] || {};
            return Object.keys(grupos).some(r => grupos[r].some(p => matches(state, p)));
        });
    }

    // ====================================================================
    // RENDER
    // ====================================================================

    function toolbarHtml(state) {
        const opciones = (state.baseRoles || []).map(r =>
            `<option value="${esc(r.id)}">${esc(r.nombre)}</option>`).join('');
        return `
            <label class="pp-base text-sm">
                <span class="form-label">${esc(tr('ui.picker.base'))}</span>
                <select class="select" data-pp-base aria-label="${esc(tr('ui.picker.base'))}">
                    <option value="">${esc(tr('ui.picker.base_none'))}</option>
                    ${opciones}
                </select>
            </label>
            <button type="button" class="btn btn-ghost btn-sm" data-pp-readonly-action>${esc(tr('ui.picker.readonly_action'))}</button>
            <button type="button" class="btn btn-ghost btn-sm" data-pp-clear>${esc(tr('ui.picker.clear'))}</button>`;
    }

    function barHtml() {
        return `
            <div class="pp-bar">
                <input type="search" class="input" data-pp-search autocomplete="off"
                    placeholder="${esc(tr('ui.picker.search_placeholder'))}"
                    aria-label="${esc(tr('ui.picker.search_placeholder'))}">
                <label class="pp-toggle text-sm">
                    <input type="checkbox" data-pp-only>
                    ${esc(tr('ui.picker.only_selected'))}
                </label>
                <select class="select pp-risk" data-pp-risk aria-label="${esc(tr('ui.picker.risk'))}">
                    <option value="ALL">${esc(tr('ui.picker.risk_ALL'))}</option>
                    <option value="CRITICAL">${esc(tr('ui.picker.risk_CRITICAL'))}</option>
                    <option value="HIGH">${esc(tr('ui.picker.risk_HIGH'))}</option>
                    <option value="MEDIUM">${esc(tr('ui.picker.risk_MEDIUM'))}</option>
                    <option value="LOW">${esc(tr('ui.picker.risk_LOW'))}</option>
                </select>
            </div>
            <div class="pp-note hidden" data-pp-note role="status" aria-live="polite"></div>`;
    }

    function modulesHtml(state) {
        const visibles = visibleModules(state);
        const items = visibles.map(modulo => {
            const codes = codesOf(state, modulo);
            const sel = countSelected(codes, state);
            const total = codes.length;
            const otorgables = codes.filter(c => canGrant(state, c));
            const all = sel === total && total > 0;
            const some = sel > 0 && !all;
            const disabled = state.readonly || otorgables.length === 0;
            return `
                <div class="pp-module${state.active === modulo ? ' is-active' : ''}" data-pp-module="${esc(modulo)}">
                    <input type="checkbox" data-pp-modulo-all data-modulo="${esc(modulo)}"
                        ${all ? 'checked' : ''} ${disabled ? 'disabled' : ''}
                        aria-label="${esc(modulo)}: ${esc(tr('ui.picker.select_module_all'))}">
                    <span class="pp-module-name font-mono">${esc(modulo)}</span>
                    <span class="pp-module-count" data-pp-mod-count="${esc(modulo)}">${sel}/${total}</span>
                </div>`;
        });

        const sin = visibles.length === 0
            ? `<p class="pp-empty text-sm">${esc(tr('ui.picker.no_results'))}</p>` : '';

        const opciones = state.modules.map(modulo =>
            `<option value="${esc(modulo)}"${state.active === modulo ? ' selected' : ''}>${esc(modulo)}</option>`).join('');

        return `
            <div class="pp-modules" data-pp-modules role="group" aria-label="${esc(tr('ui.picker.modules'))}">
                ${items.join('')}
                ${sin}
            </div>
            <div class="pp-mobile">
                <select class="select" data-pp-modules-select aria-label="${esc(tr('ui.picker.module_select'))}">
                    ${opciones}
                </select>
            </div>`;
    }

    function panelHtml(state) {
        if (state.modules.length === 0) {
            return `<div class="pp-panel" data-pp-panel role="group" aria-label="${esc(tr('ui.picker.panel'))}">
                <p class="pp-empty text-sm">${esc(tr('ui.picker.empty'))}</p></div>`;
        }
        if (!state.active) {
            return `<div class="pp-panel" data-pp-panel role="group" aria-label="${esc(tr('ui.picker.panel'))}">
                <p class="pp-empty text-sm">${esc(tr('ui.picker.no_results'))}</p></div>`;
        }

        const grupos = state.grouped[state.active] || {};
        const recursos = Object.keys(grupos).sort();
        let html = '';

        recursos.forEach(recurso => {
            const items = grupos[recurso].filter(p => matches(state, p));
            if (items.length === 0) return;

            const codes = codesOf(state, state.active, recurso);
            const sel = countSelected(codes, state);
            const total = codes.length;
            const otorgables = codes.filter(c => canGrant(state, c));
            const all = sel === total && total > 0;
            const some = sel > 0 && !all;
            const disabled = state.readonly || otorgables.length === 0;

            const filas = items.map(p => {
                const code = p.identificador;
                const bloqueado = state.readonly || !canGrant(state, code);
                const tip = (!state.readonly && !canGrant(state, code)) ? ` title="${esc(tr('ui.picker.escalation'))}"` : '';
                const mfa = p.requiere_mfa
                    ? ` <span class="badge badge-warning">${esc(tr('roles.perms.mfa'))}</span>` : '';
                const desc = p.descripcion || p.nombre || '';
                return `
                    <label class="pp-perm"${tip}>
                        <input type="checkbox" data-perm-check value="${esc(code)}"
                            ${state.selected.has(code) ? 'checked' : ''} ${bloqueado ? 'disabled' : ''}>
                        <span class="pp-perm-code font-mono">${esc(code)}</span>
                        <span class="pp-perm-desc" title="${esc(desc)}">${esc(desc)}${mfa}</span>
                        <span class="pp-perm-risk">${riskBadge(p.criticidad)}</span>
                    </label>`;
            }).join('');

            html += `
                <div class="pp-recurso" data-pp-recurso="${esc(recurso)}">
                    <div class="pp-recurso-head">
                        <label class="pp-recurso-check">
                            <input type="checkbox" data-pp-recurso-all
                                data-modulo="${esc(state.active)}" data-recurso="${esc(recurso)}"
                                ${all ? 'checked' : ''} ${disabled ? 'disabled' : ''}
                                aria-label="${esc(state.active)}/${esc(recurso)}: ${esc(tr('ui.picker.select_module_all'))}">
                            <span class="pp-recurso-name">${esc(recurso)}</span>
                        </label>
                        <span class="pp-recurso-count" data-pp-rec-count="${esc(state.active)}.${esc(recurso)}">${sel}/${total}</span>
                    </div>
                    ${filas}
                </div>`;
        });

        if (!html) {
            html = `<p class="pp-empty text-sm">${esc(tr('ui.picker.no_results'))}</p>`;
        }
        return `<div class="pp-panel" data-pp-panel role="group" aria-label="${esc(tr('ui.picker.panel'))}">${html}</div>`;
    }

    // ====================================================================
    // REFRESCOS PARCIALES
    // ====================================================================

    function applyIndeterminate(state) {
        const panel = state.el.panel;
        panel.querySelectorAll('[data-pp-modulo-all]').forEach(el => {
            const codes = codesOf(state, el.dataset.modulo);
            const sel = countSelected(codes, state);
            el.checked = sel === codes.length && codes.length > 0;
            el.indeterminate = sel > 0 && !el.checked;
        });
        panel.querySelectorAll('[data-pp-recurso-all]').forEach(el => {
            const codes = codesOf(state, el.dataset.modulo, el.dataset.recurso);
            const sel = countSelected(codes, state);
            el.checked = sel === codes.length && codes.length > 0;
            el.indeterminate = sel > 0 && !el.checked;
        });
    }

    function refreshCounts(state) {
        state.modules.forEach(modulo => {
            const codes = codesOf(state, modulo);
            const sel = countSelected(codes, state);
            const el = state.el.panel.querySelector(`[data-pp-mod-count="${modulo}"]`);
            if (el) el.textContent = `${sel}/${codes.length}`;
        });
        const activo = state.active;
        if (activo) {
            Object.keys(state.grouped[activo] || {}).forEach(recurso => {
                const codes = codesOf(state, activo, recurso);
                const sel = countSelected(codes, state);
                const el = state.el.panel.querySelector(`[data-pp-rec-count="${activo}.${recurso}"]`);
                if (el) el.textContent = `${sel}/${codes.length}`;
            });
        }
        applyIndeterminate(state);
    }

    function renderAll(state) {
        const body = state.el.panel.querySelector('[data-pp-body]');
        if (body) body.innerHTML = modulesHtml(state) + panelHtml(state);
        applyIndeterminate(state);
    }

    function note(state, message) {
        const el = state.el.panel.querySelector('[data-pp-note]');
        if (!el) return;
        if (state.noteTimer) clearTimeout(state.noteTimer);
        el.textContent = message;
        el.classList.remove('hidden');
        state.noteTimer = setTimeout(() => {
            el.classList.add('hidden');
            el.textContent = '';
        }, 6000);
    }

    function notify(state) {
        if (typeof state.onChange === 'function') {
            state.onChange(Array.from(state.selected));
        }
    }

    // ====================================================================
    // ACCIONES
    // ====================================================================

    function toggleCheck(state, code, on) {
        if (on) state.selected.add(code);
        else state.selected.delete(code);

        // Escalada .view al marcar crear/editar/eliminar (solo UI).
        if (on && !state.readonly) {
            const p = state.byCode.get(code);
            if (p && AUTO_VIEW_ACCIONS.indexOf(p.accion) !== -1) {
                const viewCode = p.modulo + '.' + p.recurso + '.view';
                if (state.byCode.has(viewCode) && !state.selected.has(viewCode) && canGrant(state, viewCode)) {
                    state.selected.add(viewCode);
                    const viewEl = state.el.panel.querySelector(`input[data-perm-check][value="${viewCode}"]`);
                    if (viewEl) viewEl.checked = true;
                    note(state, tr('ui.picker.note_view', { code: viewCode }));
                }
            }
        }
        refreshCounts(state);
        notify(state);
    }

    function selectOnlyView(state) {
        const next = new Set();
        state.catalog.forEach(p => {
            if (p.accion === 'view' && canGrant(state, p.identificador)) next.add(p.identificador);
        });
        state.selected = next;
        renderAll(state);
        notify(state);
    }

    function clearAll(state) {
        state.selected = new Set();
        renderAll(state);
        notify(state);
    }

    function toggleGroup(state, codes, on) {
        codes.forEach(code => {
            if (!canGrant(state, code)) return;
            if (on) state.selected.add(code);
            else state.selected.delete(code);
        });
        renderAll(state);
        notify(state);
    }

    // ====================================================================
    // EVENTOS
    // ====================================================================

    function bindPanel(state) {
        const panel = state.el.panel;

        panel.addEventListener('input', (e) => {
            const search = e.target.closest('[data-pp-search]');
            if (!search) return;
            state.query = String(search.value || '').trim().toLowerCase();
            const visibles = visibleModules(state);
            if (visibles.length > 0 && visibles.indexOf(state.active) === -1) state.active = visibles[0];
            if (visibles.length === 0) state.active = null;
            renderAll(state);
        });

        panel.addEventListener('change', (e) => {
            const el = e.target;

            if (el.matches('[data-pp-only]')) {
                state.onlySelected = !!el.checked;
                const visibles = visibleModules(state);
                if (visibles.length > 0 && visibles.indexOf(state.active) === -1) state.active = visibles[0];
                if (visibles.length === 0) state.active = null;
                renderAll(state);
                return;
            }

            if (el.matches('[data-pp-risk]')) {
                state.risk = el.value || 'ALL';
                const visibles = visibleModules(state);
                if (visibles.length > 0 && visibles.indexOf(state.active) === -1) state.active = visibles[0];
                if (visibles.length === 0) state.active = null;
                renderAll(state);
                return;
            }

            if (el.matches('[data-pp-modules-select]')) {
                state.active = el.value || null;
                renderAll(state);
                return;
            }

            if (el.matches('[data-pp-modulo-all]')) {
                toggleGroup(state, codesOf(state, el.dataset.modulo), el.checked);
                return;
            }

            if (el.matches('[data-pp-recurso-all]')) {
                toggleGroup(state, codesOf(state, el.dataset.modulo, el.dataset.recurso), el.checked);
                return;
            }

            if (el.matches('[data-perm-check]')) {
                toggleCheck(state, el.value, el.checked);
            }
        });

        panel.addEventListener('click', (e) => {
            const mod = e.target.closest('[data-pp-module]');
            if (!mod || e.target.matches('input')) return;
            state.active = mod.dataset.ppModule;
            renderAll(state);
        });
    }

    function bindToolbar(state) {
        const bar = state.el.toolbar;
        if (!bar) return;

        bar.addEventListener('change', (e) => {
            const el = e.target;
            if (!el.matches('[data-pp-base]')) return;
            const id = el.value;
            if (!id || typeof state.onBaseRole !== 'function') return;
            Promise.resolve(state.onBaseRole(id)).then(codes => {
                const next = new Set();
                (codes || []).forEach(c => next.add(c));
                state.selected = next;
                renderAll(state);
                notify(state);
            }).catch(() => {});
        });

        bar.addEventListener('click', (e) => {
            if (e.target.closest('[data-pp-readonly-action]')) {
                selectOnlyView(state);
                return;
            }
            if (e.target.closest('[data-pp-clear]')) {
                clearAll(state);
            }
        });
    }

    // ====================================================================
    // API
    // ====================================================================

    function mount(opts) {
        const o = opts || {};
        const panel = o.panel;
        if (!panel) return null;

        const catalog = Array.isArray(o.catalog) ? o.catalog : [];
        const byCode = new Map();
        const grouped = {};
        catalog.forEach(p => {
            if (!p || !p.identificador) return;
            byCode.set(p.identificador, p);
            const modulo = p.modulo || 'otros';
            const recurso = p.recurso || 'general';
            if (!grouped[modulo]) grouped[modulo] = {};
            if (!grouped[modulo][recurso]) grouped[modulo][recurso] = [];
            grouped[modulo][recurso].push(p);
        });

        const state = {
            catalog, byCode, grouped,
            modules: Object.keys(grouped).sort(),
            active: null,
            selected: new Set(Array.isArray(o.selected) ? o.selected : []),
            readonly: !!o.readonly,
            granted: Array.isArray(o.granted) ? new Set(o.granted) : null,
            baseRoles: Array.isArray(o.baseRoles) ? o.baseRoles : null,
            onBaseRole: o.onBaseRole || null,
            onChange: o.onChange || null,
            query: '', onlySelected: false, risk: 'ALL',
            noteTimer: null,
            el: { panel, toolbar: o.toolbar || null }
        };
        state.active = state.modules[0] || null;

        if (o.toolbar) {
            o.toolbar.innerHTML = state.readonly ? '' : toolbarHtml(state);
            o.toolbar.__pp = state;
            if (!state.readonly) bindToolbar(state);
        }

        panel.innerHTML = `
            <div class="pp" data-pp>
                ${barHtml()}
                <div class="pp-body" data-pp-body></div>
            </div>`;
        panel.__pp = state;
        panel.querySelector('[data-pp-body]').innerHTML = modulesHtml(state) + panelHtml(state);
        applyIndeterminate(state);
        bindPanel(state);

        const search = panel.querySelector('[data-pp-search]');
        if (search && typeof search.focus === 'function') search.focus();

        notify(state);
        return state;
    }

    function stateOf(panel) {
        return (panel && panel.__pp) || null;
    }

    function getSelected(panel) {
        const state = stateOf(panel);
        return state ? Array.from(state.selected) : [];
    }

    function setSelected(panel, codes) {
        const state = stateOf(panel);
        if (!state) return;
        state.selected = new Set(Array.isArray(codes) ? codes : []);
        renderAll(state);
        notify(state);
    }

    global.UI = Object.assign(global.UI || {}, {
        permissionPicker: {
            mount: mount,
            getSelected: getSelected,
            setSelected: setSelected
        }
    });

})(window);
