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
        return [pscustomobject]@{ code = $r.StatusCode; json = $json; raw = $r.Content; headers = $r.Headers }
    } catch {
        $resp = $_.Exception.Response
        $code = 0
        $content = ''
        $hdrs = @{}
        if ($resp) {
            $code = [int]$resp.StatusCode
            foreach ($k in $resp.Headers.AllKeys) { $hdrs[$k] = $resp.Headers[$k] }
            try { $stream = $resp.GetResponseStream(); $stream.Position = 0; $reader = New-Object System.IO.StreamReader($stream); $content = $reader.ReadToEnd() } catch {}
        }
        $json = $null
        try { $json = $content | ConvertFrom-Json } catch {}
        return [pscustomobject]@{ code = $code; json = $json; raw = $content; headers = $hdrs }
    }
}

$ts = Get-Date -Format 'yyyyMMddHHmmss'
$skuE2E = "AUD-$ts"

# ============ 1. LOGINS ============
$loginOk = Call 'POST' "$base/auth/login" @{ email = 'aleon@acme.local'; password = 'Admin123!' } @{}
$token = $loginOk.json.data.tokens.access_token
$H = @{ Authorization = "Bearer $token" }
Add-R 'login ADMIN (200)' $loginOk.code $loginOk.json.data.user.email

$loginFail = Call 'POST' "$base/auth/login" @{ email = 'aleon@acme.local'; password = 'MALA' } @{}
Add-R 'login FALLIDO' $loginFail.code $loginFail.json.message

# Segundo actor: credenciales únicas desde el cambio de entorno (todas
# Admin123!, ver CHANGELOG 1.8.1). manager@acme.local sigue siendo una cuenta
# distinta de la del selector, requisito del verify de picking.
$loginMgr = Call 'POST' "$base/auth/login" @{ email = 'manager@acme.local'; password = 'Admin123!' } @{}
$tokenMgr = $loginMgr.json.data.tokens.access_token
$HM = @{ Authorization = "Bearer $tokenMgr" }
Add-R 'login manager@acme.local (200)' $loginMgr.code $loginMgr.json.data.user.email

# Cuenta con rol USER (sin tenants.tenant.edit) para el check de 403.
$loginUsr = Call 'POST' "$base/auth/login" @{ email = 'user@acme.local'; password = 'Admin123!' } @{}
$tokenUsr = $loginUsr.json.data.tokens.access_token
$HU = @{ Authorization = "Bearer $tokenUsr" }
Add-R 'login user@acme.local (200)' $loginUsr.code $loginUsr.json.data.user.email

# ============ 2. CRUD PRODUCTO ============
$prod = Call 'POST' "$base/inventory/products" @{
    sku = $skuE2E; nombre = 'Producto Auditoria'; unidad_medida = 'UNIDAD'
    categoria = 'Fijaciones'
} $H
Add-R 'POST /products (201)' $prod.code $prod.json.data.product.id
$prodId = $prod.json.data.product.id
$reqIdProd = $prod.headers['X-Request-Id']

$prodUpd = Call 'PATCH' "$base/inventory/products/$prodId" @{ nombre = 'Producto Auditoria v2' } $H
Add-R 'PATCH /products/{id} (200)' $prodUpd.code $prodUpd.json.data.product.nombre

$prodDel = Call 'DELETE' "$base/inventory/products/$prodId" $null $H
Add-R 'DELETE /products/{id} (200)' $prodDel.code $prodDel.json.message

# ============ 3. UBICACION ============
$loc = Call 'POST' "$base/inventory/locations" @{
    codigo = "AUD$ts"; nombre = 'Ubicacion Auditoria'; tipo = 'ALMACENAMIENTO'
} $H
Add-R 'POST /locations (201)' $loc.code $loc.json.data.location.id

# ============ 4. RECEPCION / MOVIMIENTO / RESERVA ============
# Dataset vivo (cambio externo, ver CHANGELOG 1.8.1): el SKU de referencia es
# PROD-001, la ubicacion de recepcion se resuelve en tiempo de ejecucion y el
# destino del movimiento es la ubicacion creada en el paso 3 (AUD$ts).
$locs = Call 'GET' "$base/inventory/locations?tipo=ALMACENAMIENTO&activa=1&page=1&per_page=50" $null $H
$cands = @($locs.json.data.data | Where-Object { $_.codigo -ne "AUD$ts" })
$prefer = $cands | Where-Object { $null -eq $_.capacidad } | Select-Object -First 1
if (-not $prefer) { $prefer = $cands | Select-Object -First 1 }
$origenCodigo = if ($prefer) { $prefer.codigo } else { $null }
Add-R 'fixture recepcion' $(if ($origenCodigo) { 'OK' } else { 'FALLO' }) "sku=PROD-001 ubicacion=$origenCodigo destino=AUD$ts"

$recv = Call 'POST' "$base/inventory/receive" @{
    sku = 'PROD-001'; cantidad_esperada = 1; ubicacion_codigo = $origenCodigo
} (@{ 'Idempotency-Key' = "e2e-aud-recv-$ts" } + $H)
Add-R 'POST /receive (201)' $recv.code $recv.json.data.unidad_fisica_id
$unidad = $recv.json.data.unidad_fisica_id

