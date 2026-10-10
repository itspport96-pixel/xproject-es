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

# Filas de auditoria que mencionan una Idempotency-Key concreta:
# dominio (receiving.create / picking.start / shipment.load) + HTTP (AuditMiddleware).
function AuditCount($key) {
    $sql = "SELECT COUNT(*) FROM audit_logs WHERE detalles_adicionales LIKE '%$key%'"
        $v = & mysql.exe -u root $e2eDb -N -e $sql | Select-Object -First 1
    if ($null -eq $v) { return -1 }
    return [int]("$v".Trim())
}

function Sql($query) { & mysql.exe -u root $e2eDb -e $query | ForEach-Object { Write-Output ("  " + $_) } }

$env:PATH = "C:\xampp\mysql\bin;$env:PATH"

# ---------------------------------------------------------------- 1) actores
$tokenA = Login 'aleon@acme.local' 'Admin123!'
if (-not $tokenA) { Bad 'LOGIN aleon' 'sin token'; exit 1 }
Ok 'LOGIN aleon@acme.local' "token len=$($tokenA.Length)"

$tokenB = Login 'manager@acme.local' 'Admin123!'
if (-not $tokenB) { Bad 'LOGIN 2do actor' 'sin token'; exit 1 }
Ok 'LOGIN manager@acme.local (2do actor)' "token len=$($tokenB.Length)"

$hA = @{ Authorization = "Bearer $tokenA" }

# --------------------------------------- 2) A1: ubicacion TRANSITO por tenant
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/locations?tipo=TRANSITO&page=1&per_page=50" -Headers $hA -TimeoutSec 15
    $rows = @($r.data.data)
    if ($rows.Count -gt 0) {
        $transito = $rows[0]
        Ok 'GET locations?tipo=TRANSITO' "id=$($transito.id) codigo=$($transito.codigo) activa=$($transito.activa)"
    } else {
        Bad 'GET locations?tipo=TRANSITO' 'sin ubicacion TRANSITO para el tenant'
        $transito = $null
    }
} catch { Bad 'GET locations?tipo=TRANSITO' "$(StatusCode $_) $($_.Exception.Message)"; $transito = $null }

# alta de un tenant nuevo: debe nacer con su TRANSITO (y se limpia despues)
try {
    & php (Join-Path $PSScriptRoot 'verify-tenant-transit.php') 2>&1 | ForEach-Object { Show '  alta tenant' $_ }
    if ($LASTEXITCODE -eq 0) { Ok 'Alta de tenant crea TRANSITO' 'verify-tenant-transit.php' }
    else { Bad 'Alta de tenant crea TRANSITO' "exit $LASTEXITCODE" }
} catch { Bad 'Alta de tenant crea TRANSITO' $_.Exception.Message }

# semilla CLI para tenants ya existentes (idempotente)
try {
    $out = & php "C:\xampp\htdocs\xp\bin\seed-transit-locations.php" 2>&1
    $out | ForEach-Object { Show '  seed-transit-locations' $_ }
    if ($LASTEXITCODE -eq 0) { Ok 'CLI seed-transit-locations' 'exit 0' }
    else { Bad 'CLI seed-transit-locations' "exit $LASTEXITCODE" }
} catch { Bad 'CLI seed-transit-locations' $_.Exception.Message }

# ------------------------------------------------------ 3) zona de preparacion
$pick = $null
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/locations?tipo=PICKING&page=1&per_page=50" -Headers $hA -TimeoutSec 15
    $rows = @($r.data.data | Where-Object { $_.activa -eq $true -or $_.activa -eq 1 })
    if ($rows.Count -gt 0) { $pick = $rows[0] }
} catch { Show 'GET locations?tipo=PICKING' "ERROR $(StatusCode $_)" }

