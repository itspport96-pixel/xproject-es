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
    $email = "v1131-$tag-$($script:runId)@acme.local"
    $r = Invoke-Post '/users' (@{ email = $email; nombre = 'V1131'; apellido = $tag } | ConvertTo-Json) "v1131-$tag-$($script:runId)" $token
    if ($r.Status -ne 201) { return $null }
    $c = $r.Content | ConvertFrom-Json
    $uid = $c.data.user.id
    $temp = $c.data.user.password_temporal

    $lt = LoginFull $email $temp
    if (-not $lt) { return $null }
    $tTemp = $lt.data.tokens.access_token
    $null = Invoke-Post '/auth/change-password' (@{ password_actual = $temp; password_nuevo = 'V1131Pass123' } | ConvertTo-Json) $null $tTemp
    $ln = LoginFull $email 'V1131Pass123'
    if (-not $ln) { return $null }
    return @{ Id = $uid; Email = $email; Login = $ln; Token = $ln.data.tokens.access_token }
}

# ============================================================ 0) ADMIN
$adm = LoginFull 'aleon@acme.local' 'Admin123!'
if (-not $adm) { Bad 'LOGIN admin' 'sin token'; exit 1 }
$ta = $adm.data.tokens.access_token
Ok 'LOGIN admin' "token adminId=$($adm.data.user.id)"

$fakeId = '00000000-0000-4000-8000-000000000000'

# ================================================== 1) PATCH /users: escalada
$r = Invoke-Post '/roles' (@{ nombre = "V1131 EDIT $($script:runId)"; descripcion = 'e2e v1.13.1'; permisos = @('users.user.view', 'users.user.edit') } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $editRoleId = ($r.Content | ConvertFrom-Json).data.role.id; Ok 'ALTA rol editor' "id=$editRoleId" }
else { Bad 'ALTA rol editor' "status=$($r.Status) $($r.Content)"; exit 1 }

$ed = New-ActivatedUser $ta 'ed'
if (-not $ed) { Bad 'ALTA editor' 'no se pudo crear/activar'; exit 1 }
$r = Invoke-Post "/users/$($ed.Id)/roles" (@{ role_id = $editRoleId } | ConvertTo-Json) $null $ta
if ($r.Status -ne 200) { Bad 'ASIGNAR rol editor' "status=$($r.Status) $($r.Content)"; exit 1 }
Ok 'ASIGNAR rol editor' '200'
$te = $ed.Token

$tg = New-ActivatedUser $ta 'tgt'
if (-not $tg) { Bad 'ALTA target' 'no se pudo crear/activar'; exit 1 }

# Control positivo: editor (view+edit) edita a un usuario sin roles
$r = Invoke-Patch "/users/$($tg.Id)" (@{ telefono = '600000001' } | ConvertTo-Json) $te
if ($r.Status -eq 200) { Ok 'PATCH usuario sin roles (control)' '200' }
else { Bad 'PATCH usuario sin roles (control)' "status=$($r.Status) $($r.Content)" }

# Autocambio: editar SI MISMO no lo limita assertCanActOnUser
$r = Invoke-Patch "/users/$($ed.Id)" (@{ telefono = '600000002' } | ConvertTo-Json) $te
if ($r.Status -eq 200) { Ok 'PATCH autocambio del editor' '200' }
else { Bad 'PATCH autocambio del editor' "status=$($r.Status) $($r.Content)" }

# Objetivo ADMIN: editor no tiene todos los permisos del afectado -> 403
$ad2 = New-ActivatedUser $ta 'ad2'
if (-not $ad2) { Bad 'ALTA admin2' 'no se pudo crear/activar'; exit 1 }
$rs = Invoke-Get '/roles' $ta
$adminRoleId = $null
foreach ($x in ($rs.Content | ConvertFrom-Json).data.roles) { if ($x.nombre -eq 'ADMIN') { $adminRoleId = $x.id } }
if (-not $adminRoleId) { Bad 'ROL ADMIN' 'no encontrado'; exit 1 }
$r = Invoke-Post "/users/$($ad2.Id)/roles" (@{ role_id = $adminRoleId } | ConvertTo-Json) $null $ta
if ($r.Status -ne 200) { Bad 'ASIGNAR ADMIN a admin2' "status=$($r.Status) $($r.Content)"; exit 1 }
Ok 'ASIGNAR ADMIN a admin2' '200'

