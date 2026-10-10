$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido (v1.8.3)

function Show($label, $value) { Write-Output ("{0}: {1}" -f $label, $value) }

# 1) Login
$token = $null
foreach ($cred in @(
    @{ email = 'aleon@acme.local'; password = 'Admin123!' },
    @{ email = 'admin@acme.local'; password = 'Admin123!' }
)) {
    try {
        $login = Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType 'application/json' `
            -Body ($cred | ConvertTo-Json) -TimeoutSec 15
        $token = $login.data.tokens.access_token
        Show 'LOGIN' "$($cred.email) ok (token len=$($token.Length))"
        break
    } catch {
        Show 'LOGIN' "$($cred.email) fallo: $($_.Exception.Message)"
    }
}
if (-not $token) { exit 1 }

$h = @{ Authorization = "Bearer $token" }

# 2) GET /inventory/reservations (nuevo endpoint v1.6.2)
$list = $null
try {
    $list = Invoke-RestMethod -Uri "$base/inventory/reservations?page=1&per_page=5" -Headers $h -TimeoutSec 15
    $rows = @($list.data.data)
    Show 'GET /inventory/reservations' "200 total=$($list.data.pagination.total) activas=$($list.data.summary.activas) con_expiracion=$($list.data.summary.con_expiracion) pagina=$($list.data.pagination.page)/$($list.data.pagination.per_page) has_next=$($list.data.pagination.has_next)"
    if ($rows.Count -gt 0) {
        $r0 = $rows[0]
        $venc = if ($r0.fecha_expiracion) { $r0.fecha_expiracion } else { '(null)' }
        Show '  fila[0]' ("reserva_id={0} estado={1} vencimiento={2} producto={3} ubicacion={4} creada_por={5}" -f `
            $r0.reserva_id, $r0.estado, $venc, $r0.producto, $r0.ubicacion, $r0.creado_por)
    } else {
        Show '  filas' '0 (vacio)'
    }
} catch {
    $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { '?' }
    Show 'GET /inventory/reservations' "ERROR $code $($_.Exception.Message)"
}

# 3) Filtro estado=ACTIVA
try {
    $f = Invoke-RestMethod -Uri "$base/inventory/reservations?estado=ACTIVA&page=1&per_page=5" -Headers $h -TimeoutSec 15
    Show 'GET ?estado=ACTIVA' "200 total=$($f.data.pagination.total)"
} catch {
    Show 'GET ?estado=ACTIVA' "ERROR $($_.Exception.Message)"
}

# 4) Garantizar una reserva ACTIVA para liberar
$activa = $null
if ($list) { $activa = @($list.data.data | Where-Object { $_.estado -eq 'ACTIVA' }) | Select-Object -First 1 }

if (-not $activa) {
    try {
        $stock = Invoke-RestMethod -Uri "$base/inventory/stock?estado=DISPONIBLE&page=1&per_page=1" -Headers $h -TimeoutSec 15
        $unidad = $stock.data.data[0].unidad_fisica_id
        if (-not $unidad) {
            # Dataset vivo sin unidades DISPONIBLES (cambio externo, ver
            # CHANGELOG 1.8.1): se recepciona una unidad propia para poder
            # reservarla y liberarla.
            $locs = Invoke-RestMethod -Uri "$base/inventory/locations?tipo=ALMACENAMIENTO&activa=1&page=1&per_page=50" -Headers $h -TimeoutSec 15
            $cands = @($locs.data.data)
            $prefer = $cands | Where-Object { $null -eq $_.capacidad } | Select-Object -First 1
            if (-not $prefer) { $prefer = $cands | Select-Object -First 1 }
            if ($prefer) {
                $rcv = Invoke-RestMethod -Uri "$base/inventory/receive" -Method Post `
                    -Headers @{ Authorization = "Bearer $token"; 'Idempotency-Key' = [guid]::NewGuid().ToString() } `
                    -ContentType 'application/json' `
                    -Body (@{ sku = 'PROD-001'; cantidad_esperada = 1; ubicacion_codigo = $prefer.codigo } | ConvertTo-Json) `
                    -TimeoutSec 15
                $unidad = $rcv.data.unidad_fisica_id
                Show 'POST /inventory/receive (fixture)' "unidad=$unidad ubicacion=$($prefer.codigo)"
            }
        }
        $key = [guid]::NewGuid().ToString()
        $res = Invoke-RestMethod -Uri "$base/inventory/reservations" -Method Post `
            -Headers @{ Authorization = "Bearer $token"; 'Idempotency-Key' = $key } `
            -ContentType 'application/json' -Body (@{ unidad_fisica_id = $unidad } | ConvertTo-Json) -TimeoutSec 15
        $activa = [pscustomobject]@{ reserva_id = $res.data.reserva.reserva_id; estado = $res.data.reserva.estado }
        Show 'POST /inventory/reservations' "creada $($activa.reserva_id) estado=$($activa.estado)"
    } catch {
        Show 'POST /inventory/reservations' "ERROR $($_.Exception.Message)"
    }
}

# 5) Liberar (release) con Idempotency-Key
if ($activa) {
    try {
        $key = [guid]::NewGuid().ToString()
        $rel = Invoke-RestMethod -Uri "$base/inventory/reservations/$($activa.reserva_id)/release" -Method Post `
            -Headers @{ Authorization = "Bearer $token"; 'Idempotency-Key' = $key } `
            -ContentType 'application/json' -Body '{}' -TimeoutSec 15
        $estado = if ($rel.data.reserva) { $rel.data.reserva.estado } else { ($rel.data | ConvertTo-Json -Compress) }
        Show 'POST .../release' "200 reserva=$($activa.reserva_id) estado=$estado"
    } catch {
        $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { '?' }
        Show 'POST .../release' "ERROR $code $($_.Exception.Message)"
    }
} else {
    Show 'POST .../release' 'OMITIDO (sin reserva ACTIVA)'
}

# 6) Auditoria en BD viva
$env:PATH = "C:\xampp\mysql\bin;$env:PATH"
$sql = @"
SELECT operacion, recurso, resultado, COUNT(*) AS n
FROM audit_logs
WHERE modulo = 'inventory'
  AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
GROUP BY operacion, recurso, resultado
ORDER BY n DESC;
"@
try {
    $out = & mysql.exe -u root $e2eDb -e $sql 2>&1
    Show 'audit_logs (30 min)' ''
    $out | ForEach-Object { Write-Output ("  " + $_) }
} catch {
    Show 'audit_logs' "ERROR $($_.Exception.Message)"
}

# 7) Ultima fila de liberacion
$sql2 = @"
SELECT operacion, entidad_id, tipo_cambio, resultado, timestamp
FROM audit_logs
WHERE operacion LIKE '%release%' OR recurso = 'reservations'
ORDER BY timestamp DESC LIMIT 5;
"@
try {
    $out2 = & mysql.exe -u root $e2eDb -e $sql2 2>&1
    Show 'audit de reservas (ultimas 5)' ''
    $out2 | ForEach-Object { Write-Output ("  " + $_) }
} catch {
    Show 'audit de reservas' "ERROR $($_.Exception.Message)"
}

# v1.8.3: el servidor PHP embebido se detiene al terminar cada E2E.
Stop-E2EServer
