[CmdletBinding()]
param(
    [string]$TaskName = 'Blog Telegram Resource Code Worker',
    [string]$RunnerPath = (Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'Codex\local-tools\windows-hidden-runner\Run-Hidden.vbs')
)

$ErrorActionPreference = 'Stop'

$projectDir = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$launcher = Join-Path $projectDir 'scripts\run_telegram_resource_code_worker.ps1'
$powershellExe = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
$wscriptExe = Join-Path $env:WINDIR 'System32\wscript.exe'
$currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name

foreach ($requiredPath in @($launcher, $RunnerPath, $powershellExe, $wscriptExe)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Required file not found: $requiredPath"
    }
}

function ConvertTo-RunnerToken {
    param([AllowNull()][string]$Value)

    if ([string]::IsNullOrEmpty($Value)) {
        return '-'
    }

    return [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Value))
}

$powershellArguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "{0}"' -f $launcher
$runnerArguments = '//B //Nologo "{0}" {1} {2} {3}' -f $RunnerPath, (
    ConvertTo-RunnerToken $powershellExe), (
    ConvertTo-RunnerToken $powershellArguments), (
    ConvertTo-RunnerToken $projectDir)

$action = New-ScheduledTaskAction `
    -Execute $wscriptExe `
    -Argument $runnerArguments `
    -WorkingDirectory (Split-Path -Parent $RunnerPath)

$logonTrigger = New-ScheduledTaskTrigger -AtLogOn -User $currentUser
$watchdogTrigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -MultipleInstances IgnoreNew

$principal = New-ScheduledTaskPrincipal `
    -UserId $currentUser `
    -LogonType Interactive `
    -RunLevel Limited

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger @($logonTrigger, $watchdogTrigger) `
    -Settings $settings `
    -Principal $principal `
    -Description 'Runs one Telegram code worker, restarts it after failure, and ignores duplicate starts.' `
    -Force `
    -ErrorAction Stop | Out-Null

Start-ScheduledTask -TaskName $TaskName -ErrorAction Stop
Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
