$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido (v1.9.1)
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

# Instantanea de la BD de trabajo: recuentos de las tablas que este E2E
# escribe (incidencias, conteos, unidades, eventos) + huella de productos.
function ProdSnapshot() {
    $sql = "SELECT CONCAT((SELECT COUNT(*) FROM incidents), '|', (SELECT COUNT(*) FROM inventory_counts), '|', (SELECT COUNT(*) FROM stock_units), '|', (SELECT COUNT(*) FROM stock_events), '|', (SELECT IFNULL(MD5(GROUP_CONCAT(CONCAT(sku, ':', nombre) ORDER BY sku)), 'x') FROM products))"
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

$tokenB = Login 'manager@acme.local' 'Admin123!'
if (-not $tokenB) { Bad 'LOGIN 2do actor' 'sin token'; exit 1 }
Ok 'LOGIN manager@acme.local (2do actor)' "token len=$($tokenB.Length)"

$script:tokenA = $tokenA
$script:tokenB = $tokenB

# ================================================= 2) recursos base
$almacen = (Scalar "SELECT id FROM locations WHERE codigo='E2E-ALMACEN' AND activa=1")
if (-not $almacen) { Bad 'ubicacion E2E-ALMACEN' 'sin fixture'; exit 1 }
Ok 'ubicacion E2E-ALMACEN' "id=$almacen"

$pick = (Scalar "SELECT id FROM locations WHERE codigo='E2E-PICK' AND activa=1")
if (-not $pick) { Bad 'zona E2E-PICK' 'sin fixture'; exit 1 }
Ok 'zona E2E-PICK' "id=$pick"

# ==================================================== 3) helper de preparacion
function Receive-Unit($tag, $codigo, $cantidad) {
    $rcvKey = "v191-rcv-$tag-" + [guid]::NewGuid().ToString('N')
    $rcvBody = (@{ sku = 'PROD-001'; cantidad_esperada = $cantidad; ubicacion_codigo = $codigo } | ConvertTo-Json)
    $r = Invoke-Post '/inventory/receive' $rcvBody $rcvKey $script:tokenA
    if (-not $r.Ok -or $r.Status -ne 201) { Bad "[$tag] receive" "$($r.Status) $($r.Content)"; return $null }
    $unidad = (ConvertFrom-Json $r.Content).data.unidad_fisica_id
    Ok "[$tag] receive" "unidad=$unidad"
    return $unidad
}

# ==================================================== 4) FLUJO A: PERDIDA
# desde DISPONIBLE (desviacion aprobada v1.9.1: spec §4 dice NO, CU-010/CU-011
# lo exigen; antes de v1.9.1 se dejaba pasar SIN assertCanTransition).
Write-Output ''
Write-Output '--- FLUJO A: PERDIDA desde DISPONIBLE (v1.9.1) ---'
$uA = Receive-Unit 'A' 'E2E-ALMACEN' 1
if (-not $uA) { exit 1 }

$almacenSql = "tenant_id = (SELECT tenant_id FROM locations WHERE id='" + $almacen + "') AND ubicacion_id='" + $almacen + "'"
# Cupo usado con SQL crudo: el backend recalcula capacidad_usada con
# SUM(CEIL(cantidad)) (InventoryPresenceService::cupoUsado), no con delta del
# contador almacenado; en una BD reutilizada el almacenado previo puede estar
# desfasado y el assert -1 fallaria aunque el release sea correcto.
$slotsAntes = Scalar ("SELECT IFNULL(SUM(CEIL(cantidad)),0) FROM stock_units WHERE $almacenSql")
$incsAntes = [int](Scalar "SELECT COUNT(*) FROM incidents")

$keyP = "v191-rp-A-" + [guid]::NewGuid().ToString('N')
$rpBody = '{"tipo":"PERDIDA","unidad_fisica_id":"' + $uA + '","descripcion":"Unidad desaparecida del almacen (E2E v1.9.1)","ubicacion_id":"' + $almacen + '","observaciones":"E2E v1.9.1 ' + $script:runId + '"}'

$r = Invoke-Post '/inventory/incidents/report' $rpBody $keyP $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'A POST /incidents/report (PERDIDA)' '201' }
else { Bad 'A POST /incidents/report (PERDIDA)' "$($r.Status) $($r.Content)" }

