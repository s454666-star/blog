$ErrorActionPreference = 'Stop'

$repoRoot = 'C:\www\blog'
$schedulerRegistrationScript = Join-Path $repoRoot 'scripts\register_laravel_scheduler_task.ps1'
$taskName = 'Blog Get BT'

if (-not (Test-Path -LiteralPath $schedulerRegistrationScript)) {
    throw "Scheduler registration script not found: $schedulerRegistrationScript"
}

Unregister-ScheduledTask -TaskName 'Blog Get BT 05AM' -Confirm:$false -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName 'Blog Get BT 05PM' -Confirm:$false -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue

Write-Host "Removed legacy task $taskName. Scheduling is now managed by App\Console\Kernel via Blog Laravel Scheduler."
& $schedulerRegistrationScript