$r = Invoke-Patch "/users/$($ad2.Id)" (@{ telefono = '600000003' } | ConvertTo-Json) $te
if ($r.Status -eq 403) { Ok 'PATCH usuario ADMIN por no-admin' '403 (escalada)' }
else { Bad 'PATCH usuario ADMIN por no-admin' "status=$($r.Status) $($r.Content)" }

# Control: admin (con todos los permisos) edita al editor -> 200
$r = Invoke-Patch "/users/$($ed.Id)" (@{ telefono = '600000004' } | ConvertTo-Json) $ta
if ($r.Status -eq 200) { Ok 'PATCH admin sobre editor (control)' '200' }
else { Bad 'PATCH admin sobre editor (control)' "status=$($r.Status) $($r.Content)" }

# user.update audita valores previos
$aud = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'user.update' AND entidad_id = '$($ed.Id)' AND valores_anteriores IS NOT NULL"
if ([int]$aud -ge 1) { Ok 'AUDIT user.update con valores_anteriores' "$aud fila(s)" }
else { Bad 'AUDIT user.update con valores_anteriores' "count=$aud" }

# 404 sigue funcionando tras el nuevo findById previo
$r = Invoke-Patch "/users/$fakeId" (@{ nombre = 'Fantasma' } | ConvertTo-Json) $ta
if ($r.Status -eq 404) { Ok 'PATCH usuario inexistente' '404' }
else { Bad 'PATCH usuario inexistente' "status=$($r.Status) $($r.Content)" }

# ================================================ 2) AUDIT user.delete
$ud1 = New-ActivatedUser $ta 'del'
if (-not $ud1) { Bad 'ALTA ud1' 'no se pudo crear/activar'; exit 1 }
$r = Invoke-Delete "/users/$($ud1.Id)" $ta
if ($r.Status -eq 204) { Ok 'DELETE /users/{id}' '204' }
else { Bad 'DELETE /users/{id}' "status=$($r.Status) $($r.Content)" }

$aud = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'user.delete' AND entidad_id = '$($ud1.Id)' AND valores_anteriores IS NOT NULL"
if ([int]$aud -ge 1) { Ok 'AUDIT user.delete con valores_anteriores' "$aud fila(s)" }
else { Bad 'AUDIT user.delete con valores_anteriores' "count=$aud" }

