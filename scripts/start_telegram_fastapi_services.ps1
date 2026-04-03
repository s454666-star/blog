param(
    [string]$BaseDir = 'C:\Users\User\Pictures\train',
    [int]$PrimaryPort = 8000,
    [int]$SecondaryPort = 8001
)

$ErrorActionPreference = 'Stop'

function Test-PortListening {
    param(
        [int]$TargetPort
    )

    try {
        return [bool](Get-NetTCPConnection -LocalPort $TargetPort -State Listen -ErrorAction Stop)
    } catch {
        return $false
    }
}

function Wait-ForTcpPort {
    param(
        [int]$TargetPort,
        [int]$TimeoutSeconds = 20
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-PortListening -TargetPort $TargetPort) {
            return $true
        }

        Start-Sleep -Milliseconds 300
    }

    return $false
}

function Ensure-TelegramService {
    param(
        [string]$RuntimeBaseDir,
        [string]$ModuleName,
        [int]$Port
    )

    $pythonExe = Join-Path $RuntimeBaseDir 'venv\Scripts\python.exe'
    $modulePath = Join-Path $RuntimeBaseDir ("{0}.py" -f $ModuleName)
    $stdoutLog = Join-Path $RuntimeBaseDir ("logs\telegram_service_{0}.stdout.log" -f $Port)
    $stderrLog = Join-Path $RuntimeBaseDir ("logs\telegram_service_{0}.stderr.log" -f $Port)

    if (-not (Test-Path -LiteralPath $pythonExe)) {
        throw "Python executable not found: $pythonExe"
    }

    if (-not (Test-Path -LiteralPath $modulePath)) {
        throw "Telegram service wrapper not found: $modulePath"
    }

    if (Test-PortListening -TargetPort $Port) {
        return
    }

    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $stdoutLog) | Out-Null

    $process = Start-Process `
        -FilePath $pythonExe `
        -ArgumentList @('-m', 'uvicorn', "$ModuleName`:app", '--host', '0.0.0.0', '--port', [string]$Port) `
        -WorkingDirectory $RuntimeBaseDir `
        -RedirectStandardOutput $stdoutLog `
        -RedirectStandardError $stderrLog `
        -WindowStyle Hidden `
        -PassThru

    if (-not (Wait-ForTcpPort -TargetPort $Port)) {
        try {
            Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
        } catch {
        }

        throw "Telegram FastAPI module $ModuleName did not open port $Port."
    }
}

Ensure-TelegramService -RuntimeBaseDir $BaseDir -ModuleName 'telegram_service' -Port $PrimaryPort
Ensure-TelegramService -RuntimeBaseDir $BaseDir -ModuleName 'telegram_service2' -Port $SecondaryPort
