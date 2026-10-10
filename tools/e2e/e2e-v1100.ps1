$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido (v1.10.0)
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
            -ContentType 'application/json; charset=utf-8' -Body ([System.Text.Encoding]::UTF8.GetBytes($json)) -TimeoutSec 25
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

function JsonField($json, $path) {
    try {
        $o = $json | ConvertFrom-Json
        foreach ($p in $path.Split('.')) {
            # PS 5.1 no indexa arrays con $o.$p cuando $p es la cadena '0'.
            if ($o -is [System.Array] -and $p -match '^\d+$') { $o = $o[[int]$p] }
            else { $o = $o.$p }
        }
        if ($null -eq $o) { return $null }
        return "$o"
    } catch { return $null }
}

# BD de TRABAJO (solo lectura): el E2E no debe escribir en ella (regla 10).
$script:prodDb = $null
$envFile = Join-Path $e2eRepoRoot '.env'
if (Test-Path -LiteralPath $envFile) {
    foreach ($linea in Get-Content -LiteralPath $envFile) {
        if ($linea -match '^\s*DB_DATABASE\s*=\s*([A-Za-z0-9_]+)') { $script:prodDb = $Matches[1]; break }
    }
}

function ProdScalar($sql) {
    if (-not $script:prodDb) { return $null }
    $v = & mysql.exe -u root $script:prodDb -N -e $sql | Select-Object -First 1
    if ($null -eq $v) { return $null }
    return ("$v".Trim())
}

function ProdSnapshot() {
    $sql = "SELECT CONCAT((SELECT COUNT(*) FROM incidents), '|', (SELECT COUNT(*) FROM stock_units), '|', (SELECT COUNT(*) FROM stock_events), '|', (SELECT COUNT(*) FROM movements), '|', (SELECT IFNULL(MD5(GROUP_CONCAT(CONCAT(sku, ':', nombre) ORDER BY sku)), 'x') FROM products))"
    return (ProdScalar $sql)
}

$env:PATH = "C:\xampp\mysql\bin;$env:PATH"

# ================================================================== 0) prod
$prodAntes = ProdSnapshot
if ($script:prodDb) { Ok 'BD de trabajo identificada (solo lectura)' $script:prodDb }
else { Bad 'BD de trabajo identificada' 'sin DB_DATABASE en .env' }

# ================================================================== 1) actores
$tokenA = Login 'aleon@acme.local' 'Admin123!'
if (-not $tokenA) { Bad 'LOGIN aleon' 'sin token'; exit 1 }
Ok 'LOGIN aleon@acme.local' "ADMIN token len=$($tokenA.Length)"

# USER: tiene inventory.stock.view pero NO inventory.stock.block.
$tokenU = Login 'user@acme.local' 'Admin123!'
if (-not $tokenU) { Bad 'LOGIN user@acme.local' 'sin token'; exit 1 }
Ok 'LOGIN user@acme.local (sin stock.block)' "USER token len=$($tokenU.Length)"

# ==================================================== 2) recursos base
$almacen = (Scalar "SELECT id FROM locations WHERE codigo='E2E-ALMACEN' AND activa=1")
if (-not $almacen) { Bad 'ubicacion E2E-ALMACEN' 'sin fixture'; exit 1 }
Ok 'ubicacion E2E-ALMACEN' "id=$almacen"

$pick = (Scalar "SELECT id FROM locations WHERE codigo='E2E-PICK' AND activa=1")
if (-not $pick) { Bad 'zona E2E-PICK' 'sin fixture'; exit 1 }
Ok 'zona E2E-PICK' "id=$pick"

$incsAntes = [int](Scalar 'SELECT COUNT(*) FROM incidents')
$unitsAntes = [int](Scalar 'SELECT COUNT(*) FROM stock_units')

# Auto-normalizacion de capacidad (v1.11.0): este script no depende del estado
# que dejen otros del lote. Antes de capturar las lineas base se iguala
# locations.capacidad_usada al SUM(CEIL(cantidad)) real via el CLI de E2E
# (--e2e falla si la conexion apunta a otra BD que no sea E2E_DB_NAME).
$recalcOut = & $phpExe (Join-Path $e2eRepoRoot 'bin\recalculate-capacity.php') --apply --e2e 2>&1
$recalcExit = $LASTEXITCODE
foreach ($linea in @($recalcOut)) {
    if ($null -ne $linea -and "$linea" -ne '') { Write-Output ("  [recalc] " + $linea) }
}
if ($recalcExit -eq 0) { Ok 'capacidad auto-normalizada (recalculate-capacity --apply --e2e)' 'lineas base coerentes' }
else { Bad 'capacidad auto-normalizada (recalculate-capacity --apply --e2e)' "exit=$recalcExit"; exit 1 }

function Receive-Unit($tag, $cantidad) {
    $rcvKey = "v1100-rcv-$tag-" + [guid]::NewGuid().ToString('N')
    $rcvBody = (@{ sku = 'PROD-001'; cantidad_esperada = $cantidad; ubicacion_codigo = 'E2E-ALMACEN' } | ConvertTo-Json)
    $r = Invoke-Post '/inventory/receive' $rcvBody $rcvKey $script:tokenA
    if (-not $r.Ok -or $r.Status -ne 201) { Bad "[$tag] receive" "$($r.Status) $($r.Content)"; return $null }
    $unidad = (ConvertFrom-Json $r.Content).data.unidad_fisica_id
    Ok "[$tag] receive" "unidad=$unidad"
    return $unidad
}

