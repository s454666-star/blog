param(
    [string]$StartupTaskName = "Blog Telegram Filestore Local Workers Startup",
    [string]$WatchdogTaskName = "Blog Telegram Filestore Local Workers Watchdog",
    [string]$StartupWrapperPath = "C:\www\blog\scripts\start_telegram_filestore_local_workers.bat",
    [string]$WatchdogWrapperPath = "C:\www\blog\scripts\watchdog_telegram_filestore_local_workers.bat",
    [int]$MinWorkerCount = 16,
    [int]$MaxWorkerCount = 200,
    [int]$WatchdogIntervalMinutes = 5
)

$ErrorActionPreference = "Stop"

$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

$startupAction = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$StartupWrapperPath`""
$startupTrigger = New-ScheduledTaskTrigger -AtStartup

Register-ScheduledTask `
    -TaskName $StartupTaskName `
    -Action $startupAction `
    -Trigger $startupTrigger `
    -Principal $principal `
    -Description "Autoscale local telegram_filestore queue workers at system startup with min $MinWorkerCount and max $MaxWorkerCount." `
    -Force | Out-Null

$watchdogAction = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$WatchdogWrapperPath`""
$watchdogTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date)
$watchdogTrigger.Repetition.Interval = (New-TimeSpan -Minutes $WatchdogIntervalMinutes)
$watchdogTrigger.Repetition.Duration = (New-TimeSpan -Days 3650)

Register-ScheduledTask `
    -TaskName $WatchdogTaskName `
    -Action $watchdogAction `
    -Trigger $watchdogTrigger `
    -Principal $principal `
    -Description "Watchdog for autoscaled local telegram_filestore queue workers; checks every $WatchdogIntervalMinutes minutes, keeps at least $MinWorkerCount workers, and scales up to $MaxWorkerCount based on queue load." `
    -Force | Out-Null

Write-Host "Registered startup task: $StartupTaskName"
Write-Host "Registered watchdog task: $WatchdogTaskName"
