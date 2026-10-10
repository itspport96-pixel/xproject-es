/**
 * Event Bus - Pub/Sub global
 * Comunicación entre módulos sin acoplamiento directo.
 * - Soporta namespaces (modulo:evento)
 * - Manejo seguro de errores (un listener no rompe otros)
 * - Soporta once() para listeners de un solo uso
 * - Límite de listeners por evento para detectar memory leaks
 */
(function(global) {
    'use strict';

    const MAX_LISTENERS_PER_EVENT = 50;
    const listeners = new Map();

    function safeInvoke(callback, data, event) {
        try {
            callback(data, event);
        } catch (err) {
            console.error(`[EventBus] Error in listener for "${event}":`, err);
        }
    }

    const EventBus = {
        /**
         * Suscribirse a un evento
         * @param {string} event - Nombre del evento (soporta namespace: "modulo:accion")
         * @param {Function} callback - Función a ejecutar
         * @returns {Function} Función para desuscribirse
         */
        on(event, callback) {
            if (typeof callback !== 'function') {
                console.warn('[EventBus] on() requires a function as callback');
                return () => {};
            }

            if (!listeners.has(event)) {
                listeners.set(event, []);
            }

            const list = listeners.get(event);

            if (list.length >= MAX_LISTENERS_PER_EVENT) {
                console.warn(
                    `[EventBus] Possible memory leak: ${list.length} listeners for "${event}". ` +
                    `Max recommended: ${MAX_LISTENERS_PER_EVENT}`
                );
            }

            list.push(callback);

            // Retornar función de desuscripción
            return () => this.off(event, callback);
        },

        /**
         * Suscribirse a un evento (solo se ejecuta una vez)
         */
        once(event, callback) {
            const wrapper = (data, evt) => {
                this.off(event, wrapper);
                safeInvoke(callback, data, evt);
            };
            wrapper._original = callback;
            return this.on(event, wrapper);
        },

        /**
         * Desuscribirse de un evento
         * @param {string} event - Nombre del evento
         * @param {Function} [callback] - Si se omite, elimina todos los listeners del evento
         */
        off(event, callback) {
            if (!listeners.has(event)) return;

            if (!callback) {
                listeners.delete(event);
                return;
            }

            const list = listeners.get(event);
            const filtered = list.filter(fn => fn !== callback && fn._original !== callback);
            if (filtered.length === 0) {
                listeners.delete(event);
            } else {
                listeners.set(event, filtered);
            }
        },

        /**
         * Emitir un evento
         * @param {string} event - Nombre del evento
         * @param {*} data - Datos a pasar a los listeners
         */
        emit(event, data) {
            if (!listeners.has(event)) return;

            const list = listeners.get(event).slice(); // snapshot para evitar mutación durante iteración

            for (let i = 0; i < list.length; i++) {
                safeInvoke(list[i], data, event);
            }

            // También emitir a listeners de wildcard "*"
            if (event !== '*' && listeners.has('*')) {
                const wildcardList = listeners.get('*').slice();
                for (let i = 0; i < wildcardList.length; i++) {
                    safeInvoke(wildcardList[i], { event, data }, '*');
                }
            }
        },

        /**
         * Emitir evento con múltiples argumentos
         */
        emitMany(event, ...args) {
            if (!listeners.has(event)) return;

            const list = listeners.get(event).slice();
            for (let i = 0; i < list.length; i++) {
                try {
                    list[i](...args, event);
                } catch (err) {
                    console.error(`[EventBus] Error in listener for "${event}":`, err);
                }
            }
        },

        /**
         * Verificar si un evento tiene listeners
         */
        has(event) {
            return listeners.has(event) && listeners.get(event).length > 0;
        },

        /**
         * Obtener cantidad de listeners de un evento
         */
        count(event) {
            return listeners.has(event) ? listeners.get(event).length : 0;
        },

        /**
         * Limpiar listeners
         * @param {string} [event] - Si se omite, limpia todos los eventos
         */
        clear(event) {
            if (event) {
                listeners.delete(event);
            } else {
                listeners.clear();
            }
        },

        /**
         * Listar todos los eventos registrados (útil para debug)
         */
        list() {
            const result = {};
            listeners.forEach((list, event) => {
                result[event] = list.length;
            });
            return result;
        }
    };

    global.EventBus = EventBus;

})(window);
