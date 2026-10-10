# tools\e2e\e2e-v1103.ps1 - E2E preferencias de idioma (sprint Correccion v1.10.3-A).
#
# Cubre contra la BD de E2E y el servidor PHP embebido:
#   1. GET/PATCH /api/users/me/preferences con la propia sesion (sin permiso).
#   2. PATCH {idioma} persiste y aparece en GET /auth/me y en un login nuevo.
#   3. Merge: PATCH {tema} no borra el idioma ya guardado.
#   4. Validacion 400 (idioma fuera de es|en|pt|fr, tema fuera de dark|light|system)
#      con field/category en la respuesta; claves desconocidas ignoradas.
#   5. PATCH vacio = no-op (200 sin cambios).
#   6. 401 sin token en GET y PATCH.
#   7. Preferencias independientes por usuario (aleon vs manager).
#   8. users.preferencias en la BD de E2E == respuesta de la API; la BD de
#      TRABAJO queda intacta (regla 10).
#   9. Auditoria: fila de dominio user.preferences.update en el PATCH valido y
#      fila FAILURE del middleware en el PATCH invalido (por X-Request-Id).
#
# Uso: powershell -ExecutionPolicy Bypass -File tools\e2e\e2e-v1103.ps1
$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')   # BD E2E + servidor PHP embebido (v1.8.3)
$script:failures = 0

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

function Parse-Json($content) {
    try { return (ConvertFrom-Json $content) } catch { return $null }
}

function Invoke-Api($method, $path, $json, $token) {
    $headers = @{}
    if ($token) { $headers['Authorization'] = "Bearer $token" }
    try {
        $p = @{ Uri = "$base$path"; Method = $method; UseBasicParsing = $true; TimeoutSec = 25 }
        if ($json -ne $null) {
            $p.Body = $json
            $p.ContentType = 'application/json'
        }
        if ($headers.Count -gt 0) { $p.Headers = $headers }
        $w = Invoke-WebRequest @p
        return @{
            Ok = $true; Status = [int]$w.StatusCode; Content = $w.Content
            ReqId = $w.Headers['X-Request-Id']
        }
    } catch {
        $msg = ''
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $msg = $_.ErrorDetails.Message }
        $reqId = $null
        if ($_.Exception.Response) {
            try { $reqId = $_.Exception.Response.Headers['X-Request-Id'] } catch { $reqId = $null }
        }
        return @{ Ok = $false; Status = (StatusCode $_); Content = $msg; ReqId = $reqId }
    }
}

function Scalar($sql) {
    $v = & mysql.exe -u root $e2eDb -N -e $sql | Select-Object -First 1
    if ($null -eq $v) { return $null }
    return ("$v".Trim())
}

# BD de TRABAJO (solo lectura): este E2E escribe users.preferencias y no debe
# tocar la BD de trabajo (regla 10).
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

$env:PATH = "C:\xampp\mysql\bin;$env:PATH"

# ================================================================ 0) huella
$prodAntes = ProdScalar ("SELECT IFNULL(MD5(GROUP_CONCAT(CONCAT(email, ':', IFNULL(preferencias, 'NULL')) ORDER BY email)), 'x') FROM users")
if ($script:prodDb) { Ok 'BD de trabajo identificada (solo lectura)' $script:prodDb }
else { Bad 'BD de trabajo identificada' 'sin DB_DATABASE en .env' }

# ================================================================ 1) actores
$tokenA = Login 'aleon@acme.local' 'Admin123!'
if (-not $tokenA) { Bad 'LOGIN aleon' 'sin token'; exit 1 }
Ok 'LOGIN aleon@acme.local' "ADMIN token len=$($tokenA.Length)"

$tokenB = Login 'manager@acme.local' 'Admin123!'
if (-not $tokenB) { Bad 'LOGIN manager' 'sin token'; exit 1 }
Ok 'LOGIN manager@acme.local' "token len=$($tokenB.Length)"

