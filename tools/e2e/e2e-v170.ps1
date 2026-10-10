$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido (v1.8.3)
$script:failures = 0

function Show($label, $value) { Write-Output ("{0}: {1}" -f $label, $value) }
function Ok($label, $value) { Show $label "OK  $value" }
function Bad($label, $value) { $script:failures++; Show $label "FALLO  $value" }
function StatusCode($e) { if ($e.Exception.Response) { [int]$e.Exception.Response.StatusCode } else { '?' } }

function Login($email, $password) {
    try {
        $r = Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType 'application/json' `
            -Body (@{ email = $email; password = $password } | ConvertTo-Json) -TimeoutSec 15
        return $r.data.tokens.access_token
    } catch {
        Show "LOGIN $email" "ERROR $(StatusCode $_) $($_.Exception.Message)"
        return $null
    }
}

# ---------------------------------------------------------------- 1) actores
$tokenA = Login 'aleon@acme.local' 'Admin123!'
if (-not $tokenA) { Bad 'LOGIN aleon' 'sin token'; exit 1 }
Ok 'LOGIN aleon@acme.local' "token len=$($tokenA.Length)"

$tokenB = Login 'manager@acme.local' 'Admin123!'
if (-not $tokenB) { Bad 'LOGIN 2do actor' 'sin token' }
else { Ok 'LOGIN manager@acme.local (2do actor)' "token len=$($tokenB.Length)" }

$hA = @{ Authorization = "Bearer $tokenA" }
$hB = @{ Authorization = "Bearer $tokenB" }

# --------------------------------------------------- 2) zonas PICKING/TRANSITO
function FindLocation($headers, $tipo) {
    try {
        $r = Invoke-RestMethod -Uri "$base/inventory/locations?tipo=$tipo&activa=1&page=1&per_page=50" -Headers $headers -TimeoutSec 15
        $rows = @($r.data.data)
        if ($rows.Count -gt 0) { return $rows[0] }
    } catch { Show "GET locations?tipo=$tipo" "ERROR $(StatusCode $_) $($_.Exception.Message)" }
    return $null
}

$transito = FindLocation $hA 'TRANSITO'
if (-not $transito) {
    try {
        $r = Invoke-RestMethod -Uri "$base/inventory/locations" -Method Post -Headers $hA `
            -ContentType 'application/json' `
            -Body (@{ codigo = 'TRANSITO-E2E'; nombre = 'Transito E2E v1.7.0'; tipo = 'TRANSITO' } | ConvertTo-Json) `
            -TimeoutSec 15
        $transito = $r.data.location
        Ok 'POST /inventory/locations (TRANSITO)' "id=$($transito.id) codigo=$($transito.codigo)"
    } catch {
        Bad 'POST /inventory/locations (TRANSITO)' "$(StatusCode $_) $($_.Exception.Message)"
        $transito = FindLocation $hA 'TRANSITO'
    }
} else {
    Ok 'GET locations (TRANSITO)' "id=$($transito.id) codigo=$($transito.codigo)"
}

$pick = $null
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/locations" -Method Post -Headers $hA `
        -ContentType 'application/json' `
        -Body (@{ codigo = 'PICK-E2E'; nombre = 'Zona picking E2E'; tipo = 'PICKING'; capacidad = 20 } | ConvertTo-Json) `
        -TimeoutSec 15
    $pick = $r.data.location
    Ok 'POST /inventory/locations (PICKING)' "id=$($pick.id) codigo=$($pick.codigo)"
} catch {
    $pick = FindLocation $hA 'PICKING'
    if ($pick) { Ok 'POST /inventory/locations (PICKING)' "ya existia id=$($pick.id)" }
    else { Bad 'POST /inventory/locations (PICKING)' "$(StatusCode $_) $($_.Exception.Message)" }
}
if (-not $pick) { exit 1 }

