$ErrorActionPreference = 'Stop'

$taskNames = @(
    'TG Token Scan Dispatch',
    'EnsureTgScanGroupMediaHourly',
    'Telegram FastAPI Service',
    'TG API2',
    'Telegram FastAPI Services',
    'Blog Get BT',
    'Project Log Cleanup',
    'CaddyServer',
    'LaravelBlogServer',
    'VideoHTTPServer',
    'Blog Folder Video API Caddy'
)

foreach ($taskName in $taskNames) {
    Write-Host ('=' * 80)
    Write-Host "Task: $taskName"

    try {
        schtasks.exe /Query /TN $taskName /V /FO LIST
    } catch {
        Write-Host "Task not found or cannot be queried: $taskName"
        Write-Host "Fallback: inspect live process / listener / wrapper chain for this task."
    }
}
