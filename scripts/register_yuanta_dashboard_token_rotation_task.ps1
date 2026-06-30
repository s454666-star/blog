$ErrorActionPreference = 'Stop'

$repoRoot = 'C:\www\blog'
$batchPath = Join-Path $repoRoot 'scripts\run_yuanta_dashboard_token_rotation.bat'
$hiddenRunnerPath = Join-Path $repoRoot 'scripts\run_hidden_task.vbs'
$taskName = 'Blog Yuanta Dashboard Token Rotation'
$description = 'Rotate the private Yuanta portfolio dashboard token daily, sync local/AWS env values, and send the URL to the Yuanta LINE group.'

if (-not (Test-Path -LiteralPath $batchPath)) {
    throw "Batch file not found: $batchPath"
}

if (-not (Test-Path -LiteralPath $hiddenRunnerPath)) {
    throw "Hidden task runner not found: $hiddenRunnerPath"
}

$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument ('"{0}" "{1}" "{2}"' -f $hiddenRunnerPath, $batchPath, $repoRoot)
$trigger = New-ScheduledTaskTrigger -Daily -At '08:05'
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable

Write-Host "Registering task $taskName to run daily at 08:05"
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
    Select-Object StartBoundary, Enabled |
    Format-Table -AutoSize

Write-Host ''
Write-Host 'Action:'
Get-ScheduledTask -TaskName $taskName |
    Select-Object -ExpandProperty Actions |
    Select-Object Execute, Arguments, WorkingDirectory |
    Format-Table -AutoSize