function UnitEstado($id) { Scalar ("SELECT estado FROM stock_units WHERE id='" + $id + "'") }
function UnitUbicacion($id) { Scalar ("SELECT IF(ubicacion_id IS NULL,'SIN_UBI',ubicacion_id) FROM stock_units WHERE id='" + $id + "'") }
function Cupo($locId) { Scalar ("SELECT IF(capacidad_usada IS NULL, 'NULL', capacidad_usada) FROM locations WHERE id='" + $locId + "'") }
# Cupo con SQL crudo: el backend recalcula capacidad_usada con
# SUM(CEIL(cantidad)) (InventoryPresenceService::cupoUsado), no con delta del
# contador almacenado; en una BD reutilizada el almacenado previo puede estar
# desfasado y los asserts de delta fallarian aunque el release sea correcto.
function CupoRaw($locId) { Scalar ("SELECT IFNULL(SUM(CEIL(cantidad)),0) FROM stock_units WHERE tenant_id = (SELECT tenant_id FROM locations WHERE id='" + $locId + "') AND ubicacion_id='" + $locId + "'") }

# ==================================================== 3) FLUJO A: E4
# Reporte UBICACION_DESCONOCIDA bloquea la unidad (sin tocar cupo) y la
# resolucion ENCONTRADA la devuelve a DISPONIBLE en la ubicacion destino.
Write-Output ''
Write-Output '--- FLUJO A: reporte bloquea + resolve ENCONTRADA (E4) ---'
# El cupo (capacidad_usada) es la SUMA de cantidad de las unidades presentes.
$cupoAlmBase = CupoRaw $almacen
$cupoPickBase = CupoRaw $pick
$uA = Receive-Unit 'A' 10
if (-not $uA) { exit 1 }

$cupoAlmA = Cupo $almacen
$cupoPickA = Cupo $pick

$keyRA = "v1100-rp-A-" + [guid]::NewGuid().ToString('N')
$rpBodyA = (@{ tipo = 'UBICACION_DESCONOCIDA'; unidad_fisica_id = $uA; ubicacion_id = $almacen; descripcion = 'Unidad no esta en su estanteria (E2E v1.10.0)'; observaciones = 'E2E v1.10.0 ' + $script:runId } | ConvertTo-Json)
$r = Invoke-Post '/inventory/incidents/report' $rpBodyA $keyRA $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'A POST /incidents/report (UBICACION_DESCONOCIDA)' '201' }
else { Bad 'A POST /incidents/report' "$($r.Status) $($r.Content)"; exit 1 }
$incA = JsonField $r.Content 'data.incidencia.incidencia_id'
Ok 'A incidencia creada' "id=$incA"

Assert-Eq 'A unidad BLOQUEADO en BD' (UnitEstado $uA) 'BLOQUEADO'
Assert-Eq 'A unidad conserva ubicacion' (UnitUbicacion $uA) $almacen
Assert-Eq 'A incidencia ABIERTA' (Scalar ("SELECT estado FROM incidents WHERE id='" + $incA + "'")) 'ABIERTA'
Assert-Eq 'A prioridad NORMAL en el reporte manual' (Scalar ("SELECT prioridad FROM incidents WHERE id='" + $incA + "'")) 'NORMAL'
Assert-Eq 'A evento BLOQUEADO' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA + "' AND tipo_evento='BLOQUEADO'")) '1'
Assert-Eq 'A sin movimiento nuevo' (Scalar ("SELECT COUNT(*) FROM movements WHERE unidad_fisica_id='" + $uA + "'")) '0'
Assert-Eq 'A cupo del almacen intacto al bloquear' (Cupo $almacen) $cupoAlmA
Assert-Eq 'A AUDIT report (2 filas)' (AuditCount $keyRA) '2'

# historial fusionado (R3)
$r = Invoke-Get "/inventory/stock/$uA/history?page=1&per_page=50" $tokenU
if ($r.Ok -and $r.Status -eq 200) { Ok 'A GET /stock/{id}/history con USER (stock.view)' '200' }
else { Bad 'A GET /stock/{id}/history' "$($r.Status) $($r.Content)" }
$histA = $null
try { $histA = ConvertFrom-Json $r.Content } catch { $histA = $null }
if ($histA) {
    $entradas = @($histA.data.data)
    if ($entradas.Count -ge 2) { Ok 'A historial con eventos + movimientos' "n=$($entradas.Count)" }
    else { Bad 'A historial con eventos + movimientos' "n=$($entradas.Count)" }
    $tipos = ($entradas | ForEach-Object { $_.tipo }) -join ','
    if ($tipos -match 'RECIBIDO' -and $tipos -match 'BLOQUEADO') { Ok 'A historial trae RECIBIDO y BLOQUEADO' $tipos }
    else { Bad 'A historial trae RECIBIDO y BLOQUEADO' $tipos }
    if ($histA.data.unidad.unidad_fisica_id -eq $uA) { Ok 'A historial trae la unidad' $uA }
    else { Bad 'A historial trae la unidad' "$($histA.data.unidad.unidad_fisica_id)" }
} else { Bad 'A historial JSON' 'sin cuerpo' }

