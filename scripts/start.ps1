$ErrorActionPreference = 'Stop'

Set-Location (Split-Path -Parent $PSScriptRoot)

function New-RandomHex([int] $Length) {
    $bytes = New-Object byte[] ($Length / 2)
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return ([System.BitConverter]::ToString($bytes) -replace '-', '').ToLowerInvariant()
}

function New-RandomBase64([int] $Length) {
    $bytes = New-Object byte[] $Length
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes)
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker was not found. Install and start Docker Desktop, then run this launcher again.'
}

if (-not (Test-Path '.env.docker')) {
    $dbPassword = New-RandomHex 48
    $metricsToken = New-RandomHex 48
    $grafanaPassword = New-RandomHex 36
    $appKey = New-RandomBase64 32

    @"
APP_NAME="Clean Air Finder Bulgaria"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$appKey
APP_URL=http://localhost:8080
DB_PASSWORD=$dbPassword
POSTGRES_PASSWORD=$dbPassword
METRICS_TOKEN=$metricsToken
GF_SECURITY_ADMIN_PASSWORD=$grafanaPassword
AIR_USER_AGENT="CleanAirFinderBulgaria/1.0 (set operator contact before public deployment)"
"@ | Set-Content -Path '.env.docker' -Encoding utf8
    Set-Content -Path '.metrics-token' -Value $metricsToken -NoNewline -Encoding ascii
}

$appUrl = 'http://localhost:8080'
$appUrlLine = Get-Content '.env.docker' | Where-Object { $_ -match '^APP_URL=' } | Select-Object -First 1
if ($appUrlLine) { $appUrl = ($appUrlLine -replace '^APP_URL=', '').Trim().Trim('"') }

Write-Host 'Starting Clean Air Finder...' -ForegroundColor Cyan
docker compose --profile monitoring up -d --build
if ($LASTEXITCODE -ne 0) { throw 'Docker Compose could not start the application.' }

Write-Host "Waiting for $appUrl ..." -ForegroundColor Yellow
$healthUrl = $appUrl.TrimEnd('/') + '/health/live'
$ready = $false
for ($attempt = 1; $attempt -le 60; $attempt++) {
    try {
        $response = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 3
        if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 500) { $ready = $true; break }
    } catch { Start-Sleep -Seconds 2 }
}

if (-not $ready) { Write-Warning "The containers started, but the application did not respond yet. Opening $appUrl anyway." }
else { Write-Host 'Application is ready.' -ForegroundColor Green }

Start-Process $appUrl
Write-Host "Application: $appUrl"
Write-Host 'Grafana credentials are in .env.docker'
Write-Host 'Press Ctrl+C to close this window; Docker services will keep running.'