# ============================================= 2) estado inicial de preferencias
Write-Output ''
Write-Output '--- 1) estado inicial (v1.10.3) ---'
$me0 = Invoke-Api 'GET' '/auth/me' $null $tokenA
if ($me0.Ok) {
    $u0 = (Parse-Json $me0.Content).data.user
    if ($u0 -and ($u0.PSObject.Properties.Name -contains 'preferencias')) { Ok 'GET /auth/me trae user.preferencias' "valor=$(($u0.preferencias | ConvertTo-Json -Compress))" }
    else { Bad 'GET /auth/me trae user.preferencias' 'preferencias ausente' }
} else { Bad 'GET /auth/me' "$($me0.Status) $($me0.Content)" }

$g0 = Invoke-Api 'GET' '/users/me/preferences' $null $tokenA
if ($g0.Ok) {
    $p0 = (Parse-Json $g0.Content).data.preferencias
    if ($null -ne $p0) { Ok 'GET /users/me/preferences (200)' 'data.preferencias presente' }
    else { Bad 'GET /users/me/preferences (200)' 'data.preferencias ausente' }
} else { Bad 'GET /users/me/preferences' "$($g0.Status) $($g0.Content)" }

# ================================================== 3) PATCH {idioma} persiste
Write-Output ''
Write-Output '--- 2) PATCH {idioma} y reflejo en /auth/me ---'
$patch1 = Invoke-Api 'PATCH' '/users/me/preferences' (@{ idioma = 'en' } | ConvertTo-Json) $tokenA
if ($patch1.Ok -and $patch1.Status -eq 200) {
    $p1 = (Parse-Json $patch1.Content).data.preferencias
    Assert-Eq 'PATCH {idioma:en} (200)' $p1.idioma 'en'
    if ($patch1.ReqId) { Ok 'PATCH responde X-Request-Id' $patch1.ReqId }
    else { Bad 'PATCH responde X-Request-Id' 'cabecera ausente' }
} else { Bad 'PATCH {idioma:en}' "$($patch1.Status) $($patch1.Content)" }
$reqIdOk = $patch1.ReqId

$me1 = Invoke-Api 'GET' '/auth/me' $null $tokenA
if ($me1.Ok) {
    Assert-Eq 'GET /auth/me refleja idioma=en' (Parse-Json $me1.Content).data.user.preferencias.idioma 'en'
} else { Bad 'GET /auth/me tras PATCH' "$($me1.Status) $($me1.Content)" }

# ==================================================== 4) merge de preferencias
Write-Output ''
Write-Output '--- 3) merge: {tema} no borra {idioma} ---'
$patch2 = Invoke-Api 'PATCH' '/users/me/preferences' (@{ tema = 'dark' } | ConvertTo-Json) $tokenA
if ($patch2.Ok -and $patch2.Status -eq 200) {
    $p2 = (Parse-Json $patch2.Content).data.preferencias
    Assert-Eq 'merge conserva idioma=en' $p2.idioma 'en'
    Assert-Eq 'merge aplica tema=dark' $p2.tema 'dark'
} else { Bad 'PATCH {tema:dark}' "$($patch2.Status) $($patch2.Content)" }

# ================================================== 5) validacion 400 + 400 field
Write-Output ''
Write-Output '--- 4) validacion (400 con field/category) ---'
$badIdioma = Invoke-Api 'PATCH' '/users/me/preferences' (@{ idioma = 'de' } | ConvertTo-Json) $tokenA
if (-not $badIdioma.Ok -and $badIdioma.Status -eq 400) {
    $b1 = Parse-Json $badIdioma.Content
    Assert-Eq 'idioma invalido -> 400' $badIdioma.Status 400
    Assert-Eq 'respuesta field=idioma' $b1.data.field 'idioma'
    Assert-Eq 'respuesta category=validation' $b1.data.category 'validation'
} else { Bad 'idioma invalido -> 400' "status=$($badIdioma.Status) body=$($badIdioma.Content)" }
$reqIdFail = $badIdioma.ReqId