# resolve: primero sin resultado -> 400
$keySA = "v1100-rs-A-" + [guid]::NewGuid().ToString('N')
$rsBodySin = (@{ estado = 'RESUELTA'; descripcion_resolucion = 'Sin resultado todavia'; acciones_tomadas = 'Pendiente' } | ConvertTo-Json)
$r = Invoke-Post "/inventory/incidents/$incA/resolve" $rsBodySin $keySA $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400) { Ok 'A resolve sin resultado -> 400' '400' }
else { Bad 'A resolve sin resultado -> 400' "$($r.Status) $($r.Content)" }
Assert-Eq 'A error field=resultado' (JsonField $r.Content 'data.field') 'resultado'
Assert-Eq 'A unidad sigue BLOQUEADA tras el 400' (UnitEstado $uA) 'BLOQUEADO'

# resolve con NO_ENCONTRADA + destino -> 400 (destino solo con ENCONTRADA)
$rsBodyNo = (@{ estado = 'RESUELTA'; descripcion_resolucion = 'No encontrada con destino'; acciones_tomadas = 'Busqueda fallida'; resultado = 'NO_ENCONTRADA'; ubicacion_destino_id = $pick } | ConvertTo-Json)
$r = Invoke-Post "/inventory/incidents/$incA/resolve" $rsBodyNo '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400) { Ok 'A resolve NO_ENCONTRADA con destino -> 400' '400' }
else { Bad 'A resolve NO_ENCONTRADA con destino -> 400' "$($r.Status) $($r.Content)" }
Assert-Eq 'A error field=ubicacion_destino_id' (JsonField $r.Content 'data.field') 'ubicacion_destino_id'

# resolve valido: ENCONTRADA con destino
$keySA = "v1100-rs-A-" + [guid]::NewGuid().ToString('N')
$rsBodyOk = (@{ estado = 'RESUELTA'; descripcion_resolucion = 'Unidad reaparece en zona de picking'; acciones_tomadas = 'Devuelta a estanteria tras revision'; resultado = 'ENCONTRADA'; ubicacion_destino_id = $pick } | ConvertTo-Json)
$r = Invoke-Post "/inventory/incidents/$incA/resolve" $rsBodyOk $keySA $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'A resolve ENCONTRADA' '200' }
else { Bad 'A resolve ENCONTRADA' "$($r.Status) $($r.Content)"; exit 1 }
Assert-Eq 'A unidad DISPONIBLE' (UnitEstado $uA) 'DISPONIBLE'
Assert-Eq 'A unidad en E2E-PICK' (UnitUbicacion $uA) $pick
Assert-Eq 'A incidencia RESUELTA' (Scalar ("SELECT estado FROM incidents WHERE id='" + $incA + "'")) 'RESUELTA'
Assert-Eq 'A resolucion con resultado ENCONTRADA' (Scalar ("SELECT JSON_UNQUOTE(JSON_EXTRACT(resolucion, '$.resultado')) FROM incidents WHERE id='" + $incA + "'")) 'ENCONTRADA'
Assert-Eq 'A resolucion con ubicacion destino' (Scalar ("SELECT JSON_UNQUOTE(JSON_EXTRACT(resolucion, '$.ubicacion_destino_id')) FROM incidents WHERE id='" + $incA + "'")) $pick
Assert-Eq 'A evento DISPONIBLE al encontrar' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA + "' AND tipo_evento='DISPONIBLE'")) '1'
Assert-Eq 'A una sola incidencia para la unidad' (Scalar ("SELECT COUNT(*) FROM incidents WHERE unidad_fisica_id='" + $uA + "'")) '1'

$cupoAlmDesp = Cupo $almacen
$cupoAlmDespRaw = CupoRaw $almacen
$cupoPickDespRaw = CupoRaw $pick
if (($cupoAlmBase -match '^\d+$') -and ($cupoAlmDespRaw -match '^\d+$')) {
    Assert-Eq 'A cupo del almacen vuelve a la linea base (SQL crudo -10)' ([int]$cupoAlmDespRaw) ([int]$cupoAlmBase)
    Assert-Eq 'A capacidad_usada tras resolve = SQL crudo' ([int]$cupoAlmDesp) ([int]$cupoAlmDespRaw)
} else { Ok 'A cupo del almacen' "base=$cupoAlmBase despues=$cupoAlmDespRaw" }
if (($cupoPickBase -match '^\d+$') -and ($cupoPickDespRaw -match '^\d+$')) {
    Assert-Eq 'A cupo de picking +10 (SQL crudo)' ([int]$cupoPickDespRaw) ([int]$cupoPickBase + 10)
} else { Ok 'A cupo de picking' "base=$cupoPickBase despues=$cupoPickDespRaw" }

# replay idempotente (misma clave, mismo cuerpo)
$r2 = Invoke-Post "/inventory/incidents/$incA/resolve" $rsBodyOk $keySA $tokenA
if ($r2.Ok -and $r2.Content -eq $r.Content) { Ok 'A REPLAY resolve (body identico)' 'ok' }
else { Bad 'A REPLAY resolve' "status=$($r2.Status) difiere" }

