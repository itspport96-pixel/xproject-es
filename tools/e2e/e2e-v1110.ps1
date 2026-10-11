$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido
$script:failures = 0
$script:runId = [guid]::NewGuid().ToString('N').Substring(0, 8)
$script:email = "f2-smoke-$($script:runId)@acme.local"

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

# ================================================================== 1) admin
$admin = LoginFull 'aleon@acme.local' 'Admin123!'
$ta = if ($admin) { $admin.data.tokens.access_token } else { $null }
$adminId = if ($admin) { $admin.data.user.id } else { $null }
if (-not $ta) { Bad 'LOGIN admin' 'sin token'; exit 1 }
Ok 'LOGIN admin' "token len=$($ta.Length)"
if ($admin.data.password_change_required -eq $false) { Ok 'LOGIN flag' 'password_change_required=false' }
else { Bad 'LOGIN flag' "valor=$($admin.data.password_change_required)" }

# ============================================== 2) alta sin password + idempotencia
$body1 = @{ email = $script:email; nombre = 'F2'; apellido = 'Smoke' } | ConvertTo-Json
$key = "f2smoke-$($script:runId)"

$r1 = Invoke-Post '/users' $body1 $key $ta
$c1 = $null; try { $c1 = $r1.Content | ConvertFrom-Json } catch {}
if ($r1.Status -eq 201 -and $c1.data.user.id) { Ok 'ALTA' "201 id=$($c1.data.user.id)" }
else { Bad 'ALTA' "status=$($r1.Status) $($r1.Content)" }

$uid = $c1.data.user.id
$temp1 = $c1.data.user.password_temporal
if ($c1.data.user.password_change_required -eq $true -and $temp1 -and $temp1.Length -ge 8) {
    Ok 'ALTA password temporal' "flag=true len=$($temp1.Length)"
} else {
    Bad 'ALTA password temporal' $r1.Content
}

$r2 = Invoke-Post '/users' $body1 $key $ta
$c2 = $null; try { $c2 = $r2.Content | ConvertFrom-Json } catch {}
if ($r2.Status -eq 201 -and $c2.data.user.id -eq $uid -and $c2.data.user.password_temporal -eq $temp1) {
    Ok 'REPLAY idempotente' 'mismo id y mismo password_temporal'
} else {
    Bad 'REPLAY idempotente' "status=$($r2.Status) id=$($c2.data.user.id)"
}

$total = Scalar "SELECT COUNT(*) FROM users WHERE email = '$($script:email)' AND deleted_at IS NULL"
if ("$total" -eq '1') { Ok 'REPLAY sin duplicar' '1 fila en users' }
else { Bad 'REPLAY sin duplicar' "filas=$total" }

$r3 = Invoke-Post '/users' (@{ email = $script:email; nombre = 'Otro'; apellido = 'Payload' } | ConvertTo-Json) $key $ta
if ($r3.Status -eq 409) { Ok 'CLAVE con payload distinto' '409' }
else { Bad 'CLAVE con payload distinto' "status=$($r3.Status) $($r3.Content)" }

$r4 = Invoke-Post '/users' $body1 "bad key $([char]32) x" $ta
$c4 = $null; try { $c4 = $r4.Content | ConvertFrom-Json } catch {}
if ($r4.Status -eq 400 -and $c4.data.field -eq 'idempotency-key') {
    Ok 'CLAVE invalida' '400 field=idempotency-key'
} else { Bad 'CLAVE invalida' "status=$($r4.Status) $($r4.Content)" }

# ==================================================== 3) login con password temporal
$lt = LoginFull $script:email $temp1
$tTemp = if ($lt) { $lt.data.tokens.access_token } else { $null }
if (-not $tTemp) { Bad 'LOGIN temporal' 'sin token'; exit 1 }
if ($lt.data.password_change_required -eq $true) { Ok 'LOGIN temporal flag' 'password_change_required=true' }
else { Bad 'LOGIN temporal flag' "valor=$($lt.data.password_change_required)" }

