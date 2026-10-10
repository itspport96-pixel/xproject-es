# tools\e2e\stop-e2e-server.ps1 - detiene el servidor PHP embebido de los E2E.
#
# Cada script E2E lo detiene al final (Stop-E2EServer definido en _env.ps1).
# Este script sirve para pararlo a mano o para limpiar tras un fallo que
# abortara antes del final:
#   powershell -ExecutionPolicy Bypass -File tools\e2e\stop-e2e-server.ps1
#
# Endurecido (v1.11.0): la linea 23 original usaba "${pidFile}:" tras la
# variable (sospecha de InvalidVariableReferenceWithDrive; no reproducible en
# PowerShell 5.1 - [Parser]::ParseFile da OK, pero se evita el patron) y no
# distinguia un pid reciclado de un proceso ajeno: ahora exige que el pid
# apunte a un proceso php antes de matarlo.

$ErrorActionPreference = 'Continue'

$pidFile = Join-Path $PSScriptRoot '.e2e-server.pid'
$log = Join-Path $PSScriptRoot '.e2e-server.log'

if (-not (Test-Path -LiteralPath $pidFile)) {
    Write-Output "sin PID guardado ($pidFile): no hay servidor que parar"
    exit 0
}

$raw = ''
try { $raw = (Get-Content -LiteralPath $pidFile -Raw) } catch { $raw = '' }
$raw = if ($null -eq $raw) { '' } else { $raw.Trim() }

$pidValue = 0
if ($raw -match '^\d+$') { $pidValue = [int]$raw }

if ($pidValue -le 0) {
    Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
    Write-Output "PID invalido en el fichero ($pidFile); fichero eliminado"
    exit 0
}

$proc = Get-Process -Id $pidValue -ErrorAction SilentlyContinue
if ($null -eq $proc) {
    Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
    Write-Output "el pid $pidValue ya no existe"
    exit 0
}

$procName = [string]$proc.ProcessName
if ($procName -notlike 'php*') {
    Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
    Write-Output "el pid $pidValue pertenece a '$procName' (no php); no se detiene"
    exit 0
}

Stop-Process -Id $pidValue -Force -ErrorAction SilentlyContinue
Write-Output "servidor detenido (pid $pidValue)"

Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
Write-Output "log del ultimo arranque ($log)"
exit 0
