[CmdletBinding()]
param(
    [switch]$Start
)

$ErrorActionPreference = 'Stop'

$taskName = 'TelegramMediaQueueMonitor-s4546666'
$appDirectory = 'C:\Users\USER\AppData\Local\tdl-monitor'
$workerPath = Join-Path $appDirectory 'telegram_media_queue.py'
$runnerPath = Join-Path $appDirectory 'hidden-runner\Run-Hidden.vbs'
$backupDirectory = Join-Path $appDirectory 'task-backups'
$converterPath = 'C:\Users\USER\.codex\skills\windows-background-no-console\scripts\Convert-ScheduledTaskToHidden.ps1'
$pythonPath = (Get-Command python -ErrorAction Stop).Source
$userId = $env:USERDOMAIN + '\' + $env:USERNAME

foreach ($requiredPath in @($workerPath, $runnerPath, $converterPath, $pythonPath)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Required local component is missing: $requiredPath"
    }
}

$action = New-ScheduledTaskAction `
    -Execute $pythonPath `
    -Argument ('-u "{0}"' -f $workerPath) `
    -WorkingDirectory $appDirectory
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $userId
$principal = New-ScheduledTaskPrincipal `
    -UserId $userId `
    -LogonType Interactive `
    -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 5) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -StartWhenAvailable `
    -DontStopIfGoingOnBatteries `
    -AllowStartIfOnBatteries

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Privacy-safe sequential Telegram video download and image forwarding monitor.' `
    -Force | Out-Null

$converted = & $converterPath `
    -TaskName $taskName `
    -RunnerPath $runnerPath `
    -BackupDirectory $backupDirectory

if ($Start) {
    Start-ScheduledTask -TaskName $taskName
    Start-Sleep -Seconds 3
}

$task = Get-ScheduledTask -TaskName $taskName
$taskInfo = Get-ScheduledTaskInfo -TaskName $taskName
[pscustomobject]@{
    TaskName = $taskName
    State = [string]$task.State
    LastTaskResult = $taskInfo.LastTaskResult
    ActionExecute = $task.Actions[0].Execute
    Wrapped = $task.Actions[0].Arguments -like '*Run-Hidden.vbs*'
    TriggerCount = @($task.Triggers).Count
    Backup = $converted.Backup
    RunnerSha256 = (Get-FileHash -LiteralPath $runnerPath -Algorithm SHA256).Hash
} | ConvertTo-Json -Compress
