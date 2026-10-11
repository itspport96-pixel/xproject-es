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

# ============================================================ 0) ADMIN
$adm = LoginFull 'aleon@acme.local' 'Admin123!'
if (-not $adm) { Bad 'LOGIN admin' 'sin token'; exit 1 }
$ta = $adm.data.tokens.access_token
Ok 'LOGIN admin' "token userId=$($adm.data.user.id)"

# ================================================ 1) PRODUCTO: borrar + recrear SKU
$sku = "V1139-$($script:runId)"
$r = Invoke-Post '/inventory/products' (@{ sku = $sku; nombre = 'Producto politica borrado'; unidad_medida = 'UNIDAD'; categoria = 'Fijaciones' } | ConvertTo-Json) "v1139-p-$($script:runId)" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$pid1 = if ($c.data.product.id) { $c.data.product.id } else { $null }
if ($r.Status -eq 201 -and $pid1) { Ok 'PROD alta' "201 id=$pid1" }
else { Bad 'PROD alta' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Delete "/inventory/products/$pid1" $ta
if ($r.Status -eq 200) { Ok 'PROD baja' '200' }
else { Bad 'PROD baja' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/inventory/products' (@{ sku = $sku; nombre = 'Producto recreado'; unidad_medida = 'UNIDAD'; categoria = 'Fijaciones' } | ConvertTo-Json) "v1139-p2-$($script:runId)" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$pid2 = if ($c.data.product.id) { $c.data.product.id } else { $null }
if ($r.Status -eq 201 -and $pid2 -and $pid2 -ne $pid1) { Ok 'PROD recrear mismo SKU' "201 id-nuevo=$pid2" }
else { Bad 'PROD recrear mismo SKU' "status=$($r.Status) $($r.Content)" }

$viejo = Scalar "SELECT COUNT(*) FROM products WHERE id = '$pid1' AND deleted_at IS NOT NULL"
$nuevo = Scalar "SELECT COUNT(*) FROM products WHERE id = '$pid2' AND deleted_at IS NULL"
if ("$viejo" -eq '1' -and "$nuevo" -eq '1') { Ok 'PROD borrado persiste + nuevo activo' 'filas=1/1' }
else { Bad 'PROD borrado persiste + nuevo activo' "viejo=$viejo nuevo=$nuevo" }

$r = Invoke-Post '/inventory/products' (@{ sku = $sku; nombre = 'Duplicado activo'; unidad_medida = 'UNIDAD'; categoria = 'Fijaciones' } | ConvertTo-Json) $null $ta
if ($r.Status -eq 409) { Ok 'PROD duplicado activo' '409' }
else { Bad 'PROD duplicado activo' "status=$($r.Status) $($r.Content)" }

# SKU activo de otro tenant no choca (unicidad por tenant).
$otroTenant = Scalar "SELECT id FROM tenants WHERE id <> (SELECT tenant_id FROM products WHERE id = '$pid2') LIMIT 1"
if (-not $otroTenant) {
    $otroTenant = 'tenant-v1139'
    & mysql.exe -u root $e2eDb -e "INSERT IGNORE INTO tenants (id, nombre_publico, nombre_interno, estado, plan, created_at, updated_at) VALUES ('$otroTenant', 'Tenant V1139', 'tenant-v1139-$($script:runId)', 'ACTIVO', 'FREE', NOW(), NOW())"
}
$idOtro = "p-otro-$($script:runId)"
& mysql.exe -u root $e2eDb -e "INSERT INTO products (id, tenant_id, sku, nombre, unidad_medida, activo, created_at, updated_at) VALUES ('$idOtro', '$otroTenant', '$sku', 'Otro tenant', 'UNIDAD', 1, NOW(), NOW())"
$otros = Scalar "SELECT COUNT(*) FROM products WHERE tenant_id = '$otroTenant' AND sku = '$sku'"
if ("$otros" -eq '1') { Ok 'PROD mismo SKU en otro tenant' "permitido (tenant=$otroTenant)" }
else { Bad 'PROD mismo SKU en otro tenant' "filas=$otros" }

# Audit del borrado: valores_anteriores + detalles_adicionales.valores_despues.
$aud = Scalar "SELECT COUNT(*) FROM audit_logs WHERE operacion = 'product.delete' AND entidad_id = '$pid1' AND detalles_adicionales LIKE '%valores_despues%'"
if ([int]$aud -ge 1) { Ok 'AUDIT product.delete valores_despues' "$aud fila(s)" }
else { Bad 'AUDIT product.delete valores_despues' "count=$aud" }

# ============================================ 2) PRODUCTO con stock: baja bloqueada
# Se recibe en E2E-ALMACEN para que la ubicacion quede con movimientos
# (tambien alimenta el bloqueo de la seccion 4).
$r = Invoke-Post '/inventory/receive' (@{ sku = 'PROD-001'; cantidad_esperada = 1; ubicacion_codigo = 'E2E-ALMACEN' } | ConvertTo-Json) "v1139-recv-$($script:runId)" $ta
if ($r.Status -eq 201) { Ok 'RECV fixture PROD-001' 'en E2E-ALMACEN' }
else { Bad 'RECV fixture PROD-001' "status=$($r.Status) $($r.Content)" }

$prodConStock = Scalar "SELECT id FROM products WHERE sku = 'PROD-001' AND deleted_at IS NULL LIMIT 1"
$r = Invoke-Delete "/inventory/products/$prodConStock" $ta
if ($r.Status -eq 409) { Ok 'PROD con stock no se puede borrar' '409' }
else { Bad 'PROD con stock no se puede borrar' "status=$($r.Status) $($r.Content)" }

# ============================================ 3) UBICACION: borrar + recrear codigo
$cod = "V1139-$($script:runId)"
$r = Invoke-Post '/inventory/locations' (@{ codigo = $cod; nombre = 'Ubicacion politica'; tipo = 'ALMACENAMIENTO' } | ConvertTo-Json) "v1139-l-$($script:runId)" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$lid1 = if ($c.data.location.id) { $c.data.location.id } else { $null }
if ($r.Status -eq 201 -and $lid1) { Ok 'LOC alta' "201 id=$lid1" }
else { Bad 'LOC alta' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Delete "/inventory/locations/$lid1" $ta
if ($r.Status -eq 200) { Ok 'LOC baja' '200' }
else { Bad 'LOC baja' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/inventory/locations' (@{ codigo = $cod; nombre = 'Ubicacion recreada'; tipo = 'ALMACENAMIENTO' } | ConvertTo-Json) "v1139-l2-$($script:runId)" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$lid2 = if ($c.data.location.id) { $c.data.location.id } else { $null }
if ($r.Status -eq 201 -and $lid2 -and $lid2 -ne $lid1) { Ok 'LOC recrear mismo codigo' "201 id-nuevo=$lid2" }
else { Bad 'LOC recrear mismo codigo' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/inventory/locations' (@{ codigo = $cod; nombre = 'Duplicada activa'; tipo = 'ALMACENAMIENTO' } | ConvertTo-Json) $null $ta
if ($r.Status -eq 409) { Ok 'LOC duplicado activo' '409' }
else { Bad 'LOC duplicado activo' "status=$($r.Status) $($r.Content)" }

# ============================================ 4) UBICACION con movimientos: bloqueada
$e2eAlmacenId = Scalar "SELECT id FROM locations WHERE codigo = 'E2E-ALMACEN' AND deleted_at IS NULL LIMIT 1"
$r = Invoke-Delete "/inventory/locations/$e2eAlmacenId" $ta
if ($r.Status -eq 409) { Ok 'LOC con movimientos no se puede borrar' '409' }
else { Bad 'LOC con movimientos no se puede borrar' "status=$($r.Status) $($r.Content)" }

# ============================================ 5) ROL BUILT_IN: baja bloqueada
$r = Invoke-Get '/roles' $ta
$rs = ($r.Content | ConvertFrom-Json).data.roles
$adminRole = @($rs | Where-Object { $_.nombre -eq 'ADMIN' } | Select-Object -First 1)
$r = Invoke-Delete "/roles/$($adminRole.id)" $ta
if ($r.Status -eq 403) { Ok 'ROL BUILT_IN no se puede borrar' '403' }
else { Bad 'ROL BUILT_IN no se puede borrar' "status=$($r.Status) $($r.Content)" }

# ============================================ 6) ULTIMO ADMIN: baja bloqueada
$adminId = $adm.data.user.id
$r = Invoke-Delete "/users/$adminId" $ta
if ($r.Status -eq 403) { Ok 'USER ultimo admin no se puede borrar' '403' }
else { Bad 'USER ultimo admin no se puede borrar' "status=$($r.Status) $($r.Content)" }

# ============================================ 7) GRUPO: borrar + recrear nombre
$gName = "V1139 G $($script:runId)"
$r = Invoke-Post '/groups' (@{ nombre = $gName; descripcion = 'politica borrado' } | ConvertTo-Json) "v1139-g-$($script:runId)" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$gid1 = if ($c.data.group.id) { $c.data.group.id } else { $null }
if ($r.Status -eq 201 -and $gid1) { Ok 'GRUPO alta' "201 id=$gid1" }
else { Bad 'GRUPO alta' "status=$($r.Status) $($r.Content)"; exit 1 }

$r = Invoke-Delete "/groups/$gid1" $ta
if ($r.Status -eq 200) { Ok 'GRUPO baja' '200' }
else { Bad 'GRUPO baja' "status=$($r.Status) $($r.Content)" }

$r = Invoke-Post '/groups' (@{ nombre = $gName; descripcion = 'recreado' } | ConvertTo-Json) "v1139-g2-$($script:runId)" $ta
$c = $null; try { $c = $r.Content | ConvertFrom-Json } catch {}
$gid2 = if ($c.data.group.id) { $c.data.group.id } else { $null }
if ($r.Status -eq 201 -and $gid2 -and $gid2 -ne $gid1) { Ok 'GRUPO recrear mismo nombre' "201 id-nuevo=$gid2" }
else { Bad 'GRUPO recrear mismo nombre' "status=$($r.Status) $($r.Content)" }

# ================================================================ resumen
Write-Output ''
if ($script:failures -eq 0) { Write-Output 'E2E politica borrado v1.13.9: TODOS LOS CASOS OK' }
else { Write-Output "E2E politica borrado v1.13.9: $script:failures FALLOS" }
Stop-E2EServer
exit $script:failures
