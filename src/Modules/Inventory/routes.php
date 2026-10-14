<?php

declare(strict_types=1);

/**
 * Rutas del módulo Inventory
 *
 * Este archivo es cargado por el Router desde routes/api.php.
 * $router está disponible en el scope global de este archivo.
 *
 * Middlewares aplicados (en orden):
 *  1. AuditMiddleware        → hook de auditoría de escrituras (desde el group)
 *  2. AuthenticationMiddleware → extrae claims JWT        (desde el group)
 *  3. TenantMiddleware         → valida tenant activo     (desde el group)
 *  4. PermissionMiddleware     → verifica permiso granular (por ruta)
 *  5. RateLimitMiddleware      → protección contra abuso   (por ruta)
 *
 * Auth y Tenant se declaran UNA sola vez en el group() de este archivo;
 * no deben repetirse en las rutas individuales (evita doble ejecución).
 * AuditMiddleware va PRIMERO para que su hook exista aunque Auth/Tenant/
 * Permission fallen después (401/403 quedan registrados por el Router).
 *
 * Permisos (seeded en BD):
 *  - inventory.item.view     → listar, ver detalle, buscar por SKU
 *  - inventory.item.create   → crear producto
 *  - inventory.item.edit     → actualizar, activar, desactivar
 *  - inventory.item.delete   → eliminar, restaurar
 *  - inventory.location.view → listar, ver detalle, buscar por código, con capacidad, hijos
 *  - inventory.location.manage → crear, actualizar, eliminar, activar, desactivar, restaurar
 *  - inventory.receiving.create → recepción de inventario (CU-001)
 *  - inventory.stock.view    → dashboard (capas crítica y secundaria, spec §9),
 *                             stock actual (R1) y resumen por producto
 *  - inventory.movement.create  → mover unidad entre ubicaciones (CU-002)
 *  - inventory.reservation.view → listar reservas (CU-003, v1.6.2)
 *  - inventory.reservation.create → crear reserva (CU-003)
 *  - inventory.reservation.cancel → cancelar reserva (CU-003 / E7)
 *  - inventory.picking.create  → iniciar lote de picking (CU-004, v1.7.0)
 *  - inventory.picking.confirm → completar lote de picking (CU-004)
 *  - inventory.picking.verify  → verificar lote de picking (CU-004, nuevo)
 *  - inventory.picking.view    → listar operaciones de picking (CU-004, nuevo)
 *  - inventory.shipping.create → cargar unidades en un envío (CU-005, v1.7.0)
 *  - inventory.shipping.confirm → autorizar la salida (CU-006)
 *  - inventory.shipping.view   → listar/ver envíos (CU-005/006, nuevo)
 *  - inventory.delivery.view   → listar entregas (CU-007/008, v1.8.0)
 *  - inventory.delivery.confirm → confirmar entrega y registrar fallo
 *                                 (CU-007/CU-008)
 *  - inventory.return.view     → listar devoluciones (CU-009, v1.8.0)
 *  - inventory.return.create   → registrar devolución (CU-009)
 *  - inventory.incident.view   → listar/ver incidencias (CU-010, v1.9.0)
 *  - inventory.incident.report → reportar incidencia (CU-010)
 *  - inventory.incident.resolve→ investigar/resolver incidencia (CU-010)
 *  - inventory.count.view      → listar conteos, detalle y hoja (CU-011, v1.9.0)
 *  - inventory.count.create    → iniciar conteo, registrar resultados,
 *                                 completar (CU-011)
 *  - inventory.count.reconcile → reconciliar discrepancias (CU-011)
 *
 * v1.10.0 (R3/E4):
 *  - inventory.stock.view      → además: historial de unidad
 *                                 (GET /stock/{id}/history) y listado de
 *                                 movimientos (GET /movements); decisión:
 *                                 lectura de stock, sin permiso nuevo
 *  - inventory.stock.block     → bloquear/desbloquear unidad (POST
 *                                 /stock/{id}/block|unblock; seeded, sólo
 *                                 ADMIN y MANAGER)
 *
 * Nota: los identificadores de la BD son SINGULARES (`inventory.movement.*`,
 * `inventory.reservation.*`), tal como los usa la spec (CU-002/CU-003).
 */

