# e2e-v11320.ps1 - BLOQUE 1B: rol heredado por grupo en el modal de Roles (v1.13.20).
#
# Escenarios:
#   E1) Borrado de un rol asignado DIRECTAMENTE -> 200 y el rol desaparece.
#   E2) Borrado de un rol que solo llega por GRUPO -> 409 con
#       data.error_code=ROLE_NOT_DIRECT, el rol persiste y
#       GET /users/{id}/roles declara origen=group con group_id y group_name.
#   E3) Baja del usuario del grupo -> el rol heredado desaparece de
#       GET /users/{id}/roles y de /auth/me (permisos incluidos): la propia
#       API invalida la cache auth:perms:* y la segunda lectura de /auth/me
#       se hace sin flush manual.
#   E4) Sin groups.group.manage_members -> /auth/me no declara el permiso
#       (el front no pinta la accion "Quitar del grupo") y
#       DELETE /groups/{id}/members/{userId} responde 403. El control
#       positivo es /auth/me del ADMIN, que si lo declara. La parte visual
#       de "no pinta la accion" la cubre tools/smoke/smoke-bloque1b-roles.mjs.
#
# Solo escribe en la BD de E2E (via _env.ps1); no toca datos de la BD viva.

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

function Scalar($sql) {
    $v = & mysql.exe -u root $e2eDb -N -e $sql 2>$null | Select-Object -First 1
    if ($null -eq $v) { return $null }
    return ("$v".Trim())
}

# Crea usuario, completa el primer login (cambio forzado) y devuelve token final.
function New-ActivatedUser($token, $tag) {
    $email = "v11320-$tag-$($script:runId)@acme.local"
    $r = Invoke-Post '/users' (@{ email = $email; nombre = 'V11320'; apellido = $tag } | ConvertTo-Json) "v11320-$tag-$($script:runId)" $token
    if ($r.Status -ne 201) { return $null }
    $c = $r.Content | ConvertFrom-Json
    $uid = $c.data.user.id
    $temp = $c.data.user.password_temporal

    $lt = LoginFull $email $temp
    if (-not $lt) { return $null }
    $tTemp = $lt.data.tokens.access_token
    $null = Invoke-Post '/auth/change-password' (@{ password_actual = $temp; password_nuevo = 'V11320Pass123' } | ConvertTo-Json) $null $tTemp
    $ln = LoginFull $email 'V11320Pass123'
    if (-not $ln) { return $null }
    return @{ Id = $uid; Email = $email; Login = $ln; Token = $ln.data.tokens.access_token }
}

function Get-RolesList($userId, $token) {
    $r = Invoke-Get "/users/$userId/roles" $token
    $script:lastRoles = $r
    if ($r.Status -ne 200) { return $null }
    $c = $r.Content | ConvertFrom-Json
    # El operador coma evita que un array vacio (sin roles) se desenrolle
    # a $null al volver de la funcion: vacio sigue siendo 200, no error.
    return , @($c.data.roles)
}

# ============================================================ 0) BASE
$adm = LoginFull 'aleon@acme.local' 'Admin123!'
if (-not $adm) { Bad 'LOGIN admin' 'sin token'; exit 1 }
$ta = $adm.data.tokens.access_token
$adminId = $adm.data.user.id
Ok 'LOGIN admin' "token adminId=$adminId"

if (-not (Test-Path -LiteralPath $script:redis)) { Bad 'REDIS' "no existe $($script:redis)"; exit 1 }

$tenantId = Scalar "SELECT tenant_id FROM users WHERE email = 'aleon@acme.local' AND deleted_at IS NULL LIMIT 1"
if (-not $tenantId) { Bad 'TENANT' 'sin tenant de aleon'; exit 1 }

