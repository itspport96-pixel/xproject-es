/**
 * Auth State Manager
 * Gestiona el estado de autenticación del usuario en el frontend.
 * - Sincroniza con Storage (localStorage)
 * - Emite eventos: auth:login, auth:logout, auth:user-updated
 * - Provee API pública para otros módulos
 */
(function(global) {
    'use strict';

    let currentUser = null;
    let isAuthenticated = false;
    let initialized = false;

    function emit(event, data) {
        if (global.EventBus) {
            global.EventBus.emit(event, data);
        }
    }

    function loadFromStorage() {
        if (!global.Storage) return;

        const user = global.Storage.getUser();
        const token = global.Storage.getToken();

        if (user && token) {
            currentUser = user;
            isAuthenticated = true;
        } else {
            currentUser = null;
            isAuthenticated = false;
        }
    }

    function updateHeaderUI() {
        const userNameEl = document.getElementById('user-name');
        if (!userNameEl) return;

        if (isAuthenticated && currentUser) {
            const displayName = currentUser.nombre
                ? `${currentUser.nombre} ${currentUser.apellido || ''}`.trim()
                : currentUser.email;
            userNameEl.textContent = displayName;
        } else {
            userNameEl.textContent = global.t ? t('shell.guest') : 'Invitado';
        }
    }

    const AuthState = {
        init() {
            if (initialized) return;
            initialized = true;

            loadFromStorage();
            updateHeaderUI();

            // NOTA (v1.10.3): la preferencia de idioma del servidor
            // (users.preferencias.idioma) NO se aplica aqui: una sesion
            // restaurada desde localStorage no es un login. syncFromUser
            // se invoca unicamente en setSession() (ver mas abajo).

            // Cambio de idioma: re-pinta el nombre de invitado del header.
            if (global.EventBus) {
                global.EventBus.on('i18n:changed', () => updateHeaderUI());
            }

            // Sincronizar roles/permisos/tenant con el backend (la respuesta
            // de login no siempre trae roles completos y pueden cambiar).
            if (isAuthenticated) {
                this.refreshUser();
            }

            // v1.12.1: refrescar /auth/me al volver a la pestana: roles,
            // grupos y permisos temporales pueden haber cambiado mientras
            // la pestana estaba en segundo plano.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && this.isAuthenticated()) {
                    this.refreshUser();
                }
            });
            window.addEventListener('focus', () => {
                if (this.isAuthenticated()) {
                    this.refreshUser();
                }
            });

            // v1.12.1: tras un 403 la sesion local puede estar obsoleta
            // (rol quitado, baja del grupo, temporal revocado): se recalcula
            // en la siguiente peticion. Throttle para no saturar /auth/me.
            if (global.EventBus) {
                let lastRefresh = 0;
                global.EventBus.on('http:error', (payload) => {
                    if (!payload || payload.status !== 403 || !this.isAuthenticated()) return;
                    // El 403 de contrasena obligatoria lo gestiona password.js.
                    const inner = payload.data && payload.data.data;
                    if (inner && inner.category === 'password_change_required') return;
                    const now = Date.now();
                    if (now - lastRefresh < 1000) return;
                    lastRefresh = now;
                    this.refreshUser();
                });
            }

            // Bind logout button
            const logoutBtn = document.getElementById('logout-btn');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.logout();
                });
            }

            // v1.12.0: precarga del modulo de cambio de contrasena. Su init
            // monta el boton en el menu de usuario y escucha
            // 'auth:password-required' / 'http:error' (modo obligatorio).
            if (global.ModuleLoader && typeof global.ModuleLoader.preload === 'function') {
                global.ModuleLoader.preload('auth/password');
            }

            // Listen for storage changes (other tabs)
            window.addEventListener('storage', (e) => {
                if (e.key === 'xp.auth.user' || e.key === 'xp.auth.token') {
                    loadFromStorage();
                    updateHeaderUI();
                    emit('auth:synced', { user: currentUser, isAuthenticated });
                }
            });
        },

        /**
         * Establece sesión tras login exitoso
         * @param {Object} user - Datos del usuario
         * @param {Object} tokens - { access_token, refresh_token }
         */
        setSession(user, tokens) {
            if (!global.Storage) return false;

            global.Storage.setUser(user);
            global.Storage.setToken(tokens.access_token);
            global.Storage.setRefreshToken(tokens.refresh_token);

            if (user.tenant_id) {
                global.Storage.setTenantId(user.tenant_id);
            }

            currentUser = user;
            isAuthenticated = true;

            updateHeaderUI();
            // Sincronia de idioma SOLO en el login (v1.10.3): el resto de
            // flujos (refreshUser -> /auth/me, storage, renovacion de token)
            // pasan por updateUser y no deben tocar el locale activo.
            if (global.i18n && typeof global.i18n.syncFromUser === 'function') {
                global.i18n.syncFromUser(user);
            }
            emit('auth:login', { user, tokens });

            return true;
        },

        /**
         * Cierra sesión y limpia estado
         */
        async logout() {
            const token = global.Storage ? global.Storage.getToken() : null;

            // Intentar revocar token en backend
            if (token) {
                try {
                    if (global.HttpClient) {
                        await global.HttpClient.post('/auth/logout', null, {
                            requiresAuth: true
                        });
                    }
                } catch (e) {
                    // Ignorar errores, limpiar estado local de todos modos
                    console.warn('[AuthState] logout API error:', e.message);
                }
            }

            if (global.Storage) {
                global.Storage.clearAuth();
            }

            currentUser = null;
            isAuthenticated = false;

            updateHeaderUI();
            emit('auth:logout', {});

            // Redirigir a login
            if (global.AppRouter) {
                global.AppRouter.navigate('login');
            }
        },

        /**
         * Actualiza datos del usuario (ej: tras /auth/me)
         * @param {Object} user
         */
        updateUser(user) {
            if (!user) return;

            currentUser = user;
            isAuthenticated = true;

            if (global.Storage) {
                global.Storage.setUser(user);
                if (user.tenant_id) {
                    global.Storage.setTenantId(user.tenant_id);
                }
            }

            updateHeaderUI();
            // Sin syncFromUser: /auth/me (refreshUser) no cambia el idioma
            // (v1.10.3). La eleccion explicita de la sesion manda.
            emit('auth:user-updated', { user });
        },

        /**
         * Verifica si hay sesión activa
         * @returns {boolean}
         */
        check() {
            if (!global.Storage) return false;

            const token = global.Storage.getToken();
            if (!token) {
                isAuthenticated = false;
                currentUser = null;
                return false;
            }

            // Verificar expiración básica del JWT (sin validar firma)
            try {
                const parts = token.split('.');
                if (parts.length === 3) {
                    const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
                    if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
                        // Token expirado, intentar refresh
                        return false;
                    }
                }
            } catch (e) {
                // Si falla parseo, asumir válido y dejar que backend decida
            }

            isAuthenticated = true;
            return true;
        },

        /**
         * Obtiene el usuario actual
         * @returns {Object|null}
         */
        getUser() {
            return currentUser;
        },

        /**
         * Obtiene el user_id actual
         * @returns {string|null}
         */
        getUserId() {
            return currentUser ? currentUser.id : null;
        },

        /**
         * Obtiene el tenant_id actual
         * @returns {string|null}
         */
        getTenantId() {
            if (currentUser && currentUser.tenant_id) {
                return currentUser.tenant_id;
            }
            return global.Storage ? global.Storage.getTenantId() : null;
        },

        /**
         * Obtiene los roles del usuario (normalizados a nombres)
         * @returns {Array<string>}
         */
        getRoles() {
            const roles = currentUser && currentUser.roles ? currentUser.roles : [];
            return roles
                .map(r => (typeof r === 'string' ? r : (r && r.nombre) || ''))
                .filter(Boolean);
        },

        /**
         * Obtiene los permisos efectivos del usuario (v1.12.1).
         *
         * Fuente principal: `currentUser.permisos`, que traen login y
         * /auth/me calculados por AuthorizationService (roles directos +
         * roles de grupo + temporales vigentes). El JSON de permisos de los
         * roles individuales queda como fallback de sesiones antiguas.
         * @returns {Array<string>}
         */
        getPermissions() {
            if (currentUser && Array.isArray(currentUser.permisos)) {
                return currentUser.permisos.slice();
            }
            const roles = currentUser && currentUser.roles ? currentUser.roles : [];
            const perms = [];
            roles.forEach(r => {
                if (!r) return;
                let p = (typeof r === 'object') ? r.permisos : null;
                if (typeof p === 'string') {
                    try { p = JSON.parse(p); } catch (e) { p = []; }
                }
                if (Array.isArray(p)) {
                    p.forEach(code => { if (typeof code === 'string' && !perms.includes(code)) perms.push(code); });
                }
            });
            return perms;
        },

        /**
         * Verifica si el usuario tiene un permiso específico
         * @param {string} permission
         * @returns {boolean}
         */
        hasPermission(permission) {
            return this.getPermissions().includes(permission);
        },

        /**
         * Verifica si el usuario tiene un rol específico
         * @param {string} role
         * @returns {boolean}
         */
        hasRole(role) {
            return this.getRoles().includes(role);
        },

        /**
         * Verifica si el usuario tiene alguno de los roles dados
         * @param {Array} roles
         * @returns {boolean}
         */
        hasAnyRole(roles) {
            const userRoles = this.getRoles();
            return roles.some(r => userRoles.includes(r));
        },

        /**
         * Verifica si está autenticado
         * @returns {boolean}
         */
        isAuthenticated() {
            return isAuthenticated;
        },

        /**
         * Obtiene el token actual
         * @returns {string|null}
         */
        getToken() {
            return global.Storage ? global.Storage.getToken() : null;
        },

        /**
         * Refresca datos del usuario desde el backend
         * @returns {Promise<Object>}
         */
        async refreshUser() {
            if (!this.isAuthenticated()) {
                return null;
            }

            try {
                if (!global.HttpClient) return null;

                const response = await global.HttpClient.get('/auth/me');
                if (response && response.data && response.data.user) {
                    const merged = { ...response.data.user };
                    if (response.data.roles) {
                        merged.roles = response.data.roles;
                    }
                    if (Array.isArray(response.data.permisos)) {
                        merged.permisos = response.data.permisos;
                    }
                    if (Array.isArray(response.data.permisos_temporales)) {
                        merged.permisos_temporales = response.data.permisos_temporales;
                    }
                    if (response.data.tenant_id) {
                        merged.tenant_id = response.data.tenant_id;
                    }
                    this.updateUser(merged);
                    return merged;
                }
                return null;
            } catch (e) {
                console.warn('[AuthState] refreshUser error:', e.message);
                return null;
            }
        },

        /**
         * Limpia estado (solo para tests)
         */
        reset() {
            currentUser = null;
            isAuthenticated = false;
            if (global.Storage) {
                global.Storage.clearAuth();
            }
            updateHeaderUI();
        }
    };

    global.AuthState = AuthState;

})(window);