# ------------------------------------------------------------- 3) recepcion
# Dataset vivo (cambio externo, ver CHANGELOG 1.8.1): el SKU de referencia es
# PROD-001 y la ubicacion de recepcion se resuelve en tiempo de ejecucion
# (primera ALMACENAMIENTO activa, prefiriendo la que no tiene capacidad tope).
$sku = 'PROD-001'
$ubicacionOrigen = $null
try {
    $locs = Invoke-RestMethod -Uri "$base/inventory/locations?tipo=ALMACENAMIENTO&activa=1&page=1&per_page=50" -Headers $hA -TimeoutSec 15
    $cands = @($locs.data.data)
    $prefer = $cands | Where-Object { $null -eq $_.capacidad } | Select-Object -First 1
    if (-not $prefer) { $prefer = $cands | Select-Object -First 1 }
    if ($prefer) { $ubicacionOrigen = $prefer.codigo }
} catch { Bad 'fixture recepcion' "$(StatusCode $_) $($_.Exception.Message)" }
if (-not $ubicacionOrigen) { Bad 'fixture recepcion' 'sin ubicacion ALMACENAMIENTO activa'; exit 1 }
$unidad = $null
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/receive" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = "e2e-rcv-$([guid]::NewGuid().ToString('N'))" } `
        -ContentType 'application/json' `
        -Body (@{ sku = $sku; cantidad_esperada = 1; ubicacion_codigo = $ubicacionOrigen } | ConvertTo-Json) `
        -TimeoutSec 15
    $unidad = $r.data.unidad_fisica_id
    Ok 'POST /inventory/receive' "unidad=$unidad sku=$sku ubicacion=$ubicacionOrigen"
} catch {
    Bad 'POST /inventory/receive' "$(StatusCode $_) $($_.Exception.Message)"
    exit 1
}

# ------------------------------------------------------------- 4) reserva
$reserva = $null
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/reservations" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = "e2e-rsv-$([guid]::NewGuid().ToString('N'))" } `
        -ContentType 'application/json' `
        -Body (@{ unidad_fisica_id = $unidad } | ConvertTo-Json) -TimeoutSec 15
    $reserva = $r.data.reserva.reserva_id
    Ok 'POST /inventory/reservations' "reserva=$reserva estado=$($r.data.reserva.estado)"
} catch {
    Bad 'POST /inventory/reservations' "$(StatusCode $_) $($_.Exception.Message)"
    exit 1
}

# -------------------------------------------------------- 5) iniciar picking
$lote = "E2E-$([guid]::NewGuid().ToString('N').Substring(0, 8))"
$startBody = @{
    id_lote_picking = $lote
    ubicacion_preparacion_id = $pick.id
    unidades = @(@{ unidad_fisica_id = $unidad; cantidad = 1 })
    referencia_orden = 'SO-E2E-170'
} | ConvertTo-Json -Depth 5
$startKey = "e2e-pk-$([guid]::NewGuid().ToString('N'))"

try {
    $r = Invoke-RestMethod -Uri "$base/inventory/picking/start" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $startKey } `
        -ContentType 'application/json' -Body $startBody -TimeoutSec 15
    Ok 'POST /inventory/picking/start' "201 lote=$lote operaciones=$(@($r.data.operaciones).Count) estado=$($r.data.operaciones[0].estado)"
} catch {
    Bad 'POST /inventory/picking/start' "$(StatusCode $_) $($_.Exception.Message)"
    exit 1
}

# replay idempotente (misma clave + mismo payload)
try {
    $r2 = Invoke-RestMethod -Uri "$base/inventory/picking/start" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $startKey } `
        -ContentType 'application/json' -Body $startBody -TimeoutSec 15
    if ($r2.data.operaciones[0].picking_id -eq $r.data.operaciones[0].picking_id) {
        Ok 'REPLAY picking/start' "misma picking_id=$($r2.data.operaciones[0].picking_id)"
    } else {
        Bad 'REPLAY picking/start' 'picking_id distinto'
    }
} catch { Bad 'REPLAY picking/start' "$(StatusCode $_) $($_.Exception.Message)" }

# sin Idempotency-Key -> 400
try {
    Invoke-RestMethod -Uri "$base/inventory/picking/start" -Method Post -Headers $hA `
        -ContentType 'application/json' -Body $startBody -TimeoutSec 15 | Out-Null
    Bad 'picking/start sin clave' 'debia devolver 400'
} catch {
    $code = StatusCode $_
    if ($code -eq 400) { Ok 'picking/start sin Idempotency-Key' '400' }
    else { Bad 'picking/start sin clave' "codigo $code" }
}

# ------------------------------------------------------------- 6) listados
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/picking?estado=EN_CURSO&page=1&per_page=10" -Headers $hB -TimeoutSec 15
    $rows = @($r.data.data)
    $match = @($rows | Where-Object { $_.id_lote_picking -eq $lote })
    Ok 'GET /inventory/picking (2do actor)' "200 total=$($r.data.pagination.total) en_curso=$($r.data.summary.en_curso) loteLocalizado=$($match.Count)"
    if ($match.Count -gt 0) {
        Show '  fila' "selector=$($match[0].selector) origen=$($match[0].ubicacion_origen) preparacion=$($match[0].ubicacion_preparacion) producto=$($match[0].producto)"
    } else { Bad 'GET /inventory/picking' "lote $lote no aparece" }
} catch { Bad 'GET /inventory/picking' "$(StatusCode $_) $($_.Exception.Message)" }

