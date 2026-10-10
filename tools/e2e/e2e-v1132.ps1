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

function Login-Status($email, $password) {
    try {
        $w = Invoke-WebRequest -Uri "$base/auth/login" -Method Post -UseBasicParsing `
            -ContentType 'application/json' -Body (@{ email = $email; password = $password } | ConvertTo-Json) -TimeoutSec 15
        return [int]$w.StatusCode
    } catch { return (StatusCode $_) }
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

function Scalar($sql) {
    $v = & mysql.exe -u root $e2eDb -N -e $sql | Select-Object -First 1
    if ($null -eq $v) { return $null }
    return ("$v".Trim())
}

function Rows($sql) {
    return @(& mysql.exe -u root $e2eDb -N -e $sql)
}

# Crea usuario, completa el primer login (cambio forzado) y devuelve token final.
function New-ActivatedUser($token, $tag) {
    $email = "v1132-$tag-$($script:runId)@acme.local"
    $r = Invoke-Post '/users' (@{ email = $email; nombre = 'V1132'; apellido = $tag } | ConvertTo-Json) "v1132-$tag-$($script:runId)" $token
    if ($r.Status -ne 201) { return $null }
    $c = $r.Content | ConvertFrom-Json
    $uid = $c.data.user.id
    $temp = $c.data.user.password_temporal

    $lt = LoginFull $email $temp
    if (-not $lt) { return $null }
    $tTemp = $lt.data.tokens.access_token
    $null = Invoke-Post '/auth/change-password' (@{ password_actual = $temp; password_nuevo = 'V1132Pass123' } | ConvertTo-Json) $null $tTemp
    $ln = LoginFull $email 'V1132Pass123'
    if (-not $ln) { return $null }
    return @{ Id = $uid; Email = $email; Login = $ln; Token = $ln.data.tokens.access_token }
}

# ============================================================ 0) ADMIN
$adm = LoginFull 'aleon@acme.local' 'Admin123!'
if (-not $adm) { Bad 'LOGIN admin' 'sin token'; exit 1 }
$ta = $adm.data.tokens.access_token
$adminId = $adm.data.user.id
Ok 'LOGIN admin' "token adminId=$adminId"

# ================================== 1) GUARDIA de ultimo ADMIN activo en PATCH
$tenantId = Scalar "SELECT tenant_id FROM users WHERE email = 'aleon@acme.local' LIMIT 1"
if (-not $tenantId) { Bad 'TENANT de aleon' 'no encontrado'; exit 1 }

# Dejar a aleon como UNICO admin activo (los extras suelen ser restos de
# ejecuciones anteriores de e2e-v1131; se desactivan, nunca a aleon).
$adminSql = "SELECT DISTINCT ra.user_id
             FROM role_assignments ra
             INNER JOIN roles r ON r.id = ra.role_id
             INNER JOIN users u ON u.id = ra.user_id
             WHERE ra.tenant_id = '$tenantId'
               AND r.nombre = 'ADMIN'
               AND r.activo = 1
               AND r.deleted_at IS NULL
               AND u.estado = 'ACTIVO'
               AND u.deleted_at IS NULL
               AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())"
$extras = @(Rows $adminSql | Where-Object { $_ -ne '' -and $_ -ne $adminId })
foreach ($x in $extras) {
    $r = Invoke-Patch "/users/$x" (@{ estado = 'INACTIVO' } | ConvertTo-Json) $ta
    if ($r.Status -ne 200) { Bad 'DESACTIVAR admin extra' "id=$x status=$($r.Status) $($r.Content)" }
}
if ($script:failures -eq 0) { Ok 'ADMIN extras desactivados' "n=$($extras.Count)" }

$nActivos = Scalar ("SELECT COUNT(DISTINCT ra.user_id) FROM role_assignments ra INNER JOIN roles r ON r.id = ra.role_id INNER JOIN users u ON u.id = ra.user_id WHERE ra.tenant_id = '$tenantId' AND r.nombre = 'ADMIN' AND r.activo = 1 AND r.deleted_at IS NULL AND u.estado = 'ACTIVO' AND u.deleted_at IS NULL AND (ra.fecha_expiracion IS NULL OR ra.fecha_expiracion > NOW())")
if ([int]$nActivos -eq 1) { Ok 'PRECONDICION ultimo-admin' 'queda 1 admin activo' }
else { Bad 'PRECONDICION ultimo-admin' "activos=$nActivos (esperaba 1)" }

# El unico admin no puede bajarse a si mismo via PATCH
$r = Invoke-Patch "/users/$adminId" (@{ estado = 'INACTIVO' } | ConvertTo-Json) $ta
if ($r.Status -eq 403) { Ok 'PATCH INACTIVO del ultimo admin' '403 (guardia)' }
else { Bad 'PATCH INACTIVO del ultimo admin' "status=$($r.Status) $($r.Content)" }

$estadoAleon = Scalar "SELECT estado FROM users WHERE id = '$adminId'"
if ($estadoAleon -eq 'ACTIVO') { Ok 'aleon sigue ACTIVO tras el 403' $estadoAleon }
else { Bad 'aleon sigue ACTIVO tras el 403' "estado=$estadoAleon" }

# Control: fijar ACTIVO (sin baja) si queda permitido
$r = Invoke-Patch "/users/$adminId" (@{ estado = 'ACTIVO' } | ConvertTo-Json) $ta
if ($r.Status -eq 200) { Ok 'PATCH ACTIVO del ultimo admin (control)' '200' }
else { Bad 'PATCH ACTIVO del ultimo admin (control)' "status=$($r.Status) $($r.Content)" }

# Control: un usuario sin rol ADMIN si puede bajarse
$un = New-ActivatedUser $ta 'un'
if (-not $un) { Bad 'ALTA un' 'no se pudo crear/activar'; exit 1 }
$r = Invoke-Patch "/users/$($un.Id)" (@{ estado = 'INACTIVO' } | ConvertTo-Json) $ta
if ($r.Status -eq 200) { Ok 'PATCH INACTIVO de usuario normal (control)' '200' }
else { Bad 'PATCH INACTIVO de usuario normal (control)' "status=$($r.Status) $($r.Content)" }
$r = Invoke-Patch "/users/$($un.Id)" (@{ estado = 'ACTIVO' } | ConvertTo-Json) $ta
if ($r.Status -eq 200) { Ok 'PATCH ACTIVO de usuario normal (restauracion)' '200' }
else { Bad 'PATCH ACTIVO de usuario normal (restauracion)' "status=$($r.Status) $($r.Content)" }

# Restaurar los admin extras desactivados al principio: manager@acme.local
# tiene rol ADMIN y otros scripts del lote inician sesion con el; dejarlo
# INACTIVO romperia v1103 y demas.
foreach ($x in $extras) {
    $r = Invoke-Patch "/users/$x" (@{ estado = 'ACTIVO' } | ConvertTo-Json) $ta
    if ($r.Status -ne 200) { Bad 'RESTAURAR admin extra' "id=$x status=$($r.Status) $($r.Content)" }
}
if ($extras.Count -gt 0 -and $script:failures -eq 0) { Ok 'ADMIN extras restaurados' "n=$($extras.Count)" }

# ========================= 2) RATE LIMIT de change-password por USUARIO (10/5min)
$cp = New-ActivatedUser $ta 'cp'
if (-not $cp) { Bad 'ALTA cp' 'no se pudo crear/activar'; exit 1 }
# El alta ya hizo 1 change-password (intento 1 de 10).

$at429 = 0
for ($i = 1; $i -le 12; $i++) {
    $r = Invoke-Post '/auth/change-password' (@{ password_actual = 'Erronea123'; password_nuevo = 'NuevaCp1234' } | ConvertTo-Json) $null $cp.Token
    if ($r.Status -eq 429) { $at429 = $i; break }
}
# Intento 1 (alta) + 10 incorrectos = 11 > 10 => 429 en el incorrecto 10.
if ($at429 -eq 10) { Ok 'change-password 429 por usuario' 'en el intento 10 (11a peticion)' }
else { Bad 'change-password 429 por usuario' "429 en el intento incorrecto $at429 (esperaba 10)" }

# ================================ 3) RATE LIMIT de login por CUENTA (3 fallos/5min)
# Debe ir al final: con exactamente 4 fallos deja el contador IP del middleware
# en 4 (max 5) -> el control de otra cuenta sigue pasando (y su exito limpia
# IP). El 5to fallo va DESPUES del control para probar que el bloqueo por
# cuenta persiste aunque el IP se haya limpiado.
$lk = New-ActivatedUser $ta 'lk'
if (-not $lk) { Bad 'ALTA lk' 'no se pudo crear/activar'; exit 1 }

$seq = @()
for ($i = 1; $i -le 4; $i++) { $seq += (Login-Status $lk.Email 'Erronea1234') }

$previos = @($seq | Select-Object -First 3)
if (($previos -join ',') -eq '401,401,401') { Ok 'login con password incorrecto' '401 x3' }
else { Bad 'login con password incorrecto' "seq=$($seq -join ',')" }

if ($seq[3] -eq 429) { Ok 'login 429 por CUENTA (4to fallo)' '429' }
else { Bad 'login 429 por CUENTA (4to fallo)' "seq=$($seq -join ',')" }

# Control: otra cuenta distinta sigue pudiendo entrar (el bloqueo es por cuenta;
# su exito limpia ademas las claves IP de login)
try {
    $w = Invoke-WebRequest -Uri "$base/auth/login" -Method Post -UseBasicParsing `
        -ContentType 'application/json' -Body (@{ email = 'aleon@acme.local'; password = 'Admin123!' } | ConvertTo-Json) -TimeoutSec 15
    if ([int]$w.StatusCode -eq 200) { Ok 'login de otra cuenta tras el bloqueo' '200 (clave de cuenta y IP limpias)' }
    else { Bad 'login de otra cuenta tras el bloqueo' "status=$($w.StatusCode)" }
} catch {
    $m = ''
    if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $m = $_.ErrorDetails.Message }
    Bad 'login de otra cuenta tras el bloqueo' "status=$(StatusCode $_) body=$m"
    # Diagnostico: claves de rate limit vivas (prefijo rate_limit:)
    $rk = @(& 'C:\Program Files\Redis\redis-cli.exe' KEYS 'rate_limit:*login*')
    foreach ($k in $rk) {
        $v = & 'C:\Program Files\Redis\redis-cli.exe' GET $k
        Show "  key $k" "$v"
    }
}

# Persistencia: el bloqueo por cuenta sigue activo aunque el IP ya este limpio
$fifth = Login-Status $lk.Email 'Erronea1234'
if ($fifth -eq 429) { Ok 'bloqueo por CUENTA persiste tras login exitoso de otra cuenta' '429' }
else { Bad 'bloqueo por CUENTA persiste tras login exitoso de otra cuenta' "status=$fifth" }

# ================================================================ resumen
Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E v1.13.2: TODOS LOS CASOS OK' }
else { Write-Output "E2E v1.13.2: $script:failures FALLOS" }
Stop-E2EServer
exit $script:failures