# Rol de grupo con al menos un permiso activo (E3 comprueba que los
# permisos efectivos entran y salen con el grupo).
$par = Scalar ("SELECT CONCAT_WS('|', r.id, JSON_UNQUOTE(JSON_EXTRACT(r.permisos, '$[0]'))) " +
    "FROM roles r INNER JOIN permissions p ON p.identificador = JSON_UNQUOTE(JSON_EXTRACT(r.permisos, '$[0]')) " +
    "WHERE r.nombre <> 'ADMIN' AND r.deleted_at IS NULL AND r.activo = 1 AND p.activo = 1 " +
    "AND JSON_VALID(r.permisos) AND JSON_TYPE(r.permisos) = 'ARRAY' AND JSON_LENGTH(r.permisos) > 0 " +
    "ORDER BY r.nombre LIMIT 1")
if (-not $par -or "$par" -notlike '*|*') { Bad 'ROL base' "sin rol con permisos (par=$par)"; exit 1 }
$parts = "$par".Split('|')
$roleId = $parts[0].Trim()
$permClave = $parts[1].Trim()
Ok 'ROL base' "roleId=$roleId permClave=$permClave"

# ================================================ E1) directo -> 200
$u1 = New-ActivatedUser $ta 'dir'
if (-not $u1) { Bad 'E1 alta usuario' 'no se pudo crear/activar'; exit 1 }

& mysql.exe -u root $e2eDb -e ("INSERT INTO role_assignments (id, user_id, role_id, tenant_id, asignado_por_user_id) " +
    "VALUES (UUID(), '$($u1.Id)', '$roleId', '$tenantId', '$adminId')")
$hayAsig = Scalar "SELECT COUNT(*) FROM role_assignments WHERE user_id = '$($u1.Id)' AND role_id = '$roleId'"
if ("$hayAsig" -ne '1') { Bad 'E1 sembrado asignacion directa' "filas=$hayAsig"; exit 1 }

$antes = Get-RolesList $u1.Id $ta
if ($null -eq $antes) { Bad 'E1 GET roles' "status=$($script:lastRoles.Status) $($script:lastRoles.Content)" }
else {
    $fila = @($antes | Where-Object { $_.id -eq $roleId })
    if ($fila.Count -eq 1 -and $fila[0].origen -eq 'direct') { Ok 'E1 rol directo visible' 'origen=direct' }
    else { Bad 'E1 rol directo visible' "fila=$($antes | ConvertTo-Json -Compress)" }
}

$d1 = Invoke-Delete "/users/$($u1.Id)/roles/$roleId" $ta
if ($d1.Status -eq 200) { Ok 'E1 DELETE rol directo' '200' }
else { Bad 'E1 DELETE rol directo' "status=$($d1.Status) $($d1.Content)" }

$despues = Get-RolesList $u1.Id $ta
if ($null -eq $despues) { Bad 'E1 GET roles tras baja' "status=$($script:lastRoles.Status) $($script:lastRoles.Content)" }
elseif (@($despues | Where-Object { $_.id -eq $roleId }).Count -eq 0) { Ok 'E1 rol fuera' 'role_assignments borrada' }
else { Bad 'E1 rol fuera' "sigue: $($despues | ConvertTo-Json -Compress)" }

# ==================================== E2) heredado de grupo -> 409
$u2 = New-ActivatedUser $ta 'grp'
if (-not $u2) { Bad 'E2 alta usuario' 'no se pudo crear/activar'; exit 1 }

$g = Scalar 'SELECT UUID()'
$gNombre = "E2E-B1B-$($script:runId)"
& mysql.exe -u root $e2eDb -e ("INSERT INTO user_groups (id, tenant_id, nombre, activo) VALUES ('$g', '$tenantId', '$gNombre', 1)")
& mysql.exe -u root $e2eDb -e ("INSERT INTO group_members (id, group_id, user_id) VALUES (UUID(), '$g', '$($u2.Id)')")
& mysql.exe -u root $e2eDb -e ("INSERT INTO group_roles (id, group_id, role_id) VALUES (UUID(), '$g', '$roleId')")