$d = $null
try { $d = ConvertFrom-Json $r.Content } catch { $d = $null }
if ($d -and $d.data.incidencia.incidencia_id) { Ok 'A incidencia creada' "id=$($d.data.incidencia.incidencia_id)" }
else { Bad 'A incidencia creada' 'sin incidencia_id en la respuesta' }
$incA = if ($d) { $d.data.incidencia.incidencia_id } else { $null }

Assert-Eq 'A unidad PERDIDO en BD' (Scalar ("SELECT estado FROM stock_units WHERE id='" + $uA + "'")) 'PERDIDO'
Assert-Eq 'A unidad sin ubicacion (cupo liberado)' (Scalar ("SELECT IF(ubicacion_id IS NULL,'SIN_UBI','CON_UBI') FROM stock_units WHERE id='" + $uA + "'")) 'SIN_UBI'
Assert-Eq 'A incidencia ABIERTA en BD' (Scalar ("SELECT estado FROM incidents WHERE id='" + $incA + "'")) 'ABIERTA'
Assert-Eq 'A tipo PERDIDA en BD' (Scalar ("SELECT tipo FROM incidents WHERE id='" + $incA + "'")) 'PERDIDA'
Assert-Eq 'A una incidencia mas en el tenant' ([int](Scalar "SELECT COUNT(*) FROM incidents")) ($incsAntes + 1)
Assert-Eq 'A evento PERDIDA' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA + "' AND tipo_evento='PERDIDO'")) '1'
Assert-Eq 'A evento con hash de 64 hex' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA + "' AND hash_integridad REGEXP '^[0-9a-fA-F]{64}$'")) (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA + "'"))
Assert-Eq 'A ledger en PERDIDO' (Scalar ("SELECT estado_actual FROM stock_ledger WHERE unidad_fisica_id='" + $uA + "' ORDER BY id DESC LIMIT 1")) 'PERDIDO'
Assert-Eq 'A AUDIT report PERDIDA (2 filas)' (AuditCount $keyP) '2'

$capDespues = Scalar ("SELECT IF(capacidad_usada IS NULL, 'NULL', capacidad_usada) FROM locations WHERE id='" + $almacen + "'")
$slotsDespues = Scalar ("SELECT IFNULL(SUM(CEIL(cantidad)),0) FROM stock_units WHERE $almacenSql")
if (($slotsAntes -match '^\d+$') -and ([int]$slotsAntes -gt 0) -and ($slotsDespues -match '^\d+$')) {
    Assert-Eq 'A cupo liberado (SQL crudo -1 en SUM(CEIL(cantidad)))' ([int]$slotsDespues) ([int]$slotsAntes - 1)
    Assert-Eq 'A capacidad_usada recalculada = SQL crudo' ([int]$capDespues) ([int]$slotsDespues)
} else {
    Ok 'A cupo liberado' "cupo sin cambio observable (antes=$slotsAntes despues=$slotsDespues)"
}

# replay idempotente
$r2 = Invoke-Post '/inventory/incidents/report' $rpBody $keyP $tokenA
if ($r2.Ok -and $r2.Content -eq $r.Content) { Ok 'A REPLAY report (body identico)' 'ok' }
else { Bad 'A REPLAY report' "status=$($r2.Status) difiere" }
Assert-Eq 'A AUDIT sin duplicar tras replay' (AuditCount $keyP) '2'
Assert-Eq 'A una sola incidencia para la unidad' (Scalar ("SELECT COUNT(*) FROM incidents WHERE unidad_fisica_id='" + $uA + "' AND tipo='PERDIDA'")) '1'

# ==================================================== 5) FLUJO B: PERDIDA
# desde un estado NO contemplado en la matriz -> 422 estricto (v1.9.1).
Write-Output ''
Write-Output '--- FLUJO B: PERDIDA desde EN_TRANSITO -> 422 (v1.9.1) ---'
$uB = Receive-Unit 'B' 'E2E-ALMACEN' 1
if (-not $uB) { exit 1 }

