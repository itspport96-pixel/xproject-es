$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido
$script:failures = 0
$script:runId = [guid]::NewGuid().ToString('N').Substring(0, 8)

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

function Scalar($sql) {
    $v = & mysql.exe -u root $e2eDb -N -e $sql | Select-Object -First 1
    if ($null -eq $v) { return $null }
    return ("$v".Trim())
}

# Crea usuario, completa el primer login (cambio forzado) y devuelve token final.
function New-ActivatedUser($token, $tag) {
    $email = "v1130-$tag-$($script:runId)@acme.local"
    $r = Invoke-Post '/users' (@{ email = $email; nombre = 'V1130'; apellido = $tag } | ConvertTo-Json) "v1130-$tag-$($script:runId)" $token
    if ($r.Status -ne 201) { return $null }
    $c = $r.Content | ConvertFrom-Json
    $uid = $c.data.user.id
    $temp = $c.data.user.password_temporal

    $lt = LoginFull $email $temp
    if (-not $lt) { return $null }
    $tTemp = $lt.data.tokens.access_token
    $null = Invoke-Post '/auth/change-password' (@{ password_actual = $temp; password_nuevo = 'V1130Pass123' } | ConvertTo-Json) $null $tTemp
    $ln = LoginFull $email 'V1130Pass123'
    if (-not $ln) { return $null }
    return @{ Id = $uid; Email = $email; Login = $ln; Token = $ln.data.tokens.access_token }
}

# ============================================================ 0) ADMIN
$adm = LoginFull 'aleon@acme.local' 'Admin123!'
if (-not $adm) { Bad 'LOGIN admin' 'sin token'; exit 1 }
$ta = $adm.data.tokens.access_token
$adminId = $adm.data.user.id
Ok 'LOGIN admin' "token adminId=$adminId"

$permCat = Invoke-Get '/permissions?modulo=users' $ta
$catObj = $permCat.Content | ConvertFrom-Json
$permUserView = $null
foreach ($p in $catObj.data.permissions) { if ($p.identificador -eq 'users.user.view') { $permUserView = $p.id } }
if ($permUserView) { Ok 'CATALOGO users.user.view' "id=$permUserView" }
else { Bad 'CATALOGO users.user.view' 'no encontrado'; exit 1 }

$fakeId = '00000000-0000-4000-8000-000000000000'

# ============================================ 1) USUARIO: delete + sin restore
$u1 = New-ActivatedUser $ta 'u'
if (-not $u1) { Bad 'ALTA u1' 'no se pudo crear/activar'; exit 1 }
Ok 'ALTA u1' "id=$($u1.Id)"
$email1 = $u1.Email

$r = Invoke-Delete "/users/$($u1.Id)" $ta
if ($r.Status -eq 204) { Ok 'DELETE /users/{id}' '204' }
else { Bad 'DELETE /users/{id}' "status=$($r.Status) $($r.Content)" }

# Nota: GET /users envuelve doble: {data:{data:[...], pagination:{...}}}.
$r = Invoke-Get "/users?search=$email1&per_page=50" $ta
$d = ($r.Content | ConvertFrom-Json).data.data
if ($r.Status -eq 200 -and @($d).Count -eq 0) { Ok 'LISTADO sin borrado' '0 resultados tras DELETE' }
else { Bad 'LISTADO sin borrado' "status=$($r.Status) n=$(@($d).Count)" }

# include_deleted ya no existe: el parametro se ignora y el borrado nunca se lista.
$r = Invoke-Get "/users?search=$email1&per_page=50&include_deleted=true" $ta
$d = ($r.Content | ConvertFrom-Json).data.data
if ($r.Status -eq 200 -and @($d).Count -eq 0) { Ok 'LISTADO include_deleted ignorado' '0 resultados (parametro eliminado)' }
else { Bad 'LISTADO include_deleted ignorado' "status=$($r.Status) n=$(@($d).Count) item=$($d | ConvertTo-Json -Compress)" }

# Politica de borrado: el endpoint de restauracion ya no existe.
$r = Invoke-Post "/users/$($u1.Id)/restore" '{}' $null $ta
if ($r.Status -eq 404) { Ok 'RESTORE usuario eliminado' '404 (ruta inexistente)' }
else { Bad 'RESTORE usuario eliminado' "status=$($r.Status) $($r.Content)" }

