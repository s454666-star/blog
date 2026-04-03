$ErrorActionPreference = 'Stop'

$appDir = 'C:\www\blog'
$batchPath = Join-Path $appDir 'scripts\run_mtfxqbot_dialogues_filestore_bridge.bat'
$logPath = Join-Path $appDir 'storage\logs\ensure_mtfxqbot_dialogues_filestore_bridge.log'

function Write-Log {
    param(
        [string] $Message
    )

    $logDir = Split-Path -Path $logPath -Parent
    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -Path $logPath -Value "[${timestamp}] $Message"
}

if (-not (Test-Path -LiteralPath $batchPath)) {
    Write-Log "batch_missing path=$batchPath"
    exit 1
}

$running = Get-CimInstance Win32_Process | Where-Object {
    (
        $_.Name -ieq 'cmd.exe' -and
        ($_.CommandLine -like '*\scripts\run_mtfxqbot_dialogues_filestore_bridge.bat*')
    ) -or
    (
        $_.Name -ieq 'php.exe' -and
        ($_.CommandLine -like '*artisan filestore:bridge-dialogues-tokens*') -and
        ($_.CommandLine -like '*--prefix=mtfxqbot_*')
    )
}

if ($running) {
    $pids = ($running | Select-Object -ExpandProperty ProcessId) -join ','
    Write-Log "already_running pids=$pids"
    exit 0
}

$process = Start-Process -FilePath $env:ComSpec -ArgumentList '/d', '/c', "`"$batchPath`"" -WorkingDirectory $appDir -WindowStyle Hidden -PassThru
Write-Log "started batch=$batchPath pid=$($process.Id)"
exit 0
