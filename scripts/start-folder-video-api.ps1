param(
    [switch]$WarmCache,
    [switch]$ForceWarmCache,
    [switch]$WarmPreviews,
    [switch]$WarmThumbnails,
    [int]$PreviewLimit = 60,
    [int]$Port = 8090,
    [int]$LaravelPort = 8091,
    [string]$BindAddress = "0.0.0.0",
    [string]$MediaRoot = "",
    [string]$MediaShare = "",
    [string]$PhotoRoot = "",
    [int]$MediaWaitSeconds = 120
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$stateDir = Join-Path $projectRoot "storage\app\folder-video-server"
$stateFile = Join-Path $stateDir "state.json"
$caddyDir = Join-Path $projectRoot "storage\bin\caddy"
$caddyExe = Join-Path $caddyDir "caddy.exe"
$caddyConfig = Join-Path $stateDir "Caddyfile"
$caddyStdoutLog = Join-Path $stateDir "caddy-stdout.log"
$caddyStderrLog = Join-Path $stateDir "caddy-stderr.log"
$laravelStdoutLog = Join-Path $stateDir "laravel-stdout.log"
$laravelStderrLog = Join-Path $stateDir "laravel-stderr.log"

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

function Get-EnvFileValue {
    param([string]$Name)

    $envPath = Join-Path $projectRoot ".env"
    if (-not (Test-Path -LiteralPath $envPath)) {
        return ""
    }

    $pattern = "^\s*$([regex]::Escape($Name))\s*=\s*(.*)\s*$"
    foreach ($line in Get-Content -LiteralPath $envPath -Encoding UTF8) {
        if ($line -match $pattern) {
            $value = $Matches[1].Trim()
            if (
                ($value.StartsWith('"') -and $value.EndsWith('"')) -or
                ($value.StartsWith("'") -and $value.EndsWith("'"))
            ) {
                $value = $value.Substring(1, $value.Length - 2)
            }

            return $value
        }
    }

    return ""
}

function Stop-ProcessByIdIfRunning {
    param([int]$ProcessId)

    if ($ProcessId -le 0) {
        return
    }

    Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue
}

function Stop-ManagedProcesses {
    if (-not (Test-Path $stateFile)) {
        return
    }

    try {
        $state = Get-Content $stateFile -Raw | ConvertFrom-Json
        if ($state.caddy_pid) {
            Stop-ProcessByIdIfRunning -ProcessId ([int]$state.caddy_pid)
        }
        if ($state.laravel_pid) {
            Stop-ProcessByIdIfRunning -ProcessId ([int]$state.laravel_pid)
        }
    } catch {
    }

    Remove-Item $stateFile -Force -ErrorAction SilentlyContinue
}

function Stop-LegacyArtisanServer {
    param([int[]]$Ports)

    $artisanProcesses = Get-CimInstance Win32_Process | Where-Object {
        $commandLine = $_.CommandLine
        $commandLine -match "artisan serve" -and [bool](
            $Ports | Where-Object { $PSItem -gt 0 -and $commandLine -match "--port=$PSItem" }
        )
    }

    foreach ($process in $artisanProcesses) {
        Stop-ProcessByIdIfRunning -ProcessId $process.ProcessId
    }
}

function Stop-ProjectCaddy {
    $caddyProcesses = Get-CimInstance Win32_Process | Where-Object {
        $_.Name -eq "caddy.exe" -and $_.CommandLine -match [regex]::Escape($projectRoot)
    }

    foreach ($process in $caddyProcesses) {
        Stop-ProcessByIdIfRunning -ProcessId $process.ProcessId
    }
}

function Test-MediaRoot {
    try {
        return [bool](Test-Path -LiteralPath $MediaRoot -PathType Container -ErrorAction Stop)
    } catch {
        return $false
    }
}

function Test-PhotoRoot {
    try {
        return [bool](Test-Path -LiteralPath $PhotoRoot -PathType Container -ErrorAction Stop)
    } catch {
        return $false
    }
}

function Restore-MediaDrive {
    if ([string]::IsNullOrWhiteSpace($MediaShare)) {
        return
    }

    $driveRoot = [System.IO.Path]::GetPathRoot($MediaRoot)
    if ([string]::IsNullOrWhiteSpace($driveRoot) -or $driveRoot -notmatch "^[A-Za-z]:\\$") {
        return
    }

    $driveName = $driveRoot.TrimEnd("\")
    & net.exe use $driveName $MediaShare /persistent:yes 2>&1 | Out-Null
}

function Wait-ForMediaRoot {
    $deadline = (Get-Date).AddSeconds([Math]::Max(0, $MediaWaitSeconds))

    do {
        if (Test-MediaRoot) {
            return $true
        }

        Restore-MediaDrive
        if (Test-MediaRoot) {
            return $true
        }

        if ((Get-Date) -ge $deadline) {
            break
        }

        Start-Sleep -Seconds 5
    } while ($true)

    return $false
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

function Get-LanIps {
    try {
        return @(Get-NetIPAddress -AddressFamily IPv4 | Where-Object {
            $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*"
        } | Select-Object -ExpandProperty IPAddress)
    } catch {
        return @()
    }
}

Set-Location $projectRoot
New-Item -ItemType Directory -Force -Path $stateDir | Out-Null

if ([string]::IsNullOrWhiteSpace($MediaRoot)) {
    $MediaRoot = Get-EnvFileValue -Name "FOLDER_VIDEO_ROOT"
}
if ([string]::IsNullOrWhiteSpace($MediaRoot)) {
    $MediaRoot = "M:\video(重跑)"
}
if ([string]::IsNullOrWhiteSpace($MediaShare)) {
    $MediaShare = Get-EnvFileValue -Name "FOLDER_VIDEO_DRIVE_SHARE"
}
if (-not (Wait-ForMediaRoot)) {
    throw "Folder video media root is not available: $MediaRoot"
}
if ([string]::IsNullOrWhiteSpace($PhotoRoot)) {
    $PhotoRoot = Get-EnvFileValue -Name "FOLDER_PHOTO_ROOT"
}
if ([string]::IsNullOrWhiteSpace($PhotoRoot)) {
    $PhotoRoot = "\\mc\photo"
}
if (-not (Test-PhotoRoot)) {
    throw "Folder photo media root is not available: $PhotoRoot"
}

$phpExe = (Get-Command php -ErrorAction Stop).Source

php artisan config:clear | Out-Null

if ($WarmCache) {
    $warmArgs = @("artisan", "folder-video:warm-cache")
    if ($ForceWarmCache) {
        $warmArgs += "--force"
    }
    if ($WarmPreviews) {
        $warmArgs += "--previews"
        $warmArgs += "--preview-limit=$PreviewLimit"
    }
    if ($WarmThumbnails) {
        $warmArgs += "--thumbnails"
        if (-not $WarmPreviews) {
            $warmArgs += "--preview-limit=$PreviewLimit"
        }
    }

    & $phpExe $warmArgs
}

Ensure-CaddyBinary
Stop-ManagedProcesses
Stop-LegacyArtisanServer -Ports @($Port, $LaravelPort)
Stop-ProjectCaddy

$laravelProcess = Start-Process -FilePath $phpExe `
    -ArgumentList @("artisan", "serve", "--host=127.0.0.1", "--port=$LaravelPort") `
    -WorkingDirectory $projectRoot `
    -RedirectStandardOutput $laravelStdoutLog `
    -RedirectStandardError $laravelStderrLog `
    -WindowStyle Hidden `
    -PassThru

if (-not (Wait-ForTcpPort -TargetHost "127.0.0.1" -Port $LaravelPort -TimeoutSeconds 30)) {
    Stop-ProcessByIdIfRunning -ProcessId $laravelProcess.Id
    throw "Laravel did not start listening on port $LaravelPort."
}

$configText = @"
{
    auto_https off
    admin off
    persist_config off
}

:$Port {
    bind $BindAddress
    encode zstd gzip

    @folderApp path /folder-video-app
    handle @folderApp {
        rewrite * /index.php/folder-video-app
        reverse_proxy 127.0.0.1:$LaravelPort {
            header_up Host {host}
            header_up X-Forwarded-Host {host}
            header_up X-Forwarded-Proto {scheme}
            header_up X-Forwarded-Port $Port
        }
    }

    @folderPhotoApp path /folder-photo-app /folder-photo-app/version.json /folder-photo-app/android-version.json /folder-photo-app/folder-photo-app.apk
    handle @folderPhotoApp {
        rewrite * /index.php{path}
        reverse_proxy 127.0.0.1:$LaravelPort {
            header_up Host {host}
            header_up X-Forwarded-Host {host}
            header_up X-Forwarded-Proto {scheme}
            header_up X-Forwarded-Port $Port
        }
    }

    @videoMedia path /folder-video-media/*
    handle @videoMedia {
        uri strip_prefix /folder-video-media
        root * "$MediaRoot"
        header {
            Cache-Control "private, max-age=600"
            Accept-Ranges "bytes"
            X-Content-Type-Options "nosniff"
        }
        file_server
    }

    @photoMedia path /folder-photo-media/*
    handle @photoMedia {
        uri strip_prefix /folder-photo-media
        root * "$PhotoRoot"
        header {
            Cache-Control "public, max-age=86400"
            X-Content-Type-Options "nosniff"
        }
        file_server
    }

    handle {
        reverse_proxy 127.0.0.1:$LaravelPort {
            header_up Host {host}
            header_up X-Forwarded-Host {host}
            header_up X-Forwarded-Proto {scheme}
            header_up X-Forwarded-Port $Port
        }
    }
}
"@

Set-Content -Path $caddyConfig -Value $configText -Encoding UTF8

$caddyProcess = Start-Process -FilePath $caddyExe `
    -ArgumentList @("run", "--config", $caddyConfig, "--adapter", "caddyfile") `
    -WorkingDirectory $projectRoot `
    -RedirectStandardOutput $caddyStdoutLog `
    -RedirectStandardError $caddyStderrLog `
    -WindowStyle Hidden `
    -PassThru

if (-not (Wait-ForTcpPort -TargetHost "127.0.0.1" -Port $Port)) {
    Stop-ProcessByIdIfRunning -ProcessId $caddyProcess.Id
    Stop-ProcessByIdIfRunning -ProcessId $laravelProcess.Id
    throw "Caddy did not start listening on port $Port."
}

$lanIps = Get-LanIps

@{
    started_at = (Get-Date).ToString("o")
    caddy_pid = $caddyProcess.Id
    laravel_pid = $laravelProcess.Id
    port = $Port
    laravel_port = $LaravelPort
    bind_address = $BindAddress
    media_root = $MediaRoot
    photo_root = $PhotoRoot
    static_stream_path = "/folder-video-media"
    static_photo_path = "/folder-photo-media"
    caddy_config = $caddyConfig
    caddy_stdout_log = $caddyStdoutLog
    caddy_stderr_log = $caddyStderrLog
    laravel_stdout_log = $laravelStdoutLog
    laravel_stderr_log = $laravelStderrLog
    lan_urls = @($lanIps | ForEach-Object { "http://$PSItem`:$Port/folder-video-app" })
} | ConvertTo-Json | Set-Content -Path $stateFile -Encoding UTF8

Write-Host "Folder Video is running on this computer."
foreach ($ip in $lanIps) {
    Write-Host "URL: http://$ip`:$Port/folder-video-app"
}
Write-Host "Media root: $MediaRoot"
Write-Host "Photo root: $PhotoRoot"
Write-Host "Caddy PID: $($caddyProcess.Id)"
Write-Host "Laravel PID: $($laravelProcess.Id)"
