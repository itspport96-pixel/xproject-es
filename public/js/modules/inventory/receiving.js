/**
 * Inventory Receiving Module
 * Recepción de inventario (CU-001) contra POST /api/inventory/receive.
 *
 * Convención ModuleLoader: window.InventoryReceivingModule
 *
 * Idempotency-Key (spec R7):
 *  - Se genera al abrir el formulario y se REUTILIZA en cada reintento del
 *    mismo envío (400/401/409/422/500 no cambian la clave).
 *  - Se regenera sólo tras un 201 y cuando el servidor responde 409
 *    (clave ya usada con una carga útil distinta).
 *
 * v1.10.0 (E1):
 *  - "Rechazo total" envía rechazo_total=1: el backend no crea unidad ni
 *    ledger, marca discrepancia 100 % y abre una incidencia
 *    ENTREGA_RECHAZADA con prioridad ALTA.
 *  - El resultado muestra discrepancia_porcentaje y la prioridad de la
 *    incidencia cuando la recepción genera incidencia (parcial o total).
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    let container = null;

    let state = {
        products: [],
        locations: [],
        idempotencyKey: '',
        sending: false,
        loadingCatalog: false,
        result: null
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

    const canView = () => can('inventory.receiving.create', ['ADMIN', 'MANAGER', 'USER']);

    const DENIED_TEMPLATE = `
        <div class="card max-w-md mx-auto mt-8">
            <div class="card-header">
                <h3 class="card-title">${t('inventory.receiving.permission.title')}</h3>
            </div>
            <div class="card-body">
                <p>${t('inventory.receiving.permission.body', { permiso: 'inventory.receiving.create' })}</p>
                <div class="mt-4">
                    <a href="#/dashboard" class="btn btn-secondary">${t('router.go_dashboard')}</a>
                </div>
            </div>
        </div>`;

    function buildTemplate() {
        return `
        <div class="max-w-7xl mx-auto">
            ${UI.pageHeader({
                title: t('inventory.receiving.title'),
                subtitle: t('inventory.receiving.subtitle')
            })}

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">${t('inventory.receiving.card_title')}</h3>
                        </div>
                        <div class="card-body">
                            <form data-receive-form novalidate>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="form-group md:col-span-2">
                                        <label class="form-label">${t('inventory.receiving.labels.producto')} <span class="required">*</span></label>
                                        <select class="select" name="sku" required>
                                            <option value="">${t('inventory.receiving.selects.producto')}</option>
                                        </select>
                                        <span class="form-hint">${t('inventory.receiving.hints.producto')}</span>
                                        <span class="form-error hidden" data-error="sku"></span>
                                    </div>
                                    <div class="form-group md:col-span-2">
                                        <label class="form-label">${t('inventory.receiving.labels.ubicacion_destino')} <span class="required">*</span></label>
                                        <select class="select" name="ubicacion_id" required>
                                            <option value="">${t('inventory.receiving.selects.ubicacion')}</option>
                                        </select>
                                        <span class="form-hint">${t('inventory.receiving.hints.ubicacion')}</span>
                                        <span class="form-error hidden" data-error="ubicacion_id"></span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">${t('inventory.receiving.labels.cantidad_esperada')} <span class="required">*</span></label>
                                        <input type="number" class="input" name="cantidad_esperada" min="0.01" step="any"
                                               inputmode="decimal" placeholder="0" required>
                                        <span class="form-error hidden" data-error="cantidad_esperada"></span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">${t('inventory.receiving.labels.cantidad_recibida')}</label>
                                        <input type="number" class="input" name="cantidad_recibida" min="0.01" step="any"
                                               inputmode="decimal" placeholder="${t('inventory.receiving.placeholders.cantidad_recibida')}">
                                        <span class="form-hint">${t('inventory.receiving.hints.cantidad_recibida')}</span>
                                        <span class="form-error hidden" data-error="cantidad_recibida"></span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">${t('inventory.receiving.labels.lote')}</label>
                                        <input type="text" class="input" name="lote" maxlength="255" placeholder="${t('inventory.receiving.placeholders.opcional')}">
                                        <span class="form-error hidden" data-error="lote"></span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">${t('inventory.receiving.labels.numero_serie')}</label>
                                        <input type="text" class="input" name="numero_serie" maxlength="255" placeholder="${t('inventory.receiving.placeholders.opcional')}">
                                        <span class="form-error hidden" data-error="numero_serie"></span>
                                    </div>
                                    <div class="form-group md:col-span-2">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" name="rechazo_total" value="1" data-rechazo-total>
                                            <span class="form-label mb-0">${t('inventory.receiving.labels.rechazo_total')}</span>
                                        </label>
                                        <span class="form-hint">${t('inventory.receiving.hints.rechazo_total')}</span>
                                    </div>
                                </div>

                                <div data-form-error class="alert alert-danger hidden mt-4"></div>

                                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mt-4">
                                    <div class="text-xs text-muted" data-key-hint></div>
                                    <button type="submit" class="btn btn-primary" data-action="submit-receive">
                                        ${t('inventory.receiving.buttons.submit')}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div data-result-root></div>
            </div>
        </div>
    `;
    }

    // ====================================================================
    // IDEMPOTENCY KEY
    // ====================================================================

    function fallbackKey() {
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let out = '';
        for (let i = 0; i < 32; i++) {
            out += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
        }
        return out;
    }

    function newIdempotencyKey() {
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return global.crypto.randomUUID();
        }
        return fallbackKey();
    }

    function renderKeyHint() {
        const root = container && container.querySelector('[data-key-hint]');
        if (!root) return;
        root.innerHTML = `Idempotency-Key: <span class="font-mono">${escapeHtml(state.idempotencyKey)}</span>`;
    }

    // ====================================================================
    // RENDER
    // ====================================================================

    function renderCatalogs() {
        if (!container) return;

        const productSelect = container.querySelector('select[name="sku"]');
        const locationSelect = container.querySelector('select[name="ubicacion_id"]');

        if (productSelect) {
            const selected = productSelect.value;
            productSelect.innerHTML = `<option value="">${t('inventory.receiving.selects.producto')}</option>` +
                state.products.map(p => `
                    <option value="${escapeHtml(p.sku)}" ${p.sku === selected ? 'selected' : ''}>
                        ${escapeHtml(p.sku)} - ${escapeHtml(p.nombre)}
                    </option>`).join('');
        }

        if (locationSelect) {
            const selected = locationSelect.value;
            locationSelect.innerHTML = `<option value="">${t('inventory.receiving.selects.ubicacion')}</option>` +
                state.locations.map(l => {
                    const capacidad = (l.capacidad === null || l.capacidad === undefined)
                        ? t('inventory.receiving.options.sin_limite')
                        : `${l.capacidad_usada || 0}/${l.capacidad}`;
                    const sinEspacio = l.capacidad !== null && l.capacidad !== undefined
                        && (l.capacidad_usada || 0) >= l.capacidad;
                    return `
                        <option value="${escapeHtml(l.id)}" ${l.id === selected ? 'selected' : ''} ${sinEspacio ? 'disabled' : ''}>
                            ${escapeHtml(l.codigo)} (${escapeHtml(capacidad)})${sinEspacio ? t('inventory.receiving.options.sin_espacio') : ''}
                        </option>`;
                }).join('');
        }
    }

    function renderResult() {
        const root = container && container.querySelector('[data-result-root]');
        if (!root) return;

        if (!state.result) {
            root.innerHTML = `
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">${t('inventory.receiving.result.title')}</h3>
                    </div>
                    <div class="card-body">
                        <div class="empty-state">
                            <div class="empty-state-title">${t('inventory.receiving.result.empty_title')}</div>
                            <p class="text-muted text-sm">${t('inventory.receiving.result.empty_hint')}</p>
                        </div>
                    </div>
                </div>`;
            return;
        }

        const r = state.result;
        const parcial = !!r.recepcion_parcial;
        const rechazo = !!r.rechazo_total;
        const incidencia = r.incidencia || null;
        const prioridad = incidencia ? incidencia.prioridad : null;

        const estadoBadge = rechazo
            ? `<span class="badge badge-danger">${t('inventory.receiving.states.rechazada')}</span>`
            : (parcial
                ? `<span class="badge badge-warning">${t('inventory.receiving.states.parcial')}</span>`
                : `<span class="badge badge-success">${t('inventory.receiving.states.completa')}</span>`);

        const ubicacionRow = r.ubicacion_codigo ? `
                            <tr>
                                <td class="text-muted text-xs">${t('inventory.receiving.columns.ubicacion')}</td>
                                <td class="font-mono text-xs">${escapeHtml(r.ubicacion_codigo)}</td>
                            </tr>` : '';

        const estadoRow = r.estado ? `
                            <tr>
                                <td class="text-muted text-xs">${t('inventory.receiving.columns.estado')}</td>
                                <td><span class="badge badge-success">${escapeHtml(r.estado)}</span></td>
                            </tr>` : '';

        const discrepanciaRow = (r.discrepancia_porcentaje !== null && r.discrepancia_porcentaje !== undefined
            && (parcial || rechazo)) ? `
                            <tr>
                                <td class="text-muted text-xs">${t('inventory.receiving.columns.discrepancia')}</td>
                                <td>${formatNumber(r.discrepancia_porcentaje)} %</td>
                            </tr>` : '';

        root.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">${t('inventory.receiving.result.title')}</h3>
                    ${estadoBadge}
                </div>
                <div class="card-body">
                    <div class="text-xs text-muted mb-1">${t('inventory.receiving.columns.codigo_barras')}</div>
                    <div class="font-mono text-lg font-bold mb-3">${escapeHtml(r.codigo_barcode || t('inventory.receiving.states.sin_unidad'))}</div>

                    <table class="table">
                        <tbody>
                            <tr>
                                <td class="text-muted text-xs">${t('inventory.receiving.columns.sku')}</td>
                                <td class="font-mono text-xs">${escapeHtml(r.sku || '')}</td>
                            </tr>
                            ${ubicacionRow}
                            <tr>
                                <td class="text-muted text-xs">${t('inventory.receiving.columns.esperada')}</td>
                                <td>${formatNumber(r.cantidad_esperada)}</td>
                            </tr>
                            <tr>
                                <td class="text-muted text-xs">${t('inventory.receiving.columns.recibida')}</td>
                                <td>${formatNumber(r.cantidad_recibida)}</td>
                            </tr>
                            ${discrepanciaRow}
                            ${estadoRow}
                        </tbody>
                    </table>

                    ${rechazo ? `
                        <div class="alert alert-danger mt-3">
                            <strong>${t('inventory.receiving.messages.rechazo_titulo')}</strong>
                            ${incidencia && incidencia.descripcion
                                ? escapeHtml(incidencia.descripcion)
                                : t('inventory.receiving.messages.rechazo_fallback')}
                            ${prioridad ? ` ${prioridadBadge(prioridad)}` : ''}
                        </div>` : ''}

                    ${parcial ? `
                        <div class="alert alert-warning mt-3">
                            <strong>${t('inventory.receiving.messages.parcial_titulo')}</strong>
                            ${incidencia && incidencia.descripcion
                                ? escapeHtml(incidencia.descripcion)
                                : t('inventory.receiving.messages.parcial_fallback')}
                            ${prioridad ? ` ${prioridadBadge(prioridad)}` : ''}
                        </div>` : ''}

                    <div class="text-xs text-muted mt-3">
                        ${t('inventory.receiving.messages.evento', { tipo: escapeHtml((r.evento && r.evento.tipo_evento) || '') })}
                        ${r.evento && r.evento.hash_integridad
                            ? t('inventory.receiving.messages.evento_hash', { hash: escapeHtml(String(r.evento.hash_integridad).substring(0, 12)) })
                            : ''}
                    </div>
                </div>
            </div>`;
    }

    function showFormError(message) {
        const el = container && container.querySelector('[data-form-error]');
        if (!el) return;
        if (!message) {
            el.classList.add('hidden');
            el.textContent = '';
            return;
        }
        el.textContent = message;
        el.classList.remove('hidden');
    }

    function showFieldError(field, message) {
        const el = container && container.querySelector(`[data-error="${field}"]`);
        if (!el) return;
        if (!message) {
            el.classList.add('hidden');
            el.textContent = '';
            return;
        }
        el.textContent = message;
        el.classList.remove('hidden');
        el.scrollIntoView({ block: 'nearest' });
    }

    function clearFieldErrors() {
        if (!container) return;
        container.querySelectorAll('[data-error]').forEach(el => {
            el.classList.add('hidden');
            el.textContent = '';
        });
        showFormError('');
    }

    function setSending(sending) {
        state.sending = sending;
        if (!container) return;
        const btn = container.querySelector('[data-action="submit-receive"]');
        if (!btn) return;
        btn.disabled = sending;
        btn.textContent = sending ? t('inventory.receiving.buttons.submitting') : t('inventory.receiving.buttons.submit');
    }

    // ====================================================================
    // CATALOGOS
    // ====================================================================

    async function loadCatalogs() {
        state.loadingCatalog = true;
        try {
            const [productsRes, locationsRes] = await Promise.all([
                global.HttpClient.get('/inventory/products', {
                    params: { activo: '1', page: 1, per_page: 100 }
                }),
                global.HttpClient.get('/inventory/locations', {
                    params: { activa: '1', page: 1, per_page: 100 }
                })
            ]);

            const productsPayload = productsRes.data || productsRes;
            const locationsPayload = locationsRes.data || locationsRes;

            state.products = productsPayload.data || [];
            state.locations = locationsPayload.data || [];
            renderCatalogs();
            return true;
        } catch (err) {
            console.error('[Receiving] catalog load error:', err);
            showToast('danger', t('ui.error_generic'), err.message || t('inventory.receiving.messages.catalog_error'));
            return false;
        } finally {
            state.loadingCatalog = false;
        }
    }

    // ====================================================================
    // RECEPCIÓN
    // ====================================================================

    function readForm(form) {
        const raw = Object.fromEntries(new FormData(form));

        const payload = {
            sku: (raw.sku || '').trim(),
            ubicacion_id: (raw.ubicacion_id || '').trim(),
            cantidad_esperada: (raw.cantidad_esperada || '').trim()
        };

        const recibida = (raw.cantidad_recibida || '').trim();
        if (recibida !== '' && !raw.rechazo_total) payload.cantidad_recibida = recibida;

        // v1.10.0 (E1): sólo con rechazo marcado (value=1 si está tildado).
        if (raw.rechazo_total) payload.rechazo_total = '1';

        const lote = (raw.lote || '').trim();
        if (lote !== '') payload.lote = lote;

        const numeroSerie = (raw.numero_serie || '').trim();
        if (numeroSerie !== '') payload.numero_serie = numeroSerie;

        return payload;
    }

    function validate(payload) {
        if (!payload.sku) return { field: 'sku', message: t('inventory.receiving.messages.error_producto_required') };
        if (!payload.ubicacion_id) return { field: 'ubicacion_id', message: t('inventory.receiving.messages.error_ubicacion_required') };

        const esperada = parseFloat(payload.cantidad_esperada);
        if (!isFinite(esperada) || esperada <= 0) {
            return { field: 'cantidad_esperada', message: t('inventory.receiving.messages.error_cantidad_esperada') };
        }

        if (payload.cantidad_recibida !== undefined) {
            const recibida = parseFloat(payload.cantidad_recibida);
            if (!isFinite(recibida) || recibida <= 0) {
                return { field: 'cantidad_recibida', message: t('inventory.receiving.messages.error_cantidad_recibida') };
            }
            if (recibida > esperada) {
                return { field: 'cantidad_recibida', message: t('inventory.receiving.messages.error_cantidad_recibida_max') };
            }
        }

        return null;
    }

    async function submitReceive(form) {
        if (state.sending) return;

        clearFieldErrors();

        const payload = readForm(form);
        const invalid = validate(payload);
        if (invalid) {
            showFieldError(invalid.field, invalid.message);
            return;
        }

        setSending(true);

        try {
            const response = await global.HttpClient.post('/inventory/receive', payload, {
                headers: { 'Idempotency-Key': state.idempotencyKey }
            });

            state.result = (response && response.data) ? response.data : response;

            // 201 confirmado: la clave ya no debe reutilizarse.
            state.idempotencyKey = newIdempotencyKey();
            renderKeyHint();
            renderResult();

            form.querySelector('input[name="cantidad_esperada"]').value = '';
            form.querySelector('input[name="cantidad_recibida"]').value = '';
            form.querySelector('input[name="lote"]').value = '';
            form.querySelector('input[name="numero_serie"]').value = '';

            // Formulario limpio para la pr��xima recepci��n.
            const rechazoCheck = form.querySelector('[data-rechazo-total]');
            if (rechazoCheck) {
                rechazoCheck.checked = false;
                const recibida = form.querySelector('input[name="cantidad_recibida"]');
                if (recibida) recibida.disabled = false;
            }

            // La capacidad usada de la ubicaci��n cambi��: recargar catǭlogo.
            const recargadoCatalogos = await loadCatalogs();

            if (state.result.rechazo_total) {
                showToast('warning', t('inventory.receiving.messages.toast_rechazo_titulo'),
                    state.result.mensaje || t('inventory.receiving.messages.toast_rechazo_fallback'));
            } else if (recargadoCatalogos) {
                showToast('success', t('inventory.receiving.messages.toast_registrada_titulo'),
                    t('inventory.receiving.messages.toast_registrada_mensaje', {
                        codigo: state.result.codigo_barcode || '',
                        ubicacion: state.result.ubicacion_codigo || ''
                    }));
            }
        } catch (err) {
            handleReceiveError(err);
        } finally {
            setSending(false);
        }
    }

    function handleReceiveError(err) {
        const status = err.status;
        const field = err.data && err.data.field ? err.data.field : null;
        const message = err.message || t('inventory.receiving.messages.submit_error');

        console.error('[Receiving] submit error:', status, message);

        if (status === 401) {
            showFormError(t('inventory.receiving.messages.error_401_form'));
            showToast('danger', t('ui.toast_session'), t('inventory.receiving.messages.error_401_toast'));
            return;
        }

        if (status === 409) {
            // Mismo key con payload distinto: la clave ya no sirve para este envío.
            state.idempotencyKey = newIdempotencyKey();
            renderKeyHint();
            showFormError(t('inventory.receiving.messages.error_409_form'));
            showToast('warning', t('inventory.receiving.messages.error_409_toast_titulo'),
                t('inventory.receiving.messages.error_409_toast'));
            return;
        }

        if (status === 422) {
            showFormError(message);
            if (field === 'capacidad') {
                showToast('danger', t('inventory.receiving.messages.toast_capacidad'), message);
            } else {
                showToast('danger', t('inventory.receiving.states.rechazada'), message);
            }
            if (field) showFieldError(field, message);
            return;
        }

        if (status === 404) {
            showFormError(message);
            showToast('danger', t('ui.toast_not_found'), message);
            return;
        }

        if (status === 400) {
            if (field) showFieldError(field, message);
            showFormError(message);
            showToast('danger', t('ui.toast_invalid'), message);
            return;
        }

        // Resto (408/429/500/red): la clave se conserva para el reintento.
        showFormError(message);
        showToast('danger', t('ui.error_generic'), message);
    }

    // ====================================================================
    // EVENTS
    // ====================================================================

    function bindEvents() {
        if (!container) return;

        container.removeEventListener('submit', handleSubmit);
        container.removeEventListener('change', handleChange);
        container.addEventListener('submit', handleSubmit);
        container.addEventListener('change', handleChange);
    }

    function handleChange(e) {
        if (!e.target.matches('[data-rechazo-total]')) return;

        const form = container.querySelector('[data-receive-form]');
        const recibida = form && form.querySelector('input[name="cantidad_recibida"]');
        if (!recibida) return;

        recibida.disabled = e.target.checked;
        if (e.target.checked) recibida.value = '';
    }

    function handleSubmit(e) {
        if (e.target.matches('[data-receive-form]')) {
            e.preventDefault();
            submitReceive(e.target);
        }
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

    function formatNumber(value) {
        const n = parseFloat(value);
        if (!isFinite(n)) return '0';
        return Number.isInteger(n) ? String(n) : n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    }

    // v1.10.0 (E1): prioridad de la incidencia generada (ALTA | NORMAL).
    function prioridadBadge(valor) {
        return valor === 'ALTA'
            ? `<span class="badge badge-danger">${t('inventory.receiving.prioridad.alta')}</span>`
            : `<span class="badge badge-neutral">${t('inventory.receiving.prioridad.normal')}</span>`;
    }

    function showToast(type, title, message = '') {
        if (global.EventBus) {
            global.EventBus.emit('toast:show', { type, title, message, duration: 4000 });
        }
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const InventoryReceivingModule = {
        init(params, targetContainer) {
            container = targetContainer;
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            state.idempotencyKey = newIdempotencyKey();
            container.innerHTML = buildTemplate();
            bindEvents();
            renderKeyHint();
            renderResult();
            loadCatalogs();
        },

        onActivate() {
            if (!container) return;

            if (!canView()) {
                container.innerHTML = DENIED_TEMPLATE;
                return;
            }

            if (container && !container.querySelector('[data-receive-form]')) {
                container.innerHTML = buildTemplate();
                bindEvents();
            }
            if (!state.idempotencyKey) state.idempotencyKey = newIdempotencyKey();
            renderKeyHint();
            renderResult();
            loadCatalogs();
        },

        destroy() {
            if (container) {
                container.removeEventListener('submit', handleSubmit);
                container.removeEventListener('change', handleChange);
                container.innerHTML = '';
            }
            container = null;
            state = {
                products: [],
                locations: [],
                idempotencyKey: '',
                sending: false,
                loadingCatalog: false,
                result: null
            };
        }
    };

    global.InventoryReceivingModule = InventoryReceivingModule;

})(window);
