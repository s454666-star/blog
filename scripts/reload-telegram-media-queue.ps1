$ErrorActionPreference = 'Stop'

$taskName = 'TelegramMediaQueueMonitor-s4546666'
$appDirectory = 'C:\Users\USER\AppData\Local\tdl-monitor'
$workerPattern = 'C:\\Users\\USER\\AppData\\Local\\tdl-monitor\\telegram_media_queue\.py'

Stop-ScheduledTask -TaskName $taskName
$workers = @(Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'python.exe' -and $_.CommandLine -match $workerPattern
})
$workerIds = @($workers | Select-Object -ExpandProperty ProcessId)
$tdlChildren = @(Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'tdl.exe' -and $workerIds -contains $_.ParentProcessId
})

foreach ($process in $tdlChildren) {
    Stop-Process -Id $process.ProcessId -Force
}
foreach ($process in $workers) {
    Stop-Process -Id $process.ProcessId -Force
}

Start-Sleep -Seconds 1
$removed = 0
foreach ($directory in @(Get-ChildItem -LiteralPath $appDirectory -Directory -Filter 'tdl-item-*')) {
    $resolved = $directory.FullName
    $insideApp = $resolved.StartsWith($appDirectory + '\', [StringComparison]::OrdinalIgnoreCase)
    if (-not $insideApp -or -not $directory.Name.StartsWith('tdl-item-')) {
        throw "Refusing to remove an unexpected directory: $resolved"
    }
    Remove-Item -LiteralPath $resolved -Recurse -Force
    $removed++
}

Start-ScheduledTask -TaskName $taskName
Start-Sleep -Seconds 5

[pscustomobject]@{
    RemovedGeneratedPartialDirectories = $removed
    TaskState = [string](Get-ScheduledTask -TaskName $taskName).State
    WorkerCount = @(Get-CimInstance Win32_Process | Where-Object {
        $_.Name -eq 'python.exe' -and $_.CommandLine -match $workerPattern
    }).Count
} | ConvertTo-Json -Compress