# El envio completo es pesado: la unidad se coloca directamente en EN_TRANSITO
# (fixture local a la BD de E2E) para ejercitar el rechazo de la matriz.
& mysql.exe -u root $e2eDb -e ("UPDATE stock_units SET estado='EN_TRANSITO' WHERE id='" + $uB + "'")
Assert-Eq 'B unidad en EN_TRANSITO (fixture)' (Scalar ("SELECT estado FROM stock_units WHERE id='" + $uB + "'")) 'EN_TRANSITO'

$keyPB = "v191-rp-B-" + [guid]::NewGuid().ToString('N')
$rpBodyB = '{"tipo":"PERDIDA","unidad_fisica_id":"' + $uB + '","descripcion":"Unidad en transito no puede marcarse perdida (E2E v1.9.1)","ubicacion_id":"' + $almacen + '","observaciones":"E2E v1.9.1 ' + $script:runId + '"}'

$r = Invoke-Post '/inventory/incidents/report' $rpBodyB $keyPB $tokenA
if ((-not $r.Ok) -and $r.Status -eq 422) { Ok 'B report PERDIDA desde EN_TRANSITO' '422' }
else { Bad 'B report PERDIDA desde EN_TRANSITO' "$($r.Status) $($r.Content)" }

$d = $null
try { $d = ConvertFrom-Json $r.Content } catch { $d = $null }
if ($d) {
    Assert-Eq 'B error field=estado' $d.data.field 'estado'
    Assert-Eq 'B error category business_rule' $d.data.category 'business_rule'
    if ($r.Content -match 'EN_TRANSITO') { Ok 'B mensaje con el estado de origen' 'EN_TRANSITO' }
    else { Bad 'B mensaje con el estado de origen' "sin EN_TRANSITO en: $($r.Content)" }
    if ($r.Content -match 'PERDIDO') { Ok 'B mensaje con el estado destino' 'PERDIDO' }
    else { Bad 'B mensaje con el estado destino' "sin PERDIDO en: $($r.Content)" }
} else { Bad 'B cuerpo de error' 'sin JSON' }

Assert-Eq 'B unidad intacta' (Scalar ("SELECT estado FROM stock_units WHERE id='" + $uB + "'")) 'EN_TRANSITO'
Assert-Eq 'B sin incidencia creada' (Scalar ("SELECT COUNT(*) FROM incidents WHERE unidad_fisica_id='" + $uB + "'")) '0'
Assert-Eq 'B sin evento PERDIDA' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uB + "' AND tipo_evento='PERDIDO'")) '0'
Ok 'B AUDIT del intento rechazado' "filas=$(AuditCount $keyPB) (no se exige recuento)"

# ==================================================== 6) FLUJO C: hoja
# vencida en AJUSTAR -> 422 con detalle; JUSTIFICAR sigue permitido.
Write-Output ''
Write-Output '--- FLUJO C: AJUSTAR con hoja vencida -> 422 con detalle (v1.9.1) ---'
$uC = Receive-Unit 'C' 'E2E-ALMACEN' 1
if (-not $uC) { exit 1 }

$keyC = "v191-st-C-" + [guid]::NewGuid().ToString('N')
$stBody = (@{ ubicacion_id = $almacen; fecha = '2026-10-04' } | ConvertTo-Json)
$r = Invoke-Post '/inventory/counts/start' $stBody $keyC $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'C POST /counts/start' '201' }
else { Bad 'C POST /counts/start' "$($r.Status) $($r.Content)"; exit 1 }
$cntId = (ConvertFrom-Json $r.Content).data.conteo.conteo_id
Ok 'C conteo creado' "id=$cntId"

# hoja teorica (instantanea de las cantidades al iniciar)
$r = Invoke-Get ("/inventory/counts/" + $cntId + "/sheet") $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $filas = @((ConvertFrom-Json $r.Content).data.filas)
    Ok 'C sheet filas' "n=$($filas.Count)"
} else { Bad 'C GET /counts/{id}/sheet' "$($r.Status) $($r.Content)"; $filas = @() }

$esperadaC = 0.0
$resultados = @()
foreach ($f in $filas) {
    $e = [double]$f.cantidad_esperada
    if ($f.unidad_fisica_id -eq $uC) {
        $esperadaC = $e
        # Diferencia +1: crea la discrepancia que luego intentaremos AJUSTAR
        $resultados += @{ unidad_fisica_id = $f.unidad_fisica_id; cantidad_encontrada = $e + 1 }
    } else {
        $resultados += @{ unidad_fisica_id = $f.unidad_fisica_id; cantidad_encontrada = $e }
    }
}
$esperadaTexto = ('{0:f4}' -f $esperadaC)

$rsBody = (@{ resultados = $resultados } | ConvertTo-Json -Depth 6)
$r = Invoke-Post ("/inventory/counts/" + $cntId + "/results") $rsBody '' $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'C POST /counts/{id}/results' '200' }
else { Bad 'C POST /counts/{id}/results' "$($r.Status) $($r.Content)" }

$r = Invoke-Post ("/inventory/counts/" + $cntId + "/complete") '{}' '' $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $dc = ConvertFrom-Json $r.Content
    if ([int]$dc.data.total_discrepancias -ge 1) { Ok 'C complete con discrepancia' "n=$($dc.data.total_discrepancias)" }
    else { Bad 'C complete con discrepancia' "n=$($dc.data.total_discrepancias)" }
} else { Bad 'C POST /counts/{id}/complete' "$($r.Status) $($r.Content)" }

# La unidad cambia DESPUES de la instantanea: la hoja queda vencida.
& mysql.exe -u root $e2eDb -e ("UPDATE stock_units SET cantidad = 5 WHERE id='" + $uC + "'")
Assert-Eq 'C unidad con cantidad 5 (dato alterado)' ([math]::Round([double](Scalar ("SELECT cantidad FROM stock_units WHERE id='" + $uC + "'")), 4)) 5
$eventosAntes = [int](Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uC + "' AND tipo_evento='AJUSTE_CANTIDAD'"))

$keyZ = "v191-rc-C-" + [guid]::NewGuid().ToString('N')
$rcBody = (@{ acciones = @(@{ unidad_fisica_id = $uC; accion = 'AJUSTAR' }) } | ConvertTo-Json -Depth 6)
$r = Invoke-Post ("/inventory/counts/" + $cntId + "/reconcile") $rcBody $keyZ $tokenB

if ((-not $r.Ok) -and $r.Status -eq 422) { Ok 'C reconcile AJUSTAR con hoja vencida' '422' }
else { Bad 'C reconcile AJUSTAR con hoja vencida' "$($r.Status) $($r.Content)" }

$d = $null
try { $d = ConvertFrom-Json $r.Content } catch { $d = $null }
if ($d) {
    Assert-Eq 'C error field=cantidad' $d.data.field 'cantidad'
    Assert-Eq 'C error category business_rule' $d.data.category 'business_rule'
    foreach ($frag in @($uC, ('cantidad_esperada ' + $esperadaTexto), 'cantidad actual 5.0000', 'JUSTIFICAR', 'conteo nuevo')) {
        if ($r.Content -match [regex]::Escape($frag)) { Ok 'C mensaje con detalle' $frag }
        else { Bad 'C mensaje con detalle' "sin '$frag' en: $($r.Content)" }
    }
} else { Bad 'C cuerpo de error' 'sin JSON' }

Assert-Eq 'C unidad NO ajustada (5 intacta)' ([math]::Round([double](Scalar ("SELECT cantidad FROM stock_units WHERE id='" + $uC + "'")), 4)) 5
Assert-Eq 'C sin evento AJUSTE_CANTIDAD' ([int](Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uC + "' AND tipo_evento='AJUSTE_CANTIDAD'"))) $eventosAntes
Assert-Eq 'C conteo sigue COMPLETADO' (Scalar ("SELECT estado FROM inventory_counts WHERE id='" + $cntId + "'")) 'COMPLETADO'
Ok 'C AUDIT del intento rechazado' "filas=$(AuditCount $keyZ) (no se exige recuento)"

