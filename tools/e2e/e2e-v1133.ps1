$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido
$script:failures = 0
$script:runId = [guid]::NewGuid().ToString('N').Substring(0, 8)
$script:redis = 'C:\Program Files\Redis\redis-cli.exe'

function Show($label, $value) { [Console]::WriteLine(("{0}: {1}" -f $label, $value)) }
function Ok($label, $value) { Show $label "OK  $value" }
function Bad($label, $value) { $script:failures++; Show $label "FALLO  $value" }
function StatusCode($e) { if ($e.Exception.Response) { [int]$e.Exception.Response.StatusCode } else { '?' } }

function LoginFull($email, $password) {
    try {
        return Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType 'application/json' `
            -Body (@{ email = $email; password = $password } | ConvertTo-Json) -TimeoutSec 15
    } catch { return $null }
}

function Invoke-Post($path, $json, $key, $token) {
    $headers = @{}
    if ($token) { $headers['Authorization'] = "Bearer $token" }
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

function Invoke-Delete($path, $token) {
    try {
        $w = Invoke-WebRequest -Uri "$base$path" -Method Delete -UseBasicParsing `
            -Headers @{ Authorization = "Bearer $token" } -TimeoutSec 25
        return @{ Ok = $true; Status = [int]$w.StatusCode; Content = $w.Content }
    } catch {
        $msg = ''
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $msg = $_.ErrorDetails.Message }
        return @{ Ok = $false; Status = (StatusCode $_); Content = $msg }
    }
}

# Cabeceras de rate limit: necesarias para comprobar el override de
# rate_limit_config (X-RateLimit-Limit/Remaining y Retry-After).
function Copy-Headers($src) {
    $h = @{}
    if ($null -eq $src) { return $h }
    $keys = $null
    if ($src.PSObject.Properties['AllKeys']) { $keys = $src.AllKeys }
    elseif ($src.PSObject.Properties['Keys']) { $keys = $src.Keys }
    if ($null -ne $keys) { foreach ($k in $keys) { $h["$k"] = "$($src[$k])" } }
    return $h
}

function HeaderVal($h, $name) {
    if ($null -eq $h) { return $null }
    foreach ($k in @($h.Keys)) { if ("$k" -ieq $name) { return $h[$k] } }
    return $null
}

