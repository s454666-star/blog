$ErrorActionPreference = 'Stop'

$repoRoot = 'C:\www\blog'
$batchPath = Join-Path $repoRoot 'scripts\clear_project_logs.bat'
$hiddenRunnerPath = Join-Path $repoRoot 'scripts\run_hidden_task.ps1'
$taskName = 'Project Log Cleanup'
$description = 'Run C:\www\blog\scripts\clear_project_logs.bat every day at 07:00 to delete .log files under storage\logs and truncate locked logs when needed.'

if (-not (Test-Path -LiteralPath $batchPath)) {
    throw "Batch file not found: $batchPath"
}

if (-not (Test-Path -LiteralPath $hiddenRunnerPath)) {
    throw "Hidden task runner not found: $hiddenRunnerPath"
}

$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -BatchPath "{1}"' -f $hiddenRunnerPath, $batchPath)
$trigger = New-ScheduledTaskTrigger -Daily -At '07:00'
$trigger.Repetition = $null
$trigger.DaysInterval = 1
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew

Write-Host "Registering task $taskName to run daily at 07:00"
Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description $description `
    -User $currentUser `
    -Force | Out-Null

Write-Host ''
Write-Host 'Current task definition:'
Get-ScheduledTask -TaskName $taskName | Format-List TaskName,State,Author,Description

Write-Host ''
Write-Host 'Triggers:'
Get-ScheduledTask -TaskName $taskName |
    Select-Object -ExpandProperty Triggers |
    Select-Object @{Name = 'StartBoundary'; Expression = { $_.StartBoundary } }, @{Name = 'DaysInterval'; Expression = { $_.DaysInterval } }, Enabled |
    Format-Table -AutoSize

Write-Host ''
Write-Host 'Action:'
Get-ScheduledTask -TaskName $taskName |
    Select-Object -ExpandProperty Actions |
    Select-Object Execute, Arguments, WorkingDirectory |
    Format-Table -AutoSize
