/**
 * Inventory State Manager
 * Estado compartido entre submódulos de Inventory (products, locations, stock, etc.)
 * - Cachea productos y ubicaciones para evitar requests duplicados
 * - Invalidación manual tras cambios
 * - Emite eventos: inventory:products:updated, inventory:locations:updated
 * Expuesto como window.InventoryStateModule (convención ModuleLoader).
 */
(function(global) {
    'use strict';

    let state = {
        products: {
            items: [],
            pagination: { total: 0, page: 1, per_page: 20, has_next: false },
            filters: {},
            loadedAt: null
        },
        locations: {
            items: [],
            pagination: { total: 0, page: 1, per_page: 20, has_next: false },
            filters: {},
            loadedAt: null
        },
        cache: {
            productsById: new Map(),
            locationsById: new Map(),
            productsBySku: new Map(),
            locationsByCodigo: new Map(),
            activeProducts: null,
            activeLocations: null,
            locationsWithCapacity: null
        },
        initialized: false
    };

    const CACHE_TTL = 60 * 1000; // 60 segundos

    function emit(event, data) {
        if (global.EventBus) {
            global.EventBus.emit(event, data);
        }
    }

    function isCacheValid(key) {
        const entry = state.cache[key];
        if (entry === null || entry === undefined) return false;
        if (entry._loadedAt && (Date.now() - entry._loadedAt) > CACHE_TTL) return false;
        return true;
    }

    function markCacheLoaded(key, data) {
        state.cache[key] = data;
        state.cache[key]._loadedAt = Date.now();
    }

    // ====================================================================
    // PRODUCTS
    // ====================================================================

    async function loadProducts(filters = {}, page = 1, perPage = 20, force = false) {
        if (!force && isCacheValid('productsList') &&
            JSON.stringify(state.products.filters) === JSON.stringify(filters) &&
            state.products.pagination.page === page) {
            return state.products;
        }

        try {
            const params = { page, per_page: perPage, ...filters };
            const response = await global.HttpClient.get('/inventory/products', { params });
            const data = response.data || response;

            state.products.items = data.data || [];
            state.products.pagination = data.pagination || state.products.pagination;
            state.products.filters = filters;
            state.products.loadedAt = Date.now();

            state.products.items.forEach(p => {
                state.cache.productsById.set(p.id, p);
                state.cache.productsBySku.set(p.sku.toUpperCase(), p);
            });

            markCacheLoaded('productsList', state.products);
            emit('inventory:products:updated', { items: state.products.items });

            return state.products;
        } catch (err) {
            console.error('[InventoryState] loadProducts error:', err);
            throw err;
        }
    }

    async function getProductById(id) {
        const cached = state.cache.productsById.get(id);
        if (cached) return cached;

        try {
            const response = await global.HttpClient.get(`/inventory/products/${id}`);
            const product = (response.data && response.data.product) || response;
            state.cache.productsById.set(product.id, product);
            state.cache.productsBySku.set(product.sku.toUpperCase(), product);
            return product;
        } catch (err) {
            console.error('[InventoryState] getProductById error:', err);
            return null;
        }
    }

    async function getProductBySku(sku) {
        const normalized = sku.toUpperCase().trim();
        const cached = state.cache.productsBySku.get(normalized);
        if (cached) return cached;

        try {
            const response = await global.HttpClient.get(`/inventory/products/by-sku/${encodeURIComponent(normalized)}`);
            const product = (response.data && response.data.product) || response;
            state.cache.productsById.set(product.id, product);
            state.cache.productsBySku.set(normalized, product);
            return product;
        } catch (err) {
            console.error('[InventoryState] getProductBySku error:', err);
            return null;
        }
    }

    async function getActiveProducts(force = false) {
        if (!force && isCacheValid('activeProducts')) {
            return state.cache.activeProducts;
        }

        try {
            const response = await global.HttpClient.get('/inventory/products', {
                params: { activo: 1, per_page: 100 }
            });
            const data = response.data || response;
            const items = data.data || [];
            markCacheLoaded('activeProducts', items);
            items.forEach(p => {
                state.cache.productsById.set(p.id, p);
                state.cache.productsBySku.set(p.sku.toUpperCase(), p);
            });
            return items;
        } catch (err) {
            console.error('[InventoryState] getActiveProducts error:', err);
            return [];
        }
    }

    // ====================================================================
    // LOCATIONS
    // ====================================================================

    async function loadLocations(filters = {}, page = 1, perPage = 20, force = false) {
        if (!force && isCacheValid('locationsList') &&
            JSON.stringify(state.locations.filters) === JSON.stringify(filters) &&
            state.locations.pagination.page === page) {
            return state.locations;
        }

        try {
            const params = { page, per_page: perPage, ...filters };
            const response = await global.HttpClient.get('/inventory/locations', { params });
            const data = response.data || response;

            state.locations.items = data.data || [];
            state.locations.pagination = data.pagination || state.locations.pagination;
            state.locations.filters = filters;
            state.locations.loadedAt = Date.now();

            state.locations.items.forEach(l => {
                state.cache.locationsById.set(l.id, l);
                state.cache.locationsByCodigo.set(l.codigo.toUpperCase(), l);
            });

            markCacheLoaded('locationsList', state.locations);
            emit('inventory:locations:updated', { items: state.locations.items });

            return state.locations;
        } catch (err) {
            console.error('[InventoryState] loadLocations error:', err);
            throw err;
        }
    }

    async function getLocationById(id) {
        const cached = state.cache.locationsById.get(id);
        if (cached) return cached;

        try {
            const response = await global.HttpClient.get(`/inventory/locations/${id}`);
            const location = (response.data && response.data.location) || response;
            state.cache.locationsById.set(location.id, location);
            state.cache.locationsByCodigo.set(location.codigo.toUpperCase(), location);
            return location;
        } catch (err) {
            console.error('[InventoryState] getLocationById error:', err);
            return null;
        }
    }

    async function getLocationByCodigo(codigo) {
        const normalized = codigo.toUpperCase().trim();
        const cached = state.cache.locationsByCodigo.get(normalized);
        if (cached) return cached;

        try {
            const response = await global.HttpClient.get(`/inventory/locations/by-codigo/${encodeURIComponent(normalized)}`);
            const location = (response.data && response.data.location) || response;
            state.cache.locationsById.set(location.id, location);
            state.cache.locationsByCodigo.set(normalized, location);
            return location;
        } catch (err) {
            console.error('[InventoryState] getLocationByCodigo error:', err);
            return null;
        }
    }

    async function getActiveLocations(force = false) {
        if (!force && isCacheValid('activeLocations')) {
            return state.cache.activeLocations;
        }

        try {
            const response = await global.HttpClient.get('/inventory/locations', {
                params: { activa: 1, per_page: 100 }
            });
            const data = response.data || response;
            const items = data.data || [];
            markCacheLoaded('activeLocations', items);
            items.forEach(l => {
                state.cache.locationsById.set(l.id, l);
                state.cache.locationsByCodigo.set(l.codigo.toUpperCase(), l);
            });
            return items;
        } catch (err) {
            console.error('[InventoryState] getActiveLocations error:', err);
            return [];
        }
    }

    async function getLocationsWithCapacity(force = false) {
        if (!force && isCacheValid('locationsWithCapacity')) {
            return state.cache.locationsWithCapacity;
        }

        try {
            const response = await global.HttpClient.get('/inventory/locations/with-capacity');
            const data = response.data || response;
            const items = data.locations || [];
            markCacheLoaded('locationsWithCapacity', items);
            return items;
        } catch (err) {
            console.error('[InventoryState] getLocationsWithCapacity error:', err);
            return [];
        }
    }

    // ====================================================================
    // INVALIDACIÓN
    // ====================================================================

    function invalidateProducts() {
        state.cache.productsById.clear();
        state.cache.productsBySku.clear();
        state.cache.productsList = null;
        state.cache.activeProducts = null;
        state.products.loadedAt = null;
        emit('inventory:products:invalidated', {});
    }

    function invalidateLocations() {
        state.cache.locationsById.clear();
        state.cache.locationsByCodigo.clear();
        state.cache.locationsList = null;
        state.cache.activeLocations = null;
        state.cache.locationsWithCapacity = null;
        state.locations.loadedAt = null;
        emit('inventory:locations:invalidated', {});
    }

    function invalidateAll() {
        invalidateProducts();
        invalidateLocations();
    }

    // ====================================================================
    // PUBLIC API
    // ====================================================================

    const InventoryStateModule = {
        init() {
            if (state.initialized) return;
            state.initialized = true;

            if (global.EventBus) {
                global.EventBus.on('inventory:product:created', invalidateProducts);
                global.EventBus.on('inventory:product:updated', invalidateProducts);
                global.EventBus.on('inventory:product:deleted', invalidateProducts);
                global.EventBus.on('inventory:location:created', invalidateLocations);
                global.EventBus.on('inventory:location:updated', invalidateLocations);
                global.EventBus.on('inventory:location:deleted', invalidateLocations);
            }
        },

        loadProducts,
        getProductById,
        getProductBySku,
        getActiveProducts,
        invalidateProducts,

        loadLocations,
        getLocationById,
        getLocationByCodigo,
        getActiveLocations,
        getLocationsWithCapacity,
        invalidateLocations,

        invalidateAll,

        getProducts() { return state.products; },
        getLocations() { return state.locations; },
        getCacheStats() {
            return {
                productsById: state.cache.productsById.size,
                productsBySku: state.cache.productsBySku.size,
                locationsById: state.cache.locationsById.size,
                locationsByCodigo: state.cache.locationsByCodigo.size
            };
        },

        reset() {
            state = {
                products: { items: [], pagination: { total: 0, page: 1, per_page: 20, has_next: false }, filters: {}, loadedAt: null },
                locations: { items: [], pagination: { total: 0, page: 1, per_page: 20, has_next: false }, filters: {}, loadedAt: null },
                cache: {
                    productsById: new Map(),
                    locationsById: new Map(),
                    productsBySku: new Map(),
                    locationsByCodigo: new Map(),
                    activeProducts: null,
                    activeLocations: null,
                    locationsWithCapacity: null
                },
                initialized: false
            };
        }
    };

    global.InventoryStateModule = InventoryStateModule;

})(window);