if (-not $pick) {
    try {
        $r = Invoke-RestMethod -Uri "$base/inventory/locations" -Method Post -Headers $hA `
            -ContentType 'application/json' `
            -Body (@{ codigo = 'PICK-E2E'; nombre = 'Zona picking E2E'; tipo = 'PICKING'; capacidad = 20 } | ConvertTo-Json) `
            -TimeoutSec 15
        $pick = $r.data.location
    } catch { Bad 'POST /inventory/locations (PICKING)' "$(StatusCode $_) $($_.Exception.Message)" }
}
if (-not $pick) { Bad 'zona PICKING' 'no disponible'; exit 1 }
Ok 'zona PICKING' "id=$($pick.id) codigo=$($pick.codigo)"

# ------------------------------------------------------------- 4) recepcion
# Dataset vivo (cambio externo, ver CHANGELOG 1.8.1): SKU de referencia
# PROD-001 y ubicacion de recepcion resuelta en tiempo de ejecucion.
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
$rcvKey = "v171-rcv-$([guid]::NewGuid().ToString('N'))"
$rcvBody = (@{ sku = $sku; cantidad_esperada = 1; ubicacion_codigo = $ubicacionOrigen } | ConvertTo-Json)

try {
    $w = Invoke-WebRequest -Uri "$base/inventory/receive" -Method Post -UseBasicParsing `
        -Headers @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $rcvKey } `
        -ContentType 'application/json' -Body $rcvBody -TimeoutSec 15
    $unidad = (ConvertFrom-Json $w.Content).data.unidad_fisica_id
    Ok 'POST /inventory/receive' "201 unidad=$unidad key=$rcvKey"
} catch { Bad 'POST /inventory/receive' "$(StatusCode $_) $($_.Exception.Message)"; exit 1 }

# ------------------------------------------------------------- 5) reserva
$rsvKey = "v171-rsv-$([guid]::NewGuid().ToString('N'))"
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/reservations" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $rsvKey } `
        -ContentType 'application/json' -Body (@{ unidad_fisica_id = $unidad } | ConvertTo-Json) -TimeoutSec 15
    $reserva = $r.data.reserva.reserva_id
    Ok 'POST /inventory/reservations' "reserva=$reserva estado=$($r.data.reserva.estado)"
} catch { Bad 'POST /inventory/reservations' "$(StatusCode $_) $($_.Exception.Message)"; exit 1 }

# -------------------------------------------------------- 6) iniciar picking
$lote = "V171-$([guid]::NewGuid().ToString('N').Substring(0, 8))"
$startBody = (@{
    id_lote_picking = $lote
    ubicacion_preparacion_id = $pick.id
    unidades = @(@{ unidad_fisica_id = $unidad; cantidad = 1 })
    referencia_orden = 'SO-V171'
} | ConvertTo-Json -Depth 5)
$pkKey = "v171-pk-$([guid]::NewGuid().ToString('N'))"

try {
    $w1 = Invoke-WebRequest -Uri "$base/inventory/picking/start" -Method Post -UseBasicParsing `
        -Headers @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $pkKey } `
        -ContentType 'application/json' -Body $startBody -TimeoutSec 15
    Ok 'POST /inventory/picking/start' "201 lote=$lote status=$($w1.StatusCode)"
} catch { Bad 'POST /inventory/picking/start' "$(StatusCode $_) $($_.Exception.Message)"; exit 1 }

# ------------------------------------------------------------ 7) A3: replay
$afterFirstPk = AuditCount $pkKey
$afterFirstRcv = AuditCount $rcvKey

try {
    $w2 = Invoke-WebRequest -Uri "$base/inventory/picking/start" -Method Post -UseBasicParsing `
        -Headers @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $pkKey } `
        -ContentType 'application/json' -Body $startBody -TimeoutSec 15
    if ($w1.Content -eq $w2.Content) { Ok 'REPLAY picking/start (body identico)' "len=$($w2.Content.Length)" }
    else { Bad 'REPLAY picking/start (body identico)' 'el cuerpo difiere de la primera respuesta' }
    if ($w2.Content -match '_replay') { Bad 'REPLAY body sin _replay' 'el marcador interno se ha colado en el JSON' }
    else { Ok 'REPLAY body sin _replay' 'marcador interno fuera del JSON' }
} catch { Bad 'REPLAY picking/start' "$(StatusCode $_) $($_.Exception.Message)" }

try {
    $w3 = Invoke-WebRequest -Uri "$base/inventory/receive" -Method Post -UseBasicParsing `
        -Headers @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $rcvKey } `
        -ContentType 'application/json' -Body $rcvBody -TimeoutSec 15
    if ($w3.Content -match '_replay') { Bad 'REPLAY receive sin _replay' 'marcador colado' }
    else { Ok 'REPLAY receive sin _replay' 'marcador interno fuera del JSON' }
} catch { Bad 'REPLAY receive' "$(StatusCode $_) $($_.Exception.Message)" }

$afterReplayPk = AuditCount $pkKey
$afterReplayRcv = AuditCount $rcvKey

if ($afterFirstPk -eq 2 -and $afterReplayPk -eq 2) {
    Ok 'AUDIT picking.start no se duplica' "filas con clave $pkKey = $afterReplayPk (1 dominio + 1 HTTP)"
} else { Bad 'AUDIT picking.start no se duplica' "antes=$afterFirstPk despues=$afterReplayPk (esperado 2)" }

if ($afterFirstRcv -eq 2 -and $afterReplayRcv -eq 2) {
    Ok 'AUDIT receiving.create no se duplica' "filas con clave $rcvKey = $afterReplayRcv (1 dominio + 1 HTTP)"
} else { Bad 'AUDIT receiving.create no se duplica' "antes=$afterFirstRcv despues=$afterReplayRcv (esperado 2)" }

