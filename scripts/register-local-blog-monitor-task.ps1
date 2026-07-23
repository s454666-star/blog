param(
    [string]$TaskName = 'Blog Local Environment'
)

$ErrorActionPreference = 'Stop'

$hiddenRunner = Join-Path $PSScriptRoot 'run-local-blog-monitor-hidden.vbs'
if (-not (Test-Path -LiteralPath $hiddenRunner)) {
    throw "Hidden local blog monitor runner not found: $hiddenRunner"
}

$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$wscriptPath = Join-Path $env:WINDIR 'System32\wscript.exe'
$action = New-ScheduledTaskAction `
    -Execute $wscriptPath `
    -Argument ('//B //Nologo "{0}"' -f $hiddenRunner) `
    -WorkingDirectory $PSScriptRoot
$triggers = @(
    New-ScheduledTaskTrigger -AtLogOn -User $identity
    New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
)
$principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -MultipleInstances IgnoreNew

$task = New-ScheduledTask `
    -Action $action `
    -Trigger $triggers `
    -Principal $principal `
    -Settings $settings `
    -Description 'Keep https://blog/ and PHP FastCGI healthy without showing a console window.'

Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName
Write-Host "Registered local blog monitor task: $TaskName"