$mov = Call 'POST' "$base/inventory/movements" @{
    unidad_fisica_id = $unidad; ubicacion_destino_codigo = "AUD$ts"; motivo = 'REUBICACION'
} (@{ 'Idempotency-Key' = "e2e-aud-mov-$ts" } + $H)
Add-R 'POST /movements (201)' $mov.code $mov.json.data.movimiento.movimiento_id

$res = Call 'POST' "$base/inventory/reservations" @{
    unidad_fisica_id = $unidad; referencia_orden = 'SO-AUD'
} (@{ 'Idempotency-Key' = "e2e-aud-res-$ts" } + $H)
Add-R 'POST /reservations (201)' $res.code $res.json.data.reserva.reserva_id
$resId = $res.json.data.reserva.reserva_id

$rel = Call 'POST' "$base/inventory/reservations/$resId/release" @{ motivo = 'E2E Auditoria' } $H
Add-R 'POST release (200)' $rel.code $rel.json.data.reserva.estado

# ============ 5. FALLOS: 401 Y 403 ============
$sinToken = Call 'POST' "$base/inventory/movements" @{ unidad_fisica_id = 'x' } @{}
Add-R 'POST /movements SIN token (401)' $sinToken.code $sinToken.json.message

# Ningún rol salvo ADMIN tiene tenants.tenant.edit: se comprueba con la cuenta
# USER y con un body sin datos válidos, para que ni siquiera en el caso de que
# el permiso cambiara llegara a modificarse el tenant en vivo.
$forbidden = Call 'PATCH' "$base/tenant" @{ nombre = $null } $HU
Add-R 'PATCH /tenant sin tenants.tenant.edit (403)' $forbidden.code $forbidden.json.message

# ============ 6. GET NO AUDITADO ============
$antes = Call 'GET' "$base/inventory/products?per_page=1" $null $H
Add-R 'GET /products (lectura)' $antes.code ("total=" + $antes.json.data.pagination.total)

# ============ 7. BITACORA (GET /api/audit/logs) ============
$all = Call 'GET' "$base/audit/logs" $null $H
Add-R 'GET /audit/logs' $all.code ("total=$($all.json.data.summary.total) exitosos=$($all.json.data.summary.exitosos) fallidos=$($all.json.data.summary.fallidos)")

$fallidos = Call 'GET' "$base/audit/logs?resultado=FAILURE" $null $H
Add-R 'GET /audit/logs?resultado=FAILURE' $fallidos.code ("total=$($fallidos.json.data.summary.total) exitosos=$($fallidos.json.data.summary.exitosos)")

$porAccion = Call 'GET' "$base/audit/logs?accion=product.create" $null $H
Add-R 'GET /audit/logs?accion=product.create' $porAccion.code ("total=$($porAccion.json.data.summary.total) entidad_id=$($porAccion.json.data.data[0].entidad_id)")

$porEntidad = Call 'GET' "$base/audit/logs?entidad=products" $null $H
Add-R 'GET /audit/logs?entidad=products' $porEntidad.code ("total=$($porEntidad.json.data.summary.total)")

$porEntId = Call 'GET' "$base/audit/logs?entidad_id=$prodId" $null $H
Add-R 'GET /audit/logs?entidad_id=' $porEntId.code ("total=$($porEntId.json.data.summary.total)")

$reqListo = $null
if ($reqIdProd) {
    $porReq = Call 'GET' "$base/audit/logs?request_id=$reqIdProd" $null $H
    Add-R 'GET /audit/logs?request_id=' $porReq.code ("total=$($porReq.json.data.summary.total) operacion=$($porReq.json.data.data[0].operacion)")
    $reqListo = $porReq.code
} else {
    Add-R 'GET /audit/logs?request_id=' 'SKIP' 'sin X-Request-Id en la respuesta'
}

$pag = Call 'GET' "$base/audit/logs?page=2&per_page=2" $null $H
Add-R 'GET /audit/logs paginado (page=2)' $pag.code ("filas=$($pag.json.data.data.Count) last_page=$($pag.json.data.pagination.last_page)")

$invalido = Call 'GET' "$base/audit/logs?resultado=TODO" $null $H
Add-R 'GET /audit/logs?resultado=TODO (400)' $invalido.code $invalido.json.message

$rango = Call 'GET' "$base/audit/logs?fecha_desde=2026-10-01&fecha_hasta=2026-12-31" $null $H
Add-R 'GET /audit/logs rango fechas' $rango.code ("total=$($rango.json.data.summary.total)")

$mgrView = Call 'GET' "$base/audit/logs?per_page=5" $null $HM
Add-R 'GET /audit/logs como manager@acme.local (audit.log.view)' $mgrView.code ("total=$($mgrView.json.data.summary.total)")

Write-Output ($results | Format-Table -AutoSize | Out-String)
Write-Output "SKU=$skuE2E PRODUCTO=$prodId UNIDAD=$unidad RESERVA=$resId REQUEST_ID_PROD=$reqIdProd"

# v1.8.3: el servidor PHP embebido se detiene al terminar cada E2E.
Stop-E2EServer
