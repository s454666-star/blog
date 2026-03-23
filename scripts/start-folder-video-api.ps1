param(
    [switch]$WarmCache,
    [int]$Port = 8090,
    [string]$BindAddress = "0.0.0.0",
    [string]$UpstreamHost = "blog.test"
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$stateDir = Join-Path $projectRoot "storage\app\folder-video-server"
$stateFile = Join-Path $stateDir "state.json"
$caddyDir = Join-Path $projectRoot "storage\bin\caddy"
$caddyExe = Join-Path $caddyDir "caddy.exe"
$caddyConfig = Join-Path $stateDir "Caddyfile"
$stdoutLog = Join-Path $stateDir "caddy-stdout.log"
$stderrLog = Join-Path $stateDir "caddy-stderr.log"

function Ensure-CaddyBinary {
    if (Test-Path $caddyExe) {
        return
    }

    New-Item -ItemType Directory -Force -Path $caddyDir | Out-Null

    $release = Invoke-RestMethod -Headers @{ "User-Agent" = "Codex" } -Uri "https://api.github.com/repos/caddyserver/caddy/releases/latest"
    $asset = $release.assets | Where-Object { $_.name -match "windows_amd64.zip$" } | Select-Object -First 1

    if (-not $asset) {
        throw "Unable to locate a Windows amd64 Caddy release asset."
    }

    $zipPath = Join-Path $env:TEMP $asset.name
    Invoke-WebRequest -Headers @{ "User-Agent" = "Codex" } -Uri $asset.browser_download_url -OutFile $zipPath
    Expand-Archive -Path $zipPath -DestinationPath $caddyDir -Force
    Remove-Item $zipPath -Force
}

function Stop-LegacyArtisanServer {
    $artisanProcesses = Get-CimInstance Win32_Process | Where-Object {
        $_.CommandLine -match "artisan serve" -and $_.CommandLine -match "--port=$Port"
    }

    foreach ($process in $artisanProcesses) {
        Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
    }
}

function Stop-ManagedCaddy {
    if (-not (Test-Path $stateFile)) {
        return
    }

    try {
        $state = Get-Content $stateFile -Raw | ConvertFrom-Json
        if ($state.caddy_pid) {
            Stop-Process -Id $state.caddy_pid -Force -ErrorAction SilentlyContinue
        }
    } catch {
    }

    Remove-Item $stateFile -Force -ErrorAction SilentlyContinue
}

function Wait-ForTcpPort {
    param(
        [string]$TargetHost,
        [int]$Port,
        [int]$TimeoutSeconds = 20
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)

    while ((Get-Date) -lt $deadline) {
        try {
            $client = [System.Net.Sockets.TcpClient]::new()
            $async = $client.BeginConnect($TargetHost, $Port, $null, $null)
            if ($async.AsyncWaitHandle.WaitOne(500)) {
                $client.EndConnect($async)
                $client.Dispose()
                return $true
            }

            $client.Dispose()
        } catch {
        }

        Start-Sleep -Milliseconds 300
    }

    return $false
}

Set-Location $projectRoot
New-Item -ItemType Directory -Force -Path $stateDir | Out-Null

if ($WarmCache) {
    php artisan folder-video:warm-cache --force
}

Ensure-CaddyBinary
Stop-LegacyArtisanServer
Stop-ManagedCaddy

$configText = @"
{
    auto_https off
    admin off
    persist_config off
}

:$Port {
    bind $BindAddress

    reverse_proxy https://127.0.0.1:443 {
        header_up Host $UpstreamHost
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
        header_up X-Forwarded-Port {server_port}

        transport http {
            tls_insecure_skip_verify
            tls_server_name $UpstreamHost
        }
    }
}
"@

Set-Content -Path $caddyConfig -Value $configText -Encoding UTF8

$caddyProcess = Start-Process -FilePath $caddyExe `
    -ArgumentList @("run", "--config", $caddyConfig, "--adapter", "caddyfile") `
    -WorkingDirectory $projectRoot `
    -RedirectStandardOutput $stdoutLog `
    -RedirectStandardError $stderrLog `
    -WindowStyle Hidden `
    -PassThru

if (-not (Wait-ForTcpPort -TargetHost "127.0.0.1" -Port $Port)) {
    throw "Caddy did not start listening on port $Port."
}

@{
    started_at = (Get-Date).ToString("o")
    caddy_pid = $caddyProcess.Id
    port = $Port
    bind_address = $BindAddress
    upstream_host = $UpstreamHost
    caddy_config = $caddyConfig
    stdout_log = $stdoutLog
    stderr_log = $stderrLog
} | ConvertTo-Json | Set-Content -Path $stateFile -Encoding UTF8

Write-Host "Folder video API is now served by Caddy."
Write-Host "URL: http://10.0.0.19:$Port/api/folder-videos?limit=1"
Write-Host "Caddy PID: $($caddyProcess.Id)"
Write-Host "Config: $caddyConfig"