# ==================================================== 4) FLUJO B: E4
# INVENTARIO_DESAPARECIDO: sin resultado -> 400; NO_ENCONTRADA -> PERDIDO
# y libera la ubicacion.
Write-Output ''
Write-Output '--- FLUJO B: INVENTARIO_DESAPARECIDO + NO_ENCONTRADA (E4) ---'
$uB = Receive-Unit 'B' 1
if (-not $uB) { exit 1 }
$cupoAlmB = CupoRaw $almacen

$keyRB = "v1100-rp-B-" + [guid]::NewGuid().ToString('N')
$rpBodyB = (@{ tipo = 'INVENTARIO_DESAPARECIDO'; unidad_fisica_id = $uB; ubicacion_id = $almacen; descripcion = 'Unidad sin rastro (E2E v1.10.0)'; observaciones = 'E2E v1.10.0 ' + $script:runId } | ConvertTo-Json)
$r = Invoke-Post '/inventory/incidents/report' $rpBodyB $keyRB $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'B POST /incidents/report (INVENTARIO_DESAPARECIDO)' '201' }
else { Bad 'B POST /incidents/report' "$($r.Status) $($r.Content)"; exit 1 }
$incB = JsonField $r.Content 'data.incidencia.incidencia_id'
Assert-Eq 'B unidad BLOQUEADO' (UnitEstado $uB) 'BLOQUEADO'

$rsBodyB = (@{ estado = 'RESUELTA'; descripcion_resolucion = 'No aparece tras busqueda'; acciones_tomadas = 'Revisado camaras y estanterias'; resultado = 'NO_ENCONTRADA' } | ConvertTo-Json)
$r = Invoke-Post "/inventory/incidents/$incB/resolve" $rsBodyB "v1100-rs-B-$script:runId" $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'B resolve NO_ENCONTRADA' '200' }
else { Bad 'B resolve NO_ENCONTRADA' "$($r.Status) $($r.Content)"; exit 1 }
Assert-Eq 'B unidad PERDIDO' (UnitEstado $uB) 'PERDIDO'
Assert-Eq 'B unidad sin ubicacion (cupo liberado)' (UnitUbicacion $uB) 'SIN_UBI'
Assert-Eq 'B resolucion con resultado NO_ENCONTRADA' (Scalar ("SELECT JSON_UNQUOTE(JSON_EXTRACT(resolucion, '$.resultado')) FROM incidents WHERE id='" + $incB + "'")) 'NO_ENCONTRADA'
Assert-Eq 'B evento PERDIDO' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uB + "' AND tipo_evento='PERDIDO'")) '1'
$cupoAlmBDesp = CupoRaw $almacen
if (($cupoAlmB -match '^\d+$') -and ($cupoAlmBDesp -match '^\d+$')) {
    Assert-Eq 'B cupo liberado (SQL crudo -1)' ([int]$cupoAlmBDesp) ([int]$cupoAlmB - 1)
} else { Ok 'B cupo' "antes=$cupoAlmB despues=$cupoAlmBDesp" }

# ==================================================== 5) FLUJO C: E4
# DAÑO no admite `resultado` (400) y su resolucion valida no toca la unidad.
Write-Output ''
Write-Output '--- FLUJO C: DAÑO no admite resultado (E4) ---'
$uC = Receive-Unit 'C' 1
if (-not $uC) { exit 1 }

$keyRC = "v1100-rp-C-" + [guid]::NewGuid().ToString('N')
# 'DA\u00D1O' en ASCII puro: Invoke-WebRequest de PS 5.1 reencodea los no-ASCII
# del body y el backend no llegaria a leer el campo tipo.
$rpBodyC = '{"tipo":"DA\u00D1O","unidad_fisica_id":"' + $uC + '","ubicacion_id":"' + $almacen + '","descripcion":"Caja golpeada (E2E v1.10.0)","observaciones":"E2E v1.10.0 ' + $script:runId + '"}'
$r = Invoke-Post '/inventory/incidents/report' $rpBodyC $keyRC $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'C POST /incidents/report (DAÑO)' '201' }
else { Bad 'C POST /incidents/report' "$($r.Status) $($r.Content)"; exit 1 }
$incC = JsonField $r.Content 'data.incidencia.incidencia_id'
Assert-Eq 'C unidad DAÑADO' (UnitEstado $uC) 'DAÑADO'

$rsBodyC = (@{ estado = 'RESUELTA'; descripcion_resolucion = 'Resuelta con resultado'; acciones_tomadas = 'No deberia pasar'; resultado = 'ENCONTRADA' } | ConvertTo-Json)
$r = Invoke-Post "/inventory/incidents/$incC/resolve" $rsBodyC '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400) { Ok 'C resolve con resultado en DAÑO -> 400' '400' }
else { Bad 'C resolve con resultado en DAÑO -> 400' "$($r.Status) $($r.Content)" }
Assert-Eq 'C error field=resultado' (JsonField $r.Content 'data.field') 'resultado'
Assert-Eq 'C unidad sigue DAÑADO tras el 400' (UnitEstado $uC) 'DAÑADO'

