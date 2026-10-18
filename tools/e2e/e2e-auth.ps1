# tools\e2e\e2e-auth.ps1 - suite E2E del dominio auth (v1.18.x: eficiencia B2).
#
# Corre el lote del dominio con UN solo preparado y UN solo servidor:
#   powershell -File tools\e2e\e2e-auth.ps1 [-SkipPrepare] [-KeepServer]
#
#   -SkipPrepare  fija E2E_SKIP_PREPARE=1 (la BD de E2E ya esta preparada);
#                 sin el flag el runner prepara una vez (sin integridad).
#   -KeepServer   deja 8099 vivo al terminar (para encadenar suites a mano);
#                 por defecto el servidor se para al final del lote.
#
# Miembros: sesion base, reset/bienvenida por enlace y rate limits de
# credenciales (incluye e2e-v1132: rate limit de change-password/login y
# guardia de ultimo admin en PATCH /users). La BD se prepara en el runner;
# los miembros la saltan (E2E_SKIP_PREPARE=1) y no paran el servidor
# (E2E_KEEP_SERVER=1). Exit 0 solo si todos los miembros salen con 0.
#
# El lote completo (4 suites) solo al cierre del sprint (AGENTS.md regla 7);
# durante un bloque se lanza SOLO la suite afectada.

param([switch]$SkipPrepare, [switch]$KeepServer)

$ErrorActionPreference = 'Continue'
$suiteDir = $PSScriptRoot
$suiteName = 'e2e-auth'
$miembros = @(
    'e2e-v1100.ps1',
    'e2e-v1110.ps1',
    'e2e-v1132.ps1'
)

$hostExe = (Get-Process -Id $PID).Path
$logDir = Join-Path $suiteDir '.suite-logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null

# 1) Preparado y servidor UNA vez para todo el lote (reutiliza si ya viven).
if ($SkipPrepare) { $env:E2E_SKIP_PREPARE = '1' }
. (Join-Path $suiteDir '_env.ps1')

# 2) Los miembros no re-preparan y no paran el servidor del lote.
$env:E2E_SKIP_PREPARE = '1'
$env:E2E_KEEP_SERVER = '1'

Write-Output "=== suite ${suiteName}: $($miembros.Count) script(s) ==="
$fallas = @()

foreach ($m in $miembros) {
    $ruta = Join-Path $suiteDir $m
    if (-not (Test-Path -LiteralPath $ruta)) {
        $fallas += $m
        Write-Output "[suite $suiteName] $m -> FALLO (no existe el script)"
        continue
    }

    $log = Join-Path $logDir ($suiteName + '-' + $m + '.log')
    & $hostExe -NoProfile -ExecutionPolicy Bypass -File $ruta *> $log
    $code = $LASTEXITCODE
    if ($null -eq $code) { $code = 1 }

    Get-Content -LiteralPath $log -ErrorAction SilentlyContinue
    if ($code -eq 0) {
        Write-Output "[suite $suiteName] $m -> OK (exit 0)"
    } else {
        $fallas += $m
        Write-Output "[suite $suiteName] $m -> FALLO (exit $code)"
    }
}

# 3) Cierre: por defecto se para el servidor; -KeepServer lo conserva.
$env:E2E_SKIP_PREPARE = ''
$env:E2E_KEEP_SERVER = ''
if ($KeepServer) {
    Write-Output "[suite $suiteName] servidor mantenido en 8099 (-KeepServer); parada: powershell tools\e2e\stop-e2e-server.ps1"
} else {
    Stop-E2EServer
}

Write-Output ''
if ($fallas.Count -eq 0) {
    Write-Output "== suite ${suiteName}: $($miembros.Count)/$($miembros.Count) OK =="
    exit 0
}

Write-Output "== suite ${suiteName}: FALLOS en $($fallas.Count)/$($miembros.Count): $($fallas -join ', ') =="
exit 1
