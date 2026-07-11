param(
    [switch]$WarmCache,
    [switch]$ForceWarmCache,
    [switch]$WarmPreviews,
    [switch]$WarmThumbnails,
    [int]$PreviewLimit = 60,
    [int]$Port = 8090,
    [int]$LaravelPort = 8091,
    [int]$MediaStreamPort = 8092,
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
$mediaStdoutLog = Join-Path $stateDir "media-stdout.log"
$mediaStderrLog = Join-Path $stateDir "media-stderr.log"
$indexWarmStdoutLog = Join-Path $stateDir "index-warm-stdout.log"
$indexWarmStderrLog = Join-Path $stateDir "index-warm-stderr.log"
$mediaServerScript = Join-Path $projectRoot "scripts\folder_video_range_server.py"

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

function Get-EnvFileValueOrDefault {
    param(
        [string]$Name,
        [string]$DefaultValue
    )

    $value = Get-EnvFileValue -Name $Name
    if ([string]::IsNullOrWhiteSpace($value)) {
        return $DefaultValue
    }

    return $value
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
        if ($state.media_stream_pid) {
            Stop-ProcessByIdIfRunning -ProcessId ([int]$state.media_stream_pid)
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

function Stop-ProjectMediaServer {
    $mediaProcesses = Get-CimInstance Win32_Process | Where-Object {
        $_.Name -in @("python.exe", "pythonw.exe") -and
        $_.CommandLine -match [regex]::Escape($mediaServerScript)
    }

    foreach ($process in $mediaProcesses) {
        Stop-ProcessByIdIfRunning -ProcessId $process.ProcessId
    }

    if (-not [string]::IsNullOrWhiteSpace($hlsCachePath)) {
        $hlsTranscoders = Get-CimInstance Win32_Process | Where-Object {
            $_.Name -eq "ffmpeg.exe" -and
            $_.CommandLine -match [regex]::Escape($hlsCachePath)
        }
        foreach ($process in $hlsTranscoders) {
            Stop-ProcessByIdIfRunning -ProcessId $process.ProcessId
        }
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

$NasRoot30TA = Get-EnvFileValueOrDefault -Name "NAS_VIEWER_ROOT_30T_A" -DefaultValue "\\mc\30T-A"
$NasRoot30TB = Get-EnvFileValueOrDefault -Name "NAS_VIEWER_ROOT_30T_B" -DefaultValue "\\mc\30T-B"
$NasRootFhd = Get-EnvFileValueOrDefault -Name "NAS_VIEWER_ROOT_FHD" -DefaultValue "\\mc\FHD"
$NasRootFhdBack = Get-EnvFileValueOrDefault -Name "NAS_VIEWER_ROOT_FHD_BACK" -DefaultValue "\\mc\FHD_BACK"
$NasRootHome = Get-EnvFileValueOrDefault -Name "NAS_VIEWER_ROOT_HOME" -DefaultValue "\\mc\home"
$NasRootHomes = Get-EnvFileValueOrDefault -Name "NAS_VIEWER_ROOT_HOMES" -DefaultValue "\\mc\homes"
$NasRootPhoto = Get-EnvFileValueOrDefault -Name "NAS_VIEWER_ROOT_PHOTO" -DefaultValue "\\mc\photo"
$NasRootPlexMediaServer = Get-EnvFileValueOrDefault -Name "NAS_VIEWER_ROOT_PLEX_MEDIA_SERVER" -DefaultValue "\\mc\PlexMediaServer"
$NasRootVideo = Get-EnvFileValueOrDefault -Name "NAS_VIEWER_ROOT_VIDEO" -DefaultValue "\\mc\video"

$phpExe = (Get-Command php -ErrorAction Stop).Source
$pythonExe = (Get-Command python -ErrorAction Stop).Source
$ffmpegBin = Get-EnvFileValueOrDefault -Name "FOLDER_VIDEO_FFMPEG_BIN" -DefaultValue "C:\ffmpeg\bin\ffmpeg.exe"
$previewQueuePath = Get-EnvFileValueOrDefault -Name "FOLDER_VIDEO_PREVIEW_QUEUE_PATH" -DefaultValue (Join-Path $projectRoot "storage\app\folder-video-preview-queue")
$previewCachePath = Get-EnvFileValueOrDefault -Name "FOLDER_VIDEO_PREVIEW_CACHE_PATH" -DefaultValue (Join-Path $projectRoot "storage\app\folder-video-previews")
$hlsQueuePath = Get-EnvFileValueOrDefault -Name "FOLDER_VIDEO_TV_HLS_QUEUE_PATH" -DefaultValue (Join-Path $projectRoot "storage\app\folder-video-tv-hls-queue")
$hlsCachePath = Get-EnvFileValueOrDefault -Name "FOLDER_VIDEO_TV_HLS_CACHE_PATH" -DefaultValue (Join-Path $projectRoot "storage\app\folder-video-tv-hls")
$hlsSegmentSeconds = [int](Get-EnvFileValueOrDefault -Name "FOLDER_VIDEO_TV_HLS_SEGMENT_SECONDS" -DefaultValue "2")
$previewSeconds = [int](Get-EnvFileValueOrDefault -Name "FOLDER_VIDEO_PREVIEW_SECONDS" -DefaultValue "18")
$previewHeight = [int](Get-EnvFileValueOrDefault -Name "FOLDER_VIDEO_PREVIEW_HEIGHT" -DefaultValue "360")

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
Stop-LegacyArtisanServer -Ports @($Port, $LaravelPort, $MediaStreamPort)
Stop-ProjectCaddy
Stop-ProjectMediaServer

$mediaArguments = @(
        "-u",
        $mediaServerScript,
        "--host=127.0.0.1",
        "--port=$MediaStreamPort",
        "--root=$MediaRoot",
        "--preview-queue=$previewQueuePath",
        "--preview-root=$previewCachePath",
        "--hls-queue=$hlsQueuePath",
        "--hls-root=$hlsCachePath",
        "--hls-segment-seconds=$hlsSegmentSeconds",
        "--ffmpeg=$ffmpegBin",
        "--preview-seconds=$previewSeconds",
        "--preview-height=$previewHeight"
    )
foreach ($hlsSourceRoot in @(
    $MediaRoot,
    $NasRoot30TA,
    $NasRoot30TB,
    $NasRootFhd,
    $NasRootFhdBack,
    $NasRootHome,
    $NasRootHomes,
    $NasRootPhoto,
    $NasRootPlexMediaServer,
    $NasRootVideo
)) {
    if (-not [string]::IsNullOrWhiteSpace($hlsSourceRoot)) {
        $mediaArguments += "--hls-source-root=$hlsSourceRoot"
    }
}

$mediaProcess = Start-Process -FilePath $pythonExe `
    -ArgumentList $mediaArguments `
    -WorkingDirectory $projectRoot `
    -RedirectStandardOutput $mediaStdoutLog `
    -RedirectStandardError $mediaStderrLog `
    -WindowStyle Hidden `
    -PassThru

if (-not (Wait-ForTcpPort -TargetHost "127.0.0.1" -Port $MediaStreamPort -TimeoutSeconds 30)) {
    Stop-ProcessByIdIfRunning -ProcessId $mediaProcess.Id
    throw "Folder Video media range server did not start listening on port $MediaStreamPort."
}

$laravelProcess = Start-Process -FilePath $phpExe `
    -ArgumentList @("artisan", "serve", "--host=127.0.0.1", "--port=$LaravelPort") `
    -WorkingDirectory $projectRoot `
    -RedirectStandardOutput $laravelStdoutLog `
    -RedirectStandardError $laravelStderrLog `
    -WindowStyle Hidden `
    -PassThru

if (-not (Wait-ForTcpPort -TargetHost "127.0.0.1" -Port $LaravelPort -TimeoutSeconds 30)) {
    Stop-ProcessByIdIfRunning -ProcessId $laravelProcess.Id
    Stop-ProcessByIdIfRunning -ProcessId $mediaProcess.Id
    throw "Laravel did not start listening on port $LaravelPort."
}

$configText = @"
{
    auto_https off
    admin off
    persist_config off
}

(nasViewerFiles) {
    header {
        Cache-Control "private, max-age=600"
        Accept-Ranges "bytes"
        X-Content-Type-Options "nosniff"
    }
    file_server
}

:$Port {
    bind $BindAddress
    encode zstd gzip

    @hlsLibrary path /vendor/hls.js/hls.min.js
    handle @hlsLibrary {
        root * "$projectRoot\public"
        header {
            Cache-Control "public, max-age=31536000, immutable"
            X-Content-Type-Options "nosniff"
        }
        file_server
    }

    @folderApp path /folder-video-app /folder-video-app/tv/android-version.json /folder-video-app/tv/folder-video-tv.apk
    handle @folderApp {
        rewrite * /index.php{path}
        reverse_proxy 127.0.0.1:$LaravelPort {
            header_up Host {host}
            header_up X-Forwarded-Host {host}
            header_up X-Forwarded-Proto {scheme}
            header_up X-Forwarded-Port $Port
        }
    }

    @folderPhotoApp path /folder-photo-app /folder-photo-app/version.json /folder-photo-app/android-version.json /folder-photo-app/folder-photo-app.apk /folder-photo-app/tv/android-version.json /folder-photo-app/tv/folder-photo-tv.apk
    handle @folderPhotoApp {
        rewrite * /index.php{path}
        reverse_proxy 127.0.0.1:$LaravelPort {
            header_up Host {host}
            header_up X-Forwarded-Host {host}
            header_up X-Forwarded-Proto {scheme}
            header_up X-Forwarded-Port $Port
        }
    }

    @nasViewerApp path /nas-viewer-app /nas-viewer-app/version.json /nas-viewer-app/android-version.json /nas-viewer-app/nas-viewer-app.apk /nas-viewer-app/tv/android-version.json /nas-viewer-app/tv/nas-viewer-tv.apk
    handle @nasViewerApp {
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
        reverse_proxy 127.0.0.1:$MediaStreamPort {
            flush_interval -1
        }
    }

    @videoPreviewCache path /folder-video-preview-cache/*
    handle @videoPreviewCache {
        reverse_proxy 127.0.0.1:$MediaStreamPort {
            flush_interval -1
        }
    }

    @videoHlsCache path /folder-video-tv-hls-cache/*
    handle @videoHlsCache {
        reverse_proxy 127.0.0.1:$MediaStreamPort {
            flush_interval -1
        }
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

    @nasMedia30TA path /nas-viewer-media/30t-a/*
    handle @nasMedia30TA {
        uri strip_prefix /nas-viewer-media/30t-a
        root * "$NasRoot30TA"
        import nasViewerFiles
    }

    @nasMedia30TB path /nas-viewer-media/30t-b/*
    handle @nasMedia30TB {
        uri strip_prefix /nas-viewer-media/30t-b
        root * "$NasRoot30TB"
        import nasViewerFiles
    }

    @nasMediaFhd path /nas-viewer-media/fhd/*
    handle @nasMediaFhd {
        uri strip_prefix /nas-viewer-media/fhd
        root * "$NasRootFhd"
        import nasViewerFiles
    }

    @nasMediaFhdBack path /nas-viewer-media/fhd-back/*
    handle @nasMediaFhdBack {
        uri strip_prefix /nas-viewer-media/fhd-back
        root * "$NasRootFhdBack"
        import nasViewerFiles
    }

    @nasMediaHome path /nas-viewer-media/home/*
    handle @nasMediaHome {
        uri strip_prefix /nas-viewer-media/home
        root * "$NasRootHome"
        import nasViewerFiles
    }

    @nasMediaHomes path /nas-viewer-media/homes/*
    handle @nasMediaHomes {
        uri strip_prefix /nas-viewer-media/homes
        root * "$NasRootHomes"
        import nasViewerFiles
    }

    @nasMediaPhoto path /nas-viewer-media/photo/*
    handle @nasMediaPhoto {
        uri strip_prefix /nas-viewer-media/photo
        root * "$NasRootPhoto"
        import nasViewerFiles
    }

    @nasMediaPlex path /nas-viewer-media/plex-media-server/*
    handle @nasMediaPlex {
        uri strip_prefix /nas-viewer-media/plex-media-server
        root * "$NasRootPlexMediaServer"
        import nasViewerFiles
    }

    @nasMediaVideo path /nas-viewer-media/video/*
    handle @nasMediaVideo {
        uri strip_prefix /nas-viewer-media/video
        root * "$NasRootVideo"
        import nasViewerFiles
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
    Stop-ProcessByIdIfRunning -ProcessId $mediaProcess.Id
    throw "Caddy did not start listening on port $Port."
}

# Keep the HTTP first page fast: refresh the NAS index after the service is
# already reachable instead of blocking the first app request on thousands of
# remote file stats.
$indexWarmProcess = Start-Process -FilePath $phpExe `
    -ArgumentList @("artisan", "folder-video:warm-cache") `
    -WorkingDirectory $projectRoot `
    -RedirectStandardOutput $indexWarmStdoutLog `
    -RedirectStandardError $indexWarmStderrLog `
    -WindowStyle Hidden `
    -PassThru

$lanIps = Get-LanIps

@{
    started_at = (Get-Date).ToString("o")
    caddy_pid = $caddyProcess.Id
    laravel_pid = $laravelProcess.Id
    media_stream_pid = $mediaProcess.Id
    index_warm_pid = $indexWarmProcess.Id
    port = $Port
    laravel_port = $LaravelPort
    media_stream_port = $MediaStreamPort
    bind_address = $BindAddress
    media_root = $MediaRoot
    photo_root = $PhotoRoot
    static_stream_path = "/folder-video-media"
    static_photo_path = "/folder-photo-media"
    nas_viewer_roots = @{
        "30t-a" = $NasRoot30TA
        "30t-b" = $NasRoot30TB
        "fhd" = $NasRootFhd
        "fhd-back" = $NasRootFhdBack
        "home" = $NasRootHome
        "homes" = $NasRootHomes
        "photo" = $NasRootPhoto
        "plex-media-server" = $NasRootPlexMediaServer
        "video" = $NasRootVideo
    }
    caddy_config = $caddyConfig
    caddy_stdout_log = $caddyStdoutLog
    caddy_stderr_log = $caddyStderrLog
    laravel_stdout_log = $laravelStdoutLog
    laravel_stderr_log = $laravelStderrLog
    media_stdout_log = $mediaStdoutLog
    media_stderr_log = $mediaStderrLog
    index_warm_stdout_log = $indexWarmStdoutLog
    index_warm_stderr_log = $indexWarmStderrLog
    lan_urls = @($lanIps | ForEach-Object { "http://$PSItem`:$Port/folder-video-app" })
} | ConvertTo-Json | Set-Content -Path $stateFile -Encoding UTF8

Write-Host "Folder Video is running on this computer."
foreach ($ip in $lanIps) {
    Write-Host "URL: http://$ip`:$Port/folder-video-app"
}
Write-Host "Media root: $MediaRoot"
Write-Host "Photo root: $PhotoRoot"
Write-Host "NAS Viewer URL: http://127.0.0.1`:$Port/nas-viewer-app"
Write-Host "Caddy PID: $($caddyProcess.Id)"
Write-Host "Laravel PID: $($laravelProcess.Id)"
Write-Host "Media stream PID: $($mediaProcess.Id)"
