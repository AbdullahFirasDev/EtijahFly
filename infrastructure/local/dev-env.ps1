# Dot-source this file to put the portable toolchain on PATH for the current shell:
#   . .\infrastructure\local\dev-env.ps1
# The toolchain lives in .tools/ (gitignored) and is installed by setup-tools.ps1.

$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')

# Pick up tools installed after this shell was opened (e.g. a fresh Node.js install).
$env:Path = @($env:Path, [Environment]::GetEnvironmentVariable('Path', 'Machine'),
    [Environment]::GetEnvironmentVariable('Path', 'User')) -join ';'
$tools = Join-Path $root '.tools'

# PHP 8.4 is always taken from .tools: Laravel 13 needs >= 8.3 plus pdo_pgsql/intl/zip/gd,
# which XAMPP's PHP 8.2 does not ship enabled. Node is only used from .tools when no
# system-wide Node.js is installed.
$portableNode = $null
if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    $portableNode = Get-ChildItem (Join-Path $tools 'node') -Directory -ErrorAction SilentlyContinue |
        Select-Object -First 1 -ExpandProperty FullName
}

$paths = @(
    (Join-Path $tools 'php'),
    $portableNode,
    (Join-Path $tools 'pgsql\pgsql\bin'),
    (Join-Path $tools 'redis')
) | Where-Object { $_ -and (Test-Path $_) }

$env:Path = (($paths + ($env:Path -split ';')) | Select-Object -Unique) -join ';'
$env:PHPRC = Join-Path $tools 'php'
$env:ETIJAHFLY_ROOT = $root
$env:PGDATA = Join-Path $tools 'pgdata'

Write-Host "EtijahFly toolchain ready:" -ForegroundColor Green
foreach ($cmd in 'php', 'node', 'npm', 'psql', 'redis-server') {
    $c = Get-Command $cmd -ErrorAction SilentlyContinue
    Write-Host ("  {0,-13} {1}" -f $cmd, $(if ($c) { $c.Source } else { 'missing' }))
}