$r = Invoke-Get '/users' $tTemp
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 403 -and $c.data.category -eq 'password_change_required') {
    Ok 'BLOQUEO cambio forzado' '403 category=password_change_required'
} else { Bad 'BLOQUEO cambio forzado' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/users/me/preferences' $tTemp
if ($r.Status -eq 200) { Ok 'ALLOWLIST me/preferences' '200' }
else { Bad 'ALLOWLIST me/preferences' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/locales' $tTemp
if ($r.Status -eq 200 -or $r.Status -eq 404) { Ok 'ALLOWLIST locales' "status=$($r.Status)" }
else { Bad 'ALLOWLIST locales' "status=$($r.Status) $($r.Content)" }

# ============================================ 4) cambio de contraseña propio
$r = Invoke-Post '/auth/change-password' (@{ password_actual = 'Incorrecta1'; password_nuevo = 'NuevaF2Pass123' } | ConvertTo-Json) $null $tTemp
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 400 -and $c.data.field -eq 'password_actual') {
    Ok 'CAMBIO password actual incorrecta' '400 field=password_actual'
} else { Bad 'CAMBIO password actual incorrecta' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/auth/change-password' (@{ password_actual = $temp1; password_nuevo = 'NuevaF2Pass123' } | ConvertTo-Json) $null $tTemp
if ($r.Status -eq 200) { Ok 'CAMBIO password' '200' }
else { Bad 'CAMBIO password' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/users' $tTemp
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 403 -and $c.data.category -ne 'password_change_required') {
    Ok 'DESPUES del cambio (sesion y marcador)' '403 por permisos: sesion viva y marcador limpio'
} else { Bad 'DESPUES del cambio (sesion y marcador)' "status=$($r.Status) $($r.Content)" }

# marker limpio en BD
$marker = Scalar "SELECT IFNULL(fecha_expiracion_password, 'NULL') FROM users WHERE id = '$uid'"
if ("$marker" -eq 'NULL') { Ok 'MARCADOR limpio' 'fecha_expiracion_password=NULL' }
else { Bad 'MARCADOR limpio' "valor=$marker" }

# ============================================================ 5) ciclo de estado
$r = Invoke-Post "/users/$adminId/status" (@{ estado = 'INACTIVO'; motivo = 'autoprueba' } | ConvertTo-Json) $null $ta
if ($r.Status -eq 403) { Ok 'ESTADO self 403' 'no puede cambiarse a si mismo' }
else { Bad 'ESTADO self 403' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/users/$uid/status" (@{ estado = 'SUSPENDIDO' } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 400 -and $c.data.field -eq 'motivo') { Ok 'ESTADO motivo obligatorio' '400 field=motivo' }
else { Bad 'ESTADO motivo obligatorio' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/users/$uid/status" (@{ estado = 'SUSPENDIDO'; motivo = 'prueba F2 smoke' } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 200 -and $c.data.user.estado -eq 'SUSPENDIDO') { Ok 'ESTADO suspender' '200 SUSPENDIDO' }
else { Bad 'ESTADO suspender' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/users/me/preferences' $tTemp
if ($r.Status -eq 401) { Ok 'SUSPENDIDO bloqueado' '401 en siguiente peticion' }
else { Bad 'SUSPENDIDO bloqueado' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/users/$uid/status" (@{ estado = 'ACTIVO'; motivo = 'fin de prueba' } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 200 -and $c.data.user.estado -eq 'ACTIVO') { Ok 'ESTADO reactivar' '200 ACTIVO' }
else { Bad 'ESTADO reactivar' "status=$($r.Status) $($r.Content)" }

# ==================================================== 6) reset de contraseña admin
$r = Invoke-Post "/users/$uid/password/reset" '{}' $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$temp2 = $c.data.password_temporal
if ($r.Status -eq 200 -and $temp2 -and $c.data.password_change_required -eq $true) {
    Ok 'RESET admin' "201/temporal len=$($temp2.Length)"
} else { Bad 'RESET admin' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/users/me/preferences' $tTemp
if ($r.Status -eq 401) { Ok 'RESET revoca sesiones' '401 en sesion anterior' }
else { Bad 'RESET revoca sesiones' "status=$($r.Status) $($r.Content)" }

$lt2 = LoginFull $script:email $temp2
$tTemp2 = if ($lt2) { $lt2.data.tokens.access_token } else { $null }
if ($tTemp2 -and $lt2.data.password_change_required -eq $true) {
    Ok 'LOGIN tras reset' 'flag=true'
} else { Bad 'LOGIN tras reset' "resp=$($lt2 | ConvertTo-Json -Depth 6)" }

$r = Invoke-Post '/auth/change-password' (@{ password_actual = $temp2; password_nuevo = 'FinalF2Pass123' } | ConvertTo-Json) $null $tTemp2
if ($r.Status -eq 200) { Ok 'CAMBIO final' '200' }
else { Bad 'CAMBIO final' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/users' $tTemp2
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 403 -and $c.data.category -ne 'password_change_required') {
    Ok 'SESION final sin bloqueo' '403 por permisos: marcador limpio'
} else { Bad 'SESION final sin bloqueo' "status=$($r.Status) $($r.Content)" }

# ================================================================== 7) auditoria
$audCreate = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'user.create'"
$audStatus = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'user.status.change'"
$audReset = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'user.password.reset'"
$audChange = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'auth.password.change'"
$leak = Scalar "SELECT COUNT(*) FROM audit_logs WHERE CONCAT_WS(' ', detalles_adicionales, valores_nuevos) LIKE '%$temp1%' OR CONCAT_WS(' ', detalles_adicionales, valores_nuevos) LIKE '%$temp2%'"
if ([int]$audCreate -gt 0) { Ok 'AUDIT user.create' "$audCreate filas" } else { Bad 'AUDIT user.create' "$audCreate" }
if ([int]$audStatus -gt 0) { Ok 'AUDIT user.status.change' "$audStatus filas" } else { Bad 'AUDIT user.status.change' "$audStatus" }
if ([int]$audReset -gt 0) { Ok 'AUDIT user.password.reset' "$audReset filas" } else { Bad 'AUDIT user.password.reset' "$audReset" }
if ([int]$audChange -gt 0) { Ok 'AUDIT auth.password.change' "$audChange filas" } else { Bad 'AUDIT auth.password.change' "$audChange" }
if ("$leak" -eq '0') { Ok 'AUDIT sin password temporal en claro' '0 coincidencias' }
else { Bad 'AUDIT sin password temporal en claro' "filas=$leak" }

# ================================================== 8) replay no duplica auditoria
$beforeCreate = [int](Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'user.create'")
# (el replay de arriba ya se ejecuto; comprobamos que no aumento tras repetir)
$r2b = Invoke-Post '/users' $body1 $key $ta
$afterCreate = [int](Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'user.create'")
if ($afterCreate -eq $beforeCreate -and $r2b.Status -eq 201) {
    Ok 'AUDIT replay sin fila nueva' "$afterCreate == $beforeCreate"
} else { Bad 'AUDIT replay sin fila nueva' "antes=$beforeCreate despues=$afterCreate status=$($r2b.Status)" }

# ====================================== 10) F3: catalogo de permisos y guards
# (antes de la limpieza: se usa tTemp2, token de usuario de prueba que al
# borrarse queda invalido)
$r = Invoke-Get '/roles' $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$adminRole = $c.data.roles | Where-Object { $_.nombre -eq 'ADMIN' } | Select-Object -First 1
$managerRole = $c.data.roles | Where-Object { $_.nombre -eq 'MANAGER' } | Select-Object -First 1
if ($adminRole -and $managerRole) { Ok 'F3 roles base' "ADMIN=$($adminRole.id) MANAGER=$($managerRole.id)" }
else { Bad 'F3 roles base' $r.Content }

$r = Invoke-Get '/permissions' $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$idents = @()
if ($c.data.permissions) { $idents = @($c.data.permissions | ForEach-Object { $_.identificador }) }
if ($r.Status -eq 200 -and [int]$c.data.total -ge 57 -and $idents -contains 'groups.group.delete') {
    Ok 'CATALOGO total' "$($c.data.total) permisos, incluye groups.group.delete"
} else { Bad 'CATALOGO total' "status=$($r.Status) total=$($c.data.total)" }

$r = Invoke-Get '/permissions?modulo=inventory' $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$mods = @(); if ($c.data.permissions) { $mods = @($c.data.permissions | ForEach-Object { $_.modulo } | Select-Object -Unique) }
if ($r.Status -eq 200 -and $mods.Count -eq 1 -and $mods[0] -eq 'inventory') {
    Ok 'CATALOGO filtro modulo' "total=$($c.data.total)"
} else { Bad 'CATALOGO filtro modulo' "status=$($r.Status) mods=$($mods -join ',')" }

$r = Invoke-Get '/permissions?criticidad=CRITICAL' $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$crits = @(); if ($c.data.permissions) { $crits = @($c.data.permissions | ForEach-Object { $_.criticidad } | Select-Object -Unique) }
if ($r.Status -eq 200 -and $crits.Count -eq 1 -and $crits[0] -eq 'CRITICAL') {
    Ok 'CATALOGO filtro criticidad' "total=$($c.data.total)"
} else { Bad 'CATALOGO filtro criticidad' "status=$($r.Status) crits=$($crits -join ',')" }

$r = Invoke-Get '/permissions?modulo=inventory&criticidad=CRITICAL' $ta
if ($r.Status -eq 400) { Ok 'CATALOGO filtros excluyentes' '400' }
else { Bad 'CATALOGO filtros excluyentes' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/permissions?criticidad=ALTA' $ta
if ($r.Status -eq 400) { Ok 'CATALOGO criticidad invalida' '400' }
else { Bad 'CATALOGO criticidad invalida' "status=$($r.Status) $($r.Content)" }

$r0 = Invoke-Get '/permissions' $ta
$c0 = $null; try { $c0 = $r0.Content | ConvertFrom-Json } catch {}
$permShowId = $c0.data.permissions[0].id
$r = Invoke-Get "/permissions/$permShowId" $ta
if ($r.Status -eq 200) { Ok 'CATALOGO show' '200' }
else { Bad 'CATALOGO show' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/permissions/00000000-0000-0000-0000-000000000000' $ta
if ($r.Status -eq 404) { Ok 'CATALOGO show 404' '404' }
else { Bad 'CATALOGO show 404' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/permissions' $tTemp2
if ($r.Status -eq 403) { Ok 'CATALOGO sin permiso 403' '403 para usuario sin roles' }
else { Bad 'CATALOGO sin permiso 403' "status=$($r.Status) $($r.Content)" }

# guards BUILT_IN
$r = Invoke-Patch "/roles/$($adminRole.id)" (@{ nombre = 'HACKED' } | ConvertTo-Json) $ta
if ($r.Status -eq 403) { Ok 'GUARD renombrar ADMIN' '403' }
else { Bad 'GUARD renombrar ADMIN' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Patch "/roles/$($adminRole.id)" (@{ activo = $false } | ConvertTo-Json) $ta
if ($r.Status -eq 403) { Ok 'GUARD desactivar ADMIN' '403' }
else { Bad 'GUARD desactivar ADMIN' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Patch "/roles/$($adminRole.id)/permissions" (@{ permisos = @() } | ConvertTo-Json) $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 403 -and $c.message -like '*ADMIN no puede perder*') {
    Ok 'GUARD ADMIN no pierde permisos' '403'
} else { Bad 'GUARD ADMIN no pierde permisos' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get "/roles/$($adminRole.id)/permissions" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 200 -and [int]@($c.data.permisos).Count -ge 12) {
    Ok 'GUARD ADMIN permisos intactos' "$(@($c.data.permisos).Count) permisos tras intento"
} else { Bad 'GUARD ADMIN permisos intactos' "status=$($r.Status) count=$(@($c.data.permisos).Count)" }

$r = Invoke-Delete "/roles/$($adminRole.id)" $ta
if ($r.Status -eq 403) { Ok 'GUARD borrar ADMIN' '403' }
else { Bad 'GUARD borrar ADMIN' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Patch "/roles/$($managerRole.id)" (@{ nombre = 'HACKED2' } | ConvertTo-Json) $ta
if ($r.Status -eq 403) { Ok 'GUARD renombrar MANAGER' '403' }
else { Bad 'GUARD renombrar MANAGER' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/roles' (@{ nombre = 'rol@invalido' } | ConvertTo-Json) $null $ta
if ($r.Status -eq 400) { Ok 'ROL nombre invalido' '400' }
else { Bad 'ROL nombre invalido' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/roles' (@{ nombre = 'ADMIN' } | ConvertTo-Json) $null $ta
if ($r.Status -eq 409) { Ok 'ROL nombre duplicado' '409' }
else { Bad 'ROL nombre duplicado' "status=$($r.Status) $($r.Content)" }

# ciclo de rol CUSTOM
$customNombre = "F2 SMOKE $($script:runId)".ToUpper()
$r = Invoke-Post '/roles' (@{ nombre = $customNombre; descripcion = 'rol de prueba'; permisos = @('users.user.view') } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$customId = if ($c.data.role.id) { $c.data.role.id } else { $null }
if ($r.Status -eq 201 -and $customId) { Ok 'ROL custom alta' "201 id=$customId" }
else { Bad 'ROL custom alta' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Patch "/roles/$customId" (@{ nombre = "$customNombre V2" } | ConvertTo-Json) $ta
if ($r.Status -eq 200) { Ok 'ROL custom renombrar' '200' }
else { Bad 'ROL custom renombrar' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Delete "/roles/$customId" $ta
if ($r.Status -eq 200) { Ok 'ROL custom baja' '200' }
else { Bad 'ROL custom baja' "status=$($r.Status) $($r.Content)" }

# Politica de borrado (v1.13.9): el endpoint de restauracion ya no existe.
$r = Invoke-Post "/roles/$customId/restore" '{}' $null $ta
if ($r.Status -eq 404) { Ok 'ROL restore eliminado' '404 (ruta inexistente)' }
else { Bad 'ROL restore eliminado' "status=$($r.Status) $($r.Content)" }

# El nombre del rol borrado queda libre y puede reutilizarse (id nuevo).
$r = Invoke-Post '/roles' (@{ nombre = $customNombre; descripcion = 'rol recreado tras borrado' } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$recreadoId = if ($c.data.role.id) { $c.data.role.id } else { $null }
if ($r.Status -eq 201 -and $recreadoId -and $recreadoId -ne $customId) {
    Ok 'ROL recrear mismo nombre' "201 id-nuevo=$recreadoId"
} else { Bad 'ROL recrear mismo nombre' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Delete "/roles/$recreadoId" $ta
if ($r.Status -eq 200) { Ok 'ROL recreado baja final' '200' }
else { Bad 'ROL recreado baja final' "status=$($r.Status) $($r.Content)" }

$customCount = Scalar "SELECT COUNT(*) FROM roles WHERE id = '$customId' AND deleted_at IS NOT NULL"
if ("$customCount" -eq '1') { Ok 'ROL custom soft delete en BD' 'deleted_at fijado' }
else { Bad 'ROL custom soft delete en BD' "filas=$customCount" }

# ====================================== 11) F4: grupos de usuarios
$r = Invoke-Get '/roles' $ta
$cr = $null; try { $cr = $r.Content | ConvertFrom-Json } catch {}
$viewerRole = $cr.data.roles | Where-Object { $_.nombre -eq 'VIEWER' } | Select-Object -First 1
$managerRole = $cr.data.roles | Where-Object { $_.nombre -eq 'MANAGER' } | Select-Object -First 1

$gNombre = "F2 SMOKE GRUPO $($script:runId)".ToUpper()
$gKey = "grp-$($script:runId)"
$r = Invoke-Post '/groups' (@{ nombre = $gNombre; descripcion = 'grupo e2e' } | ConvertTo-Json) $gKey $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$gid = if ($c.data.group.id) { $c.data.group.id } else { $null }
if ($r.Status -eq 201 -and $gid) { Ok 'GRUPO alta' "201 id=$gid" }
else { Bad 'GRUPO alta' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/groups' (@{ nombre = $gNombre; descripcion = 'grupo e2e' } | ConvertTo-Json) $gKey $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 201 -and $c.data.group.id -eq $gid) { Ok 'GRUPO replay' 'mismo id' }
else { Bad 'GRUPO replay' "status=$($r.Status) id=$($c.data.group.id)" }

$gcount = Scalar "SELECT COUNT(*) FROM user_groups WHERE nombre = '$gNombre'"
if ("$gcount" -eq '1') { Ok 'GRUPO replay sin duplicar' '1 fila' }
else { Bad 'GRUPO replay sin duplicar' "filas=$gcount" }

$r = Invoke-Get "/groups/$gid" $ta
if ($r.Status -eq 200) { Ok 'GRUPO show' '200' }
else { Bad 'GRUPO show' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/groups/00000000-0000-0000-0000-000000000000' $ta
if ($r.Status -eq 404) { Ok 'GRUPO show 404' '404' }
else { Bad 'GRUPO show 404' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/groups' (@{ nombre = $gNombre } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 409 -and $c.data.field -eq 'nombre') { Ok 'GRUPO nombre duplicado' '409 field=nombre' }
else { Bad 'GRUPO nombre duplicado' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Patch "/groups/$gid" (@{ nombre = '' } | ConvertTo-Json) $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 400 -and $c.data.field -eq 'nombre') { Ok 'GRUPO nombre vacio' '400 field=nombre' }
else { Bad 'GRUPO nombre vacio' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Patch "/groups/$gid" (@{ descripcion = 'editado'; activo = $false } | ConvertTo-Json) $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 200 -and $c.data.group.activo -eq $false) { Ok 'GRUPO desactivar' '200 activo=false' }
else { Bad 'GRUPO desactivar' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Patch "/groups/$gid" (@{ activo = $true } | ConvertTo-Json) $ta
if ($r.Status -eq 200) { Ok 'GRUPO reactivar' '200' }
else { Bad 'GRUPO reactivar' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/groups' (@{ nombre = 'X' } | ConvertTo-Json) $null $tTemp2
if ($r.Status -eq 403) { Ok 'GRUPO sin permiso 403' 'POST /groups' }
else { Bad 'GRUPO sin permiso 403' "status=$($r.Status) $($r.Content)" }

# miembros
$r = Invoke-Post "/groups/$gid/members" (@{ user_id = $uid } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { Ok 'GRUPO add miembro' '201' }
else { Bad 'GRUPO add miembro' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/groups/$gid/members" (@{ user_id = $uid } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 409 -and $c.data.field -eq 'user_id') { Ok 'GRUPO miembro duplicado' '409' }
else { Bad 'GRUPO miembro duplicado' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/groups/$gid/members" (@{ user_id = '00000000-0000-0000-0000-000000000000' } | ConvertTo-Json) $null $ta
if ($r.Status -eq 404) { Ok 'GRUPO miembro inexistente' '404' }
else { Bad 'GRUPO miembro inexistente' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/groups/$gid/members" (@{ user_id = $adminId } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { Ok 'GRUPO add admin como miembro' '201' }
else { Bad 'GRUPO add admin como miembro' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get "/groups/$gid/members" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 200 -and [int]$c.data.total -eq 2) { Ok 'GRUPO lista miembros' 'total=2' }
else { Bad 'GRUPO lista miembros' "status=$($r.Status) total=$($c.data.total)" }

# usuario MANAGER-only (escalada)
$mgrEmail = "f2-mgr-$($script:runId)@acme.local"
$r = Invoke-Post '/users' (@{ email = $mgrEmail; nombre = 'Mgr'; apellido = 'Lite' } | ConvertTo-Json) "mgr-$($script:runId)" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$uid2 = if ($c.data.user.id) { $c.data.user.id } else { $null }
$tempMgr = $c.data.user.password_temporal
if ($r.Status -eq 201 -and $uid2) { Ok 'GRUPO mgr-lite alta' "id=$uid2" }
else { Bad 'GRUPO mgr-lite alta' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/users/$uid2/roles" (@{ role_id = $managerRole.id } | ConvertTo-Json) $null $ta
if ($r.Status -eq 200) { Ok 'GRUPO mgr-lite recibe MANAGER' '200' }
else { Bad 'GRUPO mgr-lite recibe MANAGER' "status=$($r.Status) $($r.Content)" }

$lt3 = LoginFull $mgrEmail $tempMgr
$tMgr = if ($lt3) { $lt3.data.tokens.access_token } else { $null }
if (-not $tMgr) { Bad 'GRUPO mgr-lite login' 'sin token'; exit 1 }
Ok 'GRUPO mgr-lite login' 'token ok'

# salir del cambio forzado (password temporal) antes de usar el token
$r = Invoke-Post '/auth/change-password' (@{ password_actual = $tempMgr; password_nuevo = 'MgrLitePass123' } | ConvertTo-Json) $null $tMgr
if ($r.Status -eq 200) { Ok 'GRUPO mgr-lite cambio pass' '200' }
else { Bad 'GRUPO mgr-lite cambio pass' "status=$($r.Status) $($r.Content)" }

# escalada: rol demasiado potente para el actor
$r = Invoke-Post "/groups/$gid/roles" (@{ role_id = $adminRole.id } | ConvertTo-Json) $null $tMgr
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 403 -and $c.message -like '*contiene permisos*') { Ok 'ESCALADA rol > actor' '403' }
else { Bad 'ESCALADA rol > actor' "status=$($r.Status) $($r.Content)" }

# escalada: miembro ADMIN del grupo con actor no-ADMIN
# (puede disparar el guard de permisos efectivos —MANAGER 42 < ADMIN 57— o
# el de "sólo ADMIN sobre ADMIN"; ambos deniegan con 403)
$r = Invoke-Post "/groups/$gid/roles" (@{ role_id = $viewerRole.id } | ConvertTo-Json) $null $tMgr
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 403 -and ($c.message -like '*permisos efectivos*' -or $c.message -like '*rol ADMIN*')) {
    Ok 'ESCALADA miembro ADMIN' '403'
} else { Bad 'ESCALADA miembro ADMIN' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Delete "/groups/$gid/members/$adminId" $ta
if ($r.Status -eq 200) { Ok 'GRUPO remove admin' '200' }
else { Bad 'GRUPO remove admin' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/groups/$gid/roles" (@{ role_id = $viewerRole.id } | ConvertTo-Json) $null $tMgr
if ($r.Status -eq 201) { Ok 'GRUPO asignar VIEWER (escalada ok)' '201' }
else { Bad 'GRUPO asignar VIEWER (escalada ok)' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/groups' $tTemp2
if ($r.Status -eq 200) { Ok 'HERENCIA grupo da view' '200 para usuario sin roles' }
else { Bad 'HERENCIA grupo da view' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Delete "/groups/$gid/roles/$($viewerRole.id)" $tMgr
if ($r.Status -eq 200) { Ok 'GRUPO quitar VIEWER' '200' }
else { Bad 'GRUPO quitar VIEWER' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/groups' $tTemp2
if ($r.Status -eq 403) { Ok 'INVALIDA quitar rol del grupo' '403 tras quitar' }
else { Bad 'INVALIDA quitar rol del grupo' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post "/groups/$gid/roles" (@{ role_id = $viewerRole.id } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { Ok 'GRUPO reasignar VIEWER' '201' }
else { Bad 'GRUPO reasignar VIEWER' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Patch "/groups/$gid" (@{ activo = $false } | ConvertTo-Json) $ta
if ($r.Status -eq 200) { Ok 'GRUPO desactivar con rol' '200' }
else { Bad 'GRUPO desactivar con rol' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/groups' $tTemp2
if ($r.Status -eq 403) { Ok 'FILTRO grupo inactivo' '403' }
else { Bad 'FILTRO grupo inactivo' "status=$($r.Status) $($r.Content)" }

# auditoria de grupos
$audG = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'group.create'"
$audM = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'group.members.add'"
$audR = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'group.roles.assign'"
if ([int]$audG -gt 0) { Ok 'AUDIT group.create' "$audG filas" } else { Bad 'AUDIT group.create' "$audG" }
if ([int]$audM -gt 0) { Ok 'AUDIT group.members.add' "$audM filas" } else { Bad 'AUDIT group.members.add' "$audM" }
if ([int]$audR -gt 0) { Ok 'AUDIT group.roles.assign' "$audR filas" } else { Bad 'AUDIT group.roles.assign' "$audR" }

# limpieza F4
$r = Invoke-Delete "/groups/$gid" $ta
if ($r.Status -eq 200) { Ok 'GRUPO delete' '200' }
else { Bad 'GRUPO delete' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get "/groups/$gid" $ta
if ($r.Status -eq 404) { Ok 'GRUPO delete show 404' '404' }
else { Bad 'GRUPO delete show 404' "status=$($r.Status) $($r.Content)" }

$gdel = Scalar "SELECT COUNT(*) FROM user_groups WHERE id = '$gid' AND deleted_at IS NOT NULL"
if ("$gdel" -eq '1') { Ok 'GRUPO soft delete en BD' 'deleted_at fijado' }
else { Bad 'GRUPO soft delete en BD' "filas=$gdel" }

$r = Invoke-Delete "/users/$uid2" $ta
if ($r.Status -eq 204) { Ok 'DELETE mgr-lite' '204' }
else { Bad 'DELETE mgr-lite' "status=$($r.Status) $($r.Content)" }

# ====================================== 12) F5: permisos temporales
$r = Invoke-Get '/permissions' $ta
$cp = $null; try { $cp = $r.Content | ConvertFrom-Json } catch {}
$allPerms = @(); if ($cp.data.permissions) { $allPerms = @($cp.data.permissions) }
$pViewId  = ($allPerms | Where-Object { $_.identificador -eq 'groups.group.view' } | Select-Object -First 1).id
$pGrantId = ($allPerms | Where-Object { $_.identificador -eq 'temporary.permission.grant' } | Select-Object -First 1).id
$pCritId  = ($allPerms | Where-Object { $_.identificador -eq 'groups.group.delete' } | Select-Object -First 1).id
$pOtherId = ($allPerms | Where-Object { $_.identificador -eq 'users.user.view' } | Select-Object -First 1).id
if ($pViewId -and $pGrantId -and $pCritId -and $pOtherId) {
    Ok 'F5 ids permisos' "view/grant/crit/other OK"
} else {
    Bad 'F5 ids permisos' "view=$pViewId grant=$pGrantId crit=$pCritId other=$pOtherId"
}

# baselines: el usuario de prueba no tiene temporary.permission.view ni grupos
$r = Invoke-Get '/temporary-permissions' $tTemp2
if ($r.Status -eq 403) { Ok 'TEMP sin view 403' '403 para usuario sin permiso' }
else { Bad 'TEMP sin view 403' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/groups' $tTemp2
if ($r.Status -eq 403) { Ok 'TEMP baseline sin herencia' '403' }
else { Bad 'TEMP baseline sin herencia' "status=$($r.Status) $($r.Content)" }

# alta con duracion_horas
$tpBody = @{ user_id = $uid; permission_id = $pViewId; motivo = 'smoke f5'; duracion_horas = 2 } | ConvertTo-Json
$r = Invoke-Post '/temporary-permissions' $tpBody $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$tpA = if ($c.data.temporary_permission.id) { $c.data.temporary_permission.id } else { $null }
if ($r.Status -eq 201 -and $tpA -and $c.data.temporary_permission.user_id -eq $uid -and $c.data.temporary_permission.permission_id -eq $pViewId) {
    Ok 'TEMP alta' "201 id=$tpA expira=$($c.data.temporary_permission.fecha_expiracion)"
} else { Bad 'TEMP alta' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/temporary-permissions' $tpBody $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 409 -and $c.data.field -eq 'permission_id') { Ok 'TEMP duplicado' '409 field=permission_id' }
else { Bad 'TEMP duplicado' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/temporary-permissions' (@{ user_id = $uid; permission_id = $pViewId; motivo = 'x' } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 400 -and $c.data.field -eq 'fecha_expiracion') { Ok 'TEMP sin expiracion' '400 field=fecha_expiracion' }
else { Bad 'TEMP sin expiracion' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/temporary-permissions' (@{ user_id = $uid; permission_id = $pViewId; motivo = 'x'; fecha_expiracion = '2030-01-01 00:00:00'; duracion_horas = 2 } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 400 -and $c.data.field -eq 'fecha_expiracion') { Ok 'TEMP fecha y duracion exclusivos' '400' }
else { Bad 'TEMP fecha y duracion exclusivos' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/temporary-permissions' (@{ user_id = $uid; permission_id = $pViewId; motivo = 'x'; duracion_horas = 0 } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 400 -and $c.data.field -eq 'duracion_horas') { Ok 'TEMP duracion invalida' '400 field=duracion_horas' }
else { Bad 'TEMP duracion invalida' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/temporary-permissions' (@{ user_id = $uid; permission_id = $pViewId; motivo = 'x'; duracion_horas = 745 } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 400 -and $c.data.field -eq 'fecha_expiracion') { Ok 'TEMP max dias 30' '400 (745h > 30d)' }
else { Bad 'TEMP max dias 30' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/temporary-permissions' (@{ user_id = '00000000-0000-0000-0000-000000000000'; permission_id = $pViewId; motivo = 'x'; duracion_horas = 1 } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 404 -and $c.data.field -eq 'user_id') { Ok 'TEMP usuario inexistente' '404 field=user_id' }
else { Bad 'TEMP usuario inexistente' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/temporary-permissions' (@{ user_id = $uid; permission_id = '00000000-0000-0000-0000-000000000000'; motivo = 'x'; duracion_horas = 1 } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 404 -and $c.data.field -eq 'permission_id') { Ok 'TEMP permiso inexistente' '404 field=permission_id' }
else { Bad 'TEMP permiso inexistente' "status=$($r.Status) $($r.Content)" }

# herencia efectiva: el temporal da groups.group.view al usuario sin roles
$r = Invoke-Get '/groups' $tTemp2
if ($r.Status -eq 200) { Ok 'TEMP herencia da view' '200 via permiso temporal' }
else { Bad 'TEMP herencia da view' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/temporary-permissions' $tTemp2
if ($r.Status -eq 403) { Ok 'TEMP sigue sin view' '403' }
else { Bad 'TEMP sigue sin view' "status=$($r.Status) $($r.Content)" }

# usuario extra (target de rate limit; no necesita login)
$f5Email = "f5-smoke-$($script:runId)@acme.local"
$r = Invoke-Post '/users' (@{ email = $f5Email; nombre = 'F5'; apellido = 'Smoke' } | ConvertTo-Json) "f5-$($script:runId)" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$uid5 = if ($c.data.user.id) { $c.data.user.id } else { $null }
if ($r.Status -eq 201 -and $uid5) { Ok 'TEMP usuario f5 alta' "id=$uid5" }
else { Bad 'TEMP usuario f5 alta' "status=$($r.Status) $($r.Content)" }

# aislamiento del rate limit CRITICAL: el limite son 2 intentos/hora por
# actor; si una ejecucion anterior dejo el contador, esta arrancaria con 429
$rateClearScript = Join-Path $PSScriptRoot 'clear-rate-limit.php'
$rlOut = & php $rateClearScript "temp_permission_grant_critical:$adminId" 2>&1
if ("$rlOut" -like 'cleared*') { Ok 'TEMP rate preclear' 'contador reiniciado' }
else { Bad 'TEMP rate preclear' "$rlOut" }

# actor no-ADMIN con grant temporal: aleon le otorga temporary.permission.grant (CRITICAL)
# -> intento de rate limit 1 de aleon
$r = Invoke-Post '/temporary-permissions' (@{ user_id = $uid; permission_id = $pGrantId; motivo = 'smoke f5 grant'; duracion_horas = 1 } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$tpG = if ($c.data.temporary_permission.id) { $c.data.temporary_permission.id } else { $null }
if ($r.Status -eq 201 -and $tpG) { Ok 'TEMP grant CRITICAL a actor' "201 id=$tpG (rate intento 1)" }
else { Bad 'TEMP grant CRITICAL a actor' "status=$($r.Status) $($r.Content)" }

# userF2 (con grant temporal, sin rol ADMIN) intenta otorgar un CRITICAL -> 403
$r = Invoke-Post '/temporary-permissions' (@{ user_id = $uid5; permission_id = $pCritId; motivo = 'x'; duracion_horas = 1 } | ConvertTo-Json) $null $tTemp2
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 403 -and $c.message -like '*rol ADMIN*') { Ok 'TEMP critical no-admin' '403 rol ADMIN requerido' }
else { Bad 'TEMP critical no-admin' "status=$($r.Status) $($r.Content)" }

# escalada: userF2 (con grant temporal) intenta otorgar a un ADMIN -> 403
$r = Invoke-Post '/temporary-permissions' (@{ user_id = $adminId; permission_id = $pOtherId; motivo = 'x'; duracion_horas = 1 } | ConvertTo-Json) $null $tTemp2
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 403 -and $c.message -like '*permisos efectivos*') { Ok 'TEMP escalada actor < objetivo' '403' }
else { Bad 'TEMP escalada actor < objetivo' "status=$($r.Status) $($r.Content)" }

# rate limit CRITICAL: aleon ya lleva 1 -> grant 2 OK y 3o rechazado (2/hora)
$r = Invoke-Post '/temporary-permissions' (@{ user_id = $uid; permission_id = $pCritId; motivo = 'smoke f5 crit'; duracion_horas = 1 } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$tpC = if ($c.data.temporary_permission.id) { $c.data.temporary_permission.id } else { $null }
if ($r.Status -eq 201 -and $tpC) { Ok 'TEMP rate intento 2' "201 id=$tpC" }
else { Bad 'TEMP rate intento 2' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/temporary-permissions' (@{ user_id = $uid5; permission_id = $pCritId; motivo = 'smoke f5 rate'; duracion_horas = 1 } | ConvertTo-Json) $null $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 429) { Ok 'TEMP rate limit 429' '3er grant CRITICAL en la hora' }
else { Bad 'TEMP rate limit 429' "status=$($r.Status) $($r.Content)" }

# listados
$r = Invoke-Get '/temporary-permissions' $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 200 -and [int]$c.data.total -ge 3) { Ok 'TEMP list' "total=$($c.data.total)" }
else { Bad 'TEMP list' "status=$($r.Status) total=$($c.data.total)" }

$r = Invoke-Get "/temporary-permissions?user_id=$uid" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$uids = @(); if ($c.data.temporary_permissions) { $uids = @($c.data.temporary_permissions | ForEach-Object { $_.user_id } | Select-Object -Unique) }
if ($r.Status -eq 200 -and [int]$c.data.total -ge 2 -and $uids.Count -eq 1 -and $uids[0] -eq $uid) {
    Ok 'TEMP list por usuario' "total=$($c.data.total)"
} else { Bad 'TEMP list por usuario' "status=$($r.Status) total=$($c.data.total) uids=$($uids -join ',')" }

$r = Invoke-Get '/temporary-permissions?include_inactive=true' $ta
if ($r.Status -eq 200) { Ok 'TEMP list include_inactive' '200' }
else { Bad 'TEMP list include_inactive' "status=$($r.Status) $($r.Content)" }

# revocar: sin permiso, ok, duplicado, 404
$r = Invoke-Delete "/temporary-permissions/$tpA" $tTemp2
if ($r.Status -eq 403) { Ok 'TEMP revoke sin permiso' '403' }
else { Bad 'TEMP revoke sin permiso' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Delete "/temporary-permissions/$tpA" $ta
if ($r.Status -eq 200) { Ok 'TEMP revoke' '200' }
else { Bad 'TEMP revoke' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/groups' $tTemp2
if ($r.Status -eq 403) { Ok 'TEMP invalida al revocar' '403 tras revocar' }
else { Bad 'TEMP invalida al revocar' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Delete "/temporary-permissions/$tpA" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
if ($r.Status -eq 409 -and $c.data.field -eq 'id') { Ok 'TEMP revoke duplicado' '409' }
else { Bad 'TEMP revoke duplicado' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Delete '/temporary-permissions/00000000-0000-0000-0000-000000000000' $ta
if ($r.Status -eq 404) { Ok 'TEMP revoke 404' '404' }
else { Bad 'TEMP revoke 404' "status=$($r.Status) $($r.Content)" }

# auditoria
$audTg = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'temporary_permission.grant'"
$audTr = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'temporary_permission.revoke'"
if ([int]$audTg -ge 3) { Ok 'AUDIT temporary_permission.grant' "$audTg filas" }
else { Bad 'AUDIT temporary_permission.grant' "$audTg (esperaba >= 3)" }
if ([int]$audTr -ge 1) { Ok 'AUDIT temporary_permission.revoke' "$audTr filas" }
else { Bad 'AUDIT temporary_permission.revoke' "$audTr (esperaba >= 1)" }

# limpieza F5: revocar CRITICAL pendientes, borrar usuario f5, limpiar rate limit
$r = Invoke-Delete "/temporary-permissions/$tpG" $ta
if ($r.Status -eq 200) { Ok 'TEMP limpieza grant' '200' }
else { Bad 'TEMP limpieza grant' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Delete "/temporary-permissions/$tpC" $ta
if ($r.Status -eq 200) { Ok 'TEMP limpieza crit' '200' }
else { Bad 'TEMP limpieza crit' "status=$($r.Status) $($r.Content)" }

$leftover = Scalar "SELECT COUNT(*) FROM temporary_permissions WHERE user_id = '$uid' AND activo = 1"
if ("$leftover" -eq '0') { Ok 'TEMP sin activos tras limpieza' '0 filas' }
else { Bad 'TEMP sin activos tras limpieza' "filas=$leftover" }

$r = Invoke-Delete "/users/$uid5" $ta
if ($r.Status -eq 204) { Ok 'DELETE usuario f5' '204' }
else { Bad 'DELETE usuario f5' "status=$($r.Status) $($r.Content)" }

$rateClearScript = Join-Path $PSScriptRoot 'clear-rate-limit.php'
$rlOut = & php $rateClearScript "temp_permission_grant_critical:$adminId" 2>&1
if ("$rlOut" -like 'cleared*') { Ok 'TEMP rate limpiado' 'clave Redis borrada' }
else { Bad 'TEMP rate limpiado' "$rlOut" }

# ================================================================== 9) limpieza
$r = Invoke-Delete "/users/$uid" $ta
if ($r.Status -eq 204) { Ok 'DELETE usuario de prueba' '204' }
else { Bad 'DELETE usuario de prueba' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get "/users/$uid" $ta
if ($r.Status -eq 404) { Ok 'GET tras DELETE' '404' }
else { Bad 'GET tras DELETE' "status=$($r.Status) $($r.Content)" }

# ================================================================== resumen
Write-Output ''
if ($script:failures -eq 0) { Write-Output 'F2+F3+F4+F5 SMOKE: TODOS LOS CASOS OK' }
else { Write-Output "F2+F3+F4+F5 SMOKE: $script:failures FALLOS" }
Stop-E2EServer
exit $script:failures