function Invoke-GetWh($path, $token) {
    try {
        $w = Invoke-WebRequest -Uri "$base$path" -Method Get -UseBasicParsing `
            -Headers @{ Authorization = "Bearer $token" } -TimeoutSec 25
        return @{ Ok = $true; Status = [int]$w.StatusCode; Headers = (Copy-Headers $w.Headers); Content = $w.Content }
    } catch {
        $msg = ''
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $msg = $_.ErrorDetails.Message }
        $hs = $null
        if ($_.Exception.Response) { $hs = Copy-Headers $_.Exception.Response.Headers }
        return @{ Ok = $false; Status = (StatusCode $_); Headers = $hs; Content = $msg }
    }
}

function Scalar($sql) {
    $v = & mysql.exe -u root $e2eDb -N -e $sql | Select-Object -First 1
    if ($null -eq $v) { return $null }
    return ("$v".Trim())
}

# Crea usuario, completa el primer login (cambio forzado) y devuelve token final.
function New-ActivatedUser($token, $tag) {
    $email = "v1133-$tag-$($script:runId)@acme.local"
    $r = Invoke-Post '/users' (@{ email = $email; nombre = 'V1133'; apellido = $tag } | ConvertTo-Json) "v1133-$tag-$($script:runId)" $token
    if ($r.Status -ne 201) { return $null }
    $c = $r.Content | ConvertFrom-Json
    $uid = $c.data.user.id
    $temp = $c.data.user.password_temporal

    $lt = LoginFull $email $temp
    if (-not $lt) { return $null }
    $tTemp = $lt.data.tokens.access_token
    $null = Invoke-Post '/auth/change-password' (@{ password_actual = $temp; password_nuevo = 'V1133Pass123' } | ConvertTo-Json) $null $tTemp
    $ln = LoginFull $email 'V1133Pass123'
    if (-not $ln) { return $null }
    return @{ Id = $uid; Email = $email; Login = $ln; Token = $ln.data.tokens.access_token }
}

# GET /temporary-permissions con los query params dados (data completo o $null).
function Get-TempPage($query) {
    $r = Invoke-Get "/temporary-permissions?$query" $ta
    if ($r.Status -ne 200) { return $null }
    return ($r.Content | ConvertFrom-Json).data
}

function Check-Page($label, $data, $expRows, $expPage, $expPerPage, $expHasNext, $expTotal) {
    if ($null -eq $data) { Bad $label 'sin respuesta 200'; return }
    $rows = @($data.temporary_permissions)
    $pg = $data.pagination
    $problemas = @()
    if ($rows.Count -ne $expRows) { $problemas += "filas=$($rows.Count) (esperaba $expRows)" }
    if ([int]$pg.page -ne $expPage) { $problemas += "page=$($pg.page) (esperaba $expPage)" }
    if ([int]$pg.per_page -ne $expPerPage) { $problemas += "per_page=$($pg.per_page) (esperaba $expPerPage)" }
    if ([bool]$pg.has_next -ne $expHasNext) { $problemas += "has_next=$($pg.has_next) (esperaba $expHasNext)" }
    if ([int]$pg.total -ne $expTotal) { $problemas += "pagination.total=$($pg.total) (esperaba $expTotal)" }
    if ([int]$data.total -ne $expTotal) { $problemas += "total=$($data.total) (esperaba $expTotal)" }
    if ($problemas.Count -eq 0) { Ok $label "filas=$($rows.Count) page=$($pg.page) per_page=$($pg.per_page) total=$($pg.total) has_next=$($pg.has_next)" }
    else { Bad $label ($problemas -join '; ') }
}

function Clear-StatKeys {
    $rk = @(& $script:redis KEYS 'rate_limit:*api:tenant:stats*')
    if ($rk.Count -gt 0) { & $script:redis DEL @rk | Out-Null }
    return $rk.Count
}

# ============================================================ 0) ADMIN
$adm = LoginFull 'aleon@acme.local' 'Admin123!'
if (-not $adm) { Bad 'LOGIN admin' 'sin token'; exit 1 }
$ta = $adm.data.tokens.access_token
$adminId = $adm.data.user.id
Ok 'LOGIN admin' "token adminId=$adminId"

$cat = Invoke-Get '/permissions' $ta
if ($cat.Status -ne 200) { Bad 'CATALOGO permisos' "status=$($cat.Status) $($cat.Content)"; exit 1 }
$catObj = $cat.Content | ConvertFrom-Json
$libres = @($catObj.data.permissions | Where-Object { $_.criticidad -ne 'CRITICAL' })
if ($libres.Count -ge 5) { Ok 'CATALOGO permisos no CRITICAL' "n=$($libres.Count)" }
else { Bad 'CATALOGO permisos no CRITICAL' "n=$($libres.Count) (hacen falta 5)"; exit 1 }
$perms5 = @($libres | Select-Object -First 5)

# ================================== 1) PAGINACION real de permisos temporales
$u1 = New-ActivatedUser $ta 'pag'
if (-not $u1) { Bad 'ALTA u1' 'no se pudo crear/activar'; exit 1 }
Ok 'ALTA u1' "id=$($u1.Id)"

$ids = @()
for ($i = 0; $i -lt 5; $i++) {
    $p = $perms5[$i]
    $fecha = Scalar "SELECT DATE_ADD(NOW(), INTERVAL $(25 + $i) HOUR)"
    $r = Invoke-Post '/temporary-permissions' (@{
        user_id = $u1.Id; permission_id = $p.id
        motivo = "e2e v1.13.3 pag $(($i + 1))"; fecha_expiracion = $fecha
    } | ConvertTo-Json) $null $ta
    if ($r.Status -eq 201) {
        $c = $r.Content | ConvertFrom-Json
        $ids += $c.data.temporary_permission.id
    } else {
        Bad "CONCESION $($i + 1) ($($p.identificador))" "status=$($r.Status) $($r.Content)"
    }
}
if ($ids.Count -eq 5) { Ok 'CONCESIONES temporales' "5/5 vigentes a +25h..+29h" }
else { Bad 'CONCESIONES temporales' "n=$($ids.Count) (esperaba 5); paginacion omitida" }

if ($ids.Count -eq 5) {
    $uid = $u1.Id
    $tenantId = Scalar "SELECT tenant_id FROM users WHERE id = '$uid'"

    $p1 = Get-TempPage "user_id=$uid&page=1&per_page=2"
    $p2 = Get-TempPage "user_id=$uid&page=2&per_page=2"
    $p3 = Get-TempPage "user_id=$uid&page=3&per_page=2"
    $p99 = Get-TempPage "user_id=$uid&page=99&per_page=2"
    $pdef = Get-TempPage "user_id=$uid"

    Check-Page 'TEMPORALES pagina 1 de per_page=2' $p1 2 1 2 $true 5
    Check-Page 'TEMPORALES pagina 2 de per_page=2' $p2 2 2 2 $true 5
    Check-Page 'TEMPORALES pagina 3 de per_page=2' $p3 1 3 2 $false 5
    Check-Page 'TEMPORALES pagina 99 (vacia)'      $p99 0 99 2 $false 5
    Check-Page 'TEMPORALES por defecto (1/20)'     $pdef 5 1 20 $false 5

    $plim = Get-TempPage "user_id=$uid&page=0&per_page=1000"
    Check-Page 'TEMPORALES limites page=0 per_page=1000' $plim 5 1 100 $false 5

    $full = Get-TempPage "user_id=$uid&page=1&per_page=100"
    $fullIds = @($full.temporary_permissions | ForEach-Object { $_.id })
    $pageIds = @()
    foreach ($pd in @($p1, $p2, $p3)) { $pageIds += @($pd.temporary_permissions | ForEach-Object { $_.id }) }
    $iguales = ($pageIds.Count -eq 5) -and (($pageIds -join ',') -eq ($fullIds -join ','))
    if ($iguales -and @($pageIds | Sort-Object -Unique).Count -eq 5) {
        Ok 'TEMPORALES union de paginas' '3 paginas de 2 = listado completo, sin solapes ni huecos'
    } else {
        Bad 'TEMPORALES union de paginas' "paginas=$($pageIds -join ',') completo=$($fullIds -join ',')"
    }

    $dbVig = Scalar "SELECT COUNT(*) FROM temporary_permissions tp
                     INNER JOIN users u ON u.id = tp.user_id
                     INNER JOIN permissions p ON p.id = tp.permission_id
                     WHERE u.tenant_id = '$tenantId'
                       AND tp.activo = 1 AND tp.fecha_inicio <= NOW() AND tp.fecha_expiracion > NOW()"
    $pglobal = Get-TempPage 'page=1&per_page=20'
    if ($null -ne $pglobal -and [int]$pglobal.total -eq [int]$dbVig) {
        Ok 'TEMPORALES total vs BD' "total=$($pglobal.total) = COUNT(*) vigentes de la BD"
    } else {
        Bad 'TEMPORALES total vs BD' "api=$($pglobal.total) bd=$dbVig"
    }

    $revocados = 0
    foreach ($tid in $ids) {
        $r = Invoke-Delete "/temporary-permissions/$tid" $ta
        if ($r.Status -eq 200) { $revocados++ }
        else { Bad 'REVOCACION temporal' "id=$tid status=$($r.Status) $($r.Content)" }
    }
    if ($revocados -eq 5) { Ok 'REVOCACION de los 5 temporales' '5 x 200' }

    Check-Page 'TEMPORALES tras revocar (solo vigentes)' (Get-TempPage "user_id=$uid") 0 1 20 $false 0
    Check-Page 'TEMPORALES con include_inactive=true'    (Get-TempPage "user_id=$uid&include_inactive=true") 5 1 20 $false 5
}

# ================================================ 2) ORIGEN de roles
$u2 = New-ActivatedUser $ta 'rol'
if (-not $u2) { Bad 'ALTA u2' 'no se pudo crear/activar'; exit 1 }
Ok 'ALTA u2' "id=$($u2.Id)"

$r = Invoke-Post '/roles' (@{ nombre = "V1133 R1 $($script:runId)"; descripcion = 'e2e v1.13.3'; permisos = @('users.user.view') } | ConvertTo-Json) $null $ta
$rid1 = $null
if ($r.Status -eq 201) { $rid1 = ($r.Content | ConvertFrom-Json).data.role.id; Ok 'ALTA rol directo' "id=$rid1" }
else { Bad 'ALTA rol directo' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/roles' (@{ nombre = "V1133 R2 $($script:runId)"; descripcion = 'e2e v1.13.3'; permisos = @('groups.group.view') } | ConvertTo-Json) $null $ta
$rid2 = $null
if ($r.Status -eq 201) { $rid2 = ($r.Content | ConvertFrom-Json).data.role.id; Ok 'ALTA rol de grupo' "id=$rid2" }
else { Bad 'ALTA rol de grupo' "status=$($r.Status) $($r.Content)" }

if ($rid1 -and $rid2) {
    $r = Invoke-Post "/users/$($u2.Id)/roles" (@{ role_id = $rid1 } | ConvertTo-Json) $null $ta
    if ($r.Status -eq 200) { Ok 'ASIGNACION directa de rol' '200' }
    else { Bad 'ASIGNACION directa de rol' "status=$($r.Status) $($r.Content)" }

    $r = Invoke-Post '/groups' (@{ nombre = "V1133 G $($script:runId)"; descripcion = 'e2e v1.13.3' } | ConvertTo-Json) $null $ta
    $gid = $null
    if ($r.Status -eq 201) { $gid = ($r.Content | ConvertFrom-Json).data.group.id; Ok 'ALTA grupo' "id=$gid" }
    else { Bad 'ALTA grupo' "status=$($r.Status) $($r.Content)" }

    if ($gid) {
        $r = Invoke-Post "/groups/$gid/members" (@{ user_id = $u2.Id } | ConvertTo-Json) $null $ta
        if ($r.Status -eq 201) { Ok 'MIEMBRO u2 al grupo' '201' }
        else { Bad 'MIEMBRO u2 al grupo' "status=$($r.Status) $($r.Content)" }

        $r = Invoke-Post "/groups/$gid/roles" (@{ role_id = $rid2 } | ConvertTo-Json) $null $ta
        if ($r.Status -eq 201) { Ok 'ROL asignado al grupo' '201' }
        else { Bad 'ROL asignado al grupo' "status=$($r.Status) $($r.Content)" }
    }

    $r = Invoke-Get "/users/$($u2.Id)/roles" $ta
    $roles = $null
    if ($r.Status -eq 200) { $roles = ($r.Content | ConvertFrom-Json).data.roles }
    else { Bad 'GET /users/{id}/roles' "status=$($r.Status) $($r.Content)" }

    if ($null -ne $roles) {
        $lista = @($roles)
        $origenDirecto = $null
        $origenGrupo = $null
        foreach ($rl in $lista) {
            if ($rl.id -eq $rid1) { $origenDirecto = $rl.origen }
            if ($rl.id -eq $rid2) { $origenGrupo = $rl.origen }
        }
        if ($lista.Count -eq 2 -and $origenDirecto -eq 'direct' -and $origenGrupo -eq 'group') {
            Ok 'GET /users/{id}/roles con procedencia' "2 roles: directo=$origenDirecto grupo=$origenGrupo"
        } else {
            Bad 'GET /users/{id}/roles con procedencia' "n=$($lista.Count) rid1=$origenDirecto rid2=$origenGrupo"
        }

        # Deduplicacion: el mismo rol en directo Y en grupo se declara una sola
        # vez y con origen directo (los directos mandan).
        $r = Invoke-Post "/groups/$gid/roles" (@{ role_id = $rid1 } | ConvertTo-Json) $null $ta
        if ($r.Status -eq 201) { Ok 'ROL directo tambien en el grupo' '201' }
        else { Bad 'ROL directo tambien en el grupo' "status=$($r.Status) $($r.Content)" }

        $r = Invoke-Get "/users/$($u2.Id)/roles" $ta
        if ($r.Status -eq 200) { $lista2 = @(($r.Content | ConvertFrom-Json).data.roles) }
        else { $lista2 = @() }
        $d2 = $null
        foreach ($rl in $lista2) { if ($rl.id -eq $rid1) { $d2 = $rl.origen } }
        if ($r.Status -eq 200 -and $lista2.Count -eq 2 -and $d2 -eq 'direct') {
            Ok 'DEDUP rol en ambos origenes' "sigue con 2 roles y el directo manda (origen=$d2)"
        } else {
            Bad 'DEDUP rol en ambos origenes' "status=$($r.Status) n=$($lista2.Count) origen=$d2"
        }
    }

    # Columna Roles de la tabla de usuarios: misma fuente, pero solo lo que
    # pinta la UI (id, nombre, tipo, origen) y sin el JSON de permisos.
    $esperadas = @('id', 'nombre', 'origen', 'tipo')
    $r = Invoke-Get "/users?search=$($u2.Email)&per_page=10" $ta
    if ($r.Status -eq 200) {
        $fila = @(($r.Content | ConvertFrom-Json).data.data) | Select-Object -First 1
        $entradas = @($fila.roles)
        $clavesOk = $fila -and $entradas.Count -eq 2
        foreach ($rr in $entradas) {
            $tiene = @($rr.PSObject.Properties.Name | Sort-Object)
            if (($tiene -join ',') -ne ($esperadas -join ',')) { $clavesOk = $false }
            if ($rr.origen -notin @('direct', 'group', 'temporary')) { $clavesOk = $false }
        }
        if ($clavesOk) { Ok 'GET /users fila con columna roles' '2 entradas, claves exactas id/nombre/tipo/origen' }
        else { Bad 'GET /users fila con columna roles' "roles=$($fila.roles | ConvertTo-Json -Compress)" }
    } else {
        Bad 'GET /users fila con columna roles' "status=$($r.Status) $($r.Content)"
    }

    $r = Invoke-Get '/users?per_page=100' $ta
    if ($r.Status -eq 200) { $todas = @(($r.Content | ConvertFrom-Json).data.data) }
    else { $todas = @() }
    $sinClave = @($todas | Where-Object { -not ($_.PSObject.Properties.Name -contains 'roles') })
    $origenRaro = @()
    foreach ($u in $todas) {
        foreach ($rr in @($u.roles)) {
            if ($rr.origen -notin @('direct', 'group', 'temporary')) { $origenRaro += "$($u.email):$($rr.origen)" }
        }
    }
    if ($r.Status -eq 200 -and $todas.Count -gt 0 -and $sinClave.Count -eq 0 -and $origenRaro.Count -eq 0) {
        Ok 'GET /users todas las filas con roles' "n=$($todas.Count), origenes directo/grupo en todos"
    } else {
        Bad 'GET /users todas las filas con roles' "n=$($todas.Count) sin_clave=$($sinClave.Count) origen_raro=$($origenRaro -join ',')"
    }

    $r = Invoke-Get "/users?search=$($u1.Email)&per_page=10" $ta
    $filaU1 = $null
    if ($r.Status -eq 200) { $filaU1 = @(($r.Content | ConvertFrom-Json).data.data) | Select-Object -First 1 }
    if ($r.Status -eq 200 -and $filaU1 -and @($filaU1.roles).Count -eq 0) {
        Ok 'GET /users usuario sin roles' 'roles=[]'
    } else {
        Bad 'GET /users usuario sin roles' "status=$($r.Status) roles=$($filaU1.roles | ConvertTo-Json -Compress)"
    }

    $limpieza = @()
    $r = Invoke-Delete "/users/$($u2.Id)/roles/$rid1" $ta; $limpieza += ($r.Status -eq 200)
    if ($gid) { $r = Invoke-Delete "/groups/$gid" $ta; $limpieza += ($r.Status -eq 200) }
    $r = Invoke-Delete "/roles/$rid1" $ta; $limpieza += ($r.Status -eq 200)
    $r = Invoke-Delete "/roles/$rid2" $ta; $limpieza += ($r.Status -eq 200)
    $r = Invoke-Delete "/users/$($u2.Id)" $ta; $limpieza += ($r.Status -eq 204)
    if ($limpieza -notcontains $false) { Ok 'LIMPIEZA seccion 2' "$($limpieza.Count) borrados (rol 200, grupo 200, usuario 204)" }
    else { Bad 'LIMPIEZA seccion 2' "estados=$($limpieza -join ',')" }
}

# ================================ 3) OVERRIDE de rate_limit_config (stats)
# La observacion de 1.13.2 (tabla sin filas) se cierra aqui: se siembra un
# override global 2/300 POR_ENDPOINT sobre /api/tenant/stats, se comprueba el
# efecto real (200, 200, 429 + cabeceras + rate_limit_log) y se retira.
$statsPath = '/tenant/stats'

$ctrl = Invoke-GetWh $statsPath $ta
if ($ctrl.Status -eq 200) { Ok 'STATS sin override (control previo)' '200' }
else { Bad 'STATS sin override (control previo)' "status=$($ctrl.Status) $($ctrl.Content)" }

$statsId = Scalar 'SELECT UUID()'
$ddl = "INSERT INTO rate_limit_config (id, tenant_id, endpoint, max_requests, tiempo_ventana, tipo_limit, activo)
        VALUES ('$statsId', NULL, '/api/tenant/stats', 2, 300, 'POR_ENDPOINT', 1)"
& mysql.exe -u root $e2eDb -e $ddl
$hay = Scalar "SELECT COUNT(*) FROM rate_limit_config WHERE id = '$statsId' AND activo = 1"
if ("$hay" -eq '1') { Ok 'INSERT rate_limit_config override' "id=$statsId 2/300 POR_ENDPOINT global" }
else { Bad 'INSERT rate_limit_config override' "filas=$hay" }

if ("$hay" -eq '1') {
    $null = Clear-StatKeys

    $s1 = Invoke-GetWh $statsPath $ta
    $s2 = Invoke-GetWh $statsPath $ta
    $s3 = Invoke-GetWh $statsPath $ta

    $lim1 = HeaderVal $s1.Headers 'X-RateLimit-Limit'
    if ($s1.Status -eq 200 -and "$lim1" -eq '2') { Ok 'STATS 1a peticion con override' "200, X-RateLimit-Limit=$lim1" }
    else { Bad 'STATS 1a peticion con override' "status=$($s1.Status) limit=$lim1" }

    $rem2 = HeaderVal $s2.Headers 'X-RateLimit-Remaining'
    if ($s2.Status -eq 200 -and "$rem2" -eq '0') { Ok 'STATS 2a peticion con override' "200, X-RateLimit-Remaining=$rem2" }
    else { Bad 'STATS 2a peticion con override' "status=$($s2.Status) remaining=$rem2" }

    $retry = HeaderVal $s3.Headers 'Retry-After'
    $lim3 = HeaderVal $s3.Headers 'X-RateLimit-Limit'
    if ($s3.Status -eq 429 -and "$lim3" -eq '2' -and [int]$retry -gt 0) {
        Ok 'STATS 3a peticion con override' "429, X-RateLimit-Limit=$lim3, Retry-After=$retry"
    } else {
        Bad 'STATS 3a peticion con override' "status=$($s3.Status) limit=$lim3 retry=$retry body=$($s3.Content)"
    }

    $logN = Scalar "SELECT COUNT(*) FROM rate_limit_log WHERE endpoint = '/api/tenant/stats' AND limite = 2 AND accion_tomada = 'REJECT'"
    $logReq = Scalar "SELECT MAX(requests_contados) FROM rate_limit_log WHERE endpoint = '/api/tenant/stats' AND limite = 2 AND accion_tomada = 'REJECT'"
    if ([int]$logN -ge 1 -and [int]$logReq -ge 3) {
        Ok 'AUDIT rate_limit_log del 429' "filas=$logN requests_contados=$logReq"
    } else {
        Bad 'AUDIT rate_limit_log del 429' "filas=$logN requests=$logReq"
    }

    & mysql.exe -u root $e2eDb -e "DELETE FROM rate_limit_log WHERE endpoint = '/api/tenant/stats'"
    & mysql.exe -u root $e2eDb -e "DELETE FROM rate_limit_config WHERE id = '$statsId'"
    $quedanCfg = Scalar "SELECT COUNT(*) FROM rate_limit_config WHERE endpoint = '/api/tenant/stats'"
    $quedanLog = Scalar "SELECT COUNT(*) FROM rate_limit_log WHERE endpoint = '/api/tenant/stats'"
    $null = Clear-StatKeys
    if ("$quedanCfg" -eq '0' -and "$quedanLog" -eq '0') { Ok 'LIMPIEZA override y log' '0 filas de config y de log' }
    else { Bad 'LIMPIEZA override y log' "config=$quedanCfg log=$quedanLog" }

    $ctrl2 = Invoke-GetWh $statsPath $ta
    if ($ctrl2.Status -eq 200) { Ok 'STATS control tras limpiar' '200 (sin limite residual)' }
    else { Bad 'STATS control tras limpiar' "status=$($ctrl2.Status) $($ctrl2.Content)" }
}

# Red de seguridad: si algo aborto antes de la limpieza, no dejar el override
# pisando a otros scripts del lote.
& mysql.exe -u root $e2eDb -e "DELETE FROM rate_limit_config WHERE endpoint = '/api/tenant/stats'"
$null = Clear-StatKeys

# ================================================================ resumen
Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E v1.13.3: TODOS LOS CASOS OK' }
else { Write-Output "E2E v1.13.3: $script:failures FALLOS" }
Stop-E2EServer
exit $script:failures
