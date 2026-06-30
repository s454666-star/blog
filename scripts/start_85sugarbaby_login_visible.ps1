Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$php = 'C:\php\php.exe'
$logDir = Join-Path $projectRoot 'storage\logs'
$logFile = Join-Path $logDir 'crawler_85sugarbaby_login_task.log'

if (-not (Test-Path -LiteralPath $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

function Write-TaskLog {
    param([string] $Message)
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -LiteralPath $logFile -Value "[$stamp] $Message"
}

Write-TaskLog 'Preparing visible 85sugarbaby login session refresh.'

$currentPid = $PID
$patterns = @(
    '*crawler:85sugarbaby-import*',
    '*crawler:85sugarbaby-login*',
    '*google_login_crawler_probe*',
    '*google-login-crawler*'
)

Get-CimInstance Win32_Process |
    Where-Object {
        $commandLine = [string] $_.CommandLine
        $matched = $false
        foreach ($pattern in $patterns) {
            if ($commandLine -like $pattern) {
                $matched = $true
                break
            }
        }

        $_.ProcessId -ne $currentPid -and
        $_.Name -in @('chrome.exe', 'node.exe', 'php.exe', 'cmd.exe') -and
        $matched
    } |
    ForEach-Object {
        try {
            Write-TaskLog "Stopping stale crawler process $($_.Name) pid=$($_.ProcessId)."
            Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop
        } catch {
            Write-TaskLog "Failed to stop pid=$($_.ProcessId): $($_.Exception.Message)"
        }
    }

if (-not (Test-Path -LiteralPath $php)) {
    Write-TaskLog "PHP executable not found: $php"
    exit 1
}

Write-TaskLog 'Starting visible crawler login command.'
Start-Process -FilePath $php `
    -ArgumentList @('artisan', 'crawler:85sugarbaby-login', '--timeout=300') `
    -WorkingDirectory $projectRoot `
    -WindowStyle Normal

Write-TaskLog 'Visible crawler login command launched.'
