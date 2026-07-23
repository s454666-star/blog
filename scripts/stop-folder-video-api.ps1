param(
    [int]$Port = 8090
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$stateDir = Join-Path $projectRoot "storage\app\folder-video-server"
$stateFile = Join-Path $stateDir "state.json"
$caddyConfig = Join-Path $stateDir "Caddyfile"

function Stop-ProcessByIdIfRunning {
    param([int]$ProcessId)

    if ($ProcessId -le 0) {
        return
    }

    Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue
}

if (Test-Path $stateFile) {
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

$artisanProcesses = Get-CimInstance Win32_Process | Where-Object {
    $commandLine = $_.CommandLine
    $isProjectServer = $commandLine -match [regex]::Escape((Join-Path $projectRoot "server.php"))
    ($commandLine -match "artisan serve" -or $isProjectServer) -and (
        $commandLine -match "--port=$Port" -or
        $commandLine -match "--port=8091" -or
        $commandLine -match "\s-S\s+\S+:8091(?:\s|$)"
    )
}

foreach ($process in $artisanProcesses) {
    Stop-ProcessByIdIfRunning -ProcessId $process.ProcessId
}

$caddyProcesses = Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq "caddy.exe" -and
    $_.CommandLine -match [regex]::Escape($caddyConfig)
}

foreach ($process in $caddyProcesses) {
    Stop-ProcessByIdIfRunning -ProcessId $process.ProcessId
}

$mediaServerScript = Join-Path $projectRoot "scripts\folder_video_range_server.py"
$mediaProcesses = Get-CimInstance Win32_Process | Where-Object {
    $_.Name -in @("python.exe", "pythonw.exe") -and
    $_.CommandLine -match [regex]::Escape($mediaServerScript)
}

foreach ($process in $mediaProcesses) {
    Stop-ProcessByIdIfRunning -ProcessId $process.ProcessId
}

Write-Host "Stopped folder video API processes on port $Port."