$rsBodyC2 = (@{ estado = 'RESUELTA'; descripcion_resolucion = 'Reparada y retirada'; acciones_tomadas = 'Aislada en zona de daños'; compensacion = 'Ninguna' } | ConvertTo-Json)
$r = Invoke-Post "/inventory/incidents/$incC/resolve" $rsBodyC2 "v1100-rs-C-$script:runId" $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'C resolve valida sin resultado' '200' }
else { Bad 'C resolve valida sin resultado' "$($r.Status) $($r.Content)" }
Assert-Eq 'C unidad sigue DAÑADO (sin transicion)' (UnitEstado $uC) 'DAÑADO'
# JSON null (clave presente con valor null) no es SQL NULL: hace falta JSON_TYPE.
$sinResultadoSql = "IF(JSON_EXTRACT(resolucion, '$.resultado') IS NULL OR JSON_TYPE(JSON_EXTRACT(resolucion, '$.resultado')) = 'NULL', 'SIN_RESULTADO', 'CON_RESULTADO')"
Assert-Eq 'C resolucion sin resultado' (Scalar ("SELECT " + $sinResultadoSql + " FROM incidents WHERE id='" + $incC + "'")) 'SIN_RESULTADO'

# ==================================================== 6) FLUJO D: E4
# Endpoints de bloqueo con permiso inventory.stock.block.
Write-Output ''
Write-Output '--- FLUJO D: POST /stock/{id}/block|unblock (E4) ---'
$uD = Receive-Unit 'D' 1
if (-not $uD) { exit 1 }
$cupoAlmD = Cupo $almacen

$keyBD = "v1100-blk-D-" + [guid]::NewGuid().ToString('N')
$r = Invoke-Post "/inventory/stock/$uD/block" '{}' $keyBD $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'D POST /stock/{id}/block' '200' }
else { Bad 'D POST /stock/{id}/block' "$($r.Status) $($r.Content)"; exit 1 }
Assert-Eq 'D estado_anterior DISPONIBLE' (JsonField $r.Content 'data.estado_anterior') 'DISPONIBLE'
Assert-Eq 'D estado BLOQUEADO' (JsonField $r.Content 'data.estado') 'BLOQUEADO'
Assert-Eq 'D unidad BLOQUEADO en BD' (UnitEstado $uD) 'BLOQUEADO'
Assert-Eq 'D cupo intacto al bloquear' (Cupo $almacen) $cupoAlmD
Assert-Eq 'D evento BLOQUEADO' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uD + "' AND tipo_evento='BLOQUEADO'")) '1'
Assert-Eq 'D evento con hash de 64 hex (solo BLOQUEADO)' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uD + "' AND tipo_evento='BLOQUEADO' AND hash_integridad REGEXP '^[0-9a-fA-F]{64}$'")) '1'

# replay con la misma clave
$r2 = Invoke-Post "/inventory/stock/$uD/block" '{}' $keyBD $tokenA
if ($r2.Ok -and $r2.Content -eq $r.Content) { Ok 'D REPLAY block (body identico)' 'ok' }
else { Bad 'D REPLAY block' "status=$($r2.Status) difiere" }

# X -> BLOQUEADO ya bloqueado -> 422
$r = Invoke-Post "/inventory/stock/$uD/block" '{}' "v1100-blk2-D-$script:runId" $tokenA
if ((-not $r.Ok) -and $r.Status -eq 422) { Ok 'D block repetido -> 422' '422' }
else { Bad 'D block repetido -> 422' "$($r.Status) $($r.Content)" }
Assert-Eq 'D error field=estado' (JsonField $r.Content 'data.field') 'estado'

# USER sin inventory.stock.block -> 403
$r = Invoke-Post "/inventory/stock/$uD/block" '{}' '' $tokenU
if ((-not $r.Ok) -and $r.Status -eq 403) { Ok 'D block con USER (sin permiso) -> 403' '403' }
else { Bad 'D block con USER (sin permiso) -> 403' "$($r.Status) $($r.Content)" }
$r = Invoke-Post "/inventory/stock/$uD/unblock" '{}' '' $tokenU
if ((-not $r.Ok) -and $r.Status -eq 403) { Ok 'D unblock con USER (sin permiso) -> 403' '403' }
else { Bad 'D unblock con USER (sin permiso) -> 403' "$($r.Status) $($r.Content)" }
Assert-Eq 'D unidad sigue BLOQUEADA tras 403' (UnitEstado $uD) 'BLOQUEADO'

# desbloqueo con ADMIN
$r = Invoke-Post "/inventory/stock/$uD/unblock" '{}' "v1100-unb-D-$script:runId" $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'D POST /stock/{id}/unblock' '200' }
else { Bad 'D POST /stock/{id}/unblock' "$($r.Status) $($r.Content)" }
Assert-Eq 'D estado DISPONIBLE tras unblock' (JsonField $r.Content 'data.estado') 'DISPONIBLE'
Assert-Eq 'D unidad DISPONIBLE en BD' (UnitEstado $uD) 'DISPONIBLE'

# DISPONIBLE -> DISPONIBLE -> 422
$r = Invoke-Post "/inventory/stock/$uD/unblock" '{}' "v1100-unb2-D-$script:runId" $tokenA
if ((-not $r.Ok) -and $r.Status -eq 422) { Ok 'D unblock repetido -> 422' '422' }
else { Bad 'D unblock repetido -> 422' "$($r.Status) $($r.Content)" }