# ------------------------------------------------------------- 7) completar
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/picking/$lote/complete" -Method Post -Headers $hA `
        -ContentType 'application/json' -Body '{}' -TimeoutSec 15
    Ok 'POST /picking/{lote}/complete' "200 estado=$($r.data.estado)"
} catch { Bad 'POST /picking/{lote}/complete' "$(StatusCode $_) $($_.Exception.Message)" }

# completar dos veces -> 409
try {
    Invoke-RestMethod -Uri "$base/inventory/picking/$lote/complete" -Method Post -Headers $hA `
        -ContentType 'application/json' -Body '{}' -TimeoutSec 15 | Out-Null
    Bad 'complete repetido' 'debia devolver 409'
} catch {
    $code = StatusCode $_
    if ($code -eq 409) { Ok 'complete repetido' '409' } else { Bad 'complete repetido' "codigo $code" }
}

# ------------------------------------------------------- 8) verificar (422 y 200)
try {
    Invoke-RestMethod -Uri "$base/inventory/picking/$lote/verify" -Method Post -Headers $hA `
        -ContentType 'application/json' -Body '{}' -TimeoutSec 15 | Out-Null
    Bad 'verify como selector' 'debia devolver 422 (supervisor = selector)'
} catch {
    $code = StatusCode $_
    if ($code -eq 422) { Ok 'verify con supervisor = selector' '422 actor_id_supervisor' }
    else { Bad 'verify como selector' "codigo $code" }
}

