$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido (v1.8.3)
$results = New-Object System.Collections.ArrayList

function Add-R($name, $status, $note) {
    [void]$script:results.Add([pscustomobject]@{ Check = $name; Status = $status; Nota = $note })
}

function Call($method, $url, $body, $headers) {
    try {
        if ($body) {
            $r = Invoke-WebRequest -Uri $url -Method $method -Headers $headers -Body ($body | ConvertTo-Json -Depth 8) -ContentType 'application/json' -UseBasicParsing -TimeoutSec 20
        } else {
            $r = Invoke-WebRequest -Uri $url -Method $method -Headers $headers -UseBasicParsing -TimeoutSec 20
        }
        $json = $null
        try { $json = $r.Content | ConvertFrom-Json } catch {}
        return [pscustomobject]@{ code = $r.StatusCode; json = $json; raw = $r.Content }
    } catch {
        $resp = $_.Exception.Response
        $code = 0
        $content = ''
        if ($resp) {
            $code = [int]$resp.StatusCode
            try { $stream = $resp.GetResponseStream(); $stream.Position = 0; $reader = New-Object System.IO.StreamReader($stream); $content = $reader.ReadToEnd() } catch {}
        }
        $json = $null
        try { $json = $content | ConvertFrom-Json } catch {}
        return [pscustomobject]@{ code = $code; json = $json; raw = $content }
    }
}

# 1. Login
$login = Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType 'application/json' -Body '{"email":"aleon@acme.local","password":"Admin123!"}' -TimeoutSec 20
$token = $login.data.tokens.access_token
$H = @{ Authorization = "Bearer $token" }
Add-R 'login ADMIN' $login.status $login.data.user.email

$ts = Get-Date -Format 'yyyyMMddHHmmss'

# 2. GET /stock/summary sin filtro
$sum = Call 'GET' "$base/inventory/stock/summary" $null $H
Add-R 'GET /stock/summary (sin filtro)' $sum.code ("productos=$($sum.json.data.summary.productos) fisica=$($sum.json.data.summary.cantidad_fisica) disponible=$($sum.json.data.summary.cantidad_disponible)")

# 3. GET /stock/summary con producto_id[]
$prods = Invoke-RestMethod -Uri "$base/inventory/products?per_page=3" -Headers $H -TimeoutSec 20
$pid1 = $prods.data.data[0].id
$sku1 = $prods.data.data[0].sku
$sum2 = Call 'GET' "$base/inventory/stock/summary?producto_id[]=$pid1" $null $H
Add-R 'GET /stock/summary?producto_id[]=' $sum2.code ("sku=$sku1 productos=$($sum2.json.data.summary.productos)")

# 4. Fixture vivo (cambio externo, ver CHANGELOG 1.8.1): el SKU de referencia
#    es PROD-001 y las ubicaciones se resuelven en tiempo de ejecución.
$locs = Call 'GET' "$base/inventory/locations?tipo=ALMACENAMIENTO&activa=1&page=1&per_page=50" $null $H
$cands = @($locs.json.data.data | Where-Object { $_.codigo -notin @('S6DEST', 'S6LLENO') })
$prefer = $cands | Where-Object { $null -eq $_.capacidad } | Select-Object -First 1
if (-not $prefer) { $prefer = $cands | Select-Object -First 1 }
$origenCodigo = if ($prefer) { $prefer.codigo } else { $null }
Add-R 'fixture origen' $(if ($origenCodigo) { 'OK' } else { 'FALLO' }) "sku=PROD-001 ubicacion=$origenCodigo"

# Destino sin tope y destino lleno: códigos estables para reutilizarlos entre
# ejecuciones (S6LLENO nace con capacidad 1 y se rellena con una recepción).
$destCodigo = 'S6DEST'
if (-not (@($locs.json.data.data | Where-Object { $_.codigo -eq $destCodigo }))) {
    Call 'POST' "$base/inventory/locations" @{ codigo = $destCodigo; nombre = 'Destino Sprint 6'; tipo = 'ALMACENAMIENTO' } $H | Out-Null
}
$fullCodigo = 'S6LLENO'
if (-not (@($locs.json.data.data | Where-Object { $_.codigo -eq $fullCodigo }))) {
    Call 'POST' "$base/inventory/locations" @{ codigo = $fullCodigo; nombre = 'Destino lleno Sprint 6'; tipo = 'ALMACENAMIENTO'; capacidad = 1 } $H | Out-Null
}
$locs2 = Call 'GET' "$base/inventory/locations?tipo=ALMACENAMIENTO&activa=1&page=1&per_page=50" $null $H
$fullLoc = @($locs2.json.data.data | Where-Object { $_.codigo -eq $fullCodigo }) | Select-Object -First 1
if ($fullLoc -and [int]$fullLoc.capacidad_usada -lt [int]$fullLoc.capacidad) {
    Call 'POST' "$base/inventory/receive" @{ sku = 'PROD-001'; cantidad_esperada = 1; ubicacion_codigo = $fullCodigo } (@{ 'Idempotency-Key' = "e2e-s6-fullrcv-$ts" } + $H) | Out-Null
}

