$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido (v1.8.3)
$script:failures = 0
$script:runId = [guid]::NewGuid().ToString('N').Substring(0, 8)

function Show($label, $value) { [Console]::WriteLine(("{0}: {1}" -f $label, $value)) }
function Ok($label, $value) { Show $label "OK  $value" }
function Bad($label, $value) { $script:failures++; Show $label "FALLO  $value" }
function StatusCode($e) { if ($e.Exception.Response) { [int]$e.Exception.Response.StatusCode } else { '?' } }

function Assert-Eq($label, $actual, $expected) {
    if ("$actual" -eq "$expected") { Ok $label "$actual" }
    else { Bad $label "obtenido='$actual' esperado='$expected'" }
}

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
        return @{ Ok = $true; Status = [int]$w.StatusCode; Content = $w.Content }
    } catch {
        $msg = ''
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $msg = $_.ErrorDetails.Message }
        return @{ Ok = $false; Status = (StatusCode $_); Content = $msg }
    }
}

function Invoke-Get($path, $token) {
    try {
        $w = Invoke-WebRequest -Uri "$base$path" -Method Get -UseBasicParsing `
            -Headers @{ Authorization = "Bearer $token" } -TimeoutSec 25
        return @{ Ok = $true; Status = [int]$w.StatusCode; Content = $w.Content }
    } catch {
        $msg = ''
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $msg = $_.ErrorDetails.Message }
        return @{ Ok = $false; Status = (StatusCode $_); Content = $msg }
    }
}

function Scalar($sql) {
    $v = & mysql.exe -u root $e2eDb -N -e $sql | Select-Object -First 1
    if ($null -eq $v) { return $null }
    return ("$v".Trim())
}

function Sql($query) {
    & mysql.exe -u root $e2eDb -e $query | ForEach-Object { Write-Output ("  " + $_) }
}

function AuditCount($key) {
    $sql = "SELECT COUNT(*) FROM audit_logs WHERE detalles_adicionales LIKE '%" + $key + "%'"
    $v = & mysql.exe -u root $e2eDb -N -e $sql | Select-Object -First 1
    if ($null -eq $v) { return -1 }
    return [int]("$v".Trim())
}

$env:PATH = "C:\xampp\mysql\bin;$env:PATH"

# ================================================================== 1) actores
$tokenA = Login 'aleon@acme.local' 'Admin123!'
if (-not $tokenA) { Bad 'LOGIN aleon' 'sin token'; exit 1 }
Ok 'LOGIN aleon@acme.local' "ADMIN token len=$($tokenA.Length)"

$tokenB = Login 'manager@acme.local' 'Admin123!'
if (-not $tokenB) { Bad 'LOGIN 2do actor' 'sin token'; exit 1 }
Ok 'LOGIN manager@acme.local (2do actor)' "token len=$($tokenB.Length)"

$script:tokenA = $tokenA
$script:tokenB = $tokenB

# ================================================= 2) permisos sembrados (Sprint 8)
$permTotal = Scalar "SELECT COUNT(*) FROM permissions WHERE identificador LIKE 'inventory.%'"
Assert-Eq 'PERM total inventory.*' $permTotal '34'

foreach ($p in 'inventory.delivery.view', 'inventory.delivery.confirm', 'inventory.return.view', 'inventory.return.create') {
    $c = Scalar ("SELECT COUNT(*) FROM permissions WHERE identificador='" + $p + "' AND activo=1")
    Assert-Eq "PERM $p existe y activo" $c '1'
}

# view -> ADMIN/MANAGER/USER/VIEWER (4 roles): roles.permisos (JSON)
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER','USER','VIEWER') AND permisos LIKE '%inventory.delivery.view%'"
Assert-Eq 'PERM delivery.view en 4 roles' $c '4'
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER','USER','VIEWER') AND permisos LIKE '%inventory.return.view%'"
Assert-Eq 'PERM return.view en 4 roles' $c '4'
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER') AND permisos LIKE '%inventory.delivery.confirm%'"
Assert-Eq 'PERM delivery.confirm en ADMIN+MANAGER' $c '2'
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER') AND permisos LIKE '%inventory.return.create%'"
Assert-Eq 'PERM return.create en ADMIN+MANAGER' $c '2'

# ================================================================= 3) recursos base
# Dataset vivo (cambio externo, ver CHANGELOG 1.8.1): se prefiere una
# ALMACENAMIENTO activa sin capacidad tope y se acepta cualquier activa.
$storage = (Scalar "SELECT id FROM locations WHERE tipo='ALMACENAMIENTO' AND activa=1 AND deleted_at IS NULL ORDER BY (capacidad IS NOT NULL), codigo LIMIT 1")
$storageCodigo = (Scalar ("SELECT codigo FROM locations WHERE id='" + $storage + "'"))
if (-not $storage) { Bad 'ubicacion origen' 'sin ubicacion ALMACENAMIENTO activa'; exit 1 }
Ok 'ubicacion origen' "id=$storage codigo=$storageCodigo"

$pickId = (Scalar "SELECT id FROM locations WHERE codigo='PICK-E2E' AND activa=1")
if (-not $pickId) { $pickId = (Scalar "SELECT id FROM locations WHERE tipo='PICKING' AND activa=1 LIMIT 1") }
if (-not $pickId) { Bad 'zona PICKING' 'sin zona PICKING activa'; exit 1 }
Ok 'zona PICKING' "id=$pickId"

# zona DANADOS (tipo con enye: se busca por codigo para mantener el script ASCII)
$danadosId = (Scalar "SELECT id FROM locations WHERE codigo='DANADOS' AND activa=1")
if (-not $danadosId) { $danadosId = (Scalar "SELECT id FROM locations WHERE tipo LIKE 'DA__DOS' AND activa=1 LIMIT 1") }
if (-not $danadosId) { Bad 'zona DANADOS' 'sin zona de danados'; exit 1 }
Ok 'zona DANADOS' "id=$danadosId"

$transito = (Scalar "SELECT id FROM locations WHERE tipo='TRANSITO' AND activa=1 LIMIT 1")
if (-not $transito) { Bad 'ubicacion TRANSITO' 'sin ubicacion TRANSITO activa'; exit 1 }
Ok 'ubicacion TRANSITO' "id=$transito"

# ================================================= 4) smoke de los listados nuevos
$r = Invoke-Get '/inventory/deliveries?page=1&per_page=5' $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'GET /inventory/deliveries' '200' }
else { Bad 'GET /inventory/deliveries' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/returns?page=1&per_page=5' $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'GET /inventory/returns' '200' }
else { Bad 'GET /inventory/returns' "$($r.Status) $($r.Content)" }

# sin Idempotency-Key -> 400
$r = Invoke-Post '/inventory/deliveries/confirm' '{"shipment_id":"x","unidades":[]}' '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match 'idempotency-key') {
    Ok 'confirm sin Idempotency-Key' '400 idempotency-key'
} else { Bad 'confirm sin Idempotency-Key' "$($r.Status) $($r.Content)" }

# filtro invalido -> 400 con field=estado
$r = Invoke-Get '/inventory/deliveries?estado=ESTADO_INEXISTENTE' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match '"field"') {
    Ok 'GET deliveries estado invalido' '400 con field'
} else { Bad 'GET deliveries estado invalido' "$($r.Status) $($r.Content)" }

# ==================================================== 5) helper de preparacion
function Prepare-Unit($tag) {
    $rcvKey = "v180-rcv-$tag-" + [guid]::NewGuid().ToString('N')
    $rcvBody = (@{ sku = 'PROD-001'; cantidad_esperada = 1; ubicacion_codigo = $script:storageCodigo } | ConvertTo-Json)
    $r = Invoke-Post '/inventory/receive' $rcvBody $rcvKey $script:tokenA
    if (-not $r.Ok -or $r.Status -ne 201) { Bad "[$tag] receive" "$($r.Status) $($r.Content)"; return $null }
    $unidad = (ConvertFrom-Json $r.Content).data.unidad_fisica_id

    $rsvKey = "v180-rsv-$tag-" + [guid]::NewGuid().ToString('N')
    $r = Invoke-Post '/inventory/reservations' (@{ unidad_fisica_id = $unidad } | ConvertTo-Json) $rsvKey $script:tokenA
    if (-not $r.Ok -or $r.Status -ge 300) { Bad "[$tag] reservations" "$($r.Status) $($r.Content)"; return $null }

    $lote = "V180-$tag-" + [guid]::NewGuid().ToString('N').Substring(0, 6)
    $startBody = (@{
        id_lote_picking = $lote
        ubicacion_preparacion_id = $script:pickId
        unidades = @(@{ unidad_fisica_id = $unidad; cantidad = 1 })
        referencia_orden = "SO-V180-$tag"
    } | ConvertTo-Json -Depth 5)
    $pkKey = "v180-pk-$tag-" + [guid]::NewGuid().ToString('N')
    $r = Invoke-Post '/inventory/picking/start' $startBody $pkKey $script:tokenA
    if (-not $r.Ok -or $r.Status -ge 300) { Bad "[$tag] picking/start" "$($r.Status) $($r.Content)"; return $null }

    $r = Invoke-Post "/inventory/picking/$lote/complete" '{}' '' $script:tokenA
    if (-not $r.Ok -or $r.Status -ge 300) { Bad "[$tag] picking/complete" "$($r.Status) $($r.Content)"; return $null }

    $ldKey = "v180-ld-$tag-" + [guid]::NewGuid().ToString('N')
    $ldBody = (@{
        unidades = @(@{ unidad_fisica_id = $unidad })
        shipment = @{
            referencia_externa = "V180-$tag"
            destinatario_nombre = 'ACME SA'
            conductor_nombre = "Chofer V180 $tag"
            tipo = 'PROPIO'
            origen_ubicacion_id = $script:storage
            hora_entrega_esperada = '2026-10-05T12:30'
        }
    } | ConvertTo-Json -Depth 5)
    $r = Invoke-Post '/inventory/shipments/load' $ldBody $ldKey $script:tokenA
    if (-not $r.Ok -or $r.Status -ne 201) { Bad "[$tag] shipments/load" "$($r.Status) $($r.Content)"; return $null }
    $shipmentId = (ConvertFrom-Json $r.Content).data.shipment.shipment_id

    $azKey = "v180-az-$tag-" + [guid]::NewGuid().ToString('N')
    $r = Invoke-Post '/inventory/shipments/authorize' (@{ shipment_id = $shipmentId } | ConvertTo-Json) $azKey $script:tokenA
    if (-not $r.Ok -or $r.Status -ge 300) { Bad "[$tag] shipments/authorize" "$($r.Status) $($r.Content)"; return $null }

    Ok "[$tag] pipeline prepare" "unidad=$unidad shipment=$shipmentId"
    return @{ Unit = $unidad; Shipment = $shipmentId; Lote = $lote }
}

# ============================================================= 6) FLUJO A
Write-Output ''
Write-Output '--- FLUJO A: entrega completa (CU-007) ---'
$uA = Prepare-Unit 'A'
if (-not $uA) { exit 1 }

$keyA = "v180-cf-A-" + [guid]::NewGuid().ToString('N')
$cfBodyA = (@{
    shipment_id = $uA.Shipment
    unidades = @(@{ unidad_fisica_id = $uA.Unit })
    destinatario_real = 'Cliente ACME'
    firma_comprobante = 'https://acme.local/firmas/e2e-a.png'
    observaciones = 'E2E v1.8.0 flujo A'
} | ConvertTo-Json -Depth 5)

$r = Invoke-Post '/inventory/deliveries/confirm' $cfBodyA $keyA $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'A POST /deliveries/confirm' '200' }
else { Bad 'A POST /deliveries/confirm' "$($r.Status) $($r.Content)" }
$bodyPrimera = $r.Content

$auditFirstA = AuditCount $keyA

# replay idempotente
$r2 = Invoke-Post '/inventory/deliveries/confirm' $cfBodyA $keyA $tokenA
if ($r2.Ok -and $r2.Content -eq $bodyPrimera) { Ok 'A REPLAY confirm (body identico)' "len=$($r2.Content.Length)" }
else { Bad 'A REPLAY confirm (body identico)' "status=$($r2.Status) difiere de la primera respuesta" }
if ($r2.Content -match '_replay') { Bad 'A REPLAY sin _replay' 'marcador interno colado en el JSON' }
else { Ok 'A REPLAY sin _replay' 'marcador interno fuera del JSON' }

$auditReplayA = AuditCount $keyA
Assert-Eq 'A AUDIT confirm no se duplica (2 filas)' $auditReplayA '2'
Assert-Eq 'A AUDIT confirm tras primera llamada' $auditFirstA '2'

# estado final en BD
Assert-Eq 'A shipments.estado' (Scalar ("SELECT estado FROM shipments WHERE id='" + $uA.Shipment + "'")) 'ENTREGADO'
Assert-Eq 'A shipments.hora_entrega_real' (Scalar ("SELECT IF(hora_entrega_real IS NULL,'NULL','SET') FROM shipments WHERE id='" + $uA.Shipment + "'")) 'SET'
Assert-Eq 'A shipments.hora_entrega_esperada normalizada a UTC' (Scalar ("SELECT hora_entrega_esperada FROM shipments WHERE id='" + $uA.Shipment + "'")) '2026-10-05 12:30:00'
Assert-Eq 'A deliveries ENTREGADA' (Scalar ("SELECT COUNT(*) FROM deliveries WHERE shipment_id='" + $uA.Shipment + "' AND estado='ENTREGADA'")) '1'
Assert-Eq 'A deliveries unicas (replay sin 2a fila)' (Scalar ("SELECT COUNT(*) FROM deliveries WHERE shipment_id='" + $uA.Shipment + "'")) '1'
Assert-Eq 'A stock_units.estado' (Scalar ("SELECT estado FROM stock_units WHERE id='" + $uA.Unit + "'")) 'ENTREGADO'
Assert-Eq 'A stock_units.ubicacion_id NULL' (Scalar ("SELECT IF(ubicacion_id IS NULL,'NULL','SET') FROM stock_units WHERE id='" + $uA.Unit + "'")) 'NULL'
Assert-Eq 'A ledger sin transito' (Scalar ("SELECT IF(cantidad_en_transito=0,'0','NO0') FROM stock_ledger WHERE unidad_fisica_id='" + $uA.Unit + "'")) '0'
Assert-Eq 'A evento ENTREGADO' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA.Unit + "' AND tipo_evento='ENTREGADO'")) '1'
Assert-Eq 'A eventos con hash de 64 hex' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA.Unit + "' AND hash_integridad REGEXP '^[0-9a-fA-F]{64}$'")) (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA.Unit + "'"))

# listados con filtros reales
$r = Invoke-Get ("/inventory/deliveries?shipment_id=" + $uA.Shipment + "&page=1&per_page=10") $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'A GET /deliveries por shipment total' $d.data.pagination.total 1
    if ($d.data.data.Count -ge 1 -and $d.data.data[0].estado -eq 'ENTREGADA') { Ok 'A GET /deliveries fila' "estado=$($d.data.data[0].estado)" }
    else { Bad 'A GET /deliveries fila' 'sin fila ENTREGADA' }
} else { Bad 'A GET /deliveries por shipment' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/deliveries?estado=ENTREGADA&page=1&per_page=100' $tokenA
if ($r.Ok) {
    $d = ConvertFrom-Json $r.Content
    if ([int]$d.data.pagination.total -ge 1) { Ok 'A GET /deliveries?estado=ENTREGADA' "total=$($d.data.pagination.total)" }
    else { Bad 'A GET /deliveries?estado=ENTREGADA' "total=$($d.data.pagination.total)" }
} else { Bad 'A GET /deliveries?estado=ENTREGADA' "$($r.Status)" }

# ==================================================== 7) FLUJO B
Write-Output ''
Write-Output '--- FLUJO B: fallo de entrega + devolucion OK (CU-008 + CU-009) ---'
$uB = Prepare-Unit 'B'
if (-not $uB) { exit 1 }

$keyF = "v180-fl-B-" + [guid]::NewGuid().ToString('N')
$flBody = (@{
    shipment_id = $uB.Shipment
    motivo = 'NO_DISPONIBLE'
    unidades = @(@{ unidad_fisica_id = $uB.Unit })
    observaciones = "E2E v1.8.0 flujo B $script:runId"
} | ConvertTo-Json -Depth 5)

$r = Invoke-Post '/inventory/deliveries/failed' $flBody $keyF $tokenB
if ($r.Ok -and $r.Status -eq 200) { Ok 'B POST /deliveries/failed (2do actor)' '200' }
else { Bad 'B POST /deliveries/failed' "$($r.Status) $($r.Content)" }

Assert-Eq 'B shipments.estado' (Scalar ("SELECT estado FROM shipments WHERE id='" + $uB.Shipment + "'")) 'FALLO_ENTREGA'
Assert-Eq 'B deliveries RECHAZADA' (Scalar ("SELECT COUNT(*) FROM deliveries WHERE shipment_id='" + $uB.Shipment + "' AND estado='RECHAZADA'")) '1'
Assert-Eq 'B stock_units.estado' (Scalar ("SELECT estado FROM stock_units WHERE id='" + $uB.Unit + "'")) 'DEVUELTO'
Assert-Eq 'B stock_units devuelve al origen' (Scalar ("SELECT IF(ubicacion_id='" + $storage + "','ORIGEN','OTRO') FROM stock_units WHERE id='" + $uB.Unit + "'")) 'ORIGEN'
# una incidencia por operacion (unidad NULL: es del envio entero)
Assert-Eq 'B incidencia ENTREGA_RECHAZADA' (Scalar ("SELECT COUNT(*) FROM incidents WHERE tipo='ENTREGA_RECHAZADA' AND descripcion LIKE '%E2E v1.8.0 flujo B " + $script:runId + "%'")) '1'
Assert-Eq 'B incidencia en el destino del fallo' (Scalar ("SELECT IF(ubicacion_id='" + $storage + "','DESTINO','OTRO') FROM incidents WHERE tipo='ENTREGA_RECHAZADA' AND descripcion LIKE '%E2E v1.8.0 flujo B " + $script:runId + "%'")) 'DESTINO'
Assert-Eq 'B evento DEVUELTO' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uB.Unit + "' AND tipo_evento='DEVUELTO'")) '1'
Assert-Eq 'B ledger sin transito' (Scalar ("SELECT IF(cantidad_en_transito=0,'0','NO0') FROM stock_ledger WHERE unidad_fisica_id='" + $uB.Unit + "'")) '0'
Assert-Eq 'B AUDIT failed (2 filas)' (AuditCount $keyF) '2'

# devolucion OK desde DEVUELTO
$keyR = "v180-rt-B-" + [guid]::NewGuid().ToString('N')
$rtBodyB = (@{
    unidad_fisica_id = $uB.Unit
    motivo = 'FALLO_ENTREGA'
    estado_al_devolver = 'OK'
    ubicacion_destino_id = $storage
    observaciones = 'E2E v1.8.0 devolucion tras fallo'
} | ConvertTo-Json -Depth 5)

$r = Invoke-Post '/inventory/returns/register' $rtBodyB $keyR $tokenB
if ($r.Ok -and $r.Status -eq 201) { Ok 'B POST /returns/register (2do actor)' '201' }
else { Bad 'B POST /returns/register' "$($r.Status) $($r.Content)" }

# replay de la devolucion
$auditFirstRtB = AuditCount $keyR
$r2 = Invoke-Post '/inventory/returns/register' $rtBodyB $keyR $tokenB
if ($r2.Ok -and $r2.Content -eq $r.Content) { Ok 'B REPLAY returns/register (body identico)' "len=$($r2.Content.Length)" }
else { Bad 'B REPLAY returns/register' "status=$($r2.Status) difiere" }
if ($r2.Content -match '_replay') { Bad 'B REPLAY sin _replay' 'marcador colado' }
else { Ok 'B REPLAY sin _replay' 'marcador interno fuera del JSON' }
Assert-Eq 'B AUDIT return no se duplica (2 filas)' (AuditCount $keyR) '2'
Assert-Eq 'B AUDIT return tras 1a llamada' $auditFirstRtB '2'

Assert-Eq 'B stock_units DISPONIBLE' (Scalar ("SELECT estado FROM stock_units WHERE id='" + $uB.Unit + "'")) 'DISPONIBLE'
Assert-Eq 'B stock_units en el destino' (Scalar ("SELECT IF(ubicacion_id='" + $storage + "','DESTINO','OTRO') FROM stock_units WHERE id='" + $uB.Unit + "'")) 'DESTINO'
Assert-Eq 'B devoluciones registradas' (Scalar ("SELECT COUNT(*) FROM return_operations WHERE unidad_fisica_id='" + $uB.Unit + "' AND estado_al_devolver='OK'")) '1'
Assert-Eq 'B devolucion con destino correcto' (Scalar ("SELECT COUNT(*) FROM return_operations WHERE unidad_fisica_id='" + $uB.Unit + "' AND ubicacion_destino_id='" + $storage + "'")) '1'
# CU-009 enlaza automaticamente la delivery de la unidad (no hizo falta enviar delivery_id)
Assert-Eq 'B devolucion enlaza la delivery de la unidad' (Scalar ("SELECT IF(delivery_id IS NULL,'SIN_DELIVERY','CON_DELIVERY') FROM return_operations WHERE unidad_fisica_id='" + $uB.Unit + "'")) 'CON_DELIVERY'
Assert-Eq 'B devolucion enlaza la delivery RECHAZADA del envio' (Scalar ("SELECT COUNT(*) FROM return_operations ro JOIN deliveries d ON d.id = ro.delivery_id WHERE ro.unidad_fisica_id='" + $uB.Unit + "' AND d.estado='RECHAZADA' AND d.shipment_id='" + $uB.Shipment + "'")) '1'
Assert-Eq 'B evento DISPONIBLE' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uB.Unit + "' AND tipo_evento='DISPONIBLE'")) '1'
Assert-Eq 'B unidades fisiadas intactas (ledger fisica=1)' (Scalar ("SELECT IF(cantidad_fisica=1,'1','NO1') FROM stock_ledger WHERE unidad_fisica_id='" + $uB.Unit + "'")) '1'
Assert-Eq 'B eventos con hash de 64 hex' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uB.Unit + "' AND hash_integridad REGEXP '^[0-9a-fA-F]{64}$'")) (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uB.Unit + "'"))

$r = Invoke-Get ("/inventory/returns?unidad_fisica_id=" + $uB.Unit) $tokenA
if ($r.Ok) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'B GET /returns por unidad total' $d.data.pagination.total 1
} else { Bad 'B GET /returns por unidad' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/returns?estado=OK&page=1&per_page=100' $tokenA
if ($r.Ok) {
    $d = ConvertFrom-Json $r.Content
    if ([int]$d.data.pagination.total -ge 1) { Ok 'B GET /returns?estado=OK' "total=$($d.data.pagination.total)" }
    else { Bad 'B GET /returns?estado=OK' "total=$($d.data.pagination.total)" }
} else { Bad 'B GET /returns?estado=OK' "$($r.Status)" }

# ==================================================== 8) FLUJO C
Write-Output ''
Write-Output '--- FLUJO C: entrega completa + devolucion danada por el cliente (CU-007 + CU-009) ---'
$uC = Prepare-Unit 'C'
if (-not $uC) { exit 1 }

$keyC = "v180-cf-C-" + [guid]::NewGuid().ToString('N')
$cfBodyC = (@{
    shipment_id = $uC.Shipment
    unidades = @(@{ unidad_fisica_id = $uC.Unit })
    destinatario_real = 'Cliente ACME'
    firma_comprobante = 'https://acme.local/firmas/e2e-c.png'
} | ConvertTo-Json -Depth 5)

$r = Invoke-Post '/inventory/deliveries/confirm' $cfBodyC $keyC $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'C POST /deliveries/confirm' '200' }
else { Bad 'C POST /deliveries/confirm' "$($r.Status) $($r.Content)" }
Assert-Eq 'C AUDIT confirm (2 filas)' (AuditCount $keyC) '2'
Assert-Eq 'C shipments.estado' (Scalar ("SELECT estado FROM shipments WHERE id='" + $uC.Shipment + "'")) 'ENTREGADO'
Assert-Eq 'C stock_units ENTREGADO' (Scalar ("SELECT estado FROM stock_units WHERE id='" + $uC.Unit + "'")) 'ENTREGADO'

# devolucion con unidad danada: estado DAADO (enye via escape \u00d1 en JSON)
$keyD = "v180-rt-C-" + [guid]::NewGuid().ToString('N')
$rtBodyC = '{"unidad_fisica_id":"' + $uC.Unit + '","motivo":"DA\u00d1O","estado_al_devolver":"DA\u00d1ADO","observaciones":"E2E v1.8.0 devolucion danada"}'

$r = Invoke-Post '/inventory/returns/register' $rtBodyC $keyD $tokenB
if ($r.Ok -and $r.Status -eq 201) { Ok 'C POST /returns/register estado danado' '201' }
else { Bad 'C POST /returns/register estado danado' "$($r.Status) $($r.Content)" }

Assert-Eq 'C AUDIT return (2 filas)' (AuditCount $keyD) '2'
# estado de la unidad: DAADO (6 caracteres, evita literales con enye)
Assert-Eq 'C stock_units estado danado' (Scalar ("SELECT IF(CHAR_LENGTH(estado)=6 AND estado LIKE 'DA__DO','DAADO','OTRO:" + (Scalar ("SELECT estado FROM stock_units WHERE id='" + $uC.Unit + "'")) + "') FROM stock_units WHERE id='" + $uC.Unit + "'")) 'DAADO'
Assert-Eq 'C stock_units en zona DANADOS' (Scalar ("SELECT IF(ubicacion_id='" + $danadosId + "','DANADOS','OTRO') FROM stock_units WHERE id='" + $uC.Unit + "'")) 'DANADOS'
Assert-Eq 'C devolucion estado danado' (Scalar ("SELECT IF(CHAR_LENGTH(estado_al_devolver)=6 AND estado_al_devolver LIKE 'DA__DO','DAADO','OTRO') FROM return_operations WHERE unidad_fisica_id='" + $uC.Unit + "'")) 'DAADO'
Assert-Eq 'C incidencia de dano' (Scalar ("SELECT COUNT(*) FROM incidents WHERE unidad_fisica_id='" + $uC.Unit + "' AND CHAR_LENGTH(tipo)=4 AND tipo LIKE 'DA_O'")) '1'
Assert-Eq 'C evento DANADO' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uC.Unit + "' AND CHAR_LENGTH(tipo_evento)=6 AND tipo_evento LIKE 'DA__DO'")) '1'
Assert-Eq 'C envio sigue ENTREGADO' (Scalar ("SELECT estado FROM shipments WHERE id='" + $uC.Shipment + "'")) 'ENTREGADO'
Assert-Eq 'C eventos con hash de 64 hex' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uC.Unit + "' AND hash_integridad REGEXP '^[0-9a-fA-F]{64}$'")) (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uC.Unit + "'"))

# ==================================================== 9) comprobaciones globales
Write-Output ''
Write-Output '--- COMPROBACIONES GLOBALES ---'

# paginacion
$r = Invoke-Get '/inventory/deliveries?page=1&per_page=1' $tokenA
if ($r.Ok) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'GET deliveries per_page=1 devuelve 1 fila' $d.data.data.Count 1
    Assert-Eq 'GET deliveries per_page=1 en pagination' $d.data.pagination.per_page 1
    if ([int]$d.data.pagination.total -ge 3) { Ok 'GET deliveries total' "total=$($d.data.pagination.total)" }
    else { Bad 'GET deliveries total' "total=$($d.data.pagination.total) (esperado >=3)" }
} else { Bad 'GET deliveries paginacion' "$($r.Status)" }

# rango de fechas invalido -> 400 con field
$r = Invoke-Get '/inventory/returns?fecha_desde=no-es-fecha' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match '"field"') {
    Ok 'GET returns fecha invalida' '400 con field'
} else { Bad 'GET returns fecha invalida' "$($r.Status) $($r.Content)" }

# tope de per_page
$r = Invoke-Get '/inventory/returns?page=1&per_page=500' $tokenA
if ($r.Ok) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'GET returns tope per_page=100' $d.data.pagination.per_page 100
} else { Bad 'GET returns tope per_page' "$($r.Status)" }

# sin duplicados de auditoria para las claves v180
$dups = Scalar "SELECT COUNT(*) FROM (SELECT detalles_adicionales, operacion, COUNT(*) c FROM audit_logs WHERE detalles_adicionales LIKE '%v180-%' GROUP BY detalles_adicionales, operacion HAVING c > 1) t"
Assert-Eq 'AUDIT sin filas duplicadas por clave+operacion' $dups '0'

# los tres POST exigen clave: comprobacion del resto de rutas
$r = Invoke-Post '/inventory/deliveries/failed' '{"shipment_id":"x","motivo":"OTRO","unidades":[]}' '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match 'idempotency-key') { Ok 'failed sin Idempotency-Key' '400' }
else { Bad 'failed sin Idempotency-Key' "$($r.Status) $($r.Content)" }

$r = Invoke-Post '/inventory/returns/register' '{"unidad_fisica_id":"x","motivo":"OTRO","estado_al_devolver":"OK"}' '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match 'idempotency-key') { Ok 'returns/register sin Idempotency-Key' '400' }
else { Bad 'returns/register sin Idempotency-Key' "$($r.Status) $($r.Content)" }

Write-Output ''
Write-Output '--- AUDITORIA (claves v1.8.0) ---'
Sql "SELECT operacion, recurso, resultado, entidad_id FROM audit_logs WHERE detalles_adicionales LIKE '%v180-cf-A%' OR detalles_adicionales LIKE '%v180-fl-B%' OR detalles_adicionales LIKE '%v180-rt-B%' OR detalles_adicionales LIKE '%v180-cf-C%' OR detalles_adicionales LIKE '%v180-rt-C%' ORDER BY timestamp;"

Write-Output ''
Write-Output '--- UNIDADES E2E ---'
Sql ("SELECT id, estado, ubicacion_id FROM stock_units WHERE id IN ('" + $uA.Unit + "','" + $uB.Unit + "','" + $uC.Unit + "');")
Sql ("SELECT id, estado FROM shipments WHERE id IN ('" + $uA.Shipment + "','" + $uB.Shipment + "','" + $uC.Shipment + "');")

Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E v1.8.0: TODO OK' } else { Write-Output "E2E v1.8.0: $script:failures FALLOS" }

# v1.8.3: el servidor PHP embebido se detiene al terminar cada E2E.
Stop-E2EServer
exit $script:failures