# --------------------------------------------------- 8) A2: reserva consumida
try {
    $sql = "SELECT estado FROM reservations WHERE id = '$reserva'"
    $out = & mysql.exe -u root $e2eDb -N -e $sql
    if ("$out" -eq 'COMPLETADA') { Ok 'A2 reserva al iniciar picking' 'estado=COMPLETADA' }
    else { Bad 'A2 reserva al iniciar picking' "estado=$out (esperado COMPLETADA)" }
} catch { Bad 'A2 reserva al iniciar picking' $_.Exception.Message }

try {
    $out = & php "C:\xampp\htdocs\xp\bin\expire-reservations.php" 2>&1
    $out | ForEach-Object { Show '  expire-reservations' $_ }
    if ($LASTEXITCODE -eq 0) { Ok 'CLI expire-reservations' 'exit 0 (sin errores en bloque)' }
    else { Bad 'CLI expire-reservations' "exit $LASTEXITCODE" }
} catch { Bad 'CLI expire-reservations' $_.Exception.Message }

try {
    $sql = "SELECT estado FROM stock_units WHERE id = '$unidad'"
    $out = & mysql.exe -u root $e2eDb -N -e $sql
    if ("$out" -eq 'EN_PREPARACION') { Ok 'A2 unidad tras E7' 'estado=EN_PREPARACION (intacta)' }
    else { Bad 'A2 unidad tras E7' "estado=$out" }
} catch { Bad 'A2 unidad tras E7' $_.Exception.Message }

# --------------------------------------------------------- 9) completar/verificar
try {
    $r = Invoke-RestMethod -Uri "$base/inventory/picking/$lote/complete" -Method Post -Headers $hA `
        -ContentType 'application/json' -Body '{}' -TimeoutSec 15
    Ok 'POST /picking/{lote}/complete' "200 estado=$($r.data.estado)"
} catch { Bad 'POST /picking/{lote}/complete' "$(StatusCode $_) $($_.Exception.Message)"; exit 1 }

try {
    $r = Invoke-RestMethod -Uri "$base/inventory/picking/$lote/verify" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenB" } -ContentType 'application/json' -Body '{}' -TimeoutSec 15
    Ok 'POST /picking/{lote}/verify (2do actor)' "200 estado=$($r.data.estado)"
} catch { Bad 'POST /picking/{lote}/verify' "$(StatusCode $_) $($_.Exception.Message)"; exit 1 }

# ------------------------------------------------------------ 10) cargar envio
$ldKey = "v171-ld-$([guid]::NewGuid().ToString('N'))"
$ldBody = (@{
    unidades = @(@{ unidad_fisica_id = $unidad; cantidad = 1 })
    shipment = @{
        referencia_externa = 'V171-TR'
        destinatario_nombre = 'ACME SA'
        conductor_nombre = 'Chofer v171'
        tipo = 'PROPIO'
    }
} | ConvertTo-Json -Depth 5)

try {
    $wl1 = Invoke-WebRequest -Uri "$base/inventory/shipments/load" -Method Post -UseBasicParsing `
        -Headers @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $ldKey } `
        -ContentType 'application/json' -Body $ldBody -TimeoutSec 15
    $shipmentId = (ConvertFrom-Json $wl1.Content).data.shipment.shipment_id
    Ok 'POST /inventory/shipments/load' "201 shipment=$shipmentId"
} catch { Bad 'POST /inventory/shipments/load' "$(StatusCode $_) $($_.Exception.Message)"; exit 1 }

$afterFirstLd = AuditCount $ldKey

try {
    $wl2 = Invoke-WebRequest -Uri "$base/inventory/shipments/load" -Method Post -UseBasicParsing `
        -Headers @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $ldKey } `
        -ContentType 'application/json' -Body $ldBody -TimeoutSec 15
    if ($wl1.Content -eq $wl2.Content) { Ok 'REPLAY shipments/load (body identico)' "len=$($wl2.Content.Length)" }
    else { Bad 'REPLAY shipments/load (body identico)' 'el cuerpo difiere' }
    if ($wl2.Content -match '_replay') { Bad 'REPLAY load sin _replay' 'marcador colado' }
    else { Ok 'REPLAY load sin _replay' 'marcador interno fuera del JSON' }
} catch { Bad 'REPLAY shipments/load' "$(StatusCode $_) $($_.Exception.Message)" }

$afterReplayLd = AuditCount $ldKey
if ($afterFirstLd -eq 2 -and $afterReplayLd -eq 2) {
    Ok 'AUDIT shipment.load no se duplica' "filas con clave $ldKey = $afterReplayLd"
} else { Bad 'AUDIT shipment.load no se duplica' "antes=$afterFirstLd despues=$afterReplayLd (esperado 2)" }

# --------------------------- 11) A1: sin TRANSITO -> 404; semilla -> 200
$azKeyFail = "v171-azf-$([guid]::NewGuid().ToString('N'))"
$azKeyOk = "v171-azo-$([guid]::NewGuid().ToString('N'))"

try { & mysql.exe -u root $e2eDb -e "UPDATE locations SET activa = 0 WHERE tipo = 'TRANSITO'"; Show 'UPDATE' 'TRANSITO desactivada para el tenant' }
catch { Bad 'desactivar TRANSITO' $_.Exception.Message }

try {
    Invoke-RestMethod -Uri "$base/inventory/shipments/authorize" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $azKeyFail } `
        -ContentType 'application/json' -Body (@{ shipment_id = $shipmentId } | ConvertTo-Json) -TimeoutSec 15 | Out-Null
    Bad 'authorize sin TRANSITO' 'debia devolver 404 transitLocationMissing'
} catch {
    $code = StatusCode $_
    $msg = $_.ErrorDetails.Message
    if ($code -eq 404 -and "$msg" -match 'ubicacion_transito_id') { Ok 'authorize sin TRANSITO' '404 field=ubicacion_transito_id' }
    else { Bad 'authorize sin TRANSITO' "codigo $code $msg" }
}