# El email del usuario borrado queda libre: alta con el mismo email = id nuevo.
$r = Invoke-Post '/users' (@{ email = $email1; nombre = 'V1130'; apellido = 'u-recreado' } | ConvertTo-Json) "v1130-recrea-$($script:runId)" $ta
$cu = $null; try { $cu = $r.Content | ConvertFrom-Json } catch {}
$nuevoUid = if ($cu.data.user.id) { $cu.data.user.id } else { $null }
if ($r.Status -eq 201 -and $nuevoUid -and $nuevoUid -ne $u1.Id) {
    Ok 'USER recrear mismo email' "201 id-nuevo=$nuevoUid"
} else { Bad 'USER recrear mismo email' "status=$($r.Status) $($r.Content)" }

$filaVieja = Scalar "SELECT COUNT(*) FROM users WHERE id = '$($u1.Id)' AND deleted_at IS NOT NULL"
$filaNueva = Scalar "SELECT COUNT(*) FROM users WHERE id = '$nuevoUid' AND deleted_at IS NULL"
if ("$filaVieja" -eq '1' -and "$filaNueva" -eq '1') {
    Ok 'USER borrado persiste + nuevo activo' "viejo deleted_at fijado, nuevo activo"
} else { Bad 'USER borrado persiste + nuevo activo' "viejo=$filaVieja nuevo=$filaNueva" }

$r = Invoke-Get "/users?search=$email1&per_page=50" $ta
$d = ($r.Content | ConvertFrom-Json).data.data
if ($r.Status -eq 200 -and @($d).Count -eq 1 -and $d[0].id -eq $nuevoUid) {
    Ok 'LISTADO tras recrear' '1 resultado con el id nuevo'
} else { Bad 'LISTADO tras recrear' "n=$(@($d).Count) item=$($d | ConvertTo-Json -Compress)" }

$aud = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'user.delete' AND entidad_id = '$($u1.Id)' AND detalles_adicionales LIKE '%valores_despues%'"
if ([int]$aud -ge 1) { Ok 'AUDIT user.delete valores_despues' "$aud fila(s)" }
else { Bad 'AUDIT user.delete valores_despues' "count=$aud" }

# ================================ 2) PERMISOS: sin restore ni include_deleted
$r = Invoke-Post '/roles' (@{ nombre = "V1130 VIEW $($script:runId)"; descripcion = 'e2e v1.13.0'; permisos = @('users.user.view', 'roles.role.view', 'groups.group.view') } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $viewRoleId = ($r.Content | ConvertFrom-Json).data.role.id; Ok 'ALTA rol vista' "id=$viewRoleId" }
else { Bad 'ALTA rol vista' "status=$($r.Status) $($r.Content)"; exit 1 }

$uv = New-ActivatedUser $ta 'vista'
if (-not $uv) { Bad 'ALTA uv' 'no se pudo crear/activar'; exit 1 }

$r = Invoke-Post "/users/$($uv.Id)/roles" (@{ role_id = $viewRoleId } | ConvertTo-Json) $null $ta
if ($r.Status -eq 200) { Ok 'ASIGNAR rol vista a uv' '200' }
else { Bad 'ASIGNAR rol vista a uv' "status=$($r.Status) $($r.Content)" }

$lgv = LoginFull $uv.Email 'V1130Pass123'
if (-not $lgv) { Bad 'LOGIN uv' 'sin token'; exit 1 }
$tv = $lgv.data.tokens.access_token

$r = Invoke-Get '/users' $tv
if ($r.Status -eq 200) { Ok 'uv GET /users (control)' '200' }
else { Bad 'uv GET /users (control)' "status=$($r.Status)" }

$r = Invoke-Get '/roles' $tv
if ($r.Status -eq 200) { Ok 'uv GET /roles (control)' '200' }
else { Bad 'uv GET /roles (control)' "status=$($r.Status)" }

$r = Invoke-Get '/groups' $tv
if ($r.Status -eq 200) { Ok 'uv GET /groups (control)' '200' }
else { Bad 'uv GET /groups (control)' "status=$($r.Status)" }

