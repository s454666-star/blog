param(
    [int]$Port = 9005,
    [string]$CaddyExe = "C:\caddy\caddy.exe",
    [string]$CaddyConfig = "C:\caddy\Caddyfile"
)

$ErrorActionPreference = "Stop"

function Test-PortListening {
    param([int]$TargetPort)

    return [bool](Get-NetTCPConnection -State Listen -LocalPort $TargetPort -ErrorAction SilentlyContinue)
}

if (Test-PortListening -TargetPort $Port) {
    Write-Host "Caddy static video port $Port is already listening."
    exit 0
}

if (-not (Test-Path -LiteralPath $CaddyExe)) {
    throw "Caddy executable not found: $CaddyExe"
}

if (-not (Test-Path -LiteralPath $CaddyConfig)) {
    throw "Caddy config not found: $CaddyConfig"
}

$caddyDir = Split-Path -Parent $CaddyConfig
$stdoutLog = Join-Path $caddyDir "caddy-run-stdout.log"
$stderrLog = Join-Path $caddyDir "caddy-run-stderr.log"

$process = Start-Process -FilePath $CaddyExe `
    -ArgumentList @("run", "--config", $CaddyConfig, "--adapter", "caddyfile") `
    -WorkingDirectory $caddyDir `
    -RedirectStandardOutput $stdoutLog `
    -RedirectStandardError $stderrLog `
    -WindowStyle Hidden `
    -PassThru

$deadline = (Get-Date).AddSeconds(15)

while ((Get-Date) -lt $deadline) {
    if (Test-PortListening -TargetPort $Port) {
        Write-Host "Started Caddy PID $($process.Id) for static video port $Port."
        exit 0
    }

    Start-Sleep -Milliseconds 300
}

Write-Host "Caddy failed to open static video port $Port. Recent stderr:"
Get-Content -LiteralPath $stderrLog -Tail 80 -ErrorAction SilentlyContinue
throw "Caddy did not start listening on port $Port."
