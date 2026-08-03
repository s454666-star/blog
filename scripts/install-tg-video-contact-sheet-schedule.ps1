param(
    [string]$ProjectRoot = 'C:\Users\USER\Documents\project\blog',
    [string]$TaskName = 'TGVideoContactSheets-Every4Hours'
)

$ErrorActionPreference = 'Stop'

$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$runner = Join-Path $resolvedProject 'scripts\run-tg-video-contact-sheets.ps1'
if (-not (Test-Path -LiteralPath $runner -PathType Leaf)) {
    throw "找不到掃描腳本：$runner"
}

$powershell = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
if (-not (Test-Path -LiteralPath $powershell -PathType Leaf)) {
    throw "找不到 Windows PowerShell：$powershell"
}

$now = Get-Date
$nextHour = @(0, 4, 8, 12, 16, 20) |
    Where-Object { $now -lt $now.Date.AddHours($_) } |
    Select-Object -First 1
$firstRun = if ($null -eq $nextHour) {
    $now.Date.AddDays(1)
} else {
    $now.Date.AddHours($nextHour)
}

$arguments = '-NoLogo -NoProfile -ExecutionPolicy Bypass -File "{0}" -AutoClose' -f $runner
$action = New-ScheduledTaskAction `
    -Execute $powershell `
    -Argument $arguments `
    -WorkingDirectory $resolvedProject
$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At $firstRun `
    -RepetitionInterval (New-TimeSpan -Hours 4)
$principal = New-ScheduledTaskPrincipal `
    -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType Interactive `
    -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description '每四小時顯示 TG 暫存影片截圖進度；完成後自動關閉視窗。' `
    -Force | Out-Null

$task = Get-ScheduledTask -TaskName $TaskName
$info = $task | Get-ScheduledTaskInfo
[pscustomobject]@{
    TaskName = $task.TaskName
    State = $task.State
    NextRunTime = $info.NextRunTime
    IntervalHours = 4
    InteractiveWindow = $true
    AutoClose = $true
    MultipleInstances = $task.Settings.MultipleInstances
}