# Rutas de restauracion eliminadas: sin ruta no hay permiso que valga (404).
$r = Invoke-Post "/users/$($u1.Id)/restore" '{}' $null $tv
if ($r.Status -eq 404) { Ok 'uv POST /users/{id}/restore' '404 (ruta inexistente)' }
else { Bad 'uv POST /users/{id}/restore' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/groups/$fakeId/restore" '{}' $null $tv
if ($r.Status -eq 404) { Ok 'uv POST /groups/{id}/restore' '404 (ruta inexistente)' }
else { Bad 'uv POST /groups/{id}/restore' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/roles/$fakeId/restore" '{}' $null $tv
if ($r.Status -eq 404) { Ok 'uv POST /roles/{id}/restore' '404 (ruta inexistente)' }
else { Bad 'uv POST /roles/{id}/restore' "status=$($r.Status) $($r.Content)" }

# ============================================ 3) GRUPO: miembros + sin restore
$r = Invoke-Post '/groups' (@{ nombre = "V1130 G $($script:runId)"; descripcion = 'e2e v1.13.0' } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $gid = ($r.Content | ConvertFrom-Json).data.group.id; Ok 'ALTA grupo' "id=$gid" }
else { Bad 'ALTA grupo' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Post "/groups/$gid/members" (@{ user_id = $uv.Id } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { Ok 'MIEMBRO uv al grupo' '201' }
else { Bad 'MIEMBRO uv al grupo' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/groups' $ta
$gs = ($r.Content | ConvertFrom-Json).data.groups
$todosContados = $true
$miembrosNuestro = $null
foreach ($g in $gs) {
    if (-not ("$($g.miembros)" -match '^\d+$')) { $todosContados = $false }
    if ($g.id -eq $gid) { $miembrosNuestro = $g.miembros }
}
if ($todosContados) { Ok 'GET /groups campo miembros' "numerico en todos ($($gs.Count) grupos)" }
else { Bad 'GET /groups campo miembros' 'falta miembros numerico en algun grupo' }
if ($miembrosNuestro -eq 1) { Ok 'GET /groups miembros del grupo' '=1' }
else { Bad 'GET /groups miembros del grupo' "valor=$miembrosNuestro" }

$r = Invoke-Delete "/groups/$gid" $ta
if ($r.Status -eq 200) { Ok 'DELETE /groups/{id}' '200' }
else { Bad 'DELETE /groups/{id}' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/groups' $ta
$gs = ($r.Content | ConvertFrom-Json).data.groups
$presente = @($gs | Where-Object { $_.id -eq $gid }).Count
if ($presente -eq 0) { Ok 'LISTADO grupos sin borrado' 'grupo ausente tras DELETE' }
else { Bad 'LISTADO grupos sin borrado' 'el grupo borrado sigue listado' }

# include_deleted ya no existe: el grupo borrado no aparece ni con el parametro.
$r = Invoke-Get '/groups?include_deleted=true' $ta
$gs = ($r.Content | ConvertFrom-Json).data.groups
$gdel = @($gs | Where-Object { $_.id -eq $gid })
if ($r.Status -eq 200 -and $gdel.Count -eq 0) { Ok 'LISTADO grupos include_deleted ignorado' 'grupo ausente' }
else { Bad 'LISTADO grupos include_deleted ignorado' "item=$($gdel | ConvertTo-Json -Compress)" }

$r = Invoke-Post "/groups/$fakeId/restore" '{}' $null $ta
if ($r.Status -eq 404) { Ok 'RESTORE grupo inexistente' '404 (ruta inexistente)' }
else { Bad 'RESTORE grupo inexistente' "status=$($r.Status) $($r.Content)" }

# El nombre del grupo borrado queda libre: alta con el mismo nombre = id nuevo.
$r = Invoke-Post '/groups' (@{ nombre = "V1130 G $($script:runId)"; descripcion = 'recreado tras borrado' } | ConvertTo-Json) $null $ta
$cg = $null; try { $cg = $r.Content | ConvertFrom-Json } catch {}
$nuevoGid = if ($cg.data.group.id) { $cg.data.group.id } else { $null }
if ($r.Status -eq 201 -and $nuevoGid -and $nuevoGid -ne $gid) {
    Ok 'GRUPO recrear mismo nombre' "201 id-nuevo=$nuevoGid miembros=$($cg.data.group.miembros)"
} else { Bad 'GRUPO recrear mismo nombre' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/groups' $ta
$gs = ($r.Content | ConvertFrom-Json).data.groups
$gok = @($gs | Where-Object { $_.id -eq $nuevoGid })
if ($gok.Count -eq 1 -and -not $gok[0].deleted_at -and $gok[0].activo -eq $true -and $gok[0].miembros -eq 0) {
    Ok 'LISTADO grupo recreado' 'activo, sin miembros (los del borrado permanecen con el viejo)'
} else { Bad 'LISTADO grupo recreado' "item=$($gok | ConvertTo-Json -Compress)" }

$filaViejaG = Scalar "SELECT COUNT(*) FROM user_groups WHERE id = '$gid' AND deleted_at IS NOT NULL"
if ("$filaViejaG" -eq '1') { Ok 'GRUPO borrado persiste en BD' 'deleted_at fijado' }
else { Bad 'GRUPO borrado persiste en BD' "filas=$filaViejaG" }

$aud = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'group.delete' AND entidad_id = '$gid' AND detalles_adicionales LIKE '%valores_despues%'"
if ([int]$aud -ge 1) { Ok 'AUDIT group.delete valores_despues' "$aud fila(s)" }
else { Bad 'AUDIT group.delete valores_despues' "count=$aud" }

# ================================================ 4) ROL: sin restore
$r = Invoke-Post '/roles' (@{ nombre = "V1130 R $($script:runId)"; descripcion = 'e2e v1.13.0'; permisos = @('users.user.view') } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $rid = ($r.Content | ConvertFrom-Json).data.role.id; Ok 'ALTA rol' "id=$rid" }
else { Bad 'ALTA rol' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Delete "/roles/$rid" $ta
if ($r.Status -eq 200) { Ok 'DELETE /roles/{id}' '200' }
else { Bad 'DELETE /roles/{id}' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/roles' $ta
$rs = ($r.Content | ConvertFrom-Json).data.roles
if (@($rs | Where-Object { $_.id -eq $rid }).Count -eq 0) { Ok 'LISTADO roles sin borrado' 'rol ausente tras DELETE' }
else { Bad 'LISTADO roles sin borrado' 'el rol borrado sigue listado' }

$r = Invoke-Get '/roles?include_deleted=true' $ta
$rs = ($r.Content | ConvertFrom-Json).data.roles
$rdel = @($rs | Where-Object { $_.id -eq $rid })
if ($r.Status -eq 200 -and $rdel.Count -eq 0) { Ok 'LISTADO roles include_deleted ignorado' 'rol ausente' }
else { Bad 'LISTADO roles include_deleted ignorado' "item=$($rdel | ConvertTo-Json -Compress)" }

$r = Invoke-Post "/roles/$rid/restore" '{}' $null $ta
if ($r.Status -eq 404) { Ok 'RESTORE rol eliminado' '404 (ruta inexistente)' }
else { Bad 'RESTORE rol eliminado' "status=$($r.Status) $($r.Content)" }

# El nombre del rol borrado queda libre: alta con el mismo nombre = id nuevo.
$r = Invoke-Post '/roles' (@{ nombre = "V1130 R $($script:runId)"; descripcion = 'recreado tras borrado'; permisos = @('users.user.view') } | ConvertTo-Json) $null $ta
$cr = $null; try { $cr = $r.Content | ConvertFrom-Json } catch {}
$nuevoRid = if ($cr.data.role.id) { $cr.data.role.id } else { $null }
if ($r.Status -eq 201 -and $nuevoRid -and $nuevoRid -ne $rid) {
    Ok 'ROL recrear mismo nombre' "201 id-nuevo=$nuevoRid"
} else { Bad 'ROL recrear mismo nombre' "status=$($r.Status) $($r.Content)" }

$filaViejaR = Scalar "SELECT COUNT(*) FROM roles WHERE id = '$rid' AND deleted_at IS NOT NULL"
if ("$filaViejaR" -eq '1') { Ok 'ROL borrado persiste en BD' 'deleted_at fijado' }
else { Bad 'ROL borrado persiste en BD' "filas=$filaViejaR" }

$aud = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'role.delete' AND entidad_id = '$rid' AND detalles_adicionales LIKE '%valores_despues%'"
if ([int]$aud -ge 1) { Ok 'AUDIT role.delete valores_despues' "$aud fila(s)" }
else { Bad 'AUDIT role.delete valores_despues' "count=$aud" }

# ============ 5) CACHE DE PERMISOS: expiracion de temporal surte efecto
$u5 = New-ActivatedUser $ta 'expira'
if (-not $u5) { Bad 'ALTA u5' 'no se pudo crear/activar'; exit 1 }

# Concesion por API (invalida la cache) con caducidad a +40s: solo se pide
# que la fecha sea futura. Un sembrado por SQL no invalidaria la cache y el
# login posterior devolveria permisos cacheados obsoletos. La fecha se
# calcula con NOW() de MySQL porque el reloj local de PowerShell puede
# desfascar respecto a la BD (tz del servidor).
$expiraEn = Scalar 'SELECT DATE_ADD(NOW(), INTERVAL 40 SECOND)'
$r = Invoke-Post '/temporary-permissions' (@{ user_id = $u5.Id; permission_id = $permUserView; motivo = 'e2e v1.13.0 caduca'; fecha_expiracion = $expiraEn } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { Ok 'CONCESION temporal a +40s' "201 expira=$expiraEn" }
else { Bad 'CONCESION temporal a +40s' "status=$($r.Status) $($r.Content)"; exit 1 }

$lg5 = LoginFull $u5.Email 'V1130Pass123'
if (-not $lg5) { Bad 'LOGIN u5' 'sin token'; exit 1 }
$t5 = $lg5.data.tokens.access_token
if (($lg5.data.user.permisos -is [array]) -and ($lg5.data.user.permisos -contains 'users.user.view')) {
    Ok 'LOGIN u5 permisos efectivos' 'incluye users.user.view (temporal vigente)'
} else { Bad 'LOGIN u5 permisos efectivos' "permisos=$($lg5.data.user.permisos | ConvertTo-Json -Compress)" }

$r = Invoke-Get '/users' $t5
if ($r.Status -eq 200) { Ok 'GET /users con temporal VIGENTE (cachea)' '200' }
else { Bad 'GET /users con temporal VIGENTE (cachea)' "status=$($r.Status) $($r.Content)" }

Start-Sleep -Seconds 45   # supera la caducidad (+40s) pero NO el TTL de 60s

$r = Invoke-Get '/users' $t5
if ($r.Status -eq 403) { Ok 'GET /users tras CADUCIDAD (token vivo, TTL pendiente)' '403' }
else { Bad 'GET /users tras CADUCIDAD (token vivo, TTL pendiente)' "status=$($r.Status) $($r.Content) (la cache debio invalidarse por expiracion)" }

$r = Invoke-Get '/auth/me' $t5
$me5 = ($r.Content | ConvertFrom-Json).data
if (($me5.permisos -is [array]) -and -not ($me5.permisos -contains 'users.user.view')) {
    Ok 'ME u5 tras caducidad' 'permisos sin users.user.view'
} else { Bad 'ME u5 tras caducidad' "permisos=$($me5.permisos | ConvertTo-Json -Compress)" }

# ================================================================ 6) CONTRATO UI
$r = Invoke-Get '/users?per_page=50' $ta
$us = ($r.Content | ConvertFrom-Json).data.data
$camposOk = $true
foreach ($u in $us) { if (-not ($u.PSObject.Properties.Name -contains 'deleted_at')) { $camposOk = $false } }
if ($camposOk) { Ok 'UI /users items con deleted_at' "n=$($us.Count)" }
else { Bad 'UI /users items con deleted_at' 'falta la clave en algun item' }

$r = Invoke-Get '/groups' $ta
$gs = ($r.Content | ConvertFrom-Json).data.groups
$camposOk = $true
foreach ($g in $gs) { if (-not ("$($g.miembros)" -match '^\d+$') -or -not ($g.PSObject.Properties.Name -contains 'deleted_at')) { $camposOk = $false } }
if ($camposOk) { Ok 'UI /groups items con {miembros, deleted_at}' "n=$($gs.Count)" }
else { Bad 'UI /groups items con {miembros, deleted_at}' 'faltan claves en algun item' }

$r = Invoke-Get '/roles' $ta
$rs = ($r.Content | ConvertFrom-Json).data.roles
$camposOk = $true
foreach ($x in $rs) { if (-not ($x.PSObject.Properties.Name -contains 'deleted_at')) { $camposOk = $false } }
if ($camposOk) { Ok 'UI /roles items con deleted_at' "n=$($rs.Count)" }
else { Bad 'UI /roles items con deleted_at' 'falta la clave en algun item' }

# ================================================================ resumen
Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E v1.13.0: TODOS LOS CASOS OK' }
else { Write-Output "E2E v1.13.0: $script:failures FALLOS" }
Stop-E2EServer
exit $script:failures
