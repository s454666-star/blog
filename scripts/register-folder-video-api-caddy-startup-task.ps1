param(
    [string]$TaskName = "Blog Folder Video API Caddy",
    [string]$WrapperPath = "C:\www\blog\scripts\start-folder-video-api.bat"
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$hiddenRunnerPath = Join-Path $repoRoot 'scripts\run_hidden_task.ps1'

if (-not (Test-Path -LiteralPath $hiddenRunnerPath)) {
    throw "Hidden task runner not found: $hiddenRunnerPath"
}

$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -BatchPath "{1}"' -f $hiddenRunnerPath, $WrapperPath)
$trigger = New-ScheduledTaskTrigger -AtStartup

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Description "Start the folder video LAN API through Caddy at system startup." `
    -Force

Write-Host "Registered startup task: $TaskName"
