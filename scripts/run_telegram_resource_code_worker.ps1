[CmdletBinding()]
param(
    [int]$RestartDelaySeconds = 5
)

$ErrorActionPreference = 'Stop'

$projectDir = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$artisanPath = Join-Path $projectDir 'artisan'
$logPath = Join-Path $projectDir 'storage\logs\telegram_resource_code_worker.log'
$phpCommand = Get-Command php.exe -ErrorAction Stop
$mutex = [Threading.Mutex]::new($false, 'Global\BlogTelegramResourceCodeWorker')
$ownsMutex = $false

try {
    $ownsMutex = $mutex.WaitOne(0)
    if (-not $ownsMutex) {
        exit 0
    }

    if (-not (Test-Path -LiteralPath $artisanPath -PathType Leaf)) {
        throw "artisan not found: $artisanPath"
    }

    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $logPath) | Out-Null

    while ($true) {
        Add-Content -LiteralPath $logPath -Value ("{0} worker-start" -f (Get-Date -Format o)) -Encoding UTF8

        & $phpCommand.Source $artisanPath telegram:process-resource-codes *>> $logPath
        $exitCode = $LASTEXITCODE

        Add-Content -LiteralPath $logPath -Value ("{0} worker-exit code={1} restart_in={2}s" -f (Get-Date -Format o), $exitCode, $RestartDelaySeconds) -Encoding UTF8
        Start-Sleep -Seconds ([Math]::Max(1, $RestartDelaySeconds))
    }
} finally {
    if ($ownsMutex) {
        $mutex.ReleaseMutex()
    }
    $mutex.Dispose()
}
