$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido (v1.9.0)
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

# ================================================= 2) permisos sembrados (Sprint 9)
$permTotal = Scalar "SELECT COUNT(*) FROM permissions WHERE identificador LIKE 'inventory.%'"
Assert-Eq 'PERM total inventory.*' $permTotal '34'

foreach ($p in 'inventory.incident.view', 'inventory.incident.report', 'inventory.incident.resolve',
                'inventory.count.view', 'inventory.count.create', 'inventory.count.reconcile') {
    $c = Scalar ("SELECT COUNT(*) FROM permissions WHERE identificador='" + $p + "' AND activo=1")
    Assert-Eq "PERM $p existe y activo" $c '1'
}

# pertenencia por rol (roles.permisos, JSON)
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER','USER','VIEWER') AND permisos LIKE '%inventory.incident.view%'"
Assert-Eq 'PERM incident.view en 4 roles' $c '4'
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER','USER','VIEWER') AND permisos LIKE '%inventory.count.view%'"
Assert-Eq 'PERM count.view en 4 roles' $c '4'
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER','USER') AND permisos LIKE '%inventory.incident.report%'"
Assert-Eq 'PERM incident.report en ADMIN+MANAGER+USER' $c '3'
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER','USER') AND permisos LIKE '%inventory.count.create%'"
Assert-Eq 'PERM count.create en ADMIN+MANAGER+USER' $c '3'
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER') AND permisos LIKE '%inventory.incident.resolve%'"
Assert-Eq 'PERM incident.resolve en ADMIN+MANAGER' $c '2'
$c = Scalar "SELECT COUNT(*) FROM roles WHERE deleted_at IS NULL AND nombre IN ('ADMIN','MANAGER') AND permisos LIKE '%inventory.count.reconcile%'"
Assert-Eq 'PERM count.reconcile en ADMIN+MANAGER' $c '2'

# ================================================= 3) recursos base
$almacen = (Scalar "SELECT id FROM locations WHERE codigo='E2E-ALMACEN' AND activa=1")
if (-not $almacen) { Bad 'ubicacion E2E-ALMACEN' 'sin fixture'; exit 1 }
Ok 'ubicacion E2E-ALMACEN' "id=$almacen"

$pick = (Scalar "SELECT id FROM locations WHERE codigo='E2E-PICK' AND activa=1")
if (-not $pick) { Bad 'zona E2E-PICK' 'sin fixture'; exit 1 }
Ok 'zona E2E-PICK' "id=$pick"

# ==================================================== 4) smoke de los listados
$r = Invoke-Get '/inventory/incidents?page=1&per_page=5' $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'GET /inventory/incidents' '200' }
else { Bad 'GET /inventory/incidents' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/counts?page=1&per_page=5' $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'GET /inventory/counts' '200' }
else { Bad 'GET /inventory/counts' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/incidents?estado=ESTADO_INEXISTENTE' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match '"field"') {
    Ok 'GET incidents estado invalido' '400 con field'
} else { Bad 'GET incidents estado invalido' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/counts?fecha_desde=no-es-fecha' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match '"field"') {
    Ok 'GET counts fecha invalida' '400 con field'
} else { Bad 'GET counts fecha invalida' "$($r.Status) $($r.Content)" }

# claves obligatorias en las tres escrituras con idempotencia
$r = Invoke-Post '/inventory/incidents/report' '{"tipo":"DA\u00d1O","descripcion":"x"}' '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match 'idempotency-key') {
    Ok 'report sin Idempotency-Key' '400 idempotency-key'
} else { Bad 'report sin Idempotency-Key' "$($r.Status) $($r.Content)" }

$r = Invoke-Post '/inventory/counts/start' '{}' '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match 'idempotency-key') {
    Ok 'counts/start sin Idempotency-Key' '400 idempotency-key'
} else { Bad 'counts/start sin Idempotency-Key' "$($r.Status) $($r.Content)" }

