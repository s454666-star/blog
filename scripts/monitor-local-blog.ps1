param(
    [int]$CheckIntervalSeconds = 10
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$startScript = Join-Path $PSScriptRoot 'start-local-blog.ps1'
$logDirectory = Join-Path $projectRoot 'storage\logs'
$logPath = Join-Path $logDirectory 'local-blog-monitor.log'
$healthUrl = 'https://blog/'
$mutex = [System.Threading.Mutex]::new($false, 'Local\BlogLocalEnvironmentMonitor')
$ownsMutex = $false

function Write-MonitorLog {
    param([string]$Message)

    New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
    if ((Test-Path -LiteralPath $logPath) -and (Get-Item -LiteralPath $logPath).Length -gt 1048576) {
        Move-Item -LiteralPath $logPath -Destination "$logPath.1" -Force
    }

    Add-Content -LiteralPath $logPath -Encoding UTF8 -Value "$(Get-Date -Format o) $Message"
}

function Test-Listener {
    param([int]$Port)

    return [bool](Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
}

function Test-BlogHealth {
    if (-not (Test-Listener -Port 443) -or -not (Test-Listener -Port 9000)) {
        return $false
    }

    try {
        $statusCode = & curl.exe `
            --silent `
            --show-error `
            --insecure `
            --output NUL `
            --write-out '%{http_code}' `
            --max-time 5 `
            $healthUrl
        return $LASTEXITCODE -eq 0 -and [int]$statusCode -ge 200 -and [int]$statusCode -lt 500
    } catch {
        return $false
    }
}

try {
    $ownsMutex = $mutex.WaitOne(0)
    if (-not $ownsMutex) {
        exit 0
    }

    Set-Location $projectRoot
    Write-MonitorLog 'Monitor started.'

    while ($true) {
        if (-not (Test-BlogHealth)) {
            try {
                Write-MonitorLog 'Health check failed. Starting local blog.'
                & $startScript

                if (Test-BlogHealth) {
                    Write-MonitorLog 'Local blog is healthy.'
                } else {
                    throw 'Local blog did not pass its health check after startup.'
                }
            } catch {
                Write-MonitorLog "Startup failed: $($_.Exception.Message)"
            }
        }

        Start-Sleep -Seconds ([Math]::Max(5, $CheckIntervalSeconds))
    }
} finally {
    if ($ownsMutex) {
        $mutex.ReleaseMutex()
    }
    $mutex.Dispose()
}
