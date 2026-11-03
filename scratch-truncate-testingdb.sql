-- Deja testingdb en: 1 tenant, 1 admin (admin@acme.local), roles BUILT_IN,
-- permissions, 2 locations fijas (TRANSITO, DANADOS) y 1 producto (PROD-001).
-- Borra el resto. Ejecuta contra `testingdb` (NO contra `xproject`).

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

DELETE FROM audit_logs;
DELETE FROM deliveries;
DELETE FROM return_operations;
DELETE FROM group_members;
DELETE FROM group_roles;
DELETE FROM idempotency_keys;
DELETE FROM incidents;
DELETE FROM inventory_counts;
DELETE FROM movements;
DELETE FROM picking_operations;
DELETE FROM shipment_items;
DELETE FROM shipments;
DELETE FROM reservations;
DELETE FROM stock_events;
DELETE FROM stock_ledger;
DELETE FROM stock_units;
DELETE FROM temporary_permissions;
DELETE FROM password_reset_tokens;
DELETE FROM password_history;
DELETE FROM mail_outbox;
DELETE FROM rate_limit_log;
DELETE FROM refresh_tokens;
DELETE FROM user_groups;

DELETE FROM users WHERE id <> 'e2e00000-0000-4000-8000-63b42434c993';
DELETE FROM role_assignments WHERE user_id <> 'e2e00000-0000-4000-8000-63b42434c993';

DELETE FROM locations WHERE codigo NOT IN ('TRANSITO', 'DANADOS');
DELETE FROM products WHERE sku <> 'PROD-001';

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- Verificación: todo en una sola consulta.
SELECT 'tenants' AS tabla, COUNT(*) AS filas FROM tenants
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'role_assignments', COUNT(*) FROM role_assignments
UNION ALL SELECT 'roles', COUNT(*) FROM roles
UNION ALL SELECT 'permissions', COUNT(*) FROM permissions
UNION ALL SELECT 'products', COUNT(*) FROM products
UNION ALL SELECT 'locations', COUNT(*) FROM locations
UNION ALL SELECT 'stock_units', COUNT(*) FROM stock_units
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs
UNION ALL SELECT 'refresh_tokens', COUNT(*) FROM refresh_tokens;