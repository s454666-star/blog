param(
    [int]$Port = 8090,
    [int]$CheckIntervalSeconds = 20,
    [int]$FailureThreshold = 2
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$startScript = Join-Path $PSScriptRoot "start-folder-video-api.ps1"
$stateDir = Join-Path $projectRoot "storage\app\folder-video-server"
$logPath = Join-Path $stateDir "supervisor.log"
$healthUrl = "http://127.0.0.1:$Port/api/folder-videos/app-config"
$mutex = [System.Threading.Mutex]::new($false, "Local\BlogFolderVideoServerMonitor")
$ownsMutex = $false

function Get-EnvFileValue {
    param([string]$Name)

    $envPath = Join-Path $projectRoot ".env"
    if (-not (Test-Path -LiteralPath $envPath)) {
        return ""
    }

    $pattern = "^\s*$([regex]::Escape($Name))\s*=\s*(.*)\s*$"
    foreach ($line in Get-Content -LiteralPath $envPath -Encoding UTF8) {
        if ($line -match $pattern) {
            return $Matches[1].Trim().Trim('"').Trim("'")
        }
    }

    return ""
}

$mediaRoot = Get-EnvFileValue -Name "FOLDER_VIDEO_ROOT"
$mediaShare = Get-EnvFileValue -Name "FOLDER_VIDEO_DRIVE_SHARE"

function Write-SupervisorLog {
    param([string]$Message)

    New-Item -ItemType Directory -Force -Path $stateDir | Out-Null
    if ((Test-Path -LiteralPath $logPath) -and (Get-Item -LiteralPath $logPath).Length -gt 1048576) {
        Move-Item -LiteralPath $logPath -Destination "$logPath.1" -Force
    }

    Add-Content -LiteralPath $logPath -Encoding UTF8 -Value "$(Get-Date -Format o) $Message"
}

function Test-FolderVideoHealth {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $healthUrl -TimeoutSec 8
        return $response.StatusCode -eq 200
    } catch {
        return $false
    }
}

function Test-MediaRoot {
    if ([string]::IsNullOrWhiteSpace($mediaRoot)) {
        return $false
    }

    try {
        return [bool](Test-Path -LiteralPath $mediaRoot -PathType Container -ErrorAction Stop)
    } catch {
        return $false
    }
}

function Restore-MediaDrive {
    if ([string]::IsNullOrWhiteSpace($mediaShare)) {
        return
    }

    $driveRoot = [System.IO.Path]::GetPathRoot($mediaRoot)
    if ([string]::IsNullOrWhiteSpace($driveRoot) -or $driveRoot -notmatch "^[A-Za-z]:\\$") {
        return
    }

    & net.exe use $driveRoot.TrimEnd("\") $mediaShare /persistent:yes 2>&1 | Out-Null
}

try {
    $ownsMutex = $mutex.WaitOne(0)
    if (-not $ownsMutex) {
        exit 0
    }

    Set-Location $projectRoot
    Write-SupervisorLog "Supervisor started."
    $consecutiveFailures = $FailureThreshold

    while ($true) {
        $mediaHealthy = Test-MediaRoot
        if (-not $mediaHealthy) {
            Restore-MediaDrive
            $mediaHealthy = Test-MediaRoot
        }

        if ((Test-FolderVideoHealth) -and $mediaHealthy) {
            $consecutiveFailures = 0
        } else {
            $consecutiveFailures++
            if ($consecutiveFailures -ge $FailureThreshold) {
                try {
                    Write-SupervisorLog "Health check failed. Starting Folder Video."
                    & $startScript -Port $Port -MediaWaitSeconds 120

                    if (Test-FolderVideoHealth) {
                        Write-SupervisorLog "Folder Video is healthy."
                        $consecutiveFailures = 0
                    } else {
                        throw "Folder Video did not pass its health check after startup."
                    }
                } catch {
                    Write-SupervisorLog "Startup failed: $($_.Exception.Message)"
                    $consecutiveFailures = $FailureThreshold
                }
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