# ==================================================== 7) FLUJO E: E1
# Rechazo total: sin unidad, sin ledger, sin evento; incidencia ALTA.
Write-Output ''
Write-Output '--- FLUJO E: rechazo_total (E1) ---'
$unitsAntesE = [int](Scalar 'SELECT COUNT(*) FROM stock_units')
$incsAntesE = [int](Scalar 'SELECT COUNT(*) FROM incidents')
# La BD E2E acumula datos entre ejecuciones: los contajes son deltas, no absolutos.
$altasAntesE = [int](Scalar "SELECT COUNT(*) FROM incidents WHERE prioridad='ALTA'")
$cupoAlmE = Cupo $almacen

$keyRE = "v1100-rcv-R-" + [guid]::NewGuid().ToString('N')
$rcvR = (@{ sku = 'PROD-001'; cantidad_esperada = 5; ubicacion_codigo = 'E2E-ALMACEN'; rechazo_total = 1 } | ConvertTo-Json)
$r = Invoke-Post '/inventory/receive' $rcvR $keyRE $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'E receive con rechazo_total' '201' }
else { Bad 'E receive con rechazo_total' "$($r.Status) $($r.Content)"; exit 1 }
Assert-Eq 'E rechazo_total en la respuesta' (JsonField $r.Content 'data.rechazo_total') 'True'
Assert-Eq 'E sin unidad creada' (JsonField $r.Content 'data.unidad_fisica_id') ''
Assert-Eq 'E sin ledger' (JsonField $r.Content 'data.ledger') ''
Assert-Eq 'E sin evento' (JsonField $r.Content 'data.evento') ''
Assert-Eq 'E recepcion_parcial False' (JsonField $r.Content 'data.recepcion_parcial') 'False'
Assert-Eq 'E discrepancia 100 %' ([math]::Round([double](JsonField $r.Content 'data.discrepancia_porcentaje'), 2)) 100
Assert-Eq 'E incidencia ENTREGA_RECHAZADA' (JsonField $r.Content 'data.incidencia.tipo') 'ENTREGA_RECHAZADA'
Assert-Eq 'E incidencia prioridad ALTA' (JsonField $r.Content 'data.incidencia.prioridad') 'ALTA'
Assert-Eq 'E stock_units sin cambios' ([int](Scalar 'SELECT COUNT(*) FROM stock_units')) $unitsAntesE
Assert-Eq 'E cupo intacto' (Cupo $almacen) $cupoAlmE
Assert-Eq 'E una incidencia ALTA mas' ([int](Scalar "SELECT COUNT(*) FROM incidents WHERE prioridad='ALTA'")) ($altasAntesE + 1)
Assert-Eq 'E una incidencia mas en total' ([int](Scalar 'SELECT COUNT(*) FROM incidents')) ($incsAntesE + 1)

# rechazo_total con cantidad_recibida > 0 -> 400
$rcvR2 = (@{ sku = 'PROD-001'; cantidad_esperada = 5; ubicacion_codigo = 'E2E-ALMACEN'; rechazo_total = 1; cantidad_recibida = 3 } | ConvertTo-Json)
$keyRE2 = "v1100-rcv-E2-" + [guid]::NewGuid().ToString('N')
$r = Invoke-Post '/inventory/receive' $rcvR2 $keyRE2 $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400) { Ok 'E rechazo_total con recibida 3 -> 400' '400' }
else { Bad 'E rechazo_total con recibida 3 -> 400' "$($r.Status) $($r.Content)" }
Assert-Eq 'E error field=cantidad_recibida' (JsonField $r.Content 'data.field') 'cantidad_recibida'
Assert-Eq 'E stock_units sigue igual' ([int](Scalar 'SELECT COUNT(*) FROM stock_units')) $unitsAntesE

# ==================================================== 8) FLUJO F: E1
# Umbral de discrepancia (5 % por defecto): 6 % -> ALTA, 0.1 % -> NORMAL.
Write-Output ''
Write-Output '--- FLUJO F: umbral de discrepancia (E1) ---'
$rcvT1 = (@{ sku = 'PROD-001'; cantidad_esperada = 10; cantidad_recibida = 9.4; ubicacion_codigo = 'E2E-ALMACEN' } | ConvertTo-Json)
$r = Invoke-Post '/inventory/receive' $rcvT1 "v1100-rcv-T1-$script:runId" $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'F receive 10/9.4 (6 %)' '201' }
else { Bad 'F receive 10/9.4' "$($r.Status) $($r.Content)" }
Assert-Eq 'F discrepancia 6 %' ([math]::Round([double](JsonField $r.Content 'data.discrepancia_porcentaje'), 2)) 6
Assert-Eq 'F recepcion parcial' (JsonField $r.Content 'data.recepcion_parcial') 'True'
Assert-Eq 'F incidencia prioridad ALTA (>= umbral)' (JsonField $r.Content 'data.incidencia.prioridad') 'ALTA'
Assert-Eq 'F incidencia DISCREPANCIA_CANTIDAD' (JsonField $r.Content 'data.incidencia.tipo') 'DISCREPANCIA_CANTIDAD'

