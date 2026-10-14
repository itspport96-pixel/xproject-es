# Cifrado de datos sensibles (PII)

Documento de referencia del cifrado a nivel de campo implementado en la BD `xproject`.
Sprint v1.13.38–v1.13.40.

## Algoritmos

| Uso | Algoritmo | Clave (.env) | Formato en BD |
|-----|-----------|--------------|---------------|
| Cifrado simétrico (PII) | AES-256-GCM | `ENCRYPTION_KEY` (64 hex = 32 bytes) | `base64(iv16 + tag16 + ciphertext)` |
| Índice ciego (búsqueda) | HMAC-SHA256 | `BLIND_INDEX_KEY` (64 hex) | `hex` (64 chars) |

Las dos claves son **distintas** y deben rotarse por separado.

## Campos cifrados

### Tabla `users`
- `nombre`, `apellido`, `email`, `telefono` — AES-256-GCM directo
- `email_hmac` — HMAC-SHA256 de `email` (índice ciego, UNIQUE por tenant)

### Tabla `tenants`
- `nombre_publico`, `email_contacto`, `telefono` — AES-256-GCM directo
- `nombre_interno_hmac` — HMAC-SHA256 de `nombre_interno` (índice ciego)

### Tabla `shipments`
- `destino_direccion`, `destinatario_nombre`, `conductor_nombre`, `destinatario_contacto`, `conductor_contacto` — AES-256-GCM directo

### Tabla `deliveries`
- `destinatario_real`, `firma_comprobante`, `observaciones` — AES-256-GCM directo

### Tabla `incidents`
- `descripcion`, `observaciones` — AES-256-GCM directo
- `resolucion`, `restriccion_resolucion` — AES-256-GCM envuelto en `{"__encrypted":"<base64>"}` (la columna tiene `CHECK (json_valid(...))`)

### Tabla `audit_logs`
- `ip_address`, `user_agent` — AES-256-GCM directo
- `valores_anteriores`, `valores_nuevos`, `detalles_adicionales` — AES-256-GCM envuelto en `{"__encrypted":"<base64>"}` (la columna tiene `CHECK (json_valid(...))`)

### Tabla `rate_limit_log`
- `ip_address` — AES-256-GCM directo

### Tabla `refresh_tokens`
- `ip_address` — AES-256-GCM directo

### Tabla `secrets_access_log`
- `ip_address` — AES-256-GCM directo

## Patrón de envoltorio JSON (`__encrypted`)

Las columnas JSON con `CHECK (json_valid(...))` no pueden almacenar ciphertext
directo (base64 no es JSON válido). Se usa un wrapper:

```json
{"__encrypted": "<base64(iv + tag + ciphertext)>"}
```

Al leer, el código detecta la clave `__encrypted` y descifra el valor; si no
está presente, devuelve el valor tal cual (fallback pre-migración).

## Patrón de lectura con fallback

Todos los repositorios y query services usan try/catch al descifrar. Si el
valor no es ciphertext válido (registros antiguos en claro), se devuelve
sin modificar. Esto permite migración gradual sin romper lecturas.

## Índices ciegos (HMAC)

Para búsquedas por email/nombre interno sin descifrar:
- `users.email_hmac` + índice UNIQUE `(tenant_id, email_hmac)`
- `tenants.nombre_interno_hmac` + índice UNIQUE `(nombre_interno_hmac)`

Soft delete pone el HMAC a `NULL` para permitir reutilizar el identificador.
`TenantRepository::restore` recomputa el HMAC.

## Audit chain y cifrado

El hash de integridad (`hash_integridad`) se calcula **sobre el contenido en
claro** ANTES de cifrar. Al verificar la cadena en la BD viva hay que descifrar
los campos JSON primero.

## Cómo añadir un campo nuevo

1. Añadir el campo a `PII_FIELDS` (texto) o `PII_JSON_FIELDS` (JSON con
   CHECK) del repositorio correspondiente.
2. Añadir decrypt en el query service o método de lectura.
3. Ejecutar backfill con `--dry-run` primero, luego real.
4. Verificar con `bin/verify-decrypt.php` (si existe) o consulta manual.
5. Regenerar schema si hubo ALTER: `php bin/regenerate-schema.php`.

## Rotación de claves

1. Generar nueva clave: `bin6hex(random_bytes(32))`
2. Actualizar `.env` con la nueva clave.
3. Descifrar todos los datos con la clave vieja y recifrar con la nueva
   (script dedicado, no implementado aún).
4. Los índices HMAC se recalculan con la nueva `BLIND_INDEX_KEY`.

## Pérdida de clave

Si se pierde `ENCRYPTION_KEY`, los campos cifrados son **irrecuperables**.
Si se pierde `BLIND_INDEX_KEY`, se pierden los índices de búsqueda (se
recalculan descifrando emails con la clave de cifrado y re-hasheando).

## Recuperación de backup

El backup pre-migración (`database/backups/backup-pre-cierre-cifrado.sql`)
contiene datos en claro. Para restaurarlo: importar el SQL y ejecutar los
backfills de nuevo (`bin/b2-backfill.php`, `bin/b3-backfill.php`).