$gm = Scalar "SELECT COUNT(*) FROM group_members WHERE group_id = '$g' AND user_id = '$($u2.Id)'"
$gr = Scalar "SELECT COUNT(*) FROM group_roles WHERE group_id = '$g' AND role_id = '$roleId'"
if ("$gm" -eq '1' -and "$gr" -eq '1') { Ok 'E2 sembrado grupo' "group=$g miembros=$gm roles=$gr" }
else { Bad 'E2 sembrado grupo' "miembros=$gm roles=$gr"; exit 1 }

# El sembrado por SQL no pasa por la API: se limpia la cache a mano para que
# la primera lectura de /auth/me (E3) sea fresca y caliente la suya.
& $script:redis DEL "auth:perms:${tenantId}:$($u2.Id)" | Out-Null

$rolesU2 = Get-RolesList $u2.Id $ta
if ($null -eq $rolesU2) { Bad 'E2 GET roles' "status=$($script:lastRoles.Status) $($script:lastRoles.Content)" }
else {
    $fila = @($rolesU2 | Where-Object { $_.id -eq $roleId })
    if ($fila.Count -eq 1 -and $fila[0].origen -eq 'group' -and
        "$($fila[0].group_id)" -eq "$g" -and "$($fila[0].group_name)" -eq "$gNombre") {
        Ok 'E2 rol heredado visible' "origen=group group_id=$g group_name=$gNombre"
    } else {
        Bad 'E2 rol heredado visible' "fila=$($rolesU2 | ConvertTo-Json -Compress)"
    }
}

$d2 = Invoke-Delete "/users/$($u2.Id)/roles/$roleId" $ta
$e2ok = $false
if ($d2.Status -eq 409) {
    try { $b = $d2.Content | ConvertFrom-Json } catch { $b = $null }
    if ($b -and -not $b.success -and $b.data.category -eq 'conflict' -and $b.data.error_code -eq 'ROLE_NOT_DIRECT') {
        $e2ok = $true
    }
}
if ($e2ok) { Ok 'E2 DELETE rol heredado' '409 conflict ROLE_NOT_DIRECT' }
else { Bad 'E2 DELETE rol heredado' "status=$($d2.Status) $($d2.Content)" }

$persiste = Get-RolesList $u2.Id $ta
if ($null -eq $persiste) { Bad 'E2 GET roles tras 409' "status=$($script:lastRoles.Status) $($script:lastRoles.Content)" }
else {
    $fila = @($persiste | Where-Object { $_.id -eq $roleId })
    if ($fila.Count -eq 1 -and $fila[0].origen -eq 'group') { Ok 'E2 rol persiste' 'nada se borro en silencio' }
    else { Bad 'E2 rol persiste' "fila=$($persiste | ConvertTo-Json -Compress)" }
}

# ===================== E3) baja del grupo + invalidacion de cache
$me1 = Invoke-Get '/auth/me' $u2.Token
if ($me1.Status -ne 200) { Bad 'E3 /auth/me antes' "status=$($me1.Status) $($me1.Content)" }
else {
    $me = ($me1.Content | ConvertFrom-Json).data
    $conRol = @($me.roles | Where-Object { $_.id -eq $roleId }).Count -eq 1
    $conPerm = @($me.permisos) -contains $permClave
    if ($conRol -and $conPerm) { Ok 'E3 /auth/me antes' "rol heredado y permiso $permClave presentes" }
    else { Bad 'E3 /auth/me antes' "roles=$($me.roles | ConvertTo-Json -Compress) permisos=$($me.permisos | ConvertTo-Json -Compress)" }
}

$rm = Invoke-Delete "/groups/$g/members/$($u2.Id)" $ta
if ($rm.Status -eq 200) { Ok 'E3 baja del grupo' '200' }
else { Bad 'E3 baja del grupo' "status=$($rm.Status) $($rm.Content)" }

$fueraRoles = Get-RolesList $u2.Id $ta
if ($null -eq $fueraRoles) { Bad 'E3 GET roles tras baja' "status=$($script:lastRoles.Status) $($script:lastRoles.Content)" }
elseif (@($fueraRoles | Where-Object { $_.id -eq $roleId }).Count -eq 0) { Ok 'E3 rol heredado fuera' '/users/{id}/roles sin el rol' }
else { Bad 'E3 rol heredado fuera' "sigue: $($fueraRoles | ConvertTo-Json -Compress)" }

