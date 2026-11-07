-- M2: Migración para añadir columna seq a audit_logs
-- Esta columna garantiza orden de inserción determinístico para la cadena de hashes
-- Ejecutar en ventana de mantenimiento si la tabla es grande (puede causar lock)

-- 1. Añadir columna seq como PRIMERA columna, AUTO_INCREMENT, UNIQUE
ALTER TABLE audit_logs 
ADD COLUMN seq BIGINT AUTO_INCREMENT UNIQUE FIRST;

-- 2. Verificar que se creó correctamente
SELECT COUNT(*) as total_rows, 
       MIN(seq) as min_seq, 
       MAX(seq) as max_seq 
FROM audit_logs;

-- 3. Opcional: Añadir índice compuesto para optimizar previousHash queries
-- (ya existe idx_tenant_timestamp, pero seq es más eficiente para ORDER BY)
-- Este índice es opcional pero recomendado si hay muchas queries de auditoría
-- ALTER TABLE audit_logs ADD INDEX idx_tenant_seq (tenant_id, seq);
