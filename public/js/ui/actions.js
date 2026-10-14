/**
 * UI Components - Acciones (v1.9.3)
 *
 * Permisos, claves de idempotencia, manejo unificado de errores HTTP,
 * confirmación de descarga CSV autenticada y toasts. Depende de ui/dom.js
 * (toast, escapeHtml) y ui/modal.js (errores en línea).
 *
 * Nota sobre la descarga CSV: se pide SIEMPRE por fetch con el JWT del
 * HttpClient y se convierte a Blob en el cliente; nunca se abre un enlace
 * directo, que no llevaría la cabecera Authorization.
 */
(function(global) {
    'use strict';

    const UI = global.UI;

    // ====================================================================
    // PERMISOS
    // ====================================================================

    /**
     * Permiso real del backend con fallback a roles cuando la sesión aún no
     * expone permisos (mismo contrato que ya usaban los módulos).
     */
    function can(permission, fallbackRoles) {
        if (global.AuthState && typeof global.AuthState.getPermissions === 'function') {
            const permissions = global.AuthState.getPermissions();
            if (permissions.length > 0) return permissions.includes(permission);
        }
        if (global.AuthState && typeof global.AuthState.getRoles === 'function') {
            const roles = global.AuthState.getRoles();
            if (roles.length > 0) return (fallbackRoles || []).some(r => roles.includes(r));
        }
        return false;
    }

    // ====================================================================
    // IDEMPOTENCIA
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

    // ====================================================================
    // ERRORES HTTP
    // ====================================================================

    /**
     * Mensaje de error localizado (v1.13.3): mapea los códigos del backend
     * (category del envelope + status HTTP) a claves errors.* de t().
     *
     * El backend manda los mensajes en español (regla de proyecto: se
     * mantienen en español), pero la UI tiene 4 idiomas; para que el
     * detalle del toast no quede sin traducir se resuelve por category
     * (validation/not_found/conflict/forbidden/auth/password_change_required)
     * y, si no hay category, por status (400/401/403/404/409/429/5xx,
     * timeout 408 y errores de red sin status). Sólo cuando no hay código
     * ni status cae al message crudo del backend y, en último término,
     * al genérico.
     *
     * El envelope puede venir en dos formas: los errores de excepción
     * {success:false, message, data:{field, category}} (data anidada) y
     * los errores devueltos por controllers {message, field, category}
     * (en la raíz); se miran ambas.
     *
     * @param {{status?: number, message?: string, data?: object}|null} err
     * @returns {string}
     */
    function errorMessage(err) {
        if (!err) return t('ui.error_generic');

        // El backend ya devuelve el mensaje traducido (api_errors + X-Locale).
        // Si hay message especifica del API, se prioriza sobre el mapping
        // generico por category/status (que perderia el detalle).
        const apiMessage = (err.message && String(err.message).trim() !== '') ? String(err.message) : '';
        const isGenericApi = !apiMessage
            || apiMessage === 'Internal server error'
            || apiMessage === 'Internal Server Error'
            || /^HTTP \d+$/.test(apiMessage);
        if (apiMessage && !isGenericApi) return apiMessage;

        const body = (err.data && typeof err.data === 'object') ? err.data : null;
        const inner = (body && body.data && typeof body.data === 'object') ? body.data : null;
        const category = (inner && inner.category) || (body && body.category) || '';
        const status = err.status;

        switch (category) {
            case 'validation': return t('errors.validation');
            case 'not_found': return t('errors.not_found');
            case 'conflict': return t('errors.conflict');
            case 'forbidden': return t('errors.forbidden');
            case 'auth': return t('errors.auth');
            case 'password_change_required': return t('errors.password_change_required');
        }

        switch (status) {
            case 400:
            case 422: return t('errors.validation');
            case 401: return t('errors.auth');
            case 403: return t('errors.forbidden');
            case 404: return t('errors.not_found');
            case 408: return t('errors.timeout');
            case 409: return t('errors.conflict');
            case 429: return t('errors.rate_limited');
        }

        if (typeof status === 'number' && status >= 500) return t('errors.server');
        if (!status) return t('errors.network');

        return apiMessage || t('ui.error_generic');
    }

    /**
     * 400/401/403/404/409/422 → toast con el título del patrón de módulos y,
     * cuando el error trae `field` y hay un `root`, error de campo en línea.
     *
     * @param {{status?: number, message?: string, data?: {field?: string}}} err
     * @param {{fallbackMessage?: string, root?: Element, inline?: boolean,
     *         tag?: string, onConflict?: Function, reload?: Function}} opts
     */
    function handleActionError(err, opts) {
        const o = opts || {};
        const status = err ? err.status : null;
        const body = (err && err.data && typeof err.data === 'object') ? err.data : null;
        const inner = (body && body.data && typeof body.data === 'object') ? body.data : null;
        const field = (inner && inner.field) || (body && body.field) || null;
        const message = errorMessage(err) || o.fallbackMessage || t('ui.error_generic');

        if (o.tag) {
            console.error(`[${o.tag}] action error:`, status, message);
        }

        if (o.root && field) {
            if (o.inline) UI.showFormError(o.root, message);
            UI.showFieldError(o.root, field, message);
        } else if (o.root && o.inline) {
            UI.showFormError(o.root, message);
        }

        switch (status) {
            case 400:
                UI.toast('danger', t('ui.toast_invalid'), message);
                break;
            case 401:
                UI.toast('warning', t('ui.toast_session'), message);
                break;
            case 403:
                UI.toast('warning', t('ui.toast_forbidden'), message);
                break;
            case 404:
                UI.toast('danger', t('ui.toast_not_found'), message);
                break;
            case 409:
                if (typeof o.onConflict === 'function') o.onConflict();
                UI.toast('warning', t('ui.toast_conflict'), message);
                if (typeof o.reload === 'function') o.reload();
                break;
            case 422:
                UI.toast('danger', t('ui.toast_rejected'), message);
                break;
            default:
                UI.toast('danger', t('ui.error_generic'), message);
        }
    }

    // ====================================================================
    // CSV
    // ====================================================================

    /**
     * Descarga `path` como CSV: GET autenticado (`rawResponse`), Blob con
     * BOM UTF-8 y clic sintético. `filename` es el nombre sugerido.
     */
    async function downloadCsv(path, filename) {
        const res = await global.HttpClient.get(path, {
            rawResponse: true,
            headers: { 'Accept': 'text/csv' }
        });
        const data = res ? res.data : null;

        if (typeof data !== 'string') {
            throw new Error(t('ui.error_not_csv'));
        }

        // response.text() retira el BOM del servidor: se vuelve a anteponer
        // para que Excel abra bien los acentos.
        const blob = new Blob(['\uFEFF', data], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename || 'descarga.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 0);
    }

    global.UI = Object.assign(global.UI || {}, {
        can: can,
        newIdempotencyKey: newIdempotencyKey,
        errorMessage: errorMessage,
        handleActionError: handleActionError,
        downloadCsv: downloadCsv
    });

})(window);
