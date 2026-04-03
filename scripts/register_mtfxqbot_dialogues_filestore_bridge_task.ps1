$ErrorActionPreference = 'Stop'

$repoRoot = 'C:\www\blog'
$batchPath = Join-Path $repoRoot 'scripts\ensure_mtfxqbot_dialogues_filestore_bridge.bat'
$hiddenRunnerPath = Join-Path $repoRoot 'scripts\run_hidden_task.ps1'
$taskName = 'EnsureMtfxqbotDialogueFilestoreBridge'

if (-not (Test-Path -LiteralPath $batchPath)) {
    throw "Batch file not found: $batchPath"
}

if (-not (Test-Path -LiteralPath $hiddenRunnerPath)) {
    throw "Hidden task runner not found: $hiddenRunnerPath"
}

$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -BatchPath "{1}"' -f $hiddenRunnerPath, $batchPath)
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Hours 2)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew

Write-Host "Registering task $taskName to check every 2 hours"
Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description 'Every 2 hours, ensure the mtfxqbot dialogues to @filestoebot bridge command is running.' `
    -User $currentUser `
    -Force | Out-Null

Write-Host ''
Write-Host 'Current task definition:'
Get-ScheduledTask -TaskName $taskName | Format-List TaskName,State,Author,Description

Write-Host ''
Write-Host 'Triggers:'
Get-ScheduledTask -TaskName $taskName |
    Select-Object -ExpandProperty Triggers |
    Select-Object @{Name = 'StartBoundary'; Expression = { $_.StartBoundary } }, @{Name = 'Interval'; Expression = { $_.Repetition.Interval } }, @{Name = 'Duration'; Expression = { $_.Repetition.Duration } }, Enabled |
    Format-Table -AutoSize

Write-Host ''
Write-Host 'Action:'
Get-ScheduledTask -TaskName $taskName |
    Select-Object -ExpandProperty Actions |
    Select-Object Execute, Arguments, WorkingDirectory |
    Format-Table -AutoSize