$badTema = Invoke-Api 'PATCH' '/users/me/preferences' (@{ tema = 'claro' } | ConvertTo-Json) $tokenA
if (-not $badTema.Ok -and $badTema.Status -eq 400) {
    $b2 = Parse-Json $badTema.Content
    Assert-Eq 'tema invalido -> 400' $badTema.Status 400
    Assert-Eq 'respuesta field=tema' $b2.data.field 'tema'
} else { Bad 'tema invalido -> 400' "status=$($badTema.Status) body=$($badTema.Content)" }

# Tras los 400 la preferencia guardada no cambia
$gAfterBad = Invoke-Api 'GET' '/users/me/preferences' $null $tokenA
$pAfterBad = (Parse-Json $gAfterBad.Content).data.preferencias
Assert-Eq 'los 400 no alteran la preferencia guardada' $pAfterBad.idioma 'en'

# ============================================= 6) claves desconocidas ignoradas
Write-Output ''
Write-Output '--- 5) claves desconocidas ignoradas + no-op ---'
$patch3 = Invoke-Api 'PATCH' '/users/me/preferences' (@{ idioma = 'es'; color = 'rojo' } | ConvertTo-Json) $tokenA
if ($patch3.Ok -and $patch3.Status -eq 200) {
    $p3 = (Parse-Json $patch3.Content).data.preferencias
    Assert-Eq 'clave conocida idioma=es aplica' $p3.idioma 'es'
    if ($p3.PSObject.Properties.Name -contains 'color') { Bad 'clave desconocida color ignorada' 'color persistida' }
    else { Ok 'clave desconocida color ignorada' 'sin clave color' }
} else { Bad 'PATCH {idioma,color}' "$($patch3.Status) $($patch3.Content)" }

$patchEmpty = Invoke-Api 'PATCH' '/users/me/preferences' '{}' $tokenA
if ($patchEmpty.Ok -and $patchEmpty.Status -eq 200) {
    $pE = (Parse-Json $patchEmpty.Content).data.preferencias
    Assert-Eq 'PATCH vacio -> 200 (no-op conserva idioma)' $pE.idioma 'es'
    Assert-Eq 'PATCH vacio conserva tema=dark' $pE.tema 'dark'
} else { Bad 'PATCH vacio -> 200' "$($patchEmpty.Status) $($patchEmpty.Content)" }

# ================================================================ 7) 401 sin token
Write-Output ''
Write-Output '--- 6) 401 sin token ---'
$getNoAuth = Invoke-Api 'GET' '/users/me/preferences' $null $null
Assert-Eq 'GET sin token -> 401' $getNoAuth.Status 401
$patchNoAuth = Invoke-Api 'PATCH' '/users/me/preferences' (@{ idioma = 'en' } | ConvertTo-Json) $null
Assert-Eq 'PATCH sin token -> 401' $patchNoAuth.Status 401

# =============================================== 8) preferencias por usuario
Write-Output ''
Write-Output '--- 7) preferencias independientes por usuario ---'
$patchB = Invoke-Api 'PATCH' '/users/me/preferences' (@{ idioma = 'pt' } | ConvertTo-Json) $tokenB
if ($patchB.Ok) {
    Assert-Eq 'manager PATCH idioma=pt (200)' (Parse-Json $patchB.Content).data.preferencias.idioma 'pt'
} else { Bad 'manager PATCH' "$($patchB.Status) $($patchB.Content)" }

$getA = Invoke-Api 'GET' '/users/me/preferences' $null $tokenA
Assert-Eq 'aleon sigue en es (independencia)' (Parse-Json $getA.Content).data.preferencias.idioma 'es'
$getB = Invoke-Api 'GET' '/users/me/preferences' $null $tokenB
Assert-Eq 'manager sigue en pt' (Parse-Json $getB.Content).data.preferencias.idioma 'pt'

