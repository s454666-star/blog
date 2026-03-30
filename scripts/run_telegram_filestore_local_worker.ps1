param(
    [Parameter(Mandatory = $true)]
    [string]$WorkerName,
    [string]$ProjectDir = "C:\www\blog",
    [string]$PhpExe = "C:\php\php.exe",
    [string]$EnvFile = "C:\www\blog\storage\app\telegram-filestore-local-workers\worker.env",
    [string]$StateDir = "C:\www\blog\storage\app\telegram-filestore-local-workers",
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
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::AppendAllText($Path, "$timestamp [$WorkerName] $Message" + [Environment]::NewLine, $utf8NoBom)
}

function Test-FileContainsNullByte {
    param(
        [string]$Path,
        [int]$SampleBytes = 4096
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return $false
    }

    $stream = [System.IO.File]::Open($Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
    try {
        if ($stream.Length -le 0) {
            return $false
        }

        $bufferLength = [Math]::Min([int]$stream.Length, $SampleBytes)
        $buffer = New-Object byte[] $bufferLength
        $bytesRead = $stream.Read($buffer, 0, $bufferLength)

        for ($index = 0; $index -lt $bytesRead; $index++) {
            if ($buffer[$index] -eq 0) {
                return $true
            }
        }

        return $false
    } finally {
        $stream.Dispose()
    }
}

function Repair-LegacyLogFile {
    param(
        [string]$Path
    )

    if (-not (Test-FileContainsNullByte -Path $Path)) {
        return
    }

    $directory = Split-Path -Parent $Path
    $baseName = [System.IO.Path]::GetFileNameWithoutExtension($Path)
    $extension = [System.IO.Path]::GetExtension($Path)
    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $legacyPath = Join-Path $directory "$baseName.legacy-utf16-$timestamp$extension"

    Move-Item -LiteralPath $Path -Destination $legacyPath -Force
}

function Get-DesiredWorkerCountFile {
    return Join-Path $StateDir "desired_worker_count.txt"
}

function Get-WorkerIndex {
    param([string]$Name)

    if ($Name -match '(\d+)$') {
        return [int]$matches[1]
    }

    return $null
}

function Read-DesiredWorkerCount {
    $path = Get-DesiredWorkerCountFile
    if (-not (Test-Path -LiteralPath $path)) {
        return $null
    }

    $raw = ((Get-Content -LiteralPath $path -ErrorAction SilentlyContinue | Select-Object -First 1) -as [string])
    if ([string]::IsNullOrWhiteSpace($raw)) {
        return $null
    }

    $value = 0
    if ([int]::TryParse($raw.Trim(), [ref]$value)) {
        return $value
    }

    return $null
}

function Test-WorkerShouldRun {
    $desiredWorkerCount = Read-DesiredWorkerCount
    if ($null -eq $desiredWorkerCount) {
        return $true
    }

    $workerIndex = Get-WorkerIndex -Name $WorkerName
    if ($null -eq $workerIndex) {
        return $true
    }

    return $workerIndex -le $desiredWorkerCount
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

function Quote-CmdArgument {
    param([string]$Value)

    if ($null -eq $Value) {
        return '""'
    }

    if ($Value -eq "") {
        return '""'
    }

    if ($Value -notmatch '[\s"]') {
        return $Value
    }

    return '"' + ($Value -replace '"', '\"') + '"'
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

if (-not (Test-Path -LiteralPath $StateDir)) {
    New-Item -ItemType Directory -Path $StateDir -Force | Out-Null
}

while ($true) {
    Import-EnvFile -Path $EnvFile
    Repair-LegacyLogFile -Path $LogFile

    if (-not (Test-WorkerShouldRun)) {
        $desiredWorkerCount = Read-DesiredWorkerCount
        Write-LogLine -Path $LogFile -Message "worker-stop autoscale desired_count=$desiredWorkerCount"
        break
    }

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
        $commandLine = ((Quote-CmdArgument -Value $PhpExe) + " " + (($phpArguments | ForEach-Object {
                    Quote-CmdArgument -Value ([string]$_)
                }) -join " ") + " >> " + (Quote-CmdArgument -Value $LogFile) + " 2>&1")

        & cmd.exe /d /c $commandLine | Out-Null

        $exitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }

    Write-LogLine -Path $LogFile -Message "worker-exit code=$exitCode restart_in=${RestartDelaySeconds}s"
    Start-Sleep -Seconds $RestartDelaySeconds
}