if ($tokenB) {
    try {
        $r = Invoke-RestMethod -Uri "$base/inventory/picking/$lote/verify" -Method Post -Headers $hB `
            -ContentType 'application/json' -Body '{}' -TimeoutSec 15
        Ok 'POST /picking/{lote}/verify (2do actor)' "200 estado=$($r.data.estado) supervisor=$($r.data.actor_id_supervisor)"
    } catch { Bad 'POST /picking/{lote}/verify' "$(StatusCode $_) $($_.Exception.Message)" }
}

# ------------------------------------------------------------- 9) cargar envio
$shipmentId = $null
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/shipments/load" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = "e2e-ld-$([guid]::NewGuid().ToString('N'))" } `
        -ContentType 'application/json' `
        -Body (@{
            unidades = @(@{ unidad_fisica_id = $unidad; cantidad = 1 })
            shipment = @{
                referencia_externa = 'E2E-TR-170'
                destinatario_nombre = 'ACME SA'
                conductor_nombre = 'Chofer E2E'
                tipo = 'PROPIO'
            }
        } | ConvertTo-Json -Depth 5) -TimeoutSec 15
    $shipmentId = $r.data.shipment.shipment_id
    Ok 'POST /inventory/shipments/load' "201 shipment=$shipmentId estado=$($r.data.shipment.estado) items=$(@($r.data.items).Count) item=$($r.data.items[0].estado)"
} catch {
    Bad 'POST /inventory/shipments/load' "$(StatusCode $_) $($_.Exception.Message)"
    exit 1
}

# sin Idempotency-Key -> 400
try {
    Invoke-RestMethod -Uri "$base/inventory/shipments/load" -Method Post -Headers $hA `
        -ContentType 'application/json' -Body (@{ unidades = @(@{ unidad_fisica_id = $unidad }) } | ConvertTo-Json -Depth 5) `
        -TimeoutSec 15 | Out-Null
    Bad 'shipments/load sin clave' 'debia devolver 400'
} catch {
    $code = StatusCode $_
    if ($code -eq 400) { Ok 'shipments/load sin Idempotency-Key' '400' }
    else { Bad 'shipments/load sin clave' "codigo $code" }
}

# ------------------------------------------------------------- 10) listados envios
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/shipments?estado=PREPARACION&page=1&per_page=10" -Headers $hB -TimeoutSec 15
    $rows = @($r.data.data)
    $match = @($rows | Where-Object { $_.shipment_id -eq $shipmentId })
    Ok 'GET /inventory/shipments (2do actor)' "200 total=$($r.data.pagination.total) preparacion=$($r.data.summary.en_preparacion) localizado=$($match.Count)"
    if ($match.Count -gt 0) { Show '  fila' "destinatario=$($match[0].destinatario_nombre) items=$($match[0].total_items) estado=$($match[0].estado)" }
} catch { Bad 'GET /inventory/shipments' "$(StatusCode $_) $($_.Exception.Message)" }

try {
    $r = Invoke-RestMethod -Uri "$base/inventory/shipments/$shipmentId" -Headers $hB -TimeoutSec 15
    Ok 'GET /inventory/shipments/{id}' "200 estado=$($r.data.shipment.estado) items=$(@($r.data.items).Count) item_estado=$($r.data.items[0].estado)"
} catch { Bad 'GET /inventory/shipments/{id}' "$(StatusCode $_) $($_.Exception.Message)" }

# ------------------------------------------------------------ 11) autorizar salida
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/shipments/authorize" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = "e2e-az-$([guid]::NewGuid().ToString('N'))" } `
        -ContentType 'application/json' `
        -Body (@{ shipment_id = $shipmentId } | ConvertTo-Json) -TimeoutSec 15
    Ok 'POST /inventory/shipments/authorize' "200 estado=$($r.data.shipment.estado) salida=$($r.data.shipment.hora_salida) transito=$($r.data.ubicacion_transito.codigo) unidad=$($r.data.unidades[0].estado)"
} catch { Bad 'POST /inventory/shipments/authorize' "$(StatusCode $_) $($_.Exception.Message)" }

# autorizar dos veces -> 409
try {
    Invoke-RestMethod -Uri "$base/inventory/shipments/authorize" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = "e2e-az2-$([guid]::NewGuid().ToString('N'))" } `
        -ContentType 'application/json' -Body (@{ shipment_id = $shipmentId } | ConvertTo-Json) -TimeoutSec 15 | Out-Null
    Bad 'authorize repetido' 'debia devolver 409'
} catch {
    $code = StatusCode $_
    if ($code -eq 409) { Ok 'authorize repetido' '409' } else { Bad 'authorize repetido' "codigo $code" }
}

# sin Idempotency-Key -> 400
try {
    Invoke-RestMethod -Uri "$base/inventory/shipments/authorize" -Method Post -Headers $hA `
        -ContentType 'application/json' -Body (@{ shipment_id = $shipmentId } | ConvertTo-Json) -TimeoutSec 15 | Out-Null
    Bad 'authorize sin clave' 'debia devolver 400'
} catch {
    $code = StatusCode $_
    if ($code -eq 400) { Ok 'authorize sin Idempotency-Key' '400' }
    else { Bad 'authorize sin clave' "codigo $code" }
}

# ------------------------------------------------------------ 12) estado final BD
$env:PATH = "C:\xampp\mysql\bin;$env:PATH"
$sql = @"
SELECT 'picking' AS tabla, id_lote_picking AS ref, estado, actor_id_selector, actor_id_supervisor
FROM picking_operations
WHERE id_lote_picking = '$lote'
UNION ALL
SELECT 'shipment', id, estado, actor_id_autoriza_salida, hora_salida
FROM shipments
WHERE id = '$shipmentId';
"@
try { Show 'BD picking/shipment' ''; & mysql.exe -u root $e2eDb -e $sql | ForEach-Object { Write-Output ("  " + $_) } }
catch { Bad 'BD picking/shipment' $_.Exception.Message }

$sql2 = @"
SELECT s.estado, u.estado, l.estado_actual, l.cantidad_reservada, l.cantidad_en_transito, l.cantidad_disponible
FROM stock_units u
JOIN stock_ledger l ON l.unidad_fisica_id = u.id
JOIN shipments s ON s.id = '$shipmentId'
WHERE u.id = '$unidad';
"@
try { Show 'BD unidad/ledger' ''; & mysql.exe -u root $e2eDb -e $sql2 | ForEach-Object { Write-Output ("  " + $_) } }
catch { Bad 'BD unidad/ledger' $_.Exception.Message }

$sql3 = @"
SELECT operacion, recurso, resultado, entidad_id, tipo_cambio
FROM audit_logs
WHERE operacion IN ('picking.start','picking.complete','picking.verify','shipment.load','shipment.authorize')
ORDER BY id DESC LIMIT 10;
"@
try { Show 'audit_logs (escrituras v1.7.0)'; & mysql.exe -u root $e2eDb -e $sql3 | ForEach-Object { Write-Output ("  " + $_) } }
catch { Bad 'audit_logs' $_.Exception.Message }

$sql4 = @"
SELECT COUNT(*) AS claves FROM idempotency_keys
WHERE idempotency_key LIKE 'e2e-%' AND operacion IN ('inventory.picking.start','inventory.shipment.load','inventory.shipment.authorize');
"@
try { Show 'idempotency_keys'; & mysql.exe -u root $e2eDb -e $sql4 | ForEach-Object { Write-Output ("  " + $_) } }
catch { Show 'idempotency_keys' "ERROR $($_.Exception.Message)" }

Write-Output ""
if ($script:failures -eq 0) { Write-Output 'E2E v1.7.0: TODO OK' } else { Write-Output "E2E v1.7.0: $script:failures FALLOS" }

# v1.8.3: el servidor PHP embebido se detiene al terminar cada E2E.
Stop-E2EServer
