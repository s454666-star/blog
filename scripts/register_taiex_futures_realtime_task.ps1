$ErrorActionPreference = 'Stop'

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$hiddenRunnerPath = Join-Path $repoRoot 'scripts\run_taiex_futures_realtime_hidden.vbs'
$taskName = 'Blog Taiex Futures Realtime Redis'
$description = 'At Windows logon, run the local authenticated TradingView TXF1 realtime worker and write fresh quotes to Redis once per second while the market is open.'

if (-not (Test-Path -LiteralPath $hiddenRunnerPath)) {
    throw "Hidden runner not found: $hiddenRunnerPath"
}

$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument ('"{0}"' -f $hiddenRunnerPath)
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $currentUser
$settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -RestartCount 5 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description $description `
    -User $currentUser `
    -Force | Out-Null

Start-ScheduledTask -TaskName $taskName

$task = Get-ScheduledTask -TaskName $taskName
$taskInfo = Get-ScheduledTaskInfo -TaskName $taskName

[pscustomobject]@{
    TaskName = $task.TaskName
    State = $task.State
    LastRunTime = $taskInfo.LastRunTime
    LastTaskResult = $taskInfo.LastTaskResult
    Runner = $hiddenRunnerPath
}
