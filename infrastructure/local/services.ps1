# Start/stop the local PostgreSQL (Supabase-compatible, v17) and Redis used in development.
#   .\infrastructure\local\services.ps1 start
#   .\infrastructure\local\services.ps1 stop
#   .\infrastructure\local\services.ps1 status
param([ValidateSet('start', 'stop', 'status', 'init')][string]$Action = 'start')

. (Join-Path $PSScriptRoot 'dev-env.ps1') | Out-Null

$tools = Join-Path $env:ETIJAHFLY_ROOT '.tools'
$pgData = Join-Path $tools 'pgdata'
$pgLog = Join-Path $tools 'pg.log'
$pgPort = 54322
$redisPort = 6379

function Initialize-Postgres {
    if (Test-Path (Join-Path $pgData 'PG_VERSION')) { return }
    $pwFile = Join-Path $tools 'pg-superuser.pw'
    # Local development password only; never reused anywhere else.
    Set-Content $pwFile 'postgres' -NoNewline -Encoding ascii
    & initdb -D $pgData -U postgres --pwfile=$pwFile -A scram-sha-256 -E UTF8 --locale=C | Out-Host
    Remove-Item $pwFile
}

function Test-Port([int]$port) {
    $c = New-Object System.Net.Sockets.TcpClient
    try { $c.Connect('127.0.0.1', $port); return $true } catch { return $false } finally { $c.Dispose() }
}

switch ($Action) {
    'init' { Initialize-Postgres }
    'start' {
        Initialize-Postgres
        if (-not (Test-Port $pgPort)) {
            # Run postgres itself detached: starting it through pg_ctl from a non-interactive
            # shell keeps inherited console handles open and the server dies with the shell.
            Start-Process -WindowStyle Hidden -FilePath (Get-Command postgres).Source `
                -ArgumentList @('-D', "`"$pgData`"", '-p', $pgPort) `
                -RedirectStandardError $pgLog -RedirectStandardOutput "$pgLog.out"
            for ($i = 0; $i -lt 30 -and -not (Test-Port $pgPort); $i++) { Start-Sleep -Milliseconds 500 }
        }
        if (-not (Test-Port $redisPort)) {
            Start-Process -WindowStyle Hidden -FilePath (Get-Command redis-server).Source `
                -ArgumentList "--port $redisPort --bind 127.0.0.1 --save `"`" --appendonly no"
            Start-Sleep -Seconds 1
        }
        & $PSCommandPath status
    }
    'stop' {
        & pg_ctl -D $pgData -m fast stop | Out-Host
        & redis-cli -p $redisPort shutdown nosave 2>$null
    }
    'status' {
        Write-Host ("PostgreSQL :{0} {1}" -f $pgPort, $(if (Test-Port $pgPort) { 'up' } else { 'down' }))
        Write-Host ("Redis      :{0} {1}" -f $redisPort, $(if (Test-Port $redisPort) { 'up' } else { 'down' }))
    }
}
