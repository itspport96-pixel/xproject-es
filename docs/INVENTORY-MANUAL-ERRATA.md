# Manual de Prueba - Módulo Inventory

## Errata y Correcciones

### A1 — Fase 5.4: Reserva con `fecha_expiracion`

**Error en el manual original**: El manual indicaba que una reserva con `fecha_expiracion` en el pasado devuelve 201.

**Comportamiento real**: `Reservation::create()` valida que `fecha_expiracion` sea posterior a `fecha_reserva`. Si `fecha_expiracion <= fecha_reserva`, lanza `InventoryException::invalidField('fecha_expiracion', ...)` y devuelve **400**.

**Prueba correcta**:
```
Reserva con expiración FUTURA:
  POST /api/inventory/reservations
  { "unidad_fisica_id": "<uid2>", "fecha_expiracion": "2030-01-01T00:00:00" }
  Esperado: 201

Para forzar E7 (reserva expirada) sin esperar:
  UPDATE reservations SET fecha_expiracion = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
   WHERE id = '<rid>';
  php bin/expire-reservations.php
```

---

### A2 — Fase 8.1: Campo `actor_id_autoriza_salida`

**Error en el manual original**: El manual indicaba que `actor_id_autoriza_salida` debe ser el `user_id` de `admin@test`.

**Comportamiento real**: `AuthorizeShipmentUseCase::execute()` recibe `?string $actorId` desde los claims del JWT. `ShipmentController::authorize()` pasa `$claims['user_id']` del usuario autenticado que ejecuta el POST, no un admin fijo.

**Prueba correcta**:
```
Si el POST lo ejecuta super@test (rol MANAGER):
  data.shipment.actor_id_autoriza_salida = <user_id del JWT de super@test>

Si el POST lo ejecuta admin@test (rol ADMIN):
  data.shipment.actor_id_autoriza_salida = <user_id del JWT de admin@test>
```

El valor es siempre el `user_id` del usuario autenticado que realiza la autorización.

---

### A3 — Fase 15: Permisos del rol USER

**Error en el manual original**: El manual indicaba que USER tiene 13 permisos "más views".

**Comportamiento real**: `InventoryPermissionSeeder::ROLE_PERMISSIONS['USER']` asigna **17 permisos** al rol USER.

**Lista completa de permisos de USER** (17 permisos):
1. `inventory.item.view`
2. `inventory.stock.view`
3. `inventory.location.view`
4. `inventory.receiving.create`
5. `inventory.movement.create`
6. `inventory.reservation.view`
7. `inventory.reservation.create`
8. `inventory.picking.create`
9. `inventory.picking.view`
10. `inventory.shipping.create`
11. `inventory.shipping.view`
12. `inventory.delivery.view`
13. `inventory.return.view`
14. `inventory.incident.report`
15. `inventory.incident.view`
16. `inventory.count.create`
17. `inventory.count.view`

Estos 17 permisos permiten a USER: ver inventario/ubicaciones/stock, crear recepciones/movimientos/reservas/picking/envíos/conteos/incidentes, ver devoluciones/entregas, y reportar incidencias. NO puede confirmar/rechazar entregas, autorizar envíos, crear devoluciones, ni reconciliar conteos (operaciones de ADMIN/MANAGER).

---

## Notas de Implementación

Este documento recoge las correcciones al manual de prueba original identificadas durante la revisión del módulo Inventory (Sprint FIX, v1.19.9). Las pruebas deben seguir las correcciones aquí documentadas para evitar falsos negativos.