# ================================================ 3) AUDIT role.delete
$r = Invoke-Post '/roles' (@{ nombre = "V1131 DEL $($script:runId)"; descripcion = 'e2e v1.13.1'; permisos = @('users.user.view') } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $ridD = ($r.Content | ConvertFrom-Json).data.role.id; Ok 'ALTA rol a borrar' "id=$ridD" }
else { Bad 'ALTA rol a borrar' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Delete "/roles/$ridD" $ta
if ($r.Status -eq 200) { Ok 'DELETE /roles/{id}' '200' }
else { Bad 'DELETE /roles/{id}' "status=$($r.Status) $($r.Content)" }

$aud = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'role.delete' AND entidad_id = '$ridD' AND valores_anteriores LIKE '%users.user.view%'"
if ([int]$aud -ge 1) { Ok 'AUDIT role.delete con permisos previos' "$aud fila(s)" }
else { Bad 'AUDIT role.delete con permisos previos' "count=$aud" }

# ==================== 4) INVALIDACION DE CACHE: portadores directos y de grupo
$r = Invoke-Post '/roles' (@{ nombre = "V1131 CACHE $($script:runId)"; descripcion = 'e2e v1.13.1'; permisos = @('users.user.view') } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $ridC = ($r.Content | ConvertFrom-Json).data.role.id; Ok 'ALTA rol cache' "id=$ridC" }
else { Bad 'ALTA rol cache' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Post '/groups' (@{ nombre = "V1131 G $($script:runId)"; descripcion = 'e2e v1.13.1' } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { $gidC = ($r.Content | ConvertFrom-Json).data.group.id; Ok 'ALTA grupo cache' "id=$gidC" }
else { Bad 'ALTA grupo cache' "status=$($r.Status) $($r.Content)"; exit 1 }

$ug = New-ActivatedUser $ta 'ug'
$ud = New-ActivatedUser $ta 'ud'
if (-not $ug -or -not $ud) { Bad 'ALTA ug/ud' 'no se pudieron crear/activar'; exit 1 }

$r = Invoke-Post "/groups/$gidC/members" (@{ user_id = $ug.Id } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { Ok 'MIEMBRO ug al grupo' '201' }
else { Bad 'MIEMBRO ug al grupo' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Post "/groups/$gidC/roles" (@{ role_id = $ridC } | ConvertTo-Json) $null $ta
if ($r.Status -eq 201) { Ok 'ROL cache asignado al grupo' '201' }
else { Bad 'ROL cache asignado al grupo' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Post "/users/$($ud.Id)/roles" (@{ role_id = $ridC } | ConvertTo-Json) $null $ta
if ($r.Status -eq 200) { Ok 'ROL cache asignado a ud (directo)' '200' }
else { Bad 'ROL cache asignado a ud (directo)' "status=$($r.Status) $($r.Content)"; exit 1 }

# Calentar las dos caches (grupo y directo)
$lgUg = LoginFull $ug.Email 'V1131Pass123'
$lgUd = LoginFull $ud.Email 'V1131Pass123'
if (-not $lgUg -or -not $lgUd) { Bad 'LOGIN ug/ud' 'sin token'; exit 1 }
$tUg = $lgUg.data.tokens.access_token
$tUd = $lgUd.data.tokens.access_token

$r = Invoke-Get '/users' $tUg
if ($r.Status -eq 200) { Ok 'GET /users ug (calienta cache por grupo)' '200' }
else { Bad 'GET /users ug (calienta cache por grupo)' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Get '/users' $tUd
if ($r.Status -eq 200) { Ok 'GET /users ud (calienta cache directa)' '200' }
else { Bad 'GET /users ud (calienta cache directa)' "status=$($r.Status) $($r.Content)" }

# Mutar los permisos del rol: invalidateCacheForRole debe limpiar AMBAS rutas
$r = Invoke-Patch "/roles/$ridC/permissions" (@{ permisos = @() } | ConvertTo-Json) $ta
if ($r.Status -eq 200) { Ok 'PATCH permisos del rol (quita users.user.view)' '200' }
else { Bad 'PATCH permisos del rol' "status=$($r.Status) $($r.Content)"; exit 1 }

# De inmediato (TTL de 60s todavia pendiente): ambos deben perder el acceso
$r = Invoke-Get '/users' $tUg
if ($r.Status -eq 403) { Ok 'ug tras mutar rol (portador por GRUPO)' '403 (cache invalidada)' }
else { Bad 'ug tras mutar rol (portador por GRUPO)' "status=$($r.Status) (la invalidacion por grupo no llego)" }

$r = Invoke-Get '/users' $tUd
if ($r.Status -eq 403) { Ok 'ud tras mutar rol (portador DIRECTO)' '403 (cache invalidada)' }
else { Bad 'ud tras mutar rol (portador DIRECTO)' "status=$($r.Status) (la invalidacion directa no llego)" }

$meUg = Invoke-Get '/auth/me' $tUg
$permUg = ($meUg.Content | ConvertFrom-Json).data.permisos
if (($permUg -is [array]) -and -not ($permUg -contains 'users.user.view')) {
    Ok 'ME ug tras mutar rol' 'sin users.user.view'
} else { Bad 'ME ug tras mutar rol' "permisos=$($permUg | ConvertTo-Json -Compress)" }

$meUd = Invoke-Get '/auth/me' $tUd
$permUd = ($meUd.Content | ConvertFrom-Json).data.permisos
if (($permUd -is [array]) -and -not ($permUd -contains 'users.user.view')) {
    Ok 'ME ud tras mutar rol' 'sin users.user.view'
} else { Bad 'ME ud tras mutar rol' "permisos=$($permUd | ConvertTo-Json -Compress)" }

# ================================================================ resumen
Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E v1.13.1: TODOS LOS CASOS OK' }
else { Write-Output "E2E v1.13.1: $script:failures FALLOS" }
Stop-E2EServer
exit $script:failures