$r = Invoke-Post '/inventory/counts/no-existe/reconcile' '{"acciones":[]}' '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 400 -and $r.Content -match 'idempotency-key') {
    Ok 'reconcile sin Idempotency-Key' '400 idempotency-key'
} else { Bad 'reconcile sin Idempotency-Key' "$($r.Status) $($r.Content)" }

# ==================================================== 5) helper de preparacion
function Receive-Unit($tag, $codigo, $cantidad) {
    $rcvKey = "v190-rcv-$tag-" + [guid]::NewGuid().ToString('N')
    $rcvBody = (@{ sku = 'PROD-001'; cantidad_esperada = $cantidad; ubicacion_codigo = $codigo } | ConvertTo-Json)
    $r = Invoke-Post '/inventory/receive' $rcvBody $rcvKey $script:tokenA
    if (-not $r.Ok -or $r.Status -ne 201) { Bad "[$tag] receive" "$($r.Status) $($r.Content)"; return $null }
    $unidad = (ConvertFrom-Json $r.Content).data.unidad_fisica_id
    Ok "[$tag] receive" "unidad=$unidad"
    return $unidad
}

# ==================================================== 6) FLUJO A: incidencias
Write-Output ''
Write-Output '--- FLUJO A: reportar DA\u00d1O, investigar y resolver (CU-010) ---'
$uA = Receive-Unit 'A' 'E2E-ALMACEN' 1
if (-not $uA) { exit 1 }

$capacityBefore = Scalar ("SELECT IFNULL(capacidad_usada, -1) FROM locations WHERE id='" + $almacen + "'")

$keyR = "v190-rp-A-" + [guid]::NewGuid().ToString('N')
$rpBody = '{"tipo":"DA\u00d1O","unidad_fisica_id":"' + $uA + '","descripcion":"Unidad golpeada en almacen (E2E v1.9.0)","ubicacion_id":"' + $almacen + '","observaciones":"E2E v1.9.0 ' + $script:runId + '"}'

$r = Invoke-Post '/inventory/incidents/report' $rpBody $keyR $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'A POST /incidents/report' '201' }
else { Bad 'A POST /incidents/report' "$($r.Status) $($r.Content)" }
$bodyPrimera = $r.Content
$incId = (ConvertFrom-Json $r.Content).data.incidencia.incidencia_id
if ($incId) { Ok 'A incidencia creada' "id=$incId" } else { Bad 'A incidencia creada' 'sin incidencia_id' }

Assert-Eq 'A incidencia ABIERTA en BD' (Scalar ("SELECT estado FROM incidents WHERE id='" + $incId + "'")) 'ABIERTA'
Assert-Eq 'A unidad DA\u00d1ADA en BD' (Scalar ("SELECT IF(CHAR_LENGTH(estado)=6 AND estado LIKE 'DA__DO','DAADO','OTRO') FROM stock_units WHERE id='" + $uA + "'")) 'DAADO'
Assert-Eq 'A unidad sigue en su ubicacion' (Scalar ("SELECT IF(ubicacion_id='" + $almacen + "','ALMACEN','OTRO') FROM stock_units WHERE id='" + $uA + "'")) 'ALMACEN'
Assert-Eq 'A cupo intacto (sin liberar)' (Scalar ("SELECT IFNULL(capacidad_usada, -1) FROM locations WHERE id='" + $almacen + "'")) $capacityBefore
Assert-Eq 'A evento DA\u00d1ADO' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA + "' AND CHAR_LENGTH(tipo_evento)=6 AND tipo_evento LIKE 'DA__DO'")) '1'
Assert-Eq 'A evento con hash de 64 hex' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA + "' AND hash_integridad REGEXP '^[0-9a-fA-F]{64}$'")) (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uA + "'"))
Assert-Eq 'A ledger en estado DA\u00d1ADO' (Scalar ("SELECT IF(CHAR_LENGTH(estado_actual)=6 AND estado_actual LIKE 'DA__DO','DAADO','OTRO') FROM stock_ledger WHERE unidad_fisica_id='" + $uA + "'")) 'DAADO'
Assert-Eq 'A AUDIT report (2 filas)' (AuditCount $keyR) '2'

