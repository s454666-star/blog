param(
    [int]$Port = 8090
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$stateDir = Join-Path $projectRoot "storage\app\folder-video-server"
$stateFile = Join-Path $stateDir "state.json"

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
    } catch {
    }

    Remove-Item $stateFile -Force -ErrorAction SilentlyContinue
}

$artisanProcesses = Get-CimInstance Win32_Process | Where-Object {
    $_.CommandLine -match "artisan serve" -and $_.CommandLine -match "--port=$Port"
}

foreach ($process in $artisanProcesses) {
    Stop-ProcessByIdIfRunning -ProcessId $process.ProcessId
}

$caddyProcesses = Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq "caddy.exe" -and $_.CommandLine -match [regex]::Escape($projectRoot)
}

foreach ($process in $caddyProcesses) {
    Stop-ProcessByIdIfRunning -ProcessId $process.ProcessId
}

Write-Host "Stopped folder video API processes on port $Port."
