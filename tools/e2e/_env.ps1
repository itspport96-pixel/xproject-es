# tools\e2e\_env.ps1 - preludio compartido de los E2E (v1.8.3).
#
# Dot-source en la linea 2 de cada script E2E (sustituye al $base fijo):
#     . (Join-Path $PSScriptRoot '_env.ps1')
#
# Que hace:
#   1. Fija la BD de E2E (E2E_DB_NAME, por defecto xproject_e2e) y la exporta
#      a los hijos: la API embebida y los CLI de bin/ corren contra ella. Los
#      E2E dejan de escribir en la BD de trabajo.
#   2. Ejecuta bin/prepare-e2e-db.php (esquema, copia de identidad/acceso y
#      fixtures) para que el primer arranque tambien funcione en limpio.
#   3. Arranca (o reutiliza) el servidor PHP embebido en 127.0.0.1:8099 con
#      public/ como raiz y tools\e2e\router.php de router: los E2E dejan de
#      depender de Apache ni del subdirectorio /xp/public.
#   4. Define Stop-E2EServer y expone al script que lo incluye:
#        $base        endpoint de la API, p. ej. http://127.0.0.1:8099/api
#        $e2eDb       nombre de la BD de E2E (para mysql.exe y comparaciones)
#        $e2eRepoRoot raiz del repositorio
#        $phpExe      ejecutable de PHP usado por el preludio
#   5. v1.18.0: fija MAIL_TRANSPORT=outbox para el servidor embebido: los
#      correos de reset/bienvenida no salen por SMTP (nada de gmail real en
#      pruebas) y quedan en la tabla mail_outbox, de donde los E2E extraen los
#      tokens de enlace. Un servidor heredado sin ese entorno se reinicia
#      (marker .e2e-server.transport) para que el modo nunca dependa de la
#      sucesion de arranques.
#   6. v1.18.0: limpia al arrancar las claves de rate limit de
#      forgot/reset-password (5/900s por IP) con clear-rate-limit.php, para
#      que los consumes de un script no arranquen con el contador de la
#      ejecucion anterior.
#   7. -SkipPrepare (o E2E_SKIP_PREPARE=1 en el entorno): omite
#      bin/prepare-e2e-db.php SOLO si la BD de E2E ya tiene las tablas
#      nucleares y los fixtures (PROD-001, E2E-ALMACEN, E2E-PICK, TRANSITO);
#      si no los tiene, prepara igual aunque este el flag (nunca deja un E2E
#      sin datos). Por defecto sin flag: comportamiento actual (prepara
#      siempre; la integridad ya no corre aqui, ver prepare-e2e-db --verify).
#   8. E2E_KEEP_SERVER=1: Stop-E2EServer no para el servidor al final del
#      script, para que las suites arranquen 8099 una vez por lote
#      (tools\e2e\start-e2e-server.ps1 o los runners de suite) y lo paren
#      al cierre. Sin la variable: comportamiento actual (para al salir).
#
# Variables opcionales de entorno: E2E_DB_NAME, E2E_PORT (8099), PHP_BIN,
# E2E_SKIP_PREPARE (1), E2E_KEEP_SERVER (1).
#
# El servidor se detiene al final de cada script (Stop-E2EServer) salvo con
# E2E_KEEP_SERVER=1. Si un script muere antes, el PID queda en
# .e2e-server.pid y el siguiente arranque lo detecta: lo reutiliza si
# responde y lo mata si esta colgado (un aborto no deja un servidor
# inutilizable; el siguiente arranque lo recupera). Para pararlo a mano:
# powershell tools\e2e\stop-e2e-server.ps1

param([switch]$SkipPrepare)

$script:e2ePrevEAP = $ErrorActionPreference
$ErrorActionPreference = 'Continue'