# replay idempotente
$r2 = Invoke-Post '/inventory/incidents/report' $rpBody $keyR $tokenA
if ($r2.Ok -and $r2.Content -eq $bodyPrimera) { Ok 'A REPLAY report (body identico)' "len=$($r2.Content.Length)" }
else { Bad 'A REPLAY report (body identico)' "status=$($r2.Status) difiere de la primera respuesta" }
if ($r2.Content -match '_replay') { Bad 'A REPLAY sin _replay' 'marcador interno colado en el JSON' }
else { Ok 'A REPLAY sin _replay' 'marcador interno fuera del JSON' }
Assert-Eq 'A una sola incidencia tras replay' (Scalar ("SELECT COUNT(*) FROM incidents WHERE unidad_fisica_id='" + $uA + "' AND CHAR_LENGTH(tipo)=4 AND tipo LIKE 'DA_O'")) '1'
Assert-Eq 'A AUDIT report sin duplicar' (AuditCount $keyR) '2'

# investigar (clave opcional; sin clave tambien vale)
$keyI = "v190-in-A-" + [guid]::NewGuid().ToString('N')
$r = Invoke-Post ("/inventory/incidents/" + $incId + "/investigate") '{}' $keyI $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'A POST /investigate' '200' }
else { Bad 'A POST /investigate' "$($r.Status) $($r.Content)" }
Assert-Eq 'A incidencia en INVESTIGACION' (Scalar ("SELECT estado FROM incidents WHERE id='" + $incId + "'")) 'INVESTIGACION'
Assert-Eq 'A AUDIT investigate (2 filas)' (AuditCount $keyI) '2'

$r2 = Invoke-Post ("/inventory/incidents/" + $incId + "/investigate") '{}' $keyI $tokenA
if ($r2.Ok -and $r2.Content -eq $r.Content) { Ok 'A REPLAY investigate (body identico)' 'ok' }
else { Bad 'A REPLAY investigate' "status=$($r2.Status)" }
Assert-Eq 'A AUDIT investigate sin duplicar' (AuditCount $keyI) '2'

# resolver
$keyS = "v190-rs-A-" + [guid]::NewGuid().ToString('N')
$rsBody = (@{
    estado = 'RESUELTA'
    descripcion_resolucion = 'Unidad revisada y repuesta en almacen (E2E v1.9.0)'
    acciones_tomadas = 'Se retiro del flujo y se revervio el embalaje'
    compensacion = 'Sin compensacion'
} | ConvertTo-Json)

$r = Invoke-Post ("/inventory/incidents/" + $incId + "/resolve") $rsBody $keyS $tokenB
if ($r.Ok -and $r.Status -eq 200) { Ok 'A POST /resolve (2do actor)' '200' }
else { Bad 'A POST /resolve (2do actor)' "$($r.Status) $($r.Content)" }

Assert-Eq 'A incidencia RESUELTA' (Scalar ("SELECT estado FROM incidents WHERE id='" + $incId + "'")) 'RESUELTA'
Assert-Eq 'A resolucion persistida' (Scalar ("SELECT IF(resolucion IS NULL,'SIN_RES',IF(resolucion LIKE '%RESUELTA%','CON_RES','SIN_ESTADO')) FROM incidents WHERE id='" + $incId + "'")) 'CON_RES'
Assert-Eq 'A AUDIT resolve (2 filas)' (AuditCount $keyS) '2'

$r2 = Invoke-Post ("/inventory/incidents/" + $incId + "/resolve") $rsBody $keyS $tokenB
if ($r2.Ok -and $r2.Content -eq $r.Content) { Ok 'A REPLAY resolve (body identico)' 'ok' }
else { Bad 'A REPLAY resolve' "status=$($r2.Status) difiere" }
Assert-Eq 'A AUDIT resolve sin duplicar' (AuditCount $keyS) '2'

