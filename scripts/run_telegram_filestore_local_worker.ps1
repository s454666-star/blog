param(
    [Parameter(Mandatory = $true)]
    [string]$WorkerName,
    [string]$ProjectDir = "C:\www\blog",
    [string]$PhpExe = "C:\php\php.exe",
    [string]$EnvFile = "C:\www\blog\storage\app\telegram-filestore-local-workers\worker.env",
    [string]$LogFile = "",
    [string]$QueueConnection = "database",
    [string]$QueueName = "telegram_filestore",
    [int]$RestartDelaySeconds = 5,
    [int]$MaxJobs = 250,
    [int]$MaxTime = 3600,
    [int]$MemoryLimitMB = 512
)

$ErrorActionPreference = "Stop"

function Write-LogLine {
    param(
        [string]$Path,
        [string]$Message
    )

    $directory = Split-Path -Parent $Path
    if (-not [string]::IsNullOrWhiteSpace($directory) -and -not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -LiteralPath $Path -Value "$timestamp [$WorkerName] $Message"
}

function Import-EnvFile {
    param(
        [string]$Path
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Env file not found: $Path"
    }

    foreach ($line in Get-Content -LiteralPath $Path) {
        if ([string]::IsNullOrWhiteSpace($line)) {
            continue
        }

        if ($line.TrimStart().StartsWith("#")) {
            continue
        }

        $parts = $line.Split("=", 2)
        if ($parts.Count -ne 2) {
            continue
        }

        $name = $parts[0].Trim()
        $value = $parts[1]

        if ([string]::IsNullOrWhiteSpace($name)) {
            continue
        }

        [System.Environment]::SetEnvironmentVariable($name, $value, "Process")
    }
}

if ([string]::IsNullOrWhiteSpace($LogFile)) {
    $LogFile = Join-Path $ProjectDir "storage\logs\telegram_filestore_local_workers\$WorkerName.log"
}

if (-not (Test-Path -LiteralPath $PhpExe)) {
    throw "PHP executable not found: $PhpExe"
}

$artisanPath = Join-Path $ProjectDir "artisan"
$autoloadPath = Join-Path $ProjectDir "vendor\autoload.php"

if (-not (Test-Path -LiteralPath $artisanPath)) {
    throw "artisan not found under $ProjectDir"
}

if (-not (Test-Path -LiteralPath $autoloadPath)) {
    throw "vendor\autoload.php not found under $ProjectDir"
}

while ($true) {
    Import-EnvFile -Path $EnvFile

    $caFile = [System.Environment]::GetEnvironmentVariable("CURL_CA_BUNDLE", "Process")
    if ([string]::IsNullOrWhiteSpace($caFile)) {
        $caFile = [System.Environment]::GetEnvironmentVariable("SSL_CERT_FILE", "Process")
    }

    $phpArguments = @()
    if (-not [string]::IsNullOrWhiteSpace($caFile)) {
        $phpArguments += "-d"
        $phpArguments += "curl.cainfo=$caFile"
        $phpArguments += "-d"
        $phpArguments += "openssl.cafile=$caFile"
    }

    $phpArguments += "artisan"
    $phpArguments += "queue:work"
    $phpArguments += $QueueConnection
    $phpArguments += "--name=$WorkerName"
    $phpArguments += "--queue=$QueueName"
    $phpArguments += "--sleep=1"
    $phpArguments += "--tries=5"
    $phpArguments += "--timeout=900"
    $phpArguments += "--max-jobs=$MaxJobs"
    $phpArguments += "--max-time=$MaxTime"
    $phpArguments += "--memory=$MemoryLimitMB"
    $phpArguments += "-v"

    Write-LogLine -Path $LogFile -Message "worker-start queue=$QueueName connection=$QueueConnection"

    Push-Location $ProjectDir
    try {
        & $PhpExe @phpArguments *>> $LogFile

        $exitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }

    Write-LogLine -Path $LogFile -Message "worker-exit code=$exitCode restart_in=${RestartDelaySeconds}s"
    Start-Sleep -Seconds $RestartDelaySeconds
}