use App\Core\Audit\AuditMiddleware;
use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authorization\Middleware\PermissionMiddleware;
use App\Core\Security\Middleware\RateLimitMiddleware;
use App\Core\Tenants\Middleware\TenantMiddleware;
use App\Modules\Inventory\Http\CountController;
use App\Modules\Inventory\Http\DashboardController;
use App\Modules\Inventory\Http\DeliveryController;
use App\Modules\Inventory\Http\IncidentController;
use App\Modules\Inventory\Http\MovementController;
use App\Modules\Inventory\Http\PickingController;
use App\Modules\Inventory\Http\ProductController;
use App\Modules\Inventory\Http\LocationController;
use App\Modules\Inventory\Http\ReceivingController;
use App\Modules\Inventory\Http\ReservationController;
use App\Modules\Inventory\Http\ReturnController;
use App\Modules\Inventory\Http\ShipmentController;
use App\Modules\Inventory\Http\StockController;

/** @var App\Shared\Router\Router $router */

$router->group('/api/inventory', function ($router) {

    // ================================================================
    // DASHBOARD (spec §9 - Lazy Loading por capas)
    // ================================================================

    // GET /api/inventory/dashboard/critical
    // CAPA 1 (primer render): tenant, contadores y stock resumido
    $router->get('/dashboard/critical', [DashboardController::class, 'critical'], [
        PermissionMiddleware::class . ':inventory.stock.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/dashboard/secondary
    // CAPA 2 (~5s): ubicaciones disponibles, reservas, tránsito, incidencias
    $router->get('/dashboard/secondary', [DashboardController::class, 'secondary'], [
        PermissionMiddleware::class . ':inventory.stock.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // PRODUCTS
    // ================================================================

    // GET /api/inventory/products
    // Lista productos con filtros y paginación
    $router->get('/products', [ProductController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.item.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/products/by-sku/{sku}
    // Busca un producto por SKU
    $router->get('/products/by-sku/{sku}', [ProductController::class, 'findBySku'], [
        PermissionMiddleware::class . ':inventory.item.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/products/{id}
    // Detalle de un producto
    $router->get('/products/{id}', [ProductController::class, 'show'], [
        PermissionMiddleware::class . ':inventory.item.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/products
    // Crea un nuevo producto
    $router->post('/products', [ProductController::class, 'store'], [
        PermissionMiddleware::class . ':inventory.item.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // PATCH /api/inventory/products/{id}
    // Actualiza un producto existente
    $router->patch('/products/{id}', [ProductController::class, 'update'], [
        PermissionMiddleware::class . ':inventory.item.edit',
        RateLimitMiddleware::class . ':api',
    ]);

    // DELETE /api/inventory/products/{id}
    // Eliminación lógica de un producto
    $router->delete('/products/{id}', [ProductController::class, 'destroy'], [
        PermissionMiddleware::class . ':inventory.item.delete',
        RateLimitMiddleware::class . ':critical',
    ]);

    // POST /api/inventory/products/{id}/activate
    // Activa un producto
    $router->post('/products/{id}/activate', [ProductController::class, 'activate'], [
        PermissionMiddleware::class . ':inventory.item.edit',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/products/{id}/deactivate
    // Desactiva un producto
    $router->post('/products/{id}/deactivate', [ProductController::class, 'deactivate'], [
        PermissionMiddleware::class . ':inventory.item.edit',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // LOCATIONS
    // ================================================================

    // GET /api/inventory/locations
    // Lista ubicaciones con filtros y paginación
    $router->get('/locations', [LocationController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.location.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/locations/by-codigo/{codigo}
    // Busca una ubicación por código
    $router->get('/locations/by-codigo/{codigo}', [LocationController::class, 'findByCodigo'], [
        PermissionMiddleware::class . ':inventory.location.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/locations/with-capacity
    // Lista ubicaciones con capacidad disponible
    $router->get('/locations/with-capacity', [LocationController::class, 'withCapacity'], [
        PermissionMiddleware::class . ':inventory.location.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/locations/children/{jerarquia}
    // Lista ubicaciones hijas de una jerarquía
    $router->get('/locations/children/{jerarquia}', [LocationController::class, 'children'], [
        PermissionMiddleware::class . ':inventory.location.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/locations/{id}
    // Detalle de una ubicación
    $router->get('/locations/{id}', [LocationController::class, 'show'], [
        PermissionMiddleware::class . ':inventory.location.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/locations
    // Crea una nueva ubicación
    $router->post('/locations', [LocationController::class, 'store'], [
        PermissionMiddleware::class . ':inventory.location.manage',
        RateLimitMiddleware::class . ':api',
    ]);

    // PATCH /api/inventory/locations/{id}
    // Actualiza una ubicación existente
    $router->patch('/locations/{id}', [LocationController::class, 'update'], [
        PermissionMiddleware::class . ':inventory.location.manage',
        RateLimitMiddleware::class . ':api',
    ]);

    // DELETE /api/inventory/locations/{id}
    // Eliminación lógica de una ubicación
    $router->delete('/locations/{id}', [LocationController::class, 'destroy'], [
        PermissionMiddleware::class . ':inventory.location.manage',
        RateLimitMiddleware::class . ':critical',
    ]);

    // POST /api/inventory/locations/{id}/activate
    // Activa una ubicación
    $router->post('/locations/{id}/activate', [LocationController::class, 'activate'], [
        PermissionMiddleware::class . ':inventory.location.manage',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/locations/{id}/deactivate
    // Desactiva una ubicación
    $router->post('/locations/{id}/deactivate', [LocationController::class, 'deactivate'], [
        PermissionMiddleware::class . ':inventory.location.manage',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // RECEIVING (CU-001)
    // ================================================================

    // POST /api/inventory/receive
    // Recibe inventario: crea unidad física + ledger + evento RECIBIDO en una
    // sola transacción. Requiere el header Idempotency-Key (spec R7).
    $router->post('/receive', [ReceivingController::class, 'receive'], [
        PermissionMiddleware::class . ':inventory.receiving.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // STOCK (R1)
    // ================================================================

    // GET /api/inventory/stock
    // Stock actual por unidad: física, reservada, en tránsito, disponible
    $router->get('/stock', [StockController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.stock.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/stock/summary?producto_id[]=...
    // Stock total por producto en UNA consulta agregada (Sprint 6, 0b)
    $router->get('/stock/summary', [StockController::class, 'summary'], [
        PermissionMiddleware::class . ':inventory.stock.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/stock/{id}/history
    // Línea de tiempo de UNA unidad (v1.10.0, R3): fusión de stock_events y
    // movements ordenada por fecha descendente, paginada (page, per_page).
    // Debe declararse DESPUÉS de /stock/summary (matchRoute() recorre en orden).
    $router->get('/stock/{id}/history', [StockController::class, 'history'], [
        PermissionMiddleware::class . ':inventory.stock.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/stock/{id}/block
    // Bloquea la unidad (v1.10.0, E4): X → BLOQUEADO según la matriz de
    // transiciones; sin cambio de ubicación ni de cupo. Idempotency-Key opcional.
    $router->post('/stock/{id}/block', [StockController::class, 'block'], [
        PermissionMiddleware::class . ':inventory.stock.block',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/stock/{id}/unblock
    // Desbloquea la unidad (v1.10.0, E4): BLOQUEADO → DISPONIBLE. Idempotency-Key opcional.
    $router->post('/stock/{id}/unblock', [StockController::class, 'unblock'], [
        PermissionMiddleware::class . ':inventory.stock.block',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // MOVEMENTS (CU-002)
    // ================================================================

    // GET /api/inventory/movements
    // Listado paginado de movimientos (v1.10.0, R3): unidad, ubicaciones,
    // motivo, hash, es_correccion y movimiento_referencia_id. Filtros:
    // unidad_fisica_id, ubicacion_origen_id, ubicacion_destino_id, motivo,
    // es_correccion, fecha_desde/hasta, page, per_page (máx. 100).
    $router->get('/movements', [MovementController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.stock.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/movements
    // Mueve una unidad entre ubicaciones en una sola transacción: capacidad,
    // fila inmutable `movements`, ledger y evento MOVIDO (spec §3.9; hasta
    // v1.6.1 se llamaba TRASLADO). Requiere el header Idempotency-Key (R7).
    $router->post('/movements', [MovementController::class, 'create'], [
        PermissionMiddleware::class . ':inventory.movement.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // RESERVATIONS (CU-003)
    // ================================================================

    // GET /api/inventory/reservations
    // Listado paginado de reservas (v1.6.2): reserva_id, stock_unit_id,
    // barcode, producto, ubicacion, cantidad, estado, fecha_expiracion,
    // creado_por y fecha_creacion. Filtros: estado, producto_id,
    // stock_unit_id, vencimiento_desde/hasta, page, per_page (máx. 100).
    $router->get('/reservations', [ReservationController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.reservation.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/reservations
    // Reserva una unidad (estado RESERVADO + ledger + evento RESERVADO).
    // Requiere el header Idempotency-Key (spec R7).
    $router->post('/reservations', [ReservationController::class, 'store'], [
        PermissionMiddleware::class . ':inventory.reservation.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/reservations/{id}/release
    // Cancela una reserva activa: vuelve la unidad a DISPONIBLE y descuenta
    // `cantidad_reservada` del ledger. IDEMPOTENTE POR ESTADO: una segunda
    // llamada sobre una reserva ya CANCELADA devuelve 409, por eso
    // Idempotency-Key es OPCIONAL (si se envía, replay de la respuesta).
    $router->post('/reservations/{id}/release', [ReservationController::class, 'release'], [
        PermissionMiddleware::class . ':inventory.reservation.cancel',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // PICKING (CU-004)
    // ================================================================

    // GET /api/inventory/picking
    // Listado paginado de operaciones de picking (v1.7.0): lote, unidad,
    // barcode, producto, cantidad, estado, ubicaciones, selector/supervisor
    // y fechas. Filtros: estado, id_lote_picking, fecha_desde/hasta,
    // page, per_page (máx. 100).
    $router->get('/picking', [PickingController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.picking.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/picking/start
    // Inicia UN lote de picking: unidades RESERVADO → EN_PREPARACION en la
    // zona PICKING, con capacidad, ledger y evento EN_PREPARACION en una
    // sola transacción. Requiere el header Idempotency-Key (spec R7).
    $router->post('/picking/start', [PickingController::class, 'start'], [
        PermissionMiddleware::class . ':inventory.picking.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/picking/{id}/complete
    // Pasa el lote de EN_CURSO a COMPLETADA. IDEMPOTENTE POR ESTADO: un lote
    // ya completado devuelve 409, por eso Idempotency-Key es OPCIONAL.
    $router->post('/picking/{id}/complete', [PickingController::class, 'complete'], [
        PermissionMiddleware::class . ':inventory.picking.confirm',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/picking/{id}/verify
    // Supervisa el lote (COMPLETADA → VERIFICADA): el supervisor es el
    // usuario autenticado y debe ser distinto del selector (422).
    $router->post('/picking/{id}/verify', [PickingController::class, 'verify'], [
        PermissionMiddleware::class . ':inventory.picking.verify',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // SHIPPING / TRANSPORTE (CU-005, CU-006)
    // ================================================================

    // GET /api/inventory/shipments
    // Listado paginado de envíos (v1.7.0): estado, destinatario, conductor,
    // horas y total de ítems. Filtros: estado, fecha_desde/hasta,
    // page, per_page (máx. 100).
    $router->get('/shipments', [ShipmentController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.shipping.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/shipments/{id}
    // Detalle de UN envío con sus items (estado por ítem y unidades).
    $router->get('/shipments/{id}', [ShipmentController::class, 'show'], [
        PermissionMiddleware::class . ':inventory.shipping.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/shipments/load
    // Carga unidades EN_PREPARACION (picking COMPLETADA/VERIFICADA) en el
    // envío: ítem CARGADA, unidad CARGADO, ledger reservada → en_transito y
    // evento CARGADO en una sola transacción. Requiere Idempotency-Key (R7).
    $router->post('/shipments/load', [ShipmentController::class, 'load'], [
        PermissionMiddleware::class . ':inventory.shipping.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/shipments/authorize
    // Autoriza la salida (CU-006): todas las unidades CARGADAS → EN_TRANSITO
    // en la ubicación virtual TRANSITO, envío EN_RUTA con hora_salida y
    // supervisor. Requiere el header Idempotency-Key (spec R7).
    $router->post('/shipments/authorize', [ShipmentController::class, 'authorize'], [
        PermissionMiddleware::class . ':inventory.shipping.confirm',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // DELIVERIES (CU-007, CU-008) / RETURNS (CU-009) - v1.8.0
    // ================================================================

    // GET /api/inventory/deliveries
    // Listado paginado de entregas: estado, envío, unidad, cantidad, firma,
    // actor y hora de entrega. Filtros: estado, fecha_desde/hasta,
    // shipment_id, unidad_fisica_id, page, per_page (máx. 100).
    $router->get('/deliveries', [DeliveryController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.delivery.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/deliveries/confirm
    // Confirma la entrega (CU-007): unidades EN_TRANSITO → ENTREGADO con su
    // fila `deliveries`, evento ENTREGADO y ledger sin tránsito; las unidades
    // no indicadas se procesan por CU-008 en la misma transacción y el envío
    // queda ENTREGADO con `hora_entrega_real`. Requiere Idempotency-Key (R7).
    $router->post('/deliveries/confirm', [DeliveryController::class, 'confirm'], [
        PermissionMiddleware::class . ':inventory.delivery.confirm',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/deliveries/failed
    // Registra el fallo de entrega (CU-008): delivery RECHAZADA, unidad
    // DEVUELTO reubicada al origen del envío, incidencia ENTREGA_RECHAZADA,
    // evento DEVUELTO y envío FALLO_ENTREGA. Requiere Idempotency-Key (R7).
    $router->post('/deliveries/failed', [DeliveryController::class, 'failed'], [
        PermissionMiddleware::class . ':inventory.delivery.confirm',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/returns
    // Listado paginado de devoluciones: motivo, estado al devolver, destino,
    // actor y hora de devolución. Filtros: estado (estado_al_devolver),
    // motivo, fecha_desde/hasta, shipment_id, unidad_fisica_id, page,
    // per_page (máx. 100).
    $router->get('/returns', [ReturnController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.return.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/returns/register
    // Registra la devolución (CU-009): unidad DEVUELTO/ENTREGADO →
    // DISPONIBLE (destino), DAÑADO (zona DAÑADOS) o PERDIDO, con
    // ReturnOperation, evento con hash e incidencia DAÑO/PERDIDA.
    // Requiere el header Idempotency-Key (spec R7).
    $router->post('/returns/register', [ReturnController::class, 'register'], [
        PermissionMiddleware::class . ':inventory.return.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // INCIDENTS (CU-010) - v1.9.0
    //
    // ORDEN DE REGISTRO: las rutas literales (/report) se declaran ANTES
    // que las de parámetro (/incidents/{id}), porque Router::matchRoute()
    // recorre la lista en orden y la primera coincidencia gana.
    // ================================================================

    // GET /api/inventory/incidents
    // Listado paginado de incidencias: estado, tipo, unidad, ubicación y
    // fechas, más summary (abiertas/en investigación/resueltas/no resueltas).
    // Filtros: estado, tipo, unidad_fisica_id, ubicacion_id, fecha_desde,
    // fecha_hasta, page, per_page (máx. 100).
    $router->get('/incidents', [IncidentController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.incident.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/incidents/report
    // Reporta una incidencia (CU-010): crea Incident ABIERTA y, según el
    // tipo, aplica el efecto en la unidad (DAÑO → DAÑADO, PERDIDA → PERDIDO
    // con librería de cupo, DISCREPANCIA_CANTIDAD → crea un conteo
    // PENDIENTE vinculado). Requiere el header Idempotency-Key (spec R7).
    $router->post('/incidents/report', [IncidentController::class, 'report'], [
        PermissionMiddleware::class . ':inventory.incident.report',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/incidents/{id}
    // Detalle de UNA incidencia (resolución, actor, fechas y conteo vinculado)
    $router->get('/incidents/{id}', [IncidentController::class, 'show'], [
        PermissionMiddleware::class . ':inventory.incident.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/incidents/{id}/investigate
    // Pasa la incidencia de ABIERTA a INVESTIGACION. IDEMPOTENTE POR ESTADO
    // (una incidencia ya en investigación devuelve 409), por eso
    // Idempotency-Key es OPCIONAL (si se envía, replay de la respuesta).
    $router->post('/incidents/{id}/investigate', [IncidentController::class, 'investigate'], [
        PermissionMiddleware::class . ':inventory.incident.resolve',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/incidents/{id}/resolve
    // Resuelve la incidencia (RESUELTA o NO_RESUELTO) con descripción,
    // acciones tomadas y compensación opcional. v1.10.0 (E4): para
    // UBICACION_DESCONOCIDA / INVENTARIO_DESAPARECIDO exige `resultado`
    // (ENCONTRADA → unidad DISPONIBLE y posible reubicación con recálculo de
    // cupo; NO_ENCONTRADA → unidad PERDIDO sin ubicación). Para el resto de
    // tipos NO toca unidades ni cupo. Idempotency-Key opcional.
    $router->post('/incidents/{id}/resolve', [IncidentController::class, 'resolve'], [
        PermissionMiddleware::class . ':inventory.incident.resolve',
        RateLimitMiddleware::class . ':api',
    ]);

    // ================================================================
    // PHYSICAL COUNTS (CU-011) - v1.9.0
    //
    // ORDEN DE REGISTRO: /counts/start antes de /counts/{id} (mismo motivo
    // que en incidents: matchRoute() recorre en orden).
    // ================================================================

    // GET /api/inventory/counts
    // Listado paginado de conteos físicos: estado, ubicación, responsable,
    // fecha de conteo y nº de discrepancias. Filtros: estado, ubicacion_id,
    // fecha_desde, fecha_hasta, page, per_page (máx. 100).
    $router->get('/counts', [CountController::class, 'index'], [
        PermissionMiddleware::class . ':inventory.count.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/counts/start
    // Inicia UN conteo físico: instantánea de la lista teórica de la
    // ubicación (unidades presentes, sin transito ni virtuales) en estado
    // PENDIENTE. Requiere el header Idempotency-Key (spec R7).
    $router->post('/counts/start', [CountController::class, 'start'], [
        PermissionMiddleware::class . ':inventory.count.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/counts/{id}
    // Detalle de UN conteo: lista teórica, resultados, discrepancias
    // (esperada/encontrada/diferencia) y ajustes aplicados
    $router->get('/counts/{id}', [CountController::class, 'show'], [
        PermissionMiddleware::class . ':inventory.count.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // GET /api/inventory/counts/{id}/sheet[?format=csv]
    // Lista teórica para contar en almacén. JSON por defecto; con
    // `format=csv` devuelve text/csv descargable con las columnas
    // cantidad_contada y notas en blanco para rellenar a mano.
    $router->get('/counts/{id}/sheet', [CountController::class, 'sheet'], [
        PermissionMiddleware::class . ':inventory.count.view',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/counts/{id}/results
    // Registra resultados parciales por unidad: fusión por
    // unidad_fisica_id, unidad fuera de la lista teórica → 422. Pasa el
    // conteo a EN_CURSO. Sin Idempotency-Key (protección por estado).
    $router->post('/counts/{id}/results', [CountController::class, 'results'], [
        PermissionMiddleware::class . ':inventory.count.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/counts/{id}/complete
    // Completa el conteo (PENDIENTE/EN_CURSO → COMPLETADO): si falta
    // alguna unidad con resultado → 422 con el detalle; las discrepancias
    // (sólo diferencia != 0) quedan guardadas en JSON. Sin Idempotency-Key.
    $router->post('/counts/{id}/complete', [CountController::class, 'complete'], [
        PermissionMiddleware::class . ':inventory.count.create',
        RateLimitMiddleware::class . ':api',
    ]);

    // POST /api/inventory/counts/{id}/reconcile
    // Reconcilia las discrepancias (COMPLETADO → RECONCILIADO): AJUSTAR
    // (evento AJUSTE_CANTIDAD con hash, ledger R1 y capacidad recalculada;
    // cantidad 0 → unidad PERDIDO + incidencia INVENTARIO_DESAPARECIDO) o
    // JUSTIFICAR (con justificación obligatoria). Requiere Idempotency-Key
    // (spec R7) y el permiso inventory.count.reconcile.
    $router->post('/counts/{id}/reconcile', [CountController::class, 'reconcile'], [
        PermissionMiddleware::class . ':inventory.count.reconcile',
        RateLimitMiddleware::class . ':api',
    ]);

}, [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
]);