# transicion no permitida: resolver otra vez con clave nueva -> 422
$keyS2 = "v190-rs2-A-" + [guid]::NewGuid().ToString('N')
$r = Invoke-Post ("/inventory/incidents/" + $incId + "/resolve") $rsBody $keyS2 $tokenA
if ((-not $r.Ok) -and $r.Status -eq 422) { Ok 'A resolve repetido' '422' }
else { Bad 'A resolve repetido' "$($r.Status) $($r.Content)" }

# detalle y listados
$r = Invoke-Get ("/inventory/incidents/" + $incId) $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'A GET /incidents/{id} estado' $d.data.estado 'RESUELTA'
} else { Bad 'A GET /incidents/{id}' "$($r.Status) $($r.Content)" }

$tipo = [uri]::EscapeDataString('DA' + [char]0x00D1 + 'O')
$r = Invoke-Get ("/inventory/incidents?tipo=" + $tipo + "&page=1&per_page=100") $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $d = ConvertFrom-Json $r.Content
    if ([int]$d.data.pagination.total -ge 1) { Ok 'A GET /incidents?tipo=DA-O' "total=$($d.data.pagination.total)" }
    else { Bad 'A GET /incidents?tipo=DA-O' "total=$($d.data.pagination.total)" }
} else { Bad 'A GET /incidents?tipo' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/incidents?estado=RESUELTA&page=1&per_page=100' $tokenA
if ($r.Ok) {
    $d = ConvertFrom-Json $r.Content
    if ([int]$d.data.pagination.total -ge 1) { Ok 'A GET /incidents?estado=RESUELTA' "total=$($d.data.pagination.total)" }
    else { Bad 'A GET /incidents?estado=RESUELTA' "total=$($d.data.pagination.total)" }
} else { Bad 'A GET /incidents?estado' "$($r.Status)" }

# ==================================================== 7) FLUJO B: conteo fisico
Write-Output ''
Write-Output '--- FLUJO B: conteo con diferencia y ajuste (CU-011) ---'
$uB1 = Receive-Unit 'B1' 'E2E-ALMACEN' 1
$uB2 = Receive-Unit 'B2' 'E2E-ALMACEN' 1
$uBP = Receive-Unit 'BP' 'E2E-PICK' 1
if (-not $uB1 -or -not $uB2 -or -not $uBP) { exit 1 }

$keyC = "v190-st-B-" + [guid]::NewGuid().ToString('N')
$stBody = (@{ ubicacion_id = $almacen; fecha = '2026-10-04' } | ConvertTo-Json)

$r = Invoke-Post '/inventory/counts/start' $stBody $keyC $tokenA
if ($r.Ok -and $r.Status -eq 201) { Ok 'B POST /counts/start' '201' }
else { Bad 'B POST /counts/start' "$($r.Status) $($r.Content)" }
$bodyPrimera = $r.Content
$cnt = (ConvertFrom-Json $r.Content).data
$cntId = $cnt.conteo.conteo_id
if ($cntId) { Ok 'B conteo creado' "id=$cntId" } else { Bad 'B conteo creado' 'sin conteo_id' }
Assert-Eq 'B conteo PENDIENTE' $cnt.conteo.estado 'PENDIENTE'
if ([int]$cnt.unidades_en_lista -ge 2) { Ok 'B unidades en la lista' "n=$($cnt.unidades_en_lista)" }
else { Bad 'B unidades en la lista' "n=$($cnt.unidades_en_lista) (esperado >=2)" }
Assert-Eq 'B una sola fila de conteo' (Scalar ("SELECT COUNT(*) FROM inventory_counts WHERE id='" + $cntId + "'")) '1'
Assert-Eq 'B AUDIT start (2 filas)' (AuditCount $keyC) '2'

$r2 = Invoke-Post '/inventory/counts/start' $stBody $keyC $tokenA
if ($r2.Ok -and $r2.Content -eq $bodyPrimera) { Ok 'B REPLAY start (body identico)' 'ok' }
else { Bad 'B REPLAY start' "status=$($r2.Status) difiere" }
if ($r2.Content -match '_replay') { Bad 'B REPLAY sin _replay' 'marcador colado' }
else { Ok 'B REPLAY sin _replay' 'marcador interno fuera del JSON' }
Assert-Eq 'B conteos totales tras replay' (Scalar ("SELECT COUNT(*) FROM inventory_counts WHERE id='" + $cntId + "'")) '1'
Assert-Eq 'B AUDIT start sin duplicar' (AuditCount $keyC) '2'

# hoja de conteo (JSON y CSV)
$r = Invoke-Get ("/inventory/counts/" + $cntId + "/sheet") $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $sheet = ConvertFrom-Json $r.Content
    $filas = @($sheet.data.filas)
    Assert-Eq 'B sheet filas = unidades_en_lista' $filas.Count $cnt.unidades_en_lista
} else { Bad 'B GET /counts/{id}/sheet' "$($r.Status) $($r.Content)"; $filas = @() }

