# End-to-end run against the real stack, isolated from development data:
#   - PostgreSQL database `etijahfly_e2e` (recreated and seeded with demo data)
#   - Laravel API on :8100, partner portal (Nuxt build) on :3100, admin portal (Next build) on :3101
#   - Playwright suites of both portals
#
#   .\infrastructure\local\e2e.ps1            # build portals first with: npm run build
#   .\infrastructure\local\e2e.ps1 -KeepRunning
param([switch]$KeepRunning)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'dev-env.ps1') | Out-Null
& (Join-Path $PSScriptRoot 'services.ps1') start | Out-Null

$root = $env:ETIJAHFLY_ROOT
$backend = Join-Path $root 'apps\backend'
$password = 'Etijah-Dev-2026!'

$e2eEnv = @{
    APP_ENV = 'local'; APP_DEBUG = 'false'; APP_URL = 'http://127.0.0.1:8100'
    DB_DATABASE = 'etijahfly_e2e'; CACHE_STORE = 'redis'; CACHE_PREFIX = 'etijahfly_e2e_'
    QUEUE_CONNECTION = 'sync'; MAIL_MAILER = 'log'; FILE_STORAGE_DRIVER = 'local'
    PARTNER_PORTAL_URL = 'http://127.0.0.1:3100'; ADMIN_PORTAL_URL = 'http://127.0.0.1:3101'
    CORS_ALLOWED_ORIGINS = 'http://127.0.0.1:3100,http://127.0.0.1:3101'
    DEV_SEED_PASSWORD = $password; MOCK_FLIGHT_PROVIDER_ENABLED = 'true'
}
foreach ($k in $e2eEnv.Keys) { Set-Item "env:$k" $e2eEnv[$k] }

function Wait-Http([string]$url, [int]$seconds = 60) {
    for ($i = 0; $i -lt $seconds; $i++) {
        try { Invoke-WebRequest $url -UseBasicParsing -TimeoutSec 3 | Out-Null; return } catch { Start-Sleep -Seconds 1 }
    }
    throw "Timed out waiting for $url"
}

$env:PGPASSWORD = 'postgres'
& psql -h 127.0.0.1 -p 54322 -U postgres -w -tc "SELECT 1 FROM pg_database WHERE datname = 'etijahfly_e2e'" | Out-String | ForEach-Object {
    if ($_.Trim() -ne '1') { & psql -h 127.0.0.1 -p 54322 -U postgres -w -c 'CREATE DATABASE etijahfly_e2e' | Out-Null }
}
& redis-cli --scan --pattern 'etijahfly_e2e_*' | ForEach-Object { & redis-cli del $_ | Out-Null }

Push-Location $backend
& php artisan config:clear | Out-Null
& php artisan migrate:fresh --seed --force | Out-Null
Pop-Location

$logs = Join-Path $root '.tools\e2e-logs'
New-Item -ItemType Directory -Force $logs | Out-Null
$processes = @()

# Laravel's router script resolves index.php from the working directory: run from public/.
$processes += Start-Process -PassThru -WindowStyle Hidden -WorkingDirectory (Join-Path $backend 'public') -FilePath (Get-Command php).Source `
    -ArgumentList '-S', '127.0.0.1:8100', (Join-Path $backend 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php') `
    -RedirectStandardOutput "$logs\api.out.log" -RedirectStandardError "$logs\api.err.log"

$env:PORT = '3100'; $env:HOST = '127.0.0.1'; $env:NUXT_API_BASE = 'http://127.0.0.1:8100/api/v1'
$processes += Start-Process -PassThru -WindowStyle Hidden -WorkingDirectory (Join-Path $root 'apps\partner-portal') -FilePath (Get-Command node).Source `
    -ArgumentList '.output/server/index.mjs' -RedirectStandardOutput "$logs\partner.out.log" -RedirectStandardError "$logs\partner.err.log"

$env:API_BASE_URL = 'http://127.0.0.1:8100/api/v1'
$processes += Start-Process -PassThru -WindowStyle Hidden -WorkingDirectory (Join-Path $root 'apps\admin-portal') -FilePath (Get-Command node).Source `
    -ArgumentList (Join-Path $root 'node_modules\next\dist\bin\next'), 'start', '-p', '3101', '-H', '127.0.0.1' `
    -RedirectStandardOutput "$logs\admin.out.log" -RedirectStandardError "$logs\admin.err.log"

$exit = 0
try {
    Wait-Http 'http://127.0.0.1:8100/api/v1/health'
    Wait-Http 'http://127.0.0.1:3100/login'
    Wait-Http 'http://127.0.0.1:3101/login'

    $env:E2E_PASSWORD = $password
    $env:E2E_API_URL = 'http://127.0.0.1:8100/api/v1'
    $env:E2E_PARTNER_URL = 'http://127.0.0.1:3100'
    $env:E2E_ADMIN_URL = 'http://127.0.0.1:3101'

    Push-Location (Join-Path $root 'apps\partner-portal'); & npx playwright test; if ($LASTEXITCODE -ne 0) { $exit = 1 }; Pop-Location
    Push-Location (Join-Path $root 'apps\admin-portal'); & npx playwright test; if ($LASTEXITCODE -ne 0) { $exit = 1 }; Pop-Location
}
finally {
    if (-not $KeepRunning) { $processes | ForEach-Object { Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue } }
}
exit $exit
