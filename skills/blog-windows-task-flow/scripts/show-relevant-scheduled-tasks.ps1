$ErrorActionPreference = 'Stop'

$taskNames = @(
    'TG Token Scan Dispatch',
    'EnsureTgScanGroupMediaHourly',
    'Telegram FastAPI Service',
    'TG API2',
    'Telegram FastAPI Services',
    'Blog Get BT',
    'Blog Telegram Filestore Local Workers Logon',
    'Blog Telegram Filestore Local Workers Watchdog',
    'Project Log Cleanup',
    'CaddyServer',
    'LaravelBlogServer',
    'VideoHTTPServer',
    'Blog Folder Video API Caddy'
)

foreach ($taskName in $taskNames) {
    Write-Host ('=' * 80)
    Write-Host "Task: $taskName"

    $escapedTaskName = $taskName.Replace('"', '""')
    $output = & cmd.exe /d /c "schtasks.exe /Query /TN ""$escapedTaskName"" /V /FO LIST 2>&1"
    $exitCode = $LASTEXITCODE

    if ($exitCode -eq 0) {
        $output | ForEach-Object { Write-Host $_ }
        continue
    }

    $output | ForEach-Object { Write-Host $_ }
    Write-Host "Task not found or cannot be queried: $taskName"
    Write-Host "Fallback: inspect live process, listener, wrapper chain, and cloudflared/Caddy state for this task."
}
