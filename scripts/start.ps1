$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')

function New-RandomBytes([int]$Count) {
    $bytes = New-Object byte[] $Count
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($bytes)
    $rng.Dispose()
    return ,$bytes
}
function New-Hex([int]$Count) {
    -join ((New-RandomBytes $Count) | ForEach-Object { $_.ToString('x2') })
}

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$envFile   = Join-Path $PWD.Path '.env.docker'
$tokenFile = Join-Path $PWD.Path '.metrics-token'

if (-not (Test-Path $envFile)) {
    $appKey       = [Convert]::ToBase64String((New-RandomBytes 32))
    $dbPass       = New-Hex 24
    $metricsToken = New-Hex 24
    $grafanaPass  = New-Hex 18

    $content = @"
APP_NAME="Clean Air Finder Bulgaria"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$appKey
APP_URL=http://localhost:8080
DB_PASSWORD=$dbPass
POSTGRES_PASSWORD=$dbPass
METRICS_TOKEN=$metricsToken
GF_SECURITY_ADMIN_PASSWORD=$grafanaPass
AIR_USER_AGENT="CleanAirFinderBulgaria/1.0 (set operator contact before public deployment)"
"@
    [System.IO.File]::WriteAllText($envFile, ($content -replace "`r`n", "`n") + "`n", $utf8NoBom)

    # No trailing newline, same as printf '%s' in the shell script.
    [System.IO.File]::WriteAllText($tokenFile, $metricsToken, $utf8NoBom)
}

docker compose --profile monitoring up -d --build
if ($LASTEXITCODE -ne 0) { throw "docker compose failed with exit code $LASTEXITCODE" }

Write-Host ""
Write-Host "Application: http://localhost:8080"
Write-Host "Grafana credentials are in .env.docker"