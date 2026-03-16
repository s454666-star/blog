$ErrorActionPreference = 'Stop'

$taskNames = @(
    'TG Token Scan Dispatch',
    'EnsureTgScanGroupMediaHourly',
    'Telegram FastAPI Service',
    'TG API2',
    'Telegram FastAPI Services',
    'Blog Get BT'
)

foreach ($taskName in $taskNames) {
    Write-Host ('=' * 80)
    Write-Host "Task: $taskName"

    try {
        schtasks.exe /Query /TN $taskName /V /FO LIST
    } catch {
        Write-Host "Task not found or cannot be queried: $taskName"
    }
}
