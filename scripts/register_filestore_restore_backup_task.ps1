$ErrorActionPreference = 'Stop'

$repoRoot = 'C:\www\blog'
$schedulerRegistrationScript = Join-Path $repoRoot 'scripts\register_laravel_scheduler_task.ps1'
$taskName = 'Blog Telegram Filestore Restore Backup'

if (-not (Test-Path -LiteralPath $schedulerRegistrationScript)) {
    throw "Scheduler registration script not found: $schedulerRegistrationScript"
}

Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue

Write-Host "Removed legacy task $taskName. Scheduling is now managed by App\Console\Kernel via Blog Laravel Scheduler."
& $schedulerRegistrationScript
