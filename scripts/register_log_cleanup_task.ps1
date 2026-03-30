$ErrorActionPreference = 'Stop'

$repoRoot = 'C:\www\blog'
$batchPath = Join-Path $repoRoot 'scripts\clear_project_logs.bat'
$taskName = 'Project Log Cleanup'

if (-not (Test-Path -LiteralPath $batchPath)) {
    throw "Batch file not found: $batchPath"
}

$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$action = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument ('/c "{0}"' -f $batchPath)
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
    -Description 'Delete all .log files under C:\www\blog\storage\logs every day at 07:00.' `
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
