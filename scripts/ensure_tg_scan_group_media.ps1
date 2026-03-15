$ErrorActionPreference = 'Stop'

$appDir = 'C:\www\blog'
$batchPath = Join-Path $appDir 'scripts\tg_scan_group_media.bat'
$logPath = Join-Path $appDir 'storage\logs\ensure_tg_scan_group_media.log'

function Write-Log {
    param(
        [string] $Message
    )

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $line = "[${timestamp}] $Message"
    Add-Content -Path $logPath -Value $line
}

if (-not (Test-Path -LiteralPath $batchPath)) {
    Write-Log "batch_missing path=$batchPath"
    exit 1
}

$running = Get-CimInstance Win32_Process | Where-Object {
    (
        $_.Name -ieq 'cmd.exe' -and
        ($_.CommandLine -like '*\scripts\tg_scan_group_media.bat*') -and
        ($_.CommandLine -notlike '*ensure_tg_scan_group_media.bat*')
    ) -or
    (
        $_.Name -ieq 'php.exe' -and
        ($_.CommandLine -like '*artisan tg:scan-group-media*')
    )
}

if ($running) {
    $pids = ($running | Select-Object -ExpandProperty ProcessId) -join ','
    Write-Log "already_running pids=$pids"
    exit 0
}

Start-Process -FilePath 'cmd.exe' -ArgumentList '/c', $batchPath -WorkingDirectory $appDir
Write-Log "started batch=$batchPath"
exit 0