$r = Invoke-Get ("/inventory/counts/" + $cntId + "/sheet?format=csv") $tokenA
if ($r.Ok -and $r.Status -eq 200 -and "$($r.Type)" -match 'text/csv') {
    Ok 'B sheet CSV content-type' "$($r.Type)"
    # El servidor emite siempre BOM UTF-8; el cliente .NET puede dejarlo como
    # U+FEFF o convertirlo en U+FFFD al decodificar. Se acepta con BOM (cualquiera
    # de los dos) o con la cabecera visible.
    $csvContent = $r.Content
    $firstCode = if ($csvContent.Length -gt 0) { [int][char]$csvContent[0] } else { 0 }
    $tieneBom = ($firstCode -eq 0xFEFF) -or ($firstCode -eq 0xFFFD)
    $tieneCabecera = $csvContent -match 'unidad_fisica_id,codigo_barcode'
    if ($tieneBom -and $tieneCabecera) {
        Ok 'B sheet CSV con BOM UTF-8' ('cabecera + BOM (primer char U+' + ('{0:X4}' -f $firstCode) + ')')
    } elseif ($tieneCabecera) {
        Ok 'B sheet CSV cabecera' 'visible (sin BOM visible)'
    } else {
        Bad 'B sheet CSV' "contenido inesperado: $($csvContent.Substring(0, [Math]::Min(60, $csvContent.Length)))"
    }
} else { Bad 'B sheet CSV' "$($r.Status) $($r.Type)" }

$r = Invoke-Get '/inventory/counts/no-existe/sheet' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 404) { Ok 'B sheet de conteo inexistente' '404' }
else { Bad 'B sheet de conteo inexistente' "$($r.Status)" }

# resultados: TODA la hoja, con diferencia +1 en nuestra unidad
$resultados = @()
foreach ($f in $filas) {
    $esperada = [double]$f.cantidad_esperada
    if ($f.unidad_fisica_id -eq $uB1) { $encontrada = $esperada + 1 }
    else { $encontrada = $esperada }
    $resultados += @{ unidad_fisica_id = $f.unidad_fisica_id; cantidad_encontrada = $encontrada }
}
$rsBody = (@{ resultados = $resultados } | ConvertTo-Json -Depth 6)
$r = Invoke-Post ("/inventory/counts/" + $cntId + "/results") $rsBody '' $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'B results estado' $d.data.conteo.estado 'EN_CURSO'
    Assert-Eq 'B results pendientes' $d.data.unidades_pendientes 0
} else { Bad 'B POST /counts/{id}/results' "$($r.Status) $($r.Content)" }

# unidad fuera de la hoja -> 422
$outBody = (@{ resultados = @(@{ unidad_fisica_id = $uBP; cantidad_encontrada = 1 }) } | ConvertTo-Json -Depth 6)
$r = Invoke-Post ("/inventory/counts/" + $cntId + "/results") $outBody '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 422 -and $r.Content -match 'unidad_fisica_id') {
    Ok 'B results unidad fuera de la hoja' '422'
} else { Bad 'B results unidad fuera de la hoja' "$($r.Status) $($r.Content)" }