# JUSTIFICAR con la hoja vencida sigue permitido
$keyJ = "v191-rj-C-" + [guid]::NewGuid().ToString('N')
$rjBody = (@{ acciones = @(@{ unidad_fisica_id = $uC; accion = 'JUSTIFICAR'; justificacion = 'Recontada fuera del sistema durante el conteo (E2E v1.9.1).' }) } | ConvertTo-Json -Depth 6)
$r = Invoke-Post ("/inventory/counts/" + $cntId + "/reconcile") $rjBody $keyJ $tokenB
if ($r.Ok -and $r.Status -eq 200) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'C JUSTIFICAR->RECONCILIADO' $d.data.conteo.estado 'RECONCILIADO'
} else { Bad 'C reconcile JUSTIFICAR' "$($r.Status) $($r.Content)" }

Assert-Eq 'C unidad sigue con 5 tras JUSTIFICAR' ([math]::Round([double](Scalar ("SELECT cantidad FROM stock_units WHERE id='" + $uC + "'")), 4)) 5
Assert-Eq 'C AUDIT justify (2 filas)' (AuditCount $keyJ) '2'

# Sanidad de capacidad (v1.11.0): el SQL crudo de 'cantidad' del flujo C deja
# locations.capacidad_usada por debajo del SUM(CEIL(cantidad)) real (residuo
# que arrastraba a otros scripts del lote, p. ej. e2e-v1100). Se recalcula
# contra la BD de E2E (--e2e falla si la conexion apunta a otra BD) para que
# este script no deje residuos.
$recalcOut = & $phpExe (Join-Path $e2eRepoRoot 'bin\recalculate-capacity.php') --apply --e2e 2>&1
$recalcExit = $LASTEXITCODE
foreach ($linea in @($recalcOut)) {
    if ($null -ne $linea -and "$linea" -ne '') { Write-Output ("  [recalc] " + $linea) }
}
if ($recalcExit -eq 0) { Ok 'C capacidad saneada (recalculate-capacity --apply --e2e)' 'sin residuos' }
else { Bad 'C capacidad saneada (recalculate-capacity --apply --e2e)' "exit=$recalcExit" }

# ==================================================== 7) globales
Write-Output ''
Write-Output '--- COMPROBACIONES GLOBALES ---'

$dups = Scalar "SELECT COUNT(*) FROM (SELECT detalles_adicionales, operacion, COUNT(*) c FROM audit_logs WHERE detalles_adicionales LIKE '%v191-%' GROUP BY detalles_adicionales, operacion HAVING c > 1) t"
Assert-Eq 'AUDIT sin filas duplicadas por clave+operacion' $dups '0'

$r = Invoke-Get '/inventory/incidents?estado=RESUELTA&page=1&per_page=5' $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'GET /incidents?estado=RESUELTA' '200' }
else { Bad 'GET /incidents?estado' "$($r.Status) $($r.Content)" }

$r = Invoke-Get ("/inventory/counts/" + $cntId) $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'GET /counts/{id} estado' $d.data.estado 'RECONCILIADO'
} else { Bad 'GET /counts/{id}' "$($r.Status) $($r.Content)" }

Write-Output ''
Write-Output '--- AUDITORIA (claves v1.9.1) ---'
Sql "SELECT operacion, recurso, resultado, entidad_id FROM audit_logs WHERE detalles_adicionales LIKE '%v191-rp-A%' OR detalles_adicionales LIKE '%v191-rp-B%' OR detalles_adicionales LIKE '%v191-st-C%' OR detalles_adicionales LIKE '%v191-rc-C%' OR detalles_adicionales LIKE '%v191-rj-C%' ORDER BY timestamp;"

Write-Output ''
Write-Output '--- UNIDADES E2E ---'
Sql ("SELECT id, estado, ubicacion_id, cantidad FROM stock_units WHERE id IN ('" + $uA + "','" + $uB + "','" + $uC + "');")
Sql ("SELECT id, estado, reconciliado FROM inventory_counts WHERE id='" + $cntId + "';")

# ==================================================== 8) la BD de trabajo no cambia
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
if ($script:failures -eq 0) { Write-Output 'E2E v1.9.1: TODO OK' } else { Write-Output "E2E v1.9.1: $script:failures FALLOS" }

Stop-E2EServer
exit $script:failures
