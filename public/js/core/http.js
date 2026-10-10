/**
 * HTTP Client
 * Wrapper sobre fetch con:
 * - Auto-attach JWT Bearer token
 * - Refresh automático en 401
 * - Timeout configurable
 * - Retry en errores de red
 * - Manejo global de errores
 */
(function(global) {
    'use strict';

    const DEFAULT_TIMEOUT = 30000;
    const MAX_RETRIES = 2;
    const RETRY_DELAY = 1000;

    let isRefreshing = false;
    let refreshSubscribers = [];

    function onRefreshed(token) {
        refreshSubscribers.forEach(cb => cb(token));
        refreshSubscribers = [];
    }

    function addRefreshSubscriber(callback) {
        refreshSubscribers.push(callback);
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function buildUrl(path, params) {
        const base = (global.AppConfig && global.AppConfig.apiBase) || '/api';
        let url = path.startsWith('http') ? path : `${base}${path.startsWith('/') ? '' : '/'}${path}`;

        if (params && typeof params === 'object') {
            const qs = new URLSearchParams();
            Object.entries(params).forEach(([k, v]) => {
                if (v !== null && v !== undefined) qs.append(k, v);
            });
            const qsString = qs.toString();
            if (qsString) url += (url.includes('?') ? '&' : '?') + qsString;
        }

        return url;
    }

    function buildHeaders(customHeaders = {}, requiresAuth = true) {
        const headers = {
            'Accept': 'application/json',
            ...customHeaders
        };

        if (requiresAuth) {
            const token = global.Storage && global.Storage.getToken();
            if (token) {
                headers['Authorization'] = `Bearer ${token}`;
            }
        }

        return headers;
    }

    function serializeBody(body, headers) {
        if (body === null || body === undefined) return null;

        if (body instanceof FormData ||
            body instanceof Blob ||
            body instanceof ArrayBuffer ||
            typeof body === 'string') {
            return body;
        }

        if (!headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }

        return JSON.stringify(body);
    }

    async function parseResponse(response) {
        const contentType = response.headers.get('Content-Type') || '';

        if (contentType.includes('application/json')) {
            try {
                return await response.json();
            } catch (e) {
                return null;
            }
        }

        const text = await response.text();
        return text || null;
    }

    async function refreshAccessToken() {
        if (!global.Storage) return false;

        const refreshToken = global.Storage.getRefreshToken();
        if (!refreshToken) return false;

        try {
            const base = (global.AppConfig && global.AppConfig.apiBase) || '/api';
            const response = await fetch(`${base}/auth/refresh`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ refresh_token: refreshToken })
            });

            if (!response.ok) {
                return false;
            }

            const data = await response.json();
            if (data && data.data && data.data.tokens) {
                global.Storage.setToken(data.data.tokens.access_token);
                global.Storage.setRefreshToken(data.data.tokens.refresh_token);
                return data.data.tokens.access_token;
            }

            return false;
        } catch (e) {
            return false;
        }
    }

    function handleUnauthorized() {
        if (global.Storage) {
            global.Storage.clearAuth();
        }
        // AuthState cachea el resultado de isAuthenticated() en un booleano:
        // sin sincronizarlo, el módulo de login seguiría viendo sesión activa
        // y redirigiría al dashboard en bucle (guard de router rechaza, login
        // vuelve a empujar, ...).
        if (global.AuthState && typeof global.AuthState.reset === 'function') {
            global.AuthState.reset();
        }
        if (global.EventBus) {
            global.EventBus.emit('auth:unauthorized');
        }
        // Redirigir a login si estamos en la app
        if (global.AppRouter && typeof global.AppRouter.navigate === 'function') {
            global.AppRouter.navigate('login');
        } else if (window.location.pathname.indexOf('login') === -1) {
            window.location.href = '/xp/public/?page=login';
        }
    }

    async function doRequest(path, options = {}) {
        const {
            method = 'GET',
            body = null,
            headers = {},
            params = null,
            timeout = DEFAULT_TIMEOUT,
            retries = MAX_RETRIES,
            requiresAuth = true,
            rawResponse = false
        } = options;

        const url = buildUrl(path, params);
        const finalHeaders = buildHeaders(headers, requiresAuth);
        const serializedBody = serializeBody(body, finalHeaders);

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        try {
            const response = await fetch(url, {
                method,
                headers: finalHeaders,
                body: serializedBody,
                signal: controller.signal,
                credentials: 'same-origin'
            });

            clearTimeout(timeoutId);

            // 401 → intentar refresh UNA vez
            if (response.status === 401 && requiresAuth && !options._isRefreshRetry) {
                if (isRefreshing) {
                    // Esperar a que otro request termine el refresh
                    const newToken = await new Promise((resolve) => {
                        addRefreshSubscriber(resolve);
                    });
                    if (newToken) {
                        return doRequest(path, {
                            ...options,
                            _isRefreshRetry: true
                        });
                    }
                } else {
                    isRefreshing = true;
                    try {
                        const newToken = await refreshAccessToken();
                        isRefreshing = false;

                        if (newToken) {
                            onRefreshed(newToken);
                            return doRequest(path, {
                                ...options,
                                _isRefreshRetry: true
                            });
                        } else {
                            onRefreshed(null);
                            handleUnauthorized();
                            throw createHttpError(t('ui.toast_session'), 401, null);
                        }
                    } catch (e) {
                        isRefreshing = false;
                        onRefreshed(null);
                        handleUnauthorized();
                        throw e;
                    }
                }
            }

            const data = await parseResponse(response);

            if (!response.ok) {
                const message = (data && data.message) || `HTTP ${response.status}`;
                const error = createHttpError(message, response.status, data);

                if (global.EventBus) {
                    global.EventBus.emit('http:error', { path, status: response.status, data });
                }

                throw error;
            }

            if (rawResponse) {
                return { data, status: response.status, headers: response.headers };
            }

            return data;
        } catch (e) {
            clearTimeout(timeoutId);

            // Abort → timeout
            if (e.name === 'AbortError') {
                const error = createHttpError('Request timeout', 408, null);
                if (global.EventBus) {
                    global.EventBus.emit('http:timeout', { path });
                }
                throw error;
            }

            // Errores de red → retry
            if (retries > 0 && !e.status) {
                await sleep(RETRY_DELAY);
                return doRequest(path, { ...options, retries: retries - 1 });
            }

            if (!e.status && global.EventBus) {
                global.EventBus.emit('http:network_error', { path, error: e.message });
            }

            throw e;
        }
    }

    function createHttpError(message, status, data) {
        const error = new Error(message);
        error.name = 'HttpError';
        error.status = status;
        error.data = data;
        return error;
    }

    const HttpClient = {
        async get(path, options = {}) {
            return doRequest(path, { ...options, method: 'GET' });
        },

        async post(path, body = null, options = {}) {
            return doRequest(path, { ...options, method: 'POST', body });
        },

        async put(path, body = null, options = {}) {
            return doRequest(path, { ...options, method: 'PUT', body });
        },

        async patch(path, body = null, options = {}) {
            return doRequest(path, { ...options, method: 'PATCH', body });
        },

        async delete(path, options = {}) {
            return doRequest(path, { ...options, method: 'DELETE' });
        },

        async upload(path, formData, options = {}) {
            // No setear Content-Type, el browser lo hace con boundary
            const headers = { ...(options.headers || {}) };
            delete headers['Content-Type'];
            return doRequest(path, {
                ...options,
                method: 'POST',
                body: formData,
                headers
            });
        },

        setDefaultTimeout(ms) {
            DEFAULT_TIMEOUT = ms;
        },

        setMaxRetries(n) {
            MAX_RETRIES = n;
        },

        // Cancelar refresh en curso (útil en tests)
        resetRefreshState() {
            isRefreshing = false;
            refreshSubscribers = [];
        }
    };

    global.HttpClient = HttpClient;

})(window);