# completar con todas las unidades contadas
$r = Invoke-Post ("/inventory/counts/" + $cntId + "/complete") '{}' '' $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'B complete estado' $d.data.conteo.estado 'COMPLETADO'
    Assert-Eq 'B complete 1 discrepancia' $d.data.total_discrepancias 1
    Assert-Eq 'B discrepancia de nuestra unidad' $d.data.discrepancias[0].unidad_fisica_id $uB1
    Assert-Eq 'B diferencia +1' ([double]$d.data.discrepancias[0].diferencia) 1
} else { Bad 'B POST /counts/{id}/complete' "$($r.Status) $($r.Content)" }

# completar dos veces -> 422
$r = Invoke-Post ("/inventory/counts/" + $cntId + "/complete") '{}' '' $tokenA
if ((-not $r.Ok) -and $r.Status -eq 422) { Ok 'B complete repetido' '422' }
else { Bad 'B complete repetido' "$($r.Status) $($r.Content)" }

# reconciliar AJUSTAR
$cantidadAntes = [double](Scalar ("SELECT cantidad FROM stock_units WHERE id='" + $uB1 + "'"))
$keyZ = "v190-rc-B-" + [guid]::NewGuid().ToString('N')
$rcBody = (@{ acciones = @(@{ unidad_fisica_id = $uB1; accion = 'AJUSTAR' }) } | ConvertTo-Json -Depth 6)

$r = Invoke-Post ("/inventory/counts/" + $cntId + "/reconcile") $rcBody $keyZ $tokenB
if ($r.Ok -and $r.Status -eq 200) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'B reconcile estado' $d.data.conteo.estado 'RECONCILIADO'
    Assert-Eq 'B reconcile pendientes' $d.data.discrepancias_pendientes 0
    Assert-Eq 'B acciones aplicadas' $d.data.acciones_aplicadas.Count 1
    Assert-Eq 'B incidencias generadas' $d.data.incidencias.Count 0
} else { Bad 'B POST /counts/{id}/reconcile' "$($r.Status) $($r.Content)" }

Assert-Eq 'B conteo RECONCILIADO en BD' (Scalar ("SELECT estado FROM inventory_counts WHERE id='" + $cntId + "'")) 'RECONCILIADO'
$cantidadDespues = [double](Scalar ("SELECT cantidad FROM stock_units WHERE id='" + $uB1 + "'"))
Assert-Eq 'B cantidad ajustada (+1)' ([math]::Round($cantidadDespues, 3)) ([math]::Round($cantidadAntes + 1, 3))
Assert-Eq 'B evento AJUSTE_CANTIDAD' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uB1 + "' AND tipo_evento='AJUSTE_CANTIDAD'")) '1'
Assert-Eq 'B evento con hash de 64 hex' (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uB1 + "' AND hash_integridad REGEXP '^[0-9a-fA-F]{64}$'")) (Scalar ("SELECT COUNT(*) FROM stock_events WHERE unidad_fisica_id='" + $uB1 + "'"))
$cantidadTexto = $cantidadDespues.ToString([cultureinfo]::InvariantCulture)
Assert-Eq 'B ledger actualizado' (Scalar ("SELECT IF(ABS(cantidad_fisica - " + $cantidadTexto + ") < 0.001,'OK','DESAJUSTADO') FROM stock_ledger WHERE unidad_fisica_id='" + $uB1 + "'")) 'OK'
Assert-Eq 'B ajustes_aplicados registrados' (Scalar ("SELECT JSON_LENGTH(ajustes_aplicados) FROM inventory_counts WHERE id='" + $cntId + "'")) '1'
Assert-Eq 'B AUDIT reconcile (2 filas)' (AuditCount $keyZ) '2'

$eventosTrasReconcile = Scalar "SELECT COUNT(*) FROM stock_events WHERE tipo_evento='AJUSTE_CANTIDAD'"
$r2 = Invoke-Post ("/inventory/counts/" + $cntId + "/reconcile") $rcBody $keyZ $tokenB
if ($r2.Ok -and $r2.Content -eq $r.Content) { Ok 'B REPLAY reconcile (body identico)' 'ok' }
else { Bad 'B REPLAY reconcile' "status=$($r2.Status) difiere" }
Assert-Eq 'B AUDIT reconcile sin duplicar' (AuditCount $keyZ) '2'
Assert-Eq 'B eventos sin duplicar tras replay' (Scalar "SELECT COUNT(*) FROM stock_events WHERE tipo_evento='AJUSTE_CANTIDAD'") $eventosTrasReconcile

