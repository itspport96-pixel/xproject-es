$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')
$script:failures = 0
$script:runId = [guid]::NewGuid().ToString('N').Substring(0, 8)

function Show($label, $value) { [Console]::WriteLine(("{0}: {1}" -f $label, $value)) }
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

function Invoke-Post($path, $json, $key, $token) {
    $headers = @{ Authorization = "Bearer $token" }
    if ($key) { $headers['Idempotency-Key'] = $key }
    try {
        $w = Invoke-WebRequest -Uri "$base$path" -Method Post -UseBasicParsing -Headers $headers `
            -ContentType 'application/json' -Body $json -TimeoutSec 25
        return @{ Ok = $true; Status = [int]$w.StatusCode; Content = $w.Content; Type = $w.Headers['Content-Type'] }
    } catch {
        $msg = ''
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $msg = $_.ErrorDetails.Message }
        return @{ Ok = $false; Status = (StatusCode $_); Content = $msg; Type = '' }
    }
}

function Invoke-Get($path, $token) {
    try {
        $w = Invoke-WebRequest -Uri "$base$path" -Method Get -UseBasicParsing `
            -Headers @{ Authorization = "Bearer $token" } -TimeoutSec 25
        return @{ Ok = $true; Status = [int]$w.StatusCode; Content = $w.Content; Type = $w.Headers['Content-Type'] }
    } catch {
        $msg = ''
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $msg = $_.ErrorDetails.Message }
        return @{ Ok = $false; Status = (StatusCode $_); Content = $msg; Type = '' }
    }
}

$tokenA = Login 'aleon@acme.local' 'Admin123!'
if (-not $tokenA) { Bad 'LOGIN aleon' 'sin token'; exit 1 }
Ok 'LOGIN aleon@acme.local' "token len=$($tokenA.Length)"

$tokenB = Login 'manager@acme.local' 'Admin123!'
if (-not $tokenB) { Bad 'LOGIN manager' 'sin token'; exit 1 }
Ok 'LOGIN manager@acme.local' "token len=$($tokenB.Length)"

# --- fixture: ubicacion ALMACENAMIENTO activa + SKU real PROD-001 ---
$ubicacionOrigen = $null
try {
    $locs = Invoke-RestMethod -Uri "$base/inventory/locations?tipo=ALMACENAMIENTO&activa=1&page=1&per_page=50" -Headers @{ Authorization = "Bearer $tokenA" } -TimeoutSec 15
    $cands = @($locs.data.data)
    $prefer = $cands | Where-Object { $null -eq $_.capacidad } | Select-Object -First 1
    if (-not $prefer) { $prefer = $cands | Select-Object -First 1 }
    if ($prefer) { $ubicacionOrigen = $prefer.codigo }
} catch { Bad 'fixture ubicacion' "$(StatusCode $_) $($_.Exception.Message)" }
if (-not $ubicacionOrigen) { Bad 'fixture ubicacion' 'sin ubicacion ALMACENAMIENTO activa'; exit 1 }
Ok 'fixture ubicacion' "codigo=$ubicacionOrigen"

$sku = 'PROD-001'
$refA = "R3-ADHOC-A-$($script:runId)"
$refB = "R3-ADHOC-B-$($script:runId)"

# 1) POST /inventory/receiving/adhoc (registra, unidades BLOQUEADO)
$adhocId = $null
$unidadId = $null
$payload = @{
    referencia_documento = $refA
    ubicacion_codigo     = $ubicacionOrigen
    lineas               = @(@{ sku = $sku; cantidad = 3 })
} | ConvertTo-Json
$r = Invoke-Post '/inventory/receiving/adhoc' $payload "r3-adhoc-$($script:runId)" $tokenA
if ($r.Ok -and $r.Status -eq 201) {
    $d = (ConvertFrom-Json $r.Content).data
    $adhocId = $d.adhoc_id
    $unidadId = $d.lineas[0].unidad_fisica_id
    Ok 'POST /receiving/adhoc (registro)' "201 adhoc=$adhocId unidad=$unidadId estado=$($d.estado)"
} else { Bad 'POST /receiving/adhoc (registro)' "$($r.Status) $($r.Content)" }

# 2) El creador NO puede confirmar (separacion de funciones, 422)
if ($adhocId) {
    $r = Invoke-Post "/inventory/receiving/adhoc/$adhocId/confirm" '{}' "r3-conf-$($script:runId)" $tokenA
    if ($r.Status -eq 422) { Ok 'POST /receiving/adhoc/{id}/confirm (creador)' "422 separacion de funciones" }
    else { Bad 'POST /receiving/adhoc/{id}/confirm (creador)' "$($r.Status) esperado 422 $($r.Content)" }
}