# ==================================================== 9) persistencia en la BD
Write-Output ''
Write-Output '--- 8) users.preferencias en la BD de E2E ---'
$dbA = Scalar "SELECT preferencias FROM users WHERE email='aleon@acme.local'"
$dbAJ = Parse-Json $dbA
Assert-Eq 'BD aleon: idioma=es' $dbAJ.idioma 'es'
Assert-Eq 'BD aleon: tema=dark' $dbAJ.tema 'dark'
if ($dbA -match 'color') { Bad 'BD aleon: sin claves desconocidas' "preferencias=$dbA" }
else { Ok 'BD aleon: sin claves desconocidas' "preferencias=$dbA" }

$dbB = Scalar "SELECT preferencias FROM users WHERE email='manager@acme.local'"
$dbBJ = Parse-Json $dbB
Assert-Eq 'BD manager: idioma=pt' $dbBJ.idioma 'pt'

# ======================================================= 10) auditoria por request
Write-Output ''
Write-Output '--- 9) auditoria (X-Request-Id) ---'
if ($reqIdOk) {
    $audOk = Invoke-Api 'GET' "/audit/logs?request_id=$reqIdOk" $null $tokenA
    $rowsOk = @((Parse-Json $audOk.Content).data.data)
    $dominio = $rowsOk | Where-Object { $_.operacion -eq 'user.preferences.update' -and $_.resultado -eq 'SUCCESS' }
    if ($dominio) { Ok 'auditoria de dominio user.preferences.update' "filas=$(@($rowsOk).Count)" }
    else { Bad 'auditoria de dominio user.preferences.update' "filas=$(@($rowsOk).Count) operaciones=$(($rowsOk | ForEach-Object { $_.operacion }) -join ',')" }
} else { Bad 'auditoria de dominio' 'sin X-Request-Id en el PATCH valido' }

if ($reqIdFail) {
    $audFail = Invoke-Api 'GET' "/audit/logs?request_id=$reqIdFail" $null $tokenA
    $rowsFail = @((Parse-Json $audFail.Content).data.data)
    $fallida = $rowsFail | Where-Object { $_.resultado -eq 'FAILURE' -and $_.operacion -eq 'PATCH /api/users/me/preferences' }
    if ($fallida) { Ok 'auditoria FAILURE del middleware en el 400' "razon=$($fallida[0].razon_fallo)" }
    else { Bad 'auditoria FAILURE del middleware en el 400' "filas=$(@($rowsFail).Count) filas_json=$(($rowsFail | ForEach-Object { $_.operacion + '/' + $_.resultado }) -join ',')" }
} else { Bad 'auditoria FAILURE' 'sin X-Request-Id en el PATCH invalido' }

# ======================================================= 11) login con preferencias
Write-Output ''
Write-Output '--- 10) el login nuevo devuelve las preferencias ---'
$loginA = $null
try {
    $loginA = Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType 'application/json' `
        -Body (@{ email = 'aleon@acme.local'; password = 'Admin123!' } | ConvertTo-Json) -TimeoutSec 15
    Assert-Eq 'login devuelve user.preferencias.idioma=es' $loginA.data.user.preferencias.idioma 'es'
    Assert-Eq 'login devuelve user.preferencias.tema=dark' $loginA.data.user.preferencias.tema 'dark'
} catch {
    Bad 'login con preferencias' "$(StatusCode $_) $($_.Exception.Message)"
}

# ================================================== 12) la BD de trabajo intacta
Write-Output ''
Write-Output '--- 11) la BD de trabajo no cambia (regla 10) ---'
$prodDespues = ProdScalar ("SELECT IFNULL(MD5(GROUP_CONCAT(CONCAT(email, ':', IFNULL(preferencias, 'NULL')) ORDER BY email)), 'x') FROM users")
if ($script:prodDb) {
    if ($prodAntes -eq $prodDespues -and $null -ne $prodAntes) {
        Ok 'BD de trabajo sin escrituras del E2E' "huella=$prodAntes"
    } else {
        Bad 'BD de trabajo sin escrituras del E2E' "antes=$prodAntes despues=$prodDespues"
    }
}

Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E v1.10.3: TODO OK' } else { Write-Output "E2E v1.10.3: $script:failures FALLOS" }

Stop-E2EServer
exit $script:failures
