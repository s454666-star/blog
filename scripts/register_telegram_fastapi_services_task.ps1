param(
    [string]$TaskName = 'Telegram FastAPI Services',
    [string]$LauncherPath = 'C:\www\blog\scripts\start_telegram_fastapi_services.vbs',
    [int]$RepeatMinutes = 5
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $LauncherPath)) {
    throw "Launcher script not found: $LauncherPath"
}

$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$taskCommand = 'wscript.exe "{0}"' -f $LauncherPath
$arguments = @(
    '/create'
    '/tn', $TaskName
    '/sc', 'minute'
    '/mo', [string]$RepeatMinutes
    '/tr', $taskCommand
    '/ru', $currentUser
    '/f'
)

$createOutput = & schtasks.exe @arguments 2>&1
if ($LASTEXITCODE -ne 0) {
    throw ("Failed to register task {0}: {1}" -f $TaskName, ($createOutput -join [Environment]::NewLine))
}

foreach ($legacyTaskName in @('Telegram FastAPI Service', 'TG API2')) {
    $null = & schtasks.exe /delete /tn $legacyTaskName /f 2>$null
}

Write-Host "Registered task: $TaskName"
Write-Host 'Removed duplicate tasks: Telegram FastAPI Service, TG API2'