$rcvT2 = (@{ sku = 'PROD-001'; cantidad_esperada = 100; cantidad_recibida = 99.9; ubicacion_codigo = 'E2E-ALMACEN' } | ConvertTo-Json)
$r = Invoke-Post '/inventory/receive' $rcvT2 "v1100-rcv-T2-$script:runId" $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'F receive 100/99.9 (0.1 %)' '201' }
else { Bad 'F receive 100/99.9' "$($r.Status) $($r.Content)" }
Assert-Eq 'F discrepancia 0.1 %' ([math]::Round([double](JsonField $r.Content 'data.discrepancia_porcentaje'), 2)) 0.1
Assert-Eq 'F incidencia prioridad NORMAL (< umbral)' (JsonField $r.Content 'data.incidencia.prioridad') 'NORMAL'

# ==================================================== 9) FLUJO G: CU-002
# Mover una unidad RESERVADA devuelve data.warnings (no bloquea).
Write-Output ''
Write-Output '--- FLUJO G: warnings al mover unidad reservada (CU-002) ---'
$uG = Receive-Unit 'G' 2
if (-not $uG) { exit 1 }

$keyResG = "v1100-rsv-G-" + [guid]::NewGuid().ToString('N')
$resBody = (@{ unidad_fisica_id = $uG; cantidad = 2; referencia_orden = 'E2E-ORD-G' } | ConvertTo-Json)
$r = Invoke-Post '/inventory/reservations' $resBody $keyResG $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'G POST /reservations (cantidad completa)' '201' }
else { Bad 'G POST /reservations' "$($r.Status) $($r.Content)"; exit 1 }
Assert-Eq 'G unidad RESERVADO' (UnitEstado $uG) 'RESERVADO'

$keyMG = "v1100-mv-G-" + [guid]::NewGuid().ToString('N')
$mvBody = (@{ unidad_fisica_id = $uG; ubicacion_destino_id = $pick; motivo = 'REUBICACION' } | ConvertTo-Json)
$r = Invoke-Post '/inventory/movements' $mvBody $keyMG $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'G POST /movements con unidad reservada' '201' }
else { Bad 'G POST /movements' "$($r.Status) $($r.Content)"; exit 1 }
$warnCodigo = JsonField $r.Content 'data.warnings.0.codigo'
$warnMensaje = JsonField $r.Content 'data.warnings.0.mensaje'
Assert-Eq 'G warnings[0].codigo = UNIDAD_RESERVADA' $warnCodigo 'UNIDAD_RESERVADA'
if ($warnMensaje -match 'E2E-ORD-G') { Ok 'G warning con la referencia de la orden' $warnMensaje }
else { Bad 'G warning con la referencia de la orden' "mensaje='$warnMensaje'" }
Assert-Eq 'G movimiento no es correccion' (JsonField $r.Content 'data.movimiento.es_correccion') 'False'

# sin Idempotency-Key -> 400
$r = Invoke-Post '/inventory/movements' $mvBody '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400) { Ok 'G movements sin Idempotency-Key -> 400' '400' }
else { Bad 'G movements sin Idempotency-Key -> 400' "$($r.Status) $($r.Content)" }

# ==================================================== 10) FLUJO H: E8
# Movimiento inverso -> es_correccion con referencia; filtros del listado.
Write-Output ''
Write-Output '--- FLUJO H: correccion de movimiento (E8) + listado ---'
$uH = Receive-Unit 'H' 1
if (-not $uH) { exit 1 }

$keyH1 = "v1100-mv-H1-" + [guid]::NewGuid().ToString('N')
$mvH1 = (@{ unidad_fisica_id = $uH; ubicacion_destino_id = $pick } | ConvertTo-Json)
$r = Invoke-Post '/inventory/movements' $mvH1 $keyH1 $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'H movimiento 1 (almacen -> picking)' '201' }
else { Bad 'H movimiento 1' "$($r.Status) $($r.Content)"; exit 1 }
$mvId1 = JsonField $r.Content 'data.movimiento.movimiento_id'
Assert-Eq 'H movimiento 1 no es correccion' (JsonField $r.Content 'data.movimiento.es_correccion') 'False'
Assert-Eq 'H movimiento 1 sin referencia' (JsonField $r.Content 'data.movimiento.movimiento_referencia_id') ''

$keyH2 = "v1100-mv-H2-" + [guid]::NewGuid().ToString('N')
$mvH2 = (@{ unidad_fisica_id = $uH; ubicacion_destino_id = $almacen } | ConvertTo-Json)
$r = Invoke-Post '/inventory/movements' $mvH2 $keyH2 $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'H movimiento inverso (picking -> almacen)' '201' }
else { Bad 'H movimiento inverso' "$($r.Status) $($r.Content)"; exit 1 }
$mvId2 = JsonField $r.Content 'data.movimiento.movimiento_id'
Assert-Eq 'H movimiento 2 marcado es_correccion' (JsonField $r.Content 'data.movimiento.es_correccion') 'True'
Assert-Eq 'H movimiento 2 referencia al 1' (JsonField $r.Content 'data.movimiento.movimiento_referencia_id') $mvId1
Assert-Eq 'H hash integro del movimiento 2' (Scalar ("SELECT COUNT(*) FROM movements WHERE id='" + $mvId2 + "' AND hash_integridad REGEXP '^[0-9a-fA-F]{64}$'")) '1'
Assert-Eq 'H historico intacto (movimiento 1 sin referencia)' (Scalar ("SELECT IF(movimiento_referencia_id IS NULL,'SIN_REF','CON_REF') FROM movements WHERE id='" + $mvId1 + "'")) 'SIN_REF'

