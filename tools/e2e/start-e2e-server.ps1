# tools\e2e\start-e2e-server.ps1 - arranca (o reutiliza) el servidor E2E una
# sola vez por lote y lo deja corriendo.
#
# Uso:
#   powershell -File tools\e2e\start-e2e-server.ps1 [-SkipPrepare]
#
#   -SkipPrepare   fija E2E_SKIP_PREPARE=1: los scripts del lote saltan
#                  bin/prepare-e2e-db.php (solo si la BD ya tiene tablas y
#                  fixtures; si no, preparan igual). Sin el flag, este
#                  comando prepara una vez (sin integridad; con integridad:
#                  php bin/prepare-e2e-db.php --fresh o --verify a mano).
#
# El servidor queda vivo tras salir (pid en .e2e-server.pid, marker
# .e2e-server.transport): los scripts del lote con E2E_KEEP_SERVER=1 lo
# reutilizan y no lo paran al final; el runner del lote lo cierra al terminar
# (o a mano: powershell tools\e2e\stop-e2e-server.ps1).
#
# Un aborto no deja huérfanos inútiles: el PID se conserva en el fichero y el
# siguiente arranque lo reutiliza si responde y lo mata si está colgado.

param([switch]$SkipPrepare)

if ($SkipPrepare) { $env:E2E_SKIP_PREPARE = '1' }

$ErrorActionPreference = 'Continue'
. (Join-Path $PSScriptRoot '_env.ps1')

Write-Output "servidor E2E listo en $base (pid $($script:e2eServerPid), MAIL_TRANSPORT=$($env:MAIL_TRANSPORT), BD=$e2eDb); no se para al salir"