try {
    $out = & php "C:\xampp\htdocs\xp\bin\seed-transit-locations.php" 2>&1
    $out | ForEach-Object { Show '  seed (reactivar)' $_ }
    if ($LASTEXITCODE -eq 0) { Ok 'seed reactiva la ubicacion' 'exit 0' }
    else { Bad 'seed reactiva la ubicacion' "exit $LASTEXITCODE" }
} catch { Bad 'seed reactiva la ubicacion' $_.Exception.Message }

try {
    $r = Invoke-RestMethod -Uri "$base/inventory/shipments/authorize" -Method Post -Headers `
        @{ Authorization = "Bearer $tokenA"; 'Idempotency-Key' = $azKeyOk } `
        -ContentType 'application/json' -Body (@{ shipment_id = $shipmentId } | ConvertTo-Json) -TimeoutSec 15
    Ok 'POST /inventory/shipments/authorize' "200 estado=$($r.data.shipment.estado) transito=$($r.data.ubicacion_transito.codigo) unidad=$($r.data.unidades[0].estado)"
} catch { Bad 'POST /inventory/shipments/authorize' "$(StatusCode $_) $($_.Exception.Message)" }

# ---------------------------------------------------- 12) estado final en BD
Show 'BD picking/reserva/shipment' ''
Sql "SELECT id_lote_picking, estado, actor_id_selector, actor_id_supervisor FROM picking_operations WHERE id_lote_picking = '$lote';"
Sql "SELECT id, estado, motivo_cancelacion FROM reservations WHERE id = '$reserva';"
Sql "SELECT id, estado FROM shipments WHERE id = '$shipmentId';"
Sql "SELECT estado_actual, cantidad_reservada, cantidad_en_transito, cantidad_disponible FROM stock_ledger WHERE unidad_fisica_id = '$unidad';"
Sql "SELECT id, codigo, tipo, activa FROM locations WHERE tipo = 'TRANSITO';"

Show 'audit_logs (claves v1.7.1)' ''
Sql "SELECT operacion, recurso, resultado, entidad_id, detalles_adicionales FROM audit_logs
     WHERE detalles_adicionales LIKE '%$pkKey%' OR detalles_adicionales LIKE '%$rcvKey%' OR detalles_adicionales LIKE '%$ldKey%'
     ORDER BY timestamp;"

Show 'duplicados de auditoria' ''
$dups = & mysql.exe -u root $e2eDb -N -e "SELECT COUNT(*) FROM (SELECT detalles_adicionales, operacion, COUNT(*) c FROM audit_logs WHERE detalles_adicionales LIKE '%v171-%' GROUP BY detalles_adicionales, operacion HAVING c > 1) t"
if ([int]$dups -eq 0) { Ok 'sin filas duplicadas' 'ninguna clave genera dos filas con la misma operacion' }
else { Bad 'sin filas duplicadas' "hay $dups combinaciones repetidas" }

Write-Output ""
if ($script:failures -eq 0) { Write-Output 'E2E v1.7.1: TODO OK' } else { Write-Output "E2E v1.7.1: $script:failures FALLOS" }

# v1.8.3: el servidor PHP embebido se detiene al terminar cada E2E.
Stop-E2EServer
exit $script:failures