# 4b. POST /receive
$recvBody = @{ sku = 'PROD-001'; cantidad_esperada = 2; ubicacion_codigo = $origenCodigo }
$keyRecv = "e2e-s6-recv-$ts"
$recv = Call 'POST' "$base/inventory/receive" $recvBody (@{ 'Idempotency-Key' = $keyRecv } + $H)
Add-R 'POST /receive (con Idempotency-Key)' $recv.code $recv.json.data.unidad_fisica_id
$unidad = $recv.json.data.unidad_fisica_id
$barcode = $recv.json.data.codigo_barcode

# 5. movements sin clave
$movBody = @{ unidad_fisica_id = $unidad; ubicacion_destino_codigo = $destCodigo; motivo = 'REUBICACION' }
$noKey = Call 'POST' "$base/inventory/movements" $movBody $H
Add-R 'POST /movements SIN Idempotency-Key' $noKey.code $noKey.json.message

# 6. movements con clave
$keyMov = "e2e-s6-mov-$ts"
$mov = Call 'POST' "$base/inventory/movements" $movBody (@{ 'Idempotency-Key' = $keyMov } + $H)
Add-R 'POST /movements (201)' $mov.code $mov.json.data.movimiento.movimiento_id
$movId = $mov.json.data.movimiento.movimiento_id

# 7. replay
$mov2 = Call 'POST' "$base/inventory/movements" $movBody (@{ 'Idempotency-Key' = $keyMov } + $H)
Add-R 'POST /movements replay (mismo id)' $mov2.code ("igual=" + ($mov2.json.data.movimiento.movimiento_id -eq $movId))

# 8. misma clave, payload distinto
$movBody2 = @{ unidad_fisica_id = $unidad; ubicacion_destino_codigo = $origenCodigo; motivo = 'REUBICACION'; referencia_externa = 'REF-E2E' }
$diff = Call 'POST' "$base/inventory/movements" $movBody2 (@{ 'Idempotency-Key' = $keyMov } + $H)
Add-R 'POST /movements clave repetida + payload distinto' $diff.code $diff.json.message

# 9. movimiento a ubicacion llena (S6LLENO 1/1)
$fullBody = @{ unidad_fisica_id = $unidad; ubicacion_destino_codigo = $fullCodigo; motivo = 'REUBICACION' }
$full = Call 'POST' "$base/inventory/movements" $fullBody (@{ 'Idempotency-Key' = "e2e-s6-full-$ts" } + $H)
Add-R 'POST /movements destino SIN capacidad' $full.code ($full.json.message + ' | field=' + $full.json.data.field)

# 10. reservations
$resBody = @{ unidad_fisica_id = $unidad; referencia_orden = 'SO-E2E-6' }
$noKeyRes = Call 'POST' "$base/inventory/reservations" $resBody $H
Add-R 'POST /reservations SIN Idempotency-Key' $noKeyRes.code $noKeyRes.json.message

$keyRes = "e2e-s6-res-$ts"
$res = Call 'POST' "$base/inventory/reservations" $resBody (@{ 'Idempotency-Key' = $keyRes } + $H)
Add-R 'POST /reservations (201)' $res.code $res.json.data.reserva.reserva_id
$resId = $res.json.data.reserva.reserva_id

$res2 = Call 'POST' "$base/inventory/reservations" $resBody (@{ 'Idempotency-Key' = "e2e-s6-res2-$ts" } + $H)
Add-R 'POST /reservations doble reserva' $res2.code $res2.json.message

# 11. release
$rel = Call 'POST' "$base/inventory/reservations/$resId/release" @{ motivo = 'E2E Sprint 6' } $H
Add-R 'POST /reservations/{id}/release (sin clave, opcional)' $rel.code $rel.json.data.reserva.estado
$rel2 = Call 'POST' "$base/inventory/reservations/$resId/release" @{} $H
Add-R 'POST release repetido (no activa)' $rel2.code $rel2.json.message

# 12. resumen final
$sum3 = Call 'GET' "$base/inventory/stock/summary" $null $H
Add-R 'GET /stock/summary (final)' $sum3.code ("fisica=$($sum3.json.data.summary.cantidad_fisica) reservada=$($sum3.json.data.summary.cantidad_reservada)")

Write-Output ($results | Format-Table -AutoSize | Out-String)
Write-Output "UNIDAD=$unidad BARCODE=$barcode MOVIMIENTO=$movId RESERVA=$resId"

# v1.8.3: el servidor PHP embebido se detiene al terminar cada E2E.
Stop-E2EServer