# listado con filtros (E8 + validaciones)
$r = Invoke-Get '/inventory/movements?page=1&per_page=20' $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'H GET /movements' '200' }
else { Bad 'H GET /movements' "$($r.Status) $($r.Content)" }
$summaryCorr = JsonField $r.Content 'data.summary.correcciones'
if ([int]$summaryCorr -ge 1) { Ok 'H summary.correcciones >= 1' $summaryCorr }
else { Bad 'H summary.correcciones >= 1' $summaryCorr }

$r = Invoke-Get '/inventory/movements?es_correccion=1' $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $encontrado = $r.Content -match [regex]::Escape($mvId2)
    if ($encontrado) { Ok 'H filtro es_correccion=1 incluye la correccion' $mvId2 }
    else { Bad 'H filtro es_correccion=1 incluye la correccion' "sin $mvId2" }
} else { Bad 'H GET /movements?es_correccion=1' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/movements?es_correccion=xyz' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400) { Ok 'H es_correccion invalido -> 400' '400' }
else { Bad 'H es_correccion invalido -> 400' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/movements?fecha_desde=notadate' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400) { Ok 'H fecha_desde invalida -> 400' '400' }
else { Bad 'H fecha_desde invalida -> 400' "$($r.Status) $($r.Content)" }

# USER con stock.view puede leer el listado y el historial
$r = Invoke-Get '/inventory/movements?page=1' $tokenU
if ($r.Ok -and $r.Status -eq 200) { Ok 'H GET /movements con USER (stock.view)' '200' }
else { Bad 'H GET /movements con USER' "$($r.Status) $($r.Content)" }
$r = Invoke-Get "/inventory/stock/$uH/history" $tokenU
if ($r.Ok -and $r.Status -eq 200) { Ok 'H GET /stock/{id}/history con USER' '200' }
else { Bad 'H GET /stock/{id}/history con USER' "$($r.Status) $($r.Content)" }
$r = Invoke-Get '/inventory/stock/00000000-0000-4000-8000-000000000000/history' $tokenU
if ((-not $r.Ok) -and $r.Status -eq 404) { Ok 'H history de unidad inexistente -> 404' '404' }
else { Bad 'H history de unidad inexistente -> 404' "$($r.Status) $($r.Content)" }

# ==================================================== 11) globales
Write-Output ''
Write-Output '--- COMPROBACIONES GLOBALES ---'

$dups = Scalar "SELECT COUNT(*) FROM (SELECT detalles_adicionales, operacion, COUNT(*) c FROM audit_logs WHERE detalles_adicionales LIKE '%v1100-%' GROUP BY detalles_adicionales, operacion HAVING c > 1) t"
Assert-Eq 'AUDIT sin filas duplicadas por clave+operacion' $dups '0'

$r = Invoke-Get '/inventory/incidents?page=1&per_page=5' $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'GET /incidents' '200' }
else { Bad 'GET /incidents' "$($r.Status) $($r.Content)" }

Write-Output ''
Write-Output '--- AUDITORIA (claves v1.10.0) ---'
Sql "SELECT operacion, recurso, resultado, entidad_id FROM audit_logs WHERE detalles_adicionales LIKE '%v1100-rp-A%' OR detalles_adicionales LIKE '%v1100-rs-A%' OR detalles_adicionales LIKE '%v1100-blk-D%' OR detalles_adicionales LIKE '%v1100-unb-D%' OR detalles_adicionales LIKE '%v1100-rcv-R%' OR detalles_adicionales LIKE '%v1100-mv-H2%' ORDER BY timestamp;"

Write-Output ''
Write-Output '--- UNIDADES E2E ---'
Sql ("SELECT id, estado, ubicacion_id, cantidad FROM stock_units WHERE id IN ('" + $uA + "','" + $uB + "','" + $uC + "','" + $uD + "','" + $uG + "','" + $uH + "');")
Write-Output ('  incidencias nuevas: ' + ([int](Scalar 'SELECT COUNT(*) FROM incidents') - $incsAntes))
Write-Output ('  unidades nuevas: ' + ([int](Scalar 'SELECT COUNT(*) FROM stock_units') - $unitsAntes))

# ==================================================== 12) la BD de trabajo no cambia
Write-Output ''
$prodDespues = ProdSnapshot
if ($script:prodDb) {
    if ($prodAntes -eq $prodDespues -and $null -ne $prodAntes) {
        Ok 'BD de trabajo sin escrituras del E2E' "huella=$prodAntes"
    } else {
        Bad 'BD de trabajo sin escrituras del E2E' "antes=$prodAntes despues=$prodDespues"
    }
}

Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E v1.10.0: TODO OK' } else { Write-Output "E2E v1.10.0: $script:failures FALLOS" }

Stop-E2EServer
exit $script:failures