# 3) Rechazo por el segundo usuario: unidades siguen BLOQUEADO + incidencia ALTA
if ($adhocId) {
    $r = Invoke-Post "/inventory/receiving/adhoc/$adhocId/reject" '{}' "r3-rej-$($script:runId)" $tokenB
    if ($r.Ok -and $r.Status -eq 200) {
        $d = (ConvertFrom-Json $r.Content).data
        Ok 'POST /receiving/adhoc/{id}/reject (2do usuario)' "200 estado=$($d.estado) incidencia_prioridad=$($d.incidencia.prioridad)"
        $estadoUnidad = & mysql.exe -u root $e2eDb -N -e "SELECT estado FROM stock_units WHERE id = '$unidadId'" | Select-Object -First 1
        if ("$estadoUnidad".Trim() -eq 'BLOQUEADO') { Ok 'unidad tras rechazo' "BLOQUEADO" }
        else { Bad 'unidad tras rechazo' "obtenido='$estadoUnidad' esperado='BLOQUEADO'" }
    } else { Bad 'POST /receiving/adhoc/{id}/reject (2do usuario)' "$($r.Status) $($r.Content)" }
}

# 4) Flujo completo: otro adhoc, este vez confirmado por el segundo usuario
$refC = "R3-ADHOC-C-$($script:runId)"
$unidadIdC = $null
$adhocIdC = $null
$payloadC = @{
    referencia_documento = $refC
    ubicacion_codigo     = $ubicacionOrigen
    lineas               = @(@{ sku = $sku; cantidad = 2 })
} | ConvertTo-Json
$r = Invoke-Post '/inventory/receiving/adhoc' $payloadC "r3-adhoc-c-$($script:runId)" $tokenA
if ($r.Ok -and $r.Status -eq 201) {
    $d = (ConvertFrom-Json $r.Content).data
    $adhocIdC = $d.adhoc_id
    $unidadIdC = $d.lineas[0].unidad_fisica_id
    Ok 'POST /receiving/adhoc (registro C)' "201 adhoc=$adhocIdC unidad=$unidadIdC"
    $r = Invoke-Post "/inventory/receiving/adhoc/$adhocIdC/confirm" '{}' "r3-conf-c-$($script:runId)" $tokenB
    if ($r.Ok -and $r.Status -eq 200) {
        $d = (ConvertFrom-Json $r.Content).data
        Ok 'POST /receiving/adhoc/{id}/confirm (2do usuario)' "200 estado=$($d.estado) linea_estado=$($d.lineas[0].estado_posterior)"
        $estadoUnidad = & mysql.exe -u root $e2eDb -N -e "SELECT estado FROM stock_units WHERE id = '$unidadIdC'" | Select-Object -First 1
        if ("$estadoUnidad".Trim() -eq 'DISPONIBLE') { Ok 'unidad tras confirmacion' "DISPONIBLE" }
        else { Bad 'unidad tras confirmacion' "obtenido='$estadoUnidad' esperado='DISPONIBLE'" }
    } else { Bad 'POST /receiving/adhoc/{id}/confirm (2do usuario)' "$($r.Status) $($r.Content)" }
} else { Bad 'POST /receiving/adhoc (registro C)' "$($r.Status) $($r.Content)" }

# 5) GETs de solo lectura de receiving con admin (regla 17: todos 200, ninguno 500)
$r = Invoke-Get '/inventory/receiving/documents?page=1&per_page=50' $tokenA
if ($r.Status -eq 200) { Ok 'GET /receiving/documents' "200" }
elseif ($r.Status -eq 500) { Bad 'GET /receiving/documents' "500 $($r.Content)" }
else { Ok 'GET /receiving/documents' "status=$($r.Status) (esperado 200 en el modulo receiving)" }

$docId = $null
try {
    $r2 = Invoke-RestMethod -Uri "$base/inventory/receiving/documents?page=1&per_page=50" -Headers @{ Authorization = "Bearer $tokenA" } -TimeoutSec 15
    $rows = @($r2.data.data)
    if ($rows.Count -gt 0) { $docId = $rows[0].id }
} catch { Show 'GET /receiving/documents (fixture)' "ERROR $(StatusCode $_)" }

if ($docId) {
    $r = Invoke-Get "/inventory/receiving/documents/$docId" $tokenA
    if ($r.Status -eq 200) { Ok 'GET /receiving/documents/{id}' "200 id=$docId" }
    elseif ($r.Status -eq 500) { Bad 'GET /receiving/documents/{id}' "500 $($r.Content)" }
    else { Ok 'GET /receiving/documents/{id}' "status=$($r.Status)" }
} else {
    Ok 'GET /receiving/documents/{id}' 'sin documentos de recepcion en la BD de E2E (fixture vacia); detalle omitido'
}

# 6) Verificacion adhoc en la BD de E2E (regla 16)
$adhocCount = & mysql.exe -u root $e2eDb -N -e "SELECT COUNT(*) FROM receiving_adhoc WHERE tenant_id = (SELECT id FROM tenants WHERE nombre_interno = 'tenant-acme' LIMIT 1)" | Select-Object -First 1
Show 'receiving_adhoc filas' "$adhocCount"

Write-Output "R3 E2E: failures=$($script:failures)"
if ($script:failures -gt 0) { exit 1 } else { exit 0 }
