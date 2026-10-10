# Wrapper: el unico DDL del proyecto es database/schema/schema.sql.
# Este fichero ya no contiene SQL: se limita a ejecutar schema.sql contra la
# BD declarada en .env (o contra los parametros). El esquema NO se edita a mano;
# se regenera desde la BD viva con:  php bin\regenerate-schema.php
#
# Uso: .\create_tables.ps1 -DbName <bd> [-DbUser u] [-DbPassword p] [-MysqlPath ruta]
#      .\create_tables.ps1 -Force          (contra la BD de .env, con DROP TABLE)

param(
    [string]$DbName = "",
    [string]$DbUser = "",
    [string]$DbPassword = "",
    [string]$MysqlPath = "",
    [switch]$Force
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$schemaFile = Join-Path $PSScriptRoot "schema.sql"
$envFile = Join-Path $projectRoot ".env"

if (-not (Test-Path -LiteralPath $schemaFile)) {
    Write-Host "No existe $schemaFile" -ForegroundColor Red
    exit 1
}

# Valores por defecto desde .env (los parametros tienen prioridad).
$envValues = @{}
if (Test-Path -LiteralPath $envFile) {
    foreach ($line in Get-Content -LiteralPath $envFile -Encoding utf8) {
        if ($line -match '^\s*([A-Za-z0-9_]+)\s*=\s*(.*)$') {
            $envValues[$matches[1]] = $matches[2].Trim().Trim('"').Trim("'")
        }
    }
}

$dbFromEnv = ($DbName -eq "")

if ($DbName -eq "" -and $envValues.ContainsKey("DB_DATABASE")) { $DbName = $envValues["DB_DATABASE"] }
if ($DbUser -eq "" -and $envValues.ContainsKey("DB_USERNAME")) { $DbUser = $envValues["DB_USERNAME"] }
if ($DbPassword -eq "" -and $envValues.ContainsKey("DB_PASSWORD")) { $DbPassword = $envValues["DB_PASSWORD"] }

if ($DbName -eq "") {
    Write-Host "No se pudo determinar la BD (parametro -DbName o DB_DATABASE en .env)" -ForegroundColor Red
    exit 1
}

# schema.sql lleva `DROP TABLE IF EXISTS`: sin mas, ejecutarlo contra la BD de
# .env borraria todas las tablas y sus datos. Exige destino explicito o -Force.
if ($dbFromEnv -and -not $Force) {
    Write-Host "Aviso: no se ejecuta nada. schema.sql hace DROP TABLE IF EXISTS, asi que" -ForegroundColor Yellow
    Write-Host "       contra la BD de .env ($DbName) borraria los datos reales." -ForegroundColor Yellow
    Write-Host "       Destructivo a proposito: .\create_tables.ps1 -Force" -ForegroundColor Yellow
    exit 1
}

if ($MysqlPath -eq "") {
    $MysqlPath = "C:\xampp\mysql\bin\mysql.exe"
    if (-not (Test-Path -LiteralPath $MysqlPath)) { $MysqlPath = "mysql" }
}

$mysqlArgs = @()
if ($envValues.ContainsKey("DB_HOST") -and $envValues["DB_HOST"] -ne "") {
    $mysqlArgs += "--host=$($envValues['DB_HOST'])"
}
if ($envValues.ContainsKey("DB_PORT") -and $envValues["DB_PORT"] -ne "") {
    $mysqlArgs += "--port=$($envValues['DB_PORT'])"
}
if ($DbUser -ne "") { $mysqlArgs += "--user=$DbUser" }
$mysqlArgs += "--default-character-set=utf8mb4"
$mysqlArgs += $DbName

Write-Host "Ejecutando database/schema/schema.sql en $DbName..." -ForegroundColor Cyan

# La contrasena via variable de entorno: no queda en la linea de comandos.
# cmd /c con redireccion: mysql recibe los bytes del fichero tal cual (sin
# pasar por la codificacion de la consola de PowerShell).
$previousPwd = $env:MYSQL_PWD
$env:MYSQL_PWD = $DbPassword
try {
    $command = "`"$MysqlPath`" " + ($mysqlArgs -join " ") + " < `"$schemaFile`""
    cmd /c $command
    $exitCode = $LASTEXITCODE
} finally {
    if ($null -eq $previousPwd) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
    else { $env:MYSQL_PWD = $previousPwd }
}

if ($exitCode -ne 0) {
    Write-Host "Error al ejecutar schema.sql (exit $exitCode)" -ForegroundColor Red
    exit 1
}

Write-Host "schema.sql ejecutado correctamente en $DbName" -ForegroundColor Green
exit 0