try {
    $e2eRoot = $PSScriptRoot
    $e2eRepoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)

    # Codificacion de los hijos nativos (mysql.exe, php.exe). PowerShell 5.1
    # decodifica su stdout con [Console]::OutputEncoding (OEM local, p. ej.
    # CP850) y mysql.exe responde en UTF-8: un literal acentuado de una
    # asercion ('DAÑADO') llegaria doblado y no casaria con el valor leido,
    # dando FALLOS falsos. Sin BOM para que el pipe siga siendo ASCII puro.
    try {
        $e2eUtf8 = New-Object System.Text.UTF8Encoding($false)
        [Console]::OutputEncoding = $e2eUtf8
        $OutputEncoding = $e2eUtf8
    } catch { }

    $e2eDb = if ($env:E2E_DB_NAME) { $env:E2E_DB_NAME } else { 'xproject_e2e' }
    $e2ePort = if ($env:E2E_PORT) { [int]$env:E2E_PORT } else { 8099 }
    $e2ePidFile = Join-Path $e2eRoot '.e2e-server.pid'
    $e2eLog = Join-Path $e2eRoot '.e2e-server.log'
    $base = "http://127.0.0.1:$e2ePort/api"

    # --------------------------------------------------------------- php.exe
    $phpExe = $null
    if ($env:PHP_BIN -and (Test-Path -LiteralPath $env:PHP_BIN)) {
        $phpExe = $env:PHP_BIN
    } elseif (Get-Command php -ErrorAction SilentlyContinue) {
        $phpExe = (Get-Command php).Source
    } elseif (Test-Path -LiteralPath 'C:\xampp\php\php.exe') {
        $phpExe = 'C:\xampp\php\php.exe'
    }
    if (-not $phpExe) { throw 'No se encontro php (define PHP_BIN o amplia el PATH)' }
    if ($env:PATH -notlike ('*' + (Split-Path -Parent $phpExe) + '*')) {
        $env:PATH = (Split-Path -Parent $phpExe) + ';' + $env:PATH
    }

    # ------------------------------------------------------------- mysql.exe
    if ($env:PATH -notlike '*C:\xampp\mysql\bin*') { $env:PATH = 'C:\xampp\mysql\bin;' + $env:PATH }

    # ----------------------------------------------------------- redis-cli
    # v1.19.2: la ruta del redis-cli es de la MAQUINA, no del repo (aqui la
    # instalacion portable vive en C:\redis, y otras maquinas pueden usar
    # C:\Program Files\Redis). Se resuelve una sola vez en el preludio y los
    # scripts usan $e2eRedisCli; E2E_REDIS_CLI manda si esta definida y existe.
    $e2eRedisCli = $null
    $candidatosRedis = @()
    if ($env:E2E_REDIS_CLI) { $candidatosRedis += $env:E2E_REDIS_CLI }
    $candidatosRedis += @('C:\Program Files\Redis\redis-cli.exe', 'C:\redis\redis-cli.exe')
    foreach ($cand in $candidatosRedis) {
        if ($cand -and (Test-Path -LiteralPath $cand)) { $e2eRedisCli = $cand; break }
    }
    if (-not $e2eRedisCli) {
        $cmdRedis = Get-Command redis-cli.exe -ErrorAction SilentlyContinue
        if ($cmdRedis) { $e2eRedisCli = $cmdRedis.Source }
    }

    # ------------------------------------------------------------------- BD
    # La variable debe existir ANTES de lanzar cualquier PHP: bootstrap la lee
    # con getenv() y la usa en lugar de DB_DATABASE.
    $env:E2E_DB_NAME = $e2eDb

    # v1.18.0: transporte de correo de los E2E (hereda el proceso embebido).
    # getenv() gana sobre .env, asi que la app real (Apache) sigue en smtp.
    $e2eTransport = 'outbox'
    $env:MAIL_TRANSPORT = $e2eTransport
    $e2eTransportFile = Join-Path $e2eRoot '.e2e-server.transport'

    # ------------------------------------------------- prepare (opcional)
    # -SkipPrepare / E2E_SKIP_PREPARE=1 salta el preparado SOLO si la BD ya
    # tiene tablas nucleares + fixtures; cualquier otro caso prepara igual.
    $e2eSkipPrepare = ($SkipPrepare.IsPresent -or ("$env:E2E_SKIP_PREPARE" -eq '1'))
    $e2eLista = $false
    if ($e2eSkipPrepare) {
        $e2eLista = $false
        try {
            $nucleo = & mysql.exe -u root $e2eDb -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$e2eDb' AND TABLE_NAME IN ('users','products','locations','password_reset_tokens','mail_outbox')" 2>$null | Select-Object -First 1
            if ([int]$nucleo -eq 5) {
                $fx = & mysql.exe -u root $e2eDb -N -e "SELECT (SELECT COUNT(*) FROM products WHERE sku = 'PROD-001') + (SELECT COUNT(*) FROM locations WHERE codigo = 'E2E-ALMACEN') + (SELECT COUNT(*) FROM locations WHERE codigo = 'E2E-PICK') + (SELECT COUNT(*) FROM locations WHERE tipo = 'TRANSITO')" 2>$null | Select-Object -First 1
                if ([int]$fx -ge 4) { $e2eLista = $true }
            }
        } catch { $e2eLista = $false }
    }

    if ($e2eSkipPrepare -and $e2eLista) {
        Write-Output '  [e2e] prepare omitido (-SkipPrepare/E2E_SKIP_PREPARE=1, BD con tablas y fixtures)'
    } else {
        if ($e2eSkipPrepare) { Write-Output '  [e2e] E2E_SKIP_PREPARE pedido pero la BD no esta lista: se prepara igual' }
        $prepOut = & $phpExe (Join-Path $e2eRepoRoot 'bin\prepare-e2e-db.php') --db=$e2eDb 2>&1
        $prepExit = $LASTEXITCODE
        foreach ($linea in @($prepOut)) {
            if ($null -ne $linea -and "$linea" -ne '') { Write-Output ("  [e2e] " + $linea) }
        }
        if ($prepExit -ne 0) { throw "prepare-e2e-db.php ha fallado (exit $prepExit)" }
    }

    # -------------------------------------------------------------- servidor
    function Test-E2EServer {
        try {
            $null = Invoke-WebRequest -Uri "http://127.0.0.1:$e2ePort/api/auth/login" -Method Post `
                -ContentType 'application/json' -Body '{"email":"ping@localhost.local","password":"x"}' `
                -UseBasicParsing -TimeoutSec 3 -ErrorAction Stop
            return $true
        } catch {
            # Cualquier respuesta HTTP (401/422...) significa que el servidor
            # vive; la excepcion sin Response es "conexion rechazada".
            return ($null -ne $_.Exception.Response)
        }
    }

    function Stop-E2EServer {
        # E2E_KEEP_SERVER=1 (suites/lotes): el servidor vive hasta que lo pare
        # el runner o stop-e2e-server.ps1; el PID se conserva en el fichero.
        if ("$env:E2E_KEEP_SERVER" -eq '1') {
            Write-Output '  [e2e] servidor mantenido para el lote (E2E_KEEP_SERVER=1)'
            return
        }
        $pidFile = Join-Path $script:e2eRoot '.e2e-server.pid'
        $pidValue = 0
        if (Test-Path -LiteralPath $pidFile) {
            try { $pidValue = [int](Get-Content -LiteralPath $pidFile -Raw).Trim() } catch { $pidValue = 0 }
        }
        if ($pidValue -gt 0) { Stop-Process -Id $pidValue -Force -ErrorAction SilentlyContinue }
        Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue
    }

    # v1.18.0: ultimo token de enlace de un correo en el outbox. Los E2E no
    # reciben SMTP: la bienvenida/reset queda en mail_outbox y de ahi sale el
    # token=hex64 que se consume con /auth/reset-password. $kind: welcome,
    # admin, reset (sspr), changed.
    function LastMailToken($kind, $email) {
        $sql = "SELECT REGEXP_SUBSTR(html, 'token=[0-9a-f]{64}') FROM mail_outbox WHERE kind = '$kind' AND destino = '$email' ORDER BY created_at DESC LIMIT 1"
        $v = & mysql.exe -u root $e2eDb -N -e $sql | Select-Object -First 1
        $t = ("$v".Trim())
        if ($t -like 'token=*') { return $t.Substring(6) }
        return $null
    }

    $script:e2eRoot = $e2eRoot

    # Reutilizacion con garantia de transporte: un servidor heredado de una
    # sesion anterior (sin MAIL_TRANSPORT=outbox) se reinicia; los E2E nunca
    # deben depender del modo en que arrancara el proceso.
    $transportActual = ''
    if (Test-Path -LiteralPath $e2eTransportFile) {
        try { $transportActual = (Get-Content -LiteralPath $e2eTransportFile -Raw).Trim() } catch { $transportActual = '' }
    }
    $reutilizar = (Test-E2EServer) -and ($transportActual -eq $e2eTransport)
    if (-not $reutilizar -and (Test-E2EServer)) {
        Stop-E2EServer
        Start-Sleep -Milliseconds 300
    }

    if ($reutilizar) {
        if (Test-Path -LiteralPath $e2ePidFile) {
            try { $script:e2eServerPid = [int](Get-Content -LiteralPath $e2ePidFile -Raw).Trim() } catch { $script:e2eServerPid = $null }
        }
        Write-Output "  [e2e] servidor reutilizado en $base (MAIL_TRANSPORT=$e2eTransport)"
    } else {
        if (Test-Path -LiteralPath $e2ePidFile) {
            $oldPid = 0
            try { $oldPid = [int](Get-Content -LiteralPath $e2ePidFile -Raw).Trim() } catch { $oldPid = 0 }
            if ($oldPid -gt 0) {
                Stop-Process -Id $oldPid -Force -ErrorAction SilentlyContinue
                Start-Sleep -Milliseconds 300
            }
        }

        $srvArgs = @(
            '-S', "127.0.0.1:$e2ePort",
            '-t', (Join-Path $e2eRepoRoot 'public'),
            (Join-Path $e2eRoot 'router.php')
        )
        $srv = Start-Process -FilePath $phpExe -ArgumentList $srvArgs -PassThru -WindowStyle Hidden `
            -RedirectStandardOutput $e2eLog -RedirectStandardError ($e2eLog + '.err')
        $script:e2eServerPid = $srv.Id
        Set-Content -LiteralPath $e2ePidFile -Value $script:e2eServerPid -Encoding ASCII

        $listo = $false
        for ($i = 0; $i -lt 40; $i++) {
            if (Test-E2EServer) { $listo = $true; break }
            if ($srv.HasExited) { break }
            Start-Sleep -Milliseconds 500
        }
        if (-not $listo) {
            throw "el servidor PHP embebido no responde en $base (log: $e2eLog)"
        }
        Set-Content -LiteralPath $e2eTransportFile -Value $e2eTransport -Encoding ASCII
        Write-Output "  [e2e] servidor arrancado en $base (pid $($script:e2eServerPid), MAIL_TRANSPORT=$e2eTransport)"
    }

    # Rate limits de liga (5/900s por IP en forgot/reset-password): se limpian
    # en cada arranque para que los consumes de un script no arranquen con el
    # contador de ejecuciones anteriores (la suite encadena scripts dentro de
    # la misma ventana de 900s y al quinto consume llega el 429). Ningun E2E
    # ejerce ese limite, asi que borrarlos no tapa ninguna asercion.
    foreach ($rlPat in @('rl:*auth/reset-password*', 'rl:*auth/forgot-password*')) {
        $null = & $phpExe (Join-Path $e2eRoot 'clear-rate-limit.php') $rlPat 2>&1
        if ($LASTEXITCODE -ne 0) { Write-Output "  [e2e] aviso: no se pudo limpiar rate limit $rlPat" }
    }

    Write-Output "  [e2e] BD=$e2eDb (E2E_DB_NAME) base=$base"
} finally {
    $ErrorActionPreference = $script:e2ePrevEAP
}
