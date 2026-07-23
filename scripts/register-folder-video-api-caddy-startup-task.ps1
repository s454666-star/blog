param(
    [string]$TaskName = "Blog Folder Video API Caddy",
    [string]$HiddenRunnerPath = (Join-Path ([Environment]::GetFolderPath("MyDocuments")) "Codex\local-tools\windows-hidden-runner\Run-Hidden.vbs")
)

$ErrorActionPreference = "Stop"

$monitorPath = Join-Path $PSScriptRoot "monitor-folder-video-server.ps1"

if (-not (Test-Path -LiteralPath $monitorPath)) {
    throw "Folder Video monitor not found: $monitorPath"
}
function ConvertTo-RunnerToken {
    param([AllowNull()][string]$Value)

    if ([string]::IsNullOrEmpty($Value)) {
        return "-"
    }

    return [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Value))
}

$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$powershellPath = Join-Path $env:WINDIR "System32\WindowsPowerShell\v1.0\powershell.exe"
$wscriptPath = Join-Path $env:WINDIR "System32\wscript.exe"
$workingDirectory = Split-Path -Parent $PSScriptRoot
$bundledHiddenRunner = Join-Path $PSScriptRoot "run-folder-video-monitor-hidden.vbs"
$actionArguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "{0}"' -f $monitorPath
if (Test-Path -LiteralPath $HiddenRunnerPath) {
    $hiddenArguments = '//B //Nologo "{0}" {1} {2} {3}' -f $HiddenRunnerPath, `
        (ConvertTo-RunnerToken $powershellPath), `
        (ConvertTo-RunnerToken $actionArguments), `
        (ConvertTo-RunnerToken $workingDirectory)
    $action = New-ScheduledTaskAction `
        -Execute $wscriptPath `
        -Argument $hiddenArguments `
        -WorkingDirectory (Split-Path -Parent $HiddenRunnerPath)
} else {
    if (-not (Test-Path -LiteralPath $bundledHiddenRunner)) {
        throw "Bundled hidden task runner not found: $bundledHiddenRunner"
    }
    $action = New-ScheduledTaskAction `
        -Execute $wscriptPath `
        -Argument ('//B //Nologo "{0}"' -f $bundledHiddenRunner) `
        -WorkingDirectory $PSScriptRoot
}
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
    -Description "Start Folder Video after Windows sign-in and recover it after failures."

Register-ScheduledTask `
    -TaskName $TaskName `
    -InputObject $task `
    -Force

Start-ScheduledTask -TaskName $TaskName
Write-Host "Registered startup task: $TaskName"