# Sin flush manual: si la API no invalido auth:perms:*, los permisos de
# /auth/me seguirian cacheados con el rol del grupo (TTL 60 s).
$me2 = Invoke-Get '/auth/me' $u2.Token
if ($me2.Status -ne 200) { Bad 'E3 /auth/me despues' "status=$($me2.Status) $($me2.Content)" }
else {
    $me = ($me2.Content | ConvertFrom-Json).data
    $conRol = @($me.roles | Where-Object { $_.id -eq $roleId }).Count -eq 0
    $conPerm = @($me.permisos) -contains $permClave
    if ($conRol -and -not $conPerm) { Ok 'E3 /auth/me despues' "rol y permiso $permClave fuera (cache invalidada)" }
    else { Bad 'E3 /auth/me despues' "roles=$($me.roles | ConvertTo-Json -Compress) permisos=$($me.permisos | ConvertTo-Json -Compress)" }
}

# ================== E4) sin manage_members -> sin accion y 403
$adm2 = LoginFull 'admin@acme.local' 'Admin123!'
if (-not $adm2) { Bad 'E4 LOGIN manager' 'sin token'; exit 1 }
$tNoPerm = $adm2.data.tokens.access_token
$noPermId = $adm2.data.user.id

$meAdm = Invoke-Get '/auth/me' $tNoPerm
if ($meAdm.Status -ne 200) { Bad 'E4 /auth/me sin permiso' "status=$($meAdm.Status)" }
else {
    $perms = @(($meAdm.Content | ConvertFrom-Json).data.permisos)
    if (@($perms | Where-Object { $_ -eq 'groups.group.manage_members' }).Count -eq 0) {
        Ok 'E4 /auth/me sin manage_members' 'no declara groups.group.manage_members'
    } else {
        Bad 'E4 /auth/me sin manage_members' "si lo declara: $($perms | ConvertTo-Json -Compress)"
    }
}

$meSi = Invoke-Get '/auth/me' $ta
if ($meSi.Status -ne 200) { Bad 'E4 /auth/me admin (control)' "status=$($meSi.Status)" }
else {
    $perms = @(($meSi.Content | ConvertFrom-Json).data.permisos)
    if (@($perms | Where-Object { $_ -eq 'groups.group.manage_members' }).Count -gt 0) {
        Ok 'E4 /auth/me admin (control)' 'si declara groups.group.manage_members'
    } else {
        Bad 'E4 /auth/me admin (control)' "no lo declara: $($perms | ConvertTo-Json -Compress)"
    }
}

$d4 = Invoke-Delete "/groups/$g/members/$noPermId" $tNoPerm
if ($d4.Status -eq 403) { Ok 'E4 DELETE miembro sin permiso' '403' }
else { Bad 'E4 DELETE miembro sin permiso' "status=$($d4.Status) $($d4.Content)" }

# ============================================================ limpieza
& mysql.exe -u root $e2eDb -e "DELETE FROM group_roles WHERE group_id = '$g'"
& mysql.exe -u root $e2eDb -e "DELETE FROM group_members WHERE group_id = '$g'"
& mysql.exe -u root $e2eDb -e "DELETE FROM user_groups WHERE id = '$g'"
$quedan = Scalar "SELECT COUNT(*) FROM user_groups WHERE id = '$g'"
if ("$quedan" -eq '0') { Ok 'LIMPIEZA grupo' 'user_groups/group_members/group_roles sin filas mias' }
else { Bad 'LIMPIEZA grupo' "quedan=$quedan" }

# ================================================================ resumen
Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E v1.13.20: TODOS LOS CASOS OK' }
else { Write-Output "E2E v1.13.20: $script:failures FALLOS" }
Stop-E2EServer
exit $script:failures
