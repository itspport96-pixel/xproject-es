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

function Invoke-Patch($path, $json, $token) {
    try {
        $w = Invoke-WebRequest -Uri "$base$path" -Method Patch -UseBasicParsing `
            -Headers @{ Authorization = "Bearer $token" } `
            -ContentType 'application/json' -Body $json -TimeoutSec 25
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

# Crea usuario sin password (alta con password temporal) y completa el primer
# login (cambio forzado) para obtener un token definitivo.
function New-ActivatedUser($token, $tag) {
    $email = "v1121-$tag-$($script:runId)@acme.local"
    $r = Invoke-Post '/users' (@{ email = $email; nombre = 'V1121'; apellido = $tag } | ConvertTo-Json) "v1121-$tag-$($script:runId)" $token
    if ($r.Status -ne 201) { return $null }
    $c = $r.Content | ConvertFrom-Json
    $uid = $c.data.user.id
    $temp = $c.data.user.password_temporal

    $lt = LoginFull $email $temp
    if (-not $lt) { return $null }
    $tTemp = $lt.data.tokens.access_token
    $null = Invoke-Post '/auth/change-password' (@{ password_actual = $temp; password_nuevo = 'V1121Pass123' } | ConvertTo-Json) $null $tTemp
    $ln = LoginFull $email 'V1121Pass123'
    if (-not $ln) { return $null }
    return @{ Id = $uid; Email = $email; Login = $ln; Token = $ln.data.tokens.access_token }
}

function Test-PermisoEn($permisos, $codigo) {
    if (-not ($permisos -is [array])) { return $false }
    return ($permisos -contains $codigo)
}

# ============================================================ 0) ADMIN
$adm = LoginFull 'aleon@acme.local' 'Admin123!'
if (-not $adm) { Bad 'LOGIN admin' 'sin token'; exit 1 }
$ta = $adm.data.tokens.access_token
$adminId = $adm.data.user.id
$adminRoleId = Scalar "SELECT id FROM roles WHERE nombre = 'ADMIN' AND deleted_at IS NULL LIMIT 1"
Ok 'LOGIN admin' "token adminId=$adminId adminRoleId=$adminRoleId"

$permCat = Invoke-Get '/permissions?modulo=users' $ta
$catObj = $permCat.Content | ConvertFrom-Json
$permUserView = $null
foreach ($p in $catObj.data.permissions) { if ($p.identificador -eq 'users.user.view') { $permUserView = $p.id } }
if ($permUserView) { Ok 'CATALOGO users.user.view' "id=$permUserView" }
else { Bad 'CATALOGO users.user.view' 'no encontrado'; exit 1 }

# ==================================================== 1) escenario de GRUPO
$u1 = New-ActivatedUser $ta 'grupo'
if (-not $u1) { Bad 'ALTA u1' 'no se pudo crear/activar'; exit 1 }
Ok 'ALTA u1' "id=$($u1.Id)"

$r = Invoke-Post '/groups' (@{ nombre = "V1121 G $($script:runId)"; descripcion = 'e2e v1.12.1' } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $gid = ($r.Content | ConvertFrom-Json).data.group.id; Ok 'ALTA grupo' "id=$gid" }
else { Bad 'ALTA grupo' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Post '/roles' (@{ nombre = "V1121 R $($script:runId)"; descripcion = 'e2e v1.12.1'; permisos = @('users.user.view') } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $rid = ($r.Content | ConvertFrom-Json).data.role.id; Ok 'ALTA rol' "id=$rid" }
else { Bad 'ALTA rol' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Post "/groups/$gid/members" (@{ user_id = $u1.Id } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { Ok 'MIEMBRO u1 al grupo' '201' } else { Bad 'MIEMBRO u1 al grupo' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/groups/$gid/roles" (@{ role_id = $rid } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { Ok 'ROL asignado al grupo' '201' } else { Bad 'ROL asignado al grupo' "status=$($r.Status) $($r.Content)" }

# Re-login tras la concesion: el login de New-ActivatedUser es anterior al
# rol de grupo, por lo que no puede reflejarlo.
$u1.Login = LoginFull $u1.Email 'V1121Pass123'
if ($u1.Login) { $u1.Token = $u1.Login.data.tokens.access_token; Ok 'LOGIN u1 tras concesion' '200' }
else { Bad 'LOGIN u1 tras concesion' 'sin respuesta' }

# ------------------------------------------- 1a) login de u1 (rol de grupo)
$lg = $u1.Login
$u1Roles = $lg.data.user.roles
$u1Perms = $lg.data.user.permisos
$origenGrupo = $false
if ($u1Roles -is [array]) {
    foreach ($rl in $u1Roles) { if ($rl.origen -eq 'grupo') { $origenGrupo = $true } }
}
if ($origenGrupo) { Ok 'LOGIN u1 roles con origen' 'al menos un rol con origen=grupo' }
else { Bad 'LOGIN u1 roles con origen' "roles=$($u1Roles | ConvertTo-Json -Compress)" }

if (Test-PermisoEn $u1Perms 'users.user.view') { Ok 'LOGIN u1 permisos efectivos' 'incluye users.user.view' }
else { Bad 'LOGIN u1 permisos efectivos' "permisos=$($u1Perms | ConvertTo-Json -Compress)" }

# -------------------------------------------- 1b) /auth/me de u1
$r = Invoke-Get '/auth/me' $u1.Token
if ($r.Status -eq 200) {
    $me = ($r.Content | ConvertFrom-Json).data
    $meOrigenGrupo = $false
    if ($me.roles -is [array]) { foreach ($rl in $me.roles) { if ($rl.origen -eq 'grupo') { $meOrigenGrupo = $true } } }
    if ($meOrigenGrupo) { Ok 'ME u1 roles con origen' 'al menos un rol con origen=grupo' }
    else { Bad 'ME u1 roles con origen' "roles=$($me.roles | ConvertTo-Json -Compress)" }
    if (Test-PermisoEn $me.permisos 'users.user.view') { Ok 'ME u1 permisos efectivos' 'incluye users.user.view' }
    else { Bad 'ME u1 permisos efectivos' "permisos=$($me.permisos | ConvertTo-Json -Compress)" }
} else { Bad 'ME u1' "status=$($r.Status) $($r.Content)" }

# ------------------------- 1c) endpoint protegido con rol de grupo (200)
$r = Invoke-Get '/users' $u1.Token
if ($r.Status -eq 200) { Ok 'GET /users con rol de GRUPO' '200' }
else { Bad 'GET /users con rol de GRUPO' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/permissions' $u1.Token
if ($r.Status -eq 403) { Ok 'GET /permissions sin permiso (control)' '403' }
else { Bad 'GET /permissions sin permiso (control)' "status=$($r.Status)" }

# ================================================= 2) TEMPORAL VIGENTE (24h)
$u2 = New-ActivatedUser $ta 'tempvig'
if (-not $u2) { Bad 'ALTA u2' 'no se pudo crear/activar'; exit 1 }

$r = Invoke-Post '/temporary-permissions' (@{ user_id = $u2.Id; permission_id = $permUserView; motivo = 'e2e v1.12.1 vigente'; duracion_horas = 24 } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) {
    $tpId = ($r.Content | ConvertFrom-Json).data.temporary_permission.id
    Ok 'CONCESION temporal vigente' "201 id=$tpId"
} else { Bad 'CONCESION temporal vigente' "status=$($r.Status) $($r.Content)"; $tpId = $null }

# Re-login tras la concesion: el login de New-ActivatedUser es anterior al
# permiso temporal, por lo que no puede reflejarlo.
$u2.Login = LoginFull $u2.Email 'V1121Pass123'
if ($u2.Login) { $u2.Token = $u2.Login.data.tokens.access_token; Ok 'LOGIN u2 tras concesion' '200' }
else { Bad 'LOGIN u2 tras concesion' 'sin respuesta' }

if (Test-PermisoEn $u2.Login.data.user.permisos 'users.user.view') { Ok 'LOGIN u2 permisos efectivos' 'incluye users.user.view' }
else { Bad 'LOGIN u2 permisos efectivos' "permisos=$($u2.Login.data.user.permisos | ConvertTo-Json -Compress)" }

$r = Invoke-Get '/auth/me' $u2.Token
$me2 = ($r.Content | ConvertFrom-Json).data
if (Test-PermisoEn $me2.permisos 'users.user.view') { Ok 'ME u2 permisos efectivos' 'incluye users.user.view' }
else { Bad 'ME u2 permisos efectivos' "status=$($r.Status) permisos=$($me2.permisos | ConvertTo-Json -Compress)" }
$me2Temps = $me2.permisos_temporales
if ($me2Temps -is [array] -and $me2Temps.Count -ge 1) { Ok 'ME u2 permisos_temporales' "$($me2Temps.Count) entrada(s)" }
else { Bad 'ME u2 permisos_temporales' "valor=$($me2Temps | ConvertTo-Json -Compress)" }

$r = Invoke-Get '/users' $u2.Token
if ($r.Status -eq 200) { Ok 'GET /users con temporal VIGENTE' '200' }
else { Bad 'GET /users con temporal VIGENTE' "status=$($r.Status) $($r.Content)" }

# ================================================= 3) TEMPORAL EXPIRADO
$u3 = New-ActivatedUser $ta 'tempexp'
if (-not $u3) { Bad 'ALTA u3' 'no se pudo crear/activar'; exit 1 }

$sqlFile = Join-Path $env:TEMP "v1121-expired-$($script:runId).sql"
$sql = "INSERT INTO temporary_permissions (id, user_id, permission_id, motivo, fecha_inicio, fecha_expiracion, activo, granted_by_user_id)`n" +
       "VALUES (UUID(), '$($u3.Id)', '$permUserView', 'e2e v1.12.1 expirado', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 1, '$adminId');"
Set-Content -LiteralPath $sqlFile -Value $sql -Encoding ASCII
& mysql.exe -u root $e2eDb -e "source $sqlFile"
Ok 'TEMPORAL expirado sembrado (SQL E2E)' 'fecha_expiracion en el pasado'

$r = Invoke-Get '/auth/me' $u3.Token
if ($r.Status -eq 200) {
    $me3 = ($r.Content | ConvertFrom-Json).data
    if ($me3.permisos -is [array] -and -not (Test-PermisoEn $me3.permisos 'users.user.view')) {
        Ok 'ME u3 sin permiso expirado' 'permisos no incluye users.user.view'
    } else {
        Bad 'ME u3 sin permiso expirado' "permisos=$($me3.permisos | ConvertTo-Json -Compress)"
    }
    if (($me3.permisos_temporales | Measure-Object).Count -eq 0) { Ok 'ME u3 permisos_temporales vacios' '0 entradas' }
    else { Bad 'ME u3 permisos_temporales vacios' "valor=$($me3.permisos_temporales | ConvertTo-Json -Compress)" }
} else { Bad 'ME u3' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/users' $u3.Token
if ($r.Status -eq 403) { Ok 'GET /users con temporal EXPIRADO' '403' }
else { Bad 'GET /users con temporal EXPIRADO' "status=$($r.Status)" }

# ============================ 4) REVOCAR con token ya emitido (misma peticion)
if ($tpId) {
    $r = Invoke-Delete "/temporary-permissions/$tpId" $ta
    if ($r.Status -eq 200) { Ok 'REVOCAR temporal' '200' } else { Bad 'REVOCAR temporal' "status=$($r.Status) $($r.Content)" }

    $r = Invoke-Get '/users' $u2.Token
    if ($r.Status -eq 403) { Ok 'GET /users tras REVOCAR (token vivo)' '403' }
    else { Bad 'GET /users tras REVOCAR (token vivo)' "status=$($r.Status)" }

    $r = Invoke-Get '/auth/me' $u2.Token
    $me2b = ($r.Content | ConvertFrom-Json).data
    if (-not (Test-PermisoEn $me2b.permisos 'users.user.view')) { Ok 'ME u2 tras REVOCAR' 'permisos sin users.user.view' }
    else { Bad 'ME u2 tras REVOCAR' "permisos=$($me2b.permisos | ConvertTo-Json -Compress)" }
}

# ================== 5) SACAR DEL GRUPO con token ya emitido (misma peticion)
$r = Invoke-Delete "/groups/$gid/members/$($u1.Id)" $ta
if ($r.Status -eq 200) { Ok 'BAJA de u1 del grupo' '200' } else { Bad 'BAJA de u1 del grupo' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/users' $u1.Token
if ($r.Status -eq 403) { Ok 'GET /users tras BAJA del grupo (token vivo)' '403' }
else { Bad 'GET /users tras BAJA del grupo (token vivo)' "status=$($r.Status)" }

$r = Invoke-Get '/auth/me' $u1.Token
if ($r.Status -eq 200) {
    $me1b = ($r.Content | ConvertFrom-Json).data
    $todaviaGrupo = $false
    if ($me1b.roles -is [array]) { foreach ($rl in $me1b.roles) { if ($rl.origen -eq 'grupo') { $todaviaGrupo = $true } } }
    if (-not $todaviaGrupo -and -not (Test-PermisoEn $me1b.permisos 'users.user.view')) {
        Ok 'ME u1 tras BAJA del grupo' 'sin rol de grupo ni su permiso'
    } else {
        Bad 'ME u1 tras BAJA del grupo' "roles=$($me1b.roles | ConvertTo-Json -Compress) permisos=$($me1b.permisos | ConvertTo-Json -Compress)"
    }
} else { Bad 'ME u1 tras BAJA' "status=$($r.Status)" }

# ============================================ 6) QUITAR ADMIN (token vivo)
$r = Invoke-Post '/roles' (@{ nombre = "V1121 CROLE $($script:runId)"; descripcion = 'e2e v1.12.1'; permisos = @('roles.role.edit') } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $croleId = ($r.Content | ConvertFrom-Json).data.role.id; Ok 'ALTA rol auxiliar (roles.role.edit)' "id=$croleId" }
else { Bad 'ALTA rol auxiliar' "status=$($r.Status) $($r.Content)"; exit 1 }

$u4 = New-ActivatedUser $ta 'quitaradmin'
if (-not $u4) { Bad 'ALTA u4' 'no se pudo crear/activar'; exit 1 }

$r = Invoke-Post "/users/$($u4.Id)/roles" (@{ role_id = $adminRoleId } | ConvertTo-Json) $null $ta
if ($r.Status -eq 200) { Ok 'ASIGNAR ADMIN a u4' '200' } else { Bad 'ASIGNAR ADMIN a u4' "status=$($r.Status) $($r.Content)" }
$r = Invoke-Post "/users/$($u4.Id)/roles" (@{ role_id = $croleId } | ConvertTo-Json) $null $ta
if ($r.Status -eq 200) { Ok 'ASIGNAR rol auxiliar a u4' '200' } else { Bad 'ASIGNAR rol auxiliar a u4' "status=$($r.Status) $($r.Content)" }

$lg4 = LoginFull $u4.Email 'V1121Pass123'
if (-not $lg4) { Bad 'LOGIN u4' 'sin token'; exit 1 }
$t4 = $lg4.data.tokens.access_token

$me4 = (Invoke-Get '/auth/me' $t4).Content | ConvertFrom-Json
$tieneAdmin = $false
if ($me4.data.roles -is [array]) { foreach ($rl in $me4.data.roles) { if ($rl.nombre -eq 'ADMIN') { $tieneAdmin = $true } } }
if ($tieneAdmin) { Ok 'ME u4 con ADMIN (control previo)' 'roles incluye ADMIN' }
else { Bad 'ME u4 con ADMIN (control previo)' "roles=$($me4.data.roles | ConvertTo-Json -Compress)" }

# Control positivo: con ADMIN vigente, PATCH /roles permitido
$r = Invoke-Patch "/roles/$rid" (@{ descripcion = 'e2e v1.12.1' } | ConvertTo-Json) $t4
if ($r.Status -eq 200) { Ok 'PATCH /roles con ADMIN vigente' '200' }
else { Bad 'PATCH /roles con ADMIN vigente' "status=$($r.Status) $($r.Content)" }

# Quitar el rol ADMIN directo de u4 (u4 conserva roles.role.edit)
$r = Invoke-Delete "/users/$($u4.Id)/roles/$adminRoleId" $ta
if ($r.Status -eq 200) { Ok 'QUITAR ADMIN a u4' '200' }
else { Bad 'QUITAR ADMIN a u4' "status=$($r.Status) $($r.Content)" }

$me4b = (Invoke-Get '/auth/me' $t4).Content | ConvertFrom-Json
$todaviaAdmin = $false
if ($me4b.data.roles -is [array]) { foreach ($rl in $me4b.data.roles) { if ($rl.nombre -eq 'ADMIN') { $todaviaAdmin = $true } } }
if (-not $todaviaAdmin) { Ok 'ME u4 tras QUITAR ADMIN' 'sin rol ADMIN (token vivo)' }
else { Bad 'ME u4 tras QUITAR ADMIN' "aun muestra ADMIN: $($me4b.data.roles | ConvertTo-Json -Compress)" }

$r = Invoke-Patch "/roles/$rid" (@{ descripcion = 'e2e v1.12.1' } | ConvertTo-Json) $t4
if ($r.Status -eq 403) { Ok 'PATCH /roles tras QUITAR ADMIN' '403 (token vivo)' }
else { Bad 'PATCH /roles tras QUITAR ADMIN' "status=$($r.Status) $($r.Content)" }

# ======================================= 7) CONTRATO DE CAMPOS QUE LEE LA UI
$meA = (Invoke-Get '/auth/me' $ta).Content | ConvertFrom-Json
$d = $meA.data
if ($d.user -and $d.user.id -and $d.user.email -and $d.user.nombre) { Ok 'UI /auth/me user.{id,email,nombre}' 'presentes' }
else { Bad 'UI /auth/me user.{id,email,nombre}' "data=$($d | ConvertTo-Json -Compress)" }
if ($d.roles -is [array] -and $d.roles.Count -gt 0 -and $d.roles[0].nombre) { Ok 'UI /auth/me roles[].nombre' 'presente' }
else { Bad 'UI /auth/me roles[].nombre' "roles=$($d.roles | ConvertTo-Json -Compress)" }
if ($d.permisos -is [array] -and $d.permisos.Count -gt 0 -and ($d.permisos[0] -is [string])) { Ok 'UI /auth/me permisos[] string' "n=$($d.permisos.Count)" }
else { Bad 'UI /auth/me permisos[] string' "permisos=$($d.permisos | ConvertTo-Json -Compress)" }
if ($d.permisos_temporales -is [array]) { Ok 'UI /auth/me permisos_temporales[]' "n=$($d.permisos_temporales.Count)" }
else { Bad 'UI /auth/me permisos_temporales[]' "valor=$($d.permisos_temporales | ConvertTo-Json -Compress)" }

$lgA = LoginFull 'aleon@acme.local' 'Admin123!'
$lu = $lgA.data.user
if ($lu.roles -is [array] -and $lu.roles.Count -gt 0 -and $lu.roles[0].nombre) { Ok 'UI login user.roles[].nombre' 'presente' }
else { Bad 'UI login user.roles[].nombre' "roles=$($lu.roles | ConvertTo-Json -Compress)" }
if ($lu.permisos -is [array] -and $lu.permisos.Count -gt 0) { Ok 'UI login user.permisos[]' "n=$($lu.permisos.Count)" }
else { Bad 'UI login user.permisos[]' "permisos=$($lu.permisos | ConvertTo-Json -Compress)" }

$ro = (Invoke-Get '/roles' $ta).Content | ConvertFrom-Json
if ($ro.data.roles -is [array] -and $ro.data.roles.Count -ge 4) { Ok 'UI GET /roles data.roles[]' "n=$($ro.data.roles.Count)" }
else { Bad 'UI GET /roles data.roles[]' "valor=$($ro.data.roles | ConvertTo-Json -Compress)" }
$campoOk = $true
foreach ($rr2 in $ro.data.roles) { if (-not ($rr2.id -and $rr2.nombre -and $rr2.tipo -and ($rr2.permisos -is [array]))) { $campoOk = $false } }
if ($campoOk) { Ok 'UI GET /roles campos {id,nombre,tipo,permisos}' 'en todos los roles' }
else { Bad 'UI GET /roles campos {id,nombre,tipo,permisos}' 'faltan en algun rol' }

$ur = (Invoke-Get "/users/$($u1.Id)/roles" $ta).Content | ConvertFrom-Json
if ($ur.data.roles -is [array]) { Ok 'UI GET /users/{id}/roles data.roles[]' "n=$($ur.data.roles.Count)" }
else { Bad 'UI GET /users/{id}/roles data.roles[]' "valor=$($ur.data.roles | ConvertTo-Json -Compress)" }

# ================================================================ resumen
Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E v1.12.1: TODOS LOS CASOS OK' }
else { Write-Output "E2E v1.12.1: $script:failures FALLOS" }
Stop-E2EServer
exit $script:failures