# consultas
$r = Invoke-Get ("/inventory/counts/" + $cntId) $tokenA
if ($r.Ok -and $r.Status -eq 200) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'B GET /counts/{id} estado' $d.data.estado 'RECONCILIADO'
} else { Bad 'B GET /counts/{id}' "$($r.Status) $($r.Content)" }

$r = Invoke-Get '/inventory/counts?estado=RECONCILIADO&page=1&per_page=100' $tokenA
if ($r.Ok) {
    $d = ConvertFrom-Json $r.Content
    if ([int]$d.data.pagination.total -ge 1) { Ok 'B GET /counts?estado=RECONCILIADO' "total=$($d.data.pagination.total)" }
    else { Bad 'B GET /counts?estado=RECONCILIADO' "total=$($d.data.pagination.total)" }
} else { Bad 'B GET /counts?estado' "$($r.Status)" }

$r = Invoke-Get ("/inventory/counts?ubicacion_id=" + $almacen + "&page=1&per_page=100") $tokenA
if ($r.Ok -and $r.Status -eq 200) { Ok 'B GET /counts?ubicacion_id' '200' }
else { Bad 'B GET /counts?ubicacion_id' "$($r.Status) $($r.Content)" }

# ==================================================== 8) comprobaciones globales
Write-Output ''
Write-Output '--- COMPROBACIONES GLOBALES ---'

$dups = Scalar "SELECT COUNT(*) FROM (SELECT detalles_adicionales, operacion, COUNT(*) c FROM audit_logs WHERE detalles_adicionales LIKE '%v190-%' GROUP BY detalles_adicionales, operacion HAVING c > 1) t"
Assert-Eq 'AUDIT sin filas duplicadas por clave+operacion' $dups '0'

# paginacion
$r = Invoke-Get '/inventory/incidents?page=1&per_page=1' $tokenA
if ($r.Ok) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'GET incidents per_page=1 devuelve 1 fila' $d.data.data.Count 1
} else { Bad 'GET incidents paginacion' "$($r.Status)" }

$r = Invoke-Get '/inventory/counts?page=1&per_page=1000' $tokenA
if ($r.Ok) {
    $d = ConvertFrom-Json $r.Content
    Assert-Eq 'GET counts tope per_page=100' $d.data.pagination.per_page 100
} else { Bad 'GET counts tope per_page' "$($r.Status)" }

Write-Output ''
Write-Output '--- AUDITORIA (claves v1.9.0) ---'
Sql "SELECT operacion, recurso, resultado, entidad_id FROM audit_logs WHERE detalles_adicionales LIKE '%v190-rp-A%' OR detalles_adicionales LIKE '%v190-in-A%' OR detalles_adicionales LIKE '%v190-rs-A%' OR detalles_adicionales LIKE '%v190-st-B%' OR detalles_adicionales LIKE '%v190-rc-B%' ORDER BY timestamp;"

Write-Output ''
Write-Output '--- UNIDADES E2E ---'
Sql ("SELECT id, estado, ubicacion_id FROM stock_units WHERE id IN ('" + $uA + "','" + $uB1 + "','" + $uB2 + "','" + $uBP + "');")
Sql ("SELECT id, estado, reconciliado FROM inventory_counts WHERE id='" + $cntId + "';")

# ==================================================== 9) la BD de trabajo no cambia
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
if ($script:failures -eq 0) { Write-Output 'E2E v1.9.0: TODO OK' } else { Write-Output "E2E v1.9.0: $script:failures FALLOS" }

# v1.8.3: el servidor PHP embebido se detiene al terminar cada E2E.
Stop-E2EServer
exit $script:failures
