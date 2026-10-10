/**
 * IdleTimer - Cierre de sesión por inactividad (frontend)
 *
 * Mide la inactividad REAL del usuario (movimiento, teclas, scroll, touch).
 * Tras AppConfig.sessionTimeout segundos sin actividad, muestra un toast
 * y llama a AuthState.logout() (que revoca la familia de refresh tokens
 * en el backend y redirige a login).
 *
 * Multi-pestaña: la actividad se sincroniza vía localStorage ('storage'
 * event). Una pestaña activa resetea el timer de las demás → trabajar en
 * una pestaña ya no expira la sesión de las pestañas tranquilas (ni al
 * revés: el receiver solo resetea, nunca re-emite → sin loops).
 *
 * Ciclo de vida:
 * - start() con 'auth:login' (y con sesión ya activa al cargar/recargar)
 * - stop()  con 'auth:logout' o 'auth:synced' sin sesión (otra pestaña)
 * - La actividad NO se reporta al backend: el backend mide por su cuenta
 *   la actividad vía Redis (session:act:*) en cada request autenticado.
 */
(function(global) {
    'use strict';

    const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'scroll'];
    const ACTIVITY_STORAGE_KEY = 'xp:idle:activity';
    const BROADCAST_THROTTLE_MS = 1000;

    let timerId = null;
    let keepAliveId = null;
    let started = false;
    let lastBroadcastAt = 0;

    function timeoutMs() {
        const configured = Number(global.AppConfig && global.AppConfig.sessionTimeout);
        return (configured > 0 ? configured : 1800) * 1000;
    }

    // Keep-alive: intervalo = sessionTimeout / 3 (3 intentos antes de vencer la clave).
    // 0 = inactividad deshabilitada en backend -> no tiene sentido el keep-alive.
    function keepAliveMs() {
        const configured = Number(global.AppConfig && global.AppConfig.sessionTimeout);
        if (!(configured > 0)) return 0;
        return Math.max(1, Math.floor(configured / 3)) * 1000;
    }

    function ping() {
        if (!global.HttpClient) return;
        global.HttpClient.post('/auth/ping', null, { requiresAuth: true })
            .catch(() => {
                // Fallo de red o sesión ya caída: http.js maneja el 401 (refresh/logout).
                // Este intervalo reintenta en el próximo tick.
            });
    }

    function resetTimer() {
        clearTimeout(timerId);
        timerId = setTimeout(expire, timeoutMs());
    }

    // Throttled: mousemove dispara decenas de veces por segundo.
    function broadcastActivity() {
        const now = Date.now();
        if (now - lastBroadcastAt < BROADCAST_THROTTLE_MS) return;
        lastBroadcastAt = now;
        try {
            global.localStorage.setItem(ACTIVITY_STORAGE_KEY, String(now));
        } catch (e) {
            // Storage no disponible (modo privado, cuota): funciona solo intra-pestaña.
        }
    }

    function onActivity() {
        if (!started) return;
        resetTimer();
        broadcastActivity();
    }

    // Actividad detectada en OTRA pestaña: resetear el timer local SIN
    // volver a emitir en storage (evita ping-pong entre pestañas).
    function onStorageEvent(event) {
        if (!started || !event) return;
        if (event.key !== ACTIVITY_STORAGE_KEY || event.newValue === null) return;
        resetTimer();
    }

    function expire() {
        timerId = null;
        if (!started) return;
        if (!global.AuthState || !global.AuthState.isAuthenticated()) {
            stop();
            return;
        }

        if (global.EventBus) {
            global.EventBus.emit('toast:show', {
                type: 'warning',
                title: t('shell.session_expired'),
                message: t('shell.session_idle'),
                duration: 6000
            });
        }

        global.AuthState.logout();
    }

    function start() {
        if (started) {
            resetTimer();
            return;
        }

        started = true;
        for (let i = 0; i < ACTIVITY_EVENTS.length; i++) {
            global.addEventListener(ACTIVITY_EVENTS[i], onActivity, { passive: true });
        }
        resetTimer();

        const kaMs = keepAliveMs();
        if (kaMs > 0) {
            keepAliveId = setInterval(ping, kaMs);
        }
    }

    function stop() {
        started = false;
        clearTimeout(timerId);
        timerId = null;
        clearInterval(keepAliveId);
        keepAliveId = null;
        for (let i = 0; i < ACTIVITY_EVENTS.length; i++) {
            global.removeEventListener(ACTIVITY_EVENTS[i], onActivity);
        }
    }

    function init() {
        if (!global.EventBus) return;

        global.EventBus.on('auth:login', start);
        global.EventBus.on('auth:logout', stop);
        // Sesión invalidada por el backend (401 y refresh rechazado): no hay nada que cronometrar
        global.EventBus.on('auth:unauthorized', stop);
        global.EventBus.on('auth:synced', (data) => {
            if (data && data.isAuthenticated) {
                start();
            } else {
                stop();
            }
        });

        // Actividad de otras pestañas (evento 'storage' solo llega a las demás)
        global.addEventListener('storage', onStorageEvent);

        // Sesión ya activa al cargar o recargar la página
        if (global.Storage && global.Storage.getToken()) {
            start();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    global.IdleTimer = {
        start,
        stop,
        isActive: () => started
    };

})(window);
