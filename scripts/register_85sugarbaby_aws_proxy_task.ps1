[CmdletBinding()]
param(
    [string] $TaskName = 'Blog 85Sugarbaby AWS Egress',
    [string] $HiddenRunnerPath = "$env:USERPROFILE\Documents\Codex\local-tools\windows-hidden-runner\Run-Hidden.vbs"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$monitorScript = Join-Path $PSScriptRoot 'run_85sugarbaby_aws_proxy.ps1'
if (-not (Test-Path -LiteralPath $monitorScript -PathType Leaf)) {
    throw "Proxy monitor script not found: $monitorScript"
}

if (-not (Test-Path -LiteralPath $HiddenRunnerPath -PathType Leaf)) {
    throw "Hidden runner not found: $HiddenRunnerPath"
}

function ConvertTo-Utf8Base64 {
    param([string] $Value)

    if ([string]::IsNullOrEmpty($Value)) {
        return '-'
    }

    return [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Value))
}

$projectRoot = Split-Path -Parent $PSScriptRoot
$powershellExecutable = "$env:WINDIR\System32\WindowsPowerShell\v1.0\powershell.exe"
$powershellArguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "{0}"' -f $monitorScript
$runnerArguments = '//B //Nologo "{0}" {1} {2} {3}' -f @(
    $HiddenRunnerPath,
    (ConvertTo-Utf8Base64 $powershellExecutable),
    (ConvertTo-Utf8Base64 $powershellArguments),
    (ConvertTo-Utf8Base64 $projectRoot)
)

$existingTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existingTask) {
    $backupDirectory = Join-Path $env:LOCALAPPDATA 'Codex\scheduled-task-backups'
    New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
    $safeTaskName = $TaskName -replace '[^A-Za-z0-9._-]', '_'
    $backupPath = Join-Path $backupDirectory ("{0}-{1}.xml" -f $safeTaskName, (Get-Date -Format 'yyyyMMdd-HHmmss'))
    Export-ScheduledTask -TaskName $TaskName | Set-Content -LiteralPath $backupPath -Encoding UTF8
}

$currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$action = New-ScheduledTaskAction `
    -Execute "$env:WINDIR\System32\wscript.exe" `
    -Argument $runnerArguments `
    -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $currentUser
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -MultipleInstances IgnoreNew `
    -RestartCount 10 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable

$task = New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings
Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName

$registered = Get-ScheduledTask -TaskName $TaskName
$info = Get-ScheduledTaskInfo -TaskName $TaskName
[pscustomobject]@{
    TaskName = $registered.TaskName
    State = $registered.State
    LastRunTime = $info.LastRunTime
    LastTaskResult = $info.LastTaskResult
    Execute = $registered.Actions.Execute
    Arguments = $registered.Actions.Arguments
}
