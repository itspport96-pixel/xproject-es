$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido (v1.8.3)

function Show($label, $value) { Write-Output ("{0}: {1}" -f $label, $value) }

$login = Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType 'application/json' `
    -Body '{"email":"aleon@acme.local","password":"Admin123!"}' -TimeoutSec 15
$token = $login.data.tokens.access_token
$h = @{ Authorization = "Bearer $token" }
Show 'LOGIN' "ok"

# 1) Unidad disponible (dataset vivo sin unidades DISPONIBLES: cambio externo,
#    ver CHANGELOG 1.8.1; si no la hay se recepciona una propia)
$stock = Invoke-RestMethod -Uri "$base/inventory/stock?estado=DISPONIBLE&page=1&per_page=1" -Headers $h -TimeoutSec 15
$unidad = $stock.data.data[0].unidad_fisica_id
if (-not $unidad) {
    $locs = Invoke-RestMethod -Uri "$base/inventory/locations?tipo=ALMACENAMIENTO&activa=1&page=1&per_page=50" -Headers $h -TimeoutSec 15
    $cands = @($locs.data.data)
    $prefer = $cands | Where-Object { $null -eq $_.capacidad } | Select-Object -First 1
    if (-not $prefer) { $prefer = $cands | Select-Object -First 1 }
    $rcv = Invoke-RestMethod -Uri "$base/inventory/receive" -Method Post `
        -Headers @{ Authorization = "Bearer $token"; 'Idempotency-Key' = [guid]::NewGuid().ToString() } `
        -ContentType 'application/json' `
        -Body (@{ sku = 'PROD-001'; cantidad_esperada = 1; ubicacion_codigo = $prefer.codigo } | ConvertTo-Json) `
        -TimeoutSec 15
    $unidad = $rcv.data.unidad_fisica_id
    Show 'POST /inventory/receive (fixture)' "unidad=$unidad ubicacion=$($prefer.codigo)"
}
Show 'stock DISPONIBLE' "unidad=$unidad"

# 2) Reserva con vencimiento a 5 segundos (la fecha la calcula PHP: su zona
#    horaria difiere de la del sistema, y la valida el backend en PHP)
$vence = (& php -r "echo (new DateTimeImmutable('+5 seconds'))->format('Y-m-d H:i:s');").Trim()
$key = [guid]::NewGuid().ToString()
$body = @{ unidad_fisica_id = $unidad; fecha_expiracion = $vence } | ConvertTo-Json
try {
    $res = Invoke-RestMethod -Uri "$base/inventory/reservations" -Method Post `
        -Headers @{ Authorization = "Bearer $token"; 'Idempotency-Key' = $key } `
        -ContentType 'application/json' -Body $body -TimeoutSec 15
    $id = $res.data.reserva.reserva_id
    Show 'POST /inventory/reservations' "creada $id vence=$vence estado=$($res.data.reserva.estado)"
} catch {
    Show 'POST /inventory/reservations' "ERROR $($_.Exception.Message)"
    exit 1
}

Show 'espera' '7s para que expire'
Start-Sleep -Seconds 7

# 3) CLI de expiracion
$out = & php C:\xampp\htdocs\xp\bin\expire-reservations.php 2>&1
Show 'php bin/expire-reservations.php' ''
$out | ForEach-Object { Write-Output ("  " + $_) }

# 4) Estado de la reserva + auditoria
$env:PATH = "C:\xampp\mysql\bin;$env:PATH"
$sql = "SELECT estado, fecha_expiracion, updated_at FROM reservations WHERE id = '$id';"
$q1 = & mysql.exe -u root $e2eDb -e $sql 2>&1
Show 'reserva tras el CLI' ''
$q1 | ForEach-Object { Write-Output ("  " + $_) }

$sql2 = "SELECT operacion, recurso, entidad_id, resultado, valores_nuevos, timestamp FROM audit_logs WHERE operacion LIKE 'CLI%' ORDER BY timestamp DESC LIMIT 3;"
$q2 = & mysql.exe -u root $e2eDb -e $sql2 2>&1
Show 'auditoria CLI (ultimas 3)' ''
$q2 | ForEach-Object { Write-Output ("  " + $_) }

# 5) Auditoria historica de movimientos/recepcion/reservas
$sql3 = @"
SELECT operacion, COUNT(*) n, MIN(DATE(timestamp)) primera
FROM audit_logs
WHERE modulo = 'inventory'
GROUP BY operacion
ORDER BY operacion;
"@
$q3 = & mysql.exe -u root $e2eDb -e $sql3 2>&1
Show 'operaciones auditadas en inventory' ''
$q3 | ForEach-Object { Write-Output ("  " + $_) }

# v1.8.3: el servidor PHP embebido se detiene al terminar cada E2E.
Stop-E2EServer
