$ErrorActionPreference = 'Stop'

$repoRoot = 'C:\www\blog'
$batchPath = Join-Path $repoRoot 'scripts\run_get_bt.bat'
$taskName = 'Blog Get BT'

if (-not (Test-Path -LiteralPath $batchPath)) {
    throw "Batch file not found: $batchPath"
}

$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$action = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument ('/c "{0}"' -f $batchPath)
$triggerMorning = New-ScheduledTaskTrigger -Daily -At '05:00'
$triggerEvening = New-ScheduledTaskTrigger -Daily -At '17:00'
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew

Unregister-ScheduledTask -TaskName 'Blog Get BT 05AM' -Confirm:$false -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName 'Blog Get BT 05PM' -Confirm:$false -ErrorAction SilentlyContinue

Write-Host "Registering task $taskName with triggers at 05:00 and 17:00"
Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger @($triggerMorning, $triggerEvening) `
    -Settings $settings `
    -User $currentUser `
    -Force | Out-Null

Write-Host ''
Write-Host 'Current task definitions:'
Get-ScheduledTask -TaskName $taskName | Format-List TaskName,State,Author,Description

Write-Host ''
Write-Host 'Triggers:'
Get-ScheduledTask -TaskName $taskName |
    Select-Object -ExpandProperty Triggers |
    Select-Object @{Name = 'StartBoundary'; Expression = { $_.StartBoundary } }, Enabled |
    Format-Table -AutoSize

Write-Host ''
Write-Host 'Action:'
Get-ScheduledTask -TaskName $taskName |
    Select-Object -ExpandProperty Actions |
    Select-Object Execute, Arguments, WorkingDirectory |
    Format-Table -AutoSize
