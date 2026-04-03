param(
    [ValidateSet("status", "start", "stop", "restart", "ensure", "watchdog", "install-task")]
    [string]$Action = "status",
    [int]$MinWorkerCount = 16,
    [Alias("WorkerCount")]
    [int]$MaxWorkerCount = 200,
    [int]$ScaleDownHoldSeconds = 300,
    [int]$RetryFailedCooldownSeconds = 60,
    [int]$WatchdogIntervalMinutes = 5,
    [string]$WorkerPrefix = "ltf",
    [string]$ProjectDir = "C:\www\blog",
    [string]$PhpExe = "C:\php\php.exe",
    [string]$WorkerScript = "C:\www\blog\scripts\run_telegram_filestore_local_worker.ps1",
    [string]$EnvFile = "C:\www\blog\storage\app\telegram-filestore-local-workers\worker.env",
    [string]$StateDir = "C:\www\blog\storage\app\telegram-filestore-local-workers",
    [string]$LogDir = "C:\www\blog\storage\logs\telegram_filestore_local_workers",
    [string]$ManagerLogFile = "C:\www\blog\storage\logs\telegram_filestore_local_workers\manager.log",
    [string]$QueueName = "telegram_filestore",
    [string]$QueueConnection = "database",
    [Alias("StartupTaskName")]
    [string]$LogonTaskName = "Blog Telegram Filestore Local Workers Logon",
    [string]$WatchdogTaskName = "Blog Telegram Filestore Local Workers Watchdog",
    [Alias("StartupWrapperPath")]
    [string]$LogonWrapperPath = "C:\www\blog\scripts\start_telegram_filestore_local_workers.bat",
    [string]$WatchdogWrapperPath = "C:\www\blog\scripts\watchdog_telegram_filestore_local_workers.bat"
)

$ErrorActionPreference = "Stop"

function Write-ManagerLog {
    param([string]$Message)

    $directory = Split-Path -Parent $ManagerLogFile
    if (-not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -LiteralPath $ManagerLogFile -Value "$timestamp $Message"
}

function Assert-Configuration {
    if ($MinWorkerCount -lt 1) {
        throw "MinWorkerCount must be >= 1"
    }

    if ($MaxWorkerCount -lt $MinWorkerCount) {
        throw "MaxWorkerCount must be >= MinWorkerCount"
    }

    if ($ScaleDownHoldSeconds -lt 0) {
        throw "ScaleDownHoldSeconds must be >= 0"
    }

    if ($RetryFailedCooldownSeconds -lt 0) {
        throw "RetryFailedCooldownSeconds must be >= 0"
    }

    if ($WatchdogIntervalMinutes -lt 1) {
        throw "WatchdogIntervalMinutes must be >= 1"
    }
}

function Assert-Prerequisites {
    if (-not (Test-Path -LiteralPath $PhpExe)) {
        throw "PHP executable not found: $PhpExe"
    }

    if (-not (Test-Path -LiteralPath $WorkerScript)) {
        throw "Worker script not found: $WorkerScript"
    }

    if (-not (Test-Path -LiteralPath $EnvFile)) {
        throw "Worker env file not found: $EnvFile"
    }

    if (-not (Test-Path -LiteralPath $LogDir)) {
        New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
    }

    if (-not (Test-Path -LiteralPath $StateDir)) {
        New-Item -ItemType Directory -Path $StateDir -Force | Out-Null
    }
}

function Import-EnvFileToProcess {
    param([string]$Path)

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

function Get-WorkerName {
    param([int]$Index)
    return "{0}{1:D3}" -f $WorkerPrefix, $Index
}

function Get-WorkerNames {
    param([int]$Count = $MaxWorkerCount)

    if ($Count -lt 1) {
        return
    }

    for ($i = 1; $i -le $Count; $i++) {
        Get-WorkerName -Index $i
    }
}

function Get-WorkerLogFile {
    param([string]$WorkerName)
    return Join-Path $LogDir "$WorkerName.log"
}

function Get-WorkerProcess {
    param([string]$WorkerName)

    $all = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'"
    foreach ($process in $all) {
        $commandLine = [string]$process.CommandLine
        if ([string]::IsNullOrWhiteSpace($commandLine)) {
            continue
        }

        if ($commandLine -notlike "*$WorkerScript*") {
            continue
        }

        if ($commandLine -notlike "*-WorkerName*") {
            continue
        }

        if ($commandLine -notlike "*$WorkerName*") {
            continue
        }

        return $process
    }

    return $null
}

function Get-WorkerProcesses {
    param([string]$WorkerName)

    $matches = @()
    $all = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'"
    foreach ($process in $all) {
        $commandLine = [string]$process.CommandLine
        if ([string]::IsNullOrWhiteSpace($commandLine)) {
            continue
        }

        if ($commandLine -notlike "*$WorkerScript*") {
            continue
        }

        if ($commandLine -notlike "*-WorkerName*") {
            continue
        }

        if ($commandLine -notlike "*$WorkerName*") {
            continue
        }

        $matches += $process
    }

    return $matches
}

function Get-WorkerPhpProcesses {
    param([string]$WorkerName)

    return @(
        Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object {
            $commandLine = [string]$_.CommandLine
            -not [string]::IsNullOrWhiteSpace($commandLine) -and
            $commandLine -like "*artisan queue:work*" -and
            $commandLine -like "*--queue=$QueueName*" -and
            $commandLine -like "*--name=$WorkerName*"
        }
    )
}

function Get-WorkerRunnerSnapshot {
    $snapshot = @{}
    $all = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'"
    foreach ($process in $all) {
        $commandLine = [string]$process.CommandLine
        if ([string]::IsNullOrWhiteSpace($commandLine)) {
            continue
        }

        if ($commandLine -notlike "*$WorkerScript*") {
            continue
        }

        if ($commandLine -notmatch '-WorkerName\s+("?)([^"\s]+)\1') {
            continue
        }

        $workerName = [string]$matches[2]
        if ($workerName -notlike "$WorkerPrefix*") {
            continue
        }

        if (-not $snapshot.ContainsKey($workerName)) {
            $snapshot[$workerName] = @()
        }

        $snapshot[$workerName] += $process
    }

    return $snapshot
}

function Get-WorkerPhpSnapshot {
    $snapshot = @{}
    $all = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'"
    foreach ($process in $all) {
        $commandLine = [string]$process.CommandLine
        if ([string]::IsNullOrWhiteSpace($commandLine)) {
            continue
        }

        if ($commandLine -notlike "*artisan queue:work*") {
            continue
        }

        if ($commandLine -notlike "*--queue=$QueueName*") {
            continue
        }

        if ($commandLine -notmatch '--name=([^\s"]+)') {
            continue
        }

        $workerName = [string]$matches[1]
        if ($workerName -notlike "$WorkerPrefix*") {
            continue
        }

        if (-not $snapshot.ContainsKey($workerName)) {
            $snapshot[$workerName] = @()
        }

        $snapshot[$workerName] += $process
    }

    return $snapshot
}

function Get-RunningWorkerCount {
    return (Get-WorkerRunnerSnapshot).Count
}

function Start-Worker {
    param([string]$WorkerName)

    $existing = Get-WorkerProcess -WorkerName $WorkerName
    if ($null -ne $existing) {
        Write-ManagerLog "$WorkerName already running pid=$($existing.ProcessId)"
        return
    }

    $workerLogFile = Get-WorkerLogFile -WorkerName $WorkerName
    $arguments = @(
        "-NoProfile",
        "-ExecutionPolicy", "Bypass",
        "-File", $WorkerScript,
        "-WorkerName", $WorkerName,
        "-ProjectDir", $ProjectDir,
        "-PhpExe", $PhpExe,
        "-EnvFile", $EnvFile,
        "-LogFile", $workerLogFile,
        "-StateDir", $StateDir,
        "-QueueConnection", $QueueConnection,
        "-QueueName", $QueueName
    )

    Start-Process -FilePath "powershell.exe" -ArgumentList $arguments -WorkingDirectory $ProjectDir -WindowStyle Hidden | Out-Null
    Start-Sleep -Seconds 1

    $started = Get-WorkerProcess -WorkerName $WorkerName
    if ($null -eq $started) {
        throw "Failed to start $WorkerName"
    }

    Write-ManagerLog "started $WorkerName pid=$($started.ProcessId)"
}

function Stop-Worker {
    param([string]$WorkerName)

    $existing = @(Get-WorkerProcesses -WorkerName $WorkerName)
    $phpProcesses = @(Get-WorkerPhpProcesses -WorkerName $WorkerName)

    if ($existing.Count -eq 0 -and $phpProcesses.Count -eq 0) {
        Write-ManagerLog "$WorkerName already stopped"
        return
    }

    foreach ($process in $existing) {
        try {
            & taskkill /PID $process.ProcessId /T /F | Out-Null
        } catch {
        }
    }

    foreach ($phpProcess in $phpProcesses) {
        try {
            Stop-Process -Id $phpProcess.ProcessId -Force -ErrorAction Stop
        } catch {
        }
    }

    Start-Sleep -Seconds 1
    Write-ManagerLog "stopped $WorkerName runners=$($existing.Count) php=$($phpProcesses.Count)"
}

function Get-RevisionStateFile {
    return Join-Path $StateDir "last_head.txt"
}

function Get-RestartRequestFile {
    return Join-Path $StateDir "restart.request"
}

function Get-DesiredWorkerCountFile {
    return Join-Path $StateDir "desired_worker_count.txt"
}

function Get-DownscaleCandidateFile {
    return Join-Path $StateDir "downscale_candidate.txt"
}

function Get-FailedRetryStateFile {
    return Join-Path $StateDir "last_retry_failed_at.txt"
}

function Read-IntegerFile {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return $null
    }

    $raw = ((Get-Content -LiteralPath $Path -ErrorAction SilentlyContinue | Select-Object -First 1) -as [string])
    if ([string]::IsNullOrWhiteSpace($raw)) {
        return $null
    }

    $value = 0
    if ([int]::TryParse($raw.Trim(), [ref]$value)) {
        return $value
    }

    return $null
}

function Write-AsciiFile {
    param(
        [string]$Path,
        [string]$Value
    )

    $directory = Split-Path -Parent $Path
    if (-not [string]::IsNullOrWhiteSpace($directory) -and -not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }

    $encoding = [System.Text.Encoding]::ASCII
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            [System.IO.File]::WriteAllText($Path, $Value, $encoding)
            return
        } catch {
            if ($attempt -ge 3) {
                throw
            }

            Start-Sleep -Milliseconds 100
        }
    }
}

function Save-IntegerFile {
    param(
        [string]$Path,
        [int]$Value
    )

    Write-AsciiFile -Path $Path -Value ([string]$Value)
}

function Read-DateTimeFile {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return $null
    }

    $raw = ((Get-Content -LiteralPath $Path -ErrorAction SilentlyContinue | Select-Object -First 1) -as [string])
    if ([string]::IsNullOrWhiteSpace($raw)) {
        return $null
    }

    $value = [datetime]::MinValue
    if ([datetime]::TryParse($raw.Trim(), [ref]$value)) {
        return $value
    }

    return $null
}

function Save-DateTimeFile {
    param(
        [string]$Path,
        [datetime]$Value
    )

    Write-AsciiFile -Path $Path -Value $Value.ToString("o")
}

function Read-DownscaleCandidate {
    $path = Get-DownscaleCandidateFile
    if (-not (Test-Path -LiteralPath $path)) {
        return $null
    }

    $raw = ((Get-Content -LiteralPath $path -ErrorAction SilentlyContinue | Select-Object -First 1) -as [string]).Trim()
    if ([string]::IsNullOrWhiteSpace($raw)) {
        return $null
    }

    $parts = $raw.Split("|", 2)
    if ($parts.Count -ne 2) {
        return $null
    }

    $count = 0
    if (-not [int]::TryParse($parts[0], [ref]$count)) {
        return $null
    }

    $sinceUtc = [datetime]::MinValue
    if (-not [datetime]::TryParse($parts[1], [ref]$sinceUtc)) {
        return $null
    }

    return [pscustomobject]@{
        Count = $count
        SinceUtc = $sinceUtc
    }
}

function Save-DownscaleCandidate {
    param(
        [int]$Count,
        [datetime]$SinceUtc
    )

    $path = Get-DownscaleCandidateFile
    $value = "{0}|{1}" -f $Count, $SinceUtc.ToString("o")
    Write-AsciiFile -Path $path -Value $value
}

function Clear-DownscaleCandidate {
    $path = Get-DownscaleCandidateFile
    if (Test-Path -LiteralPath $path) {
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
}

function Get-GitHead {
    try {
        $head = (& git -C $ProjectDir rev-parse HEAD 2>$null)
        if ($LASTEXITCODE -ne 0) {
            return $null
        }

        return ([string]$head).Trim()
    } catch {
        return $null
    }
}

function Save-GitHead {
    param([string]$Head)

    if ([string]::IsNullOrWhiteSpace($Head)) {
        return
    }

    Write-AsciiFile -Path (Get-RevisionStateFile) -Value $Head
}

function Get-PhpCliArguments {
    Import-EnvFileToProcess -Path $EnvFile

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

    return $phpArguments
}

function Invoke-ArtisanCommand {
    param([string[]]$CommandArguments)

    $phpArguments = @(Get-PhpCliArguments)
    $phpArguments += "artisan"
    $phpArguments += $CommandArguments

    Push-Location $ProjectDir
    try {
        $output = @(& $PhpExe @phpArguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }

    return [pscustomobject]@{
        ExitCode = $exitCode
        Output = @($output)
    }
}

function Invoke-RetryFailedJobsIfNeeded {
    param([int]$FailedCount)

    if ($FailedCount -le 0) {
        return $false
    }

    $stateFile = Get-FailedRetryStateFile
    $lastRetryAt = Read-DateTimeFile -Path $stateFile
    if ($null -ne $lastRetryAt) {
        $elapsedSeconds = [int][Math]::Floor(([datetime]::Now - $lastRetryAt).TotalSeconds)
        if ($elapsedSeconds -lt $RetryFailedCooldownSeconds) {
            Write-ManagerLog "failed_jobs=$FailedCount detected but queue:retry all is cooling down elapsed=${elapsedSeconds}s cooldown=${RetryFailedCooldownSeconds}s"
            return $false
        }
    }

    Write-ManagerLog "failed_jobs=$FailedCount detected; running php artisan queue:retry all"
    $result = Invoke-ArtisanCommand -CommandArguments @("queue:retry", "all")
    if ($result.ExitCode -ne 0) {
        $outputText = (($result.Output | ForEach-Object { ([string]$_).Trim() }) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }) -join " | "
        if ([string]::IsNullOrWhiteSpace($outputText)) {
            $outputText = "(no output)"
        }

        Write-ManagerLog "queue:retry all failed exit_code=$($result.ExitCode) output=$outputText"
        return $false
    }

    Save-DateTimeFile -Path $stateFile -Value ([datetime]::Now)

    foreach ($line in $result.Output) {
        $text = ([string]$line).Trim()
        if ([string]::IsNullOrWhiteSpace($text)) {
            continue
        }

        Write-ManagerLog "queue:retry all output=$text"
    }

    Write-ManagerLog "queue:retry all completed failed_jobs=$FailedCount"
    return $true
}

function Get-QueueMetrics {
    Import-EnvFileToProcess -Path $EnvFile
    [System.Environment]::SetEnvironmentVariable("TELEGRAM_FILESTORE_PROJECT_DIR", $ProjectDir, "Process")
    [System.Environment]::SetEnvironmentVariable("TELEGRAM_FILESTORE_QUEUE_NAME", $QueueName, "Process")

    $phpScript = @'
<?php
$projectDir = getenv('TELEGRAM_FILESTORE_PROJECT_DIR');
$queueName = getenv('TELEGRAM_FILESTORE_QUEUE_NAME');
$now = time();

require $projectDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
$app = require $projectDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$pending = Illuminate\Support\Facades\DB::table('jobs')
    ->where('queue', $queueName)
    ->whereNull('reserved_at')
    ->selectRaw('COUNT(*) as aggregate_count, MIN(available_at) as oldest_timestamp')
    ->first();

$reserved = Illuminate\Support\Facades\DB::table('jobs')
    ->where('queue', $queueName)
    ->whereNotNull('reserved_at')
    ->selectRaw('COUNT(*) as aggregate_count, MIN(reserved_at) as oldest_timestamp')
    ->first();

$failed = Illuminate\Support\Facades\DB::table('failed_jobs')
    ->selectRaw('COUNT(*) as aggregate_count')
    ->first();

$pendingCount = (int) ($pending->aggregate_count ?? 0);
$pendingAge = ($pendingCount > 0 && $pending->oldest_timestamp !== null)
    ? max(0, $now - (int) $pending->oldest_timestamp)
    : 0;

$reservedCount = (int) ($reserved->aggregate_count ?? 0);
$reservedAge = ($reservedCount > 0 && $reserved->oldest_timestamp !== null)
    ? max(0, $now - (int) $reserved->oldest_timestamp)
    : 0;

$failedCount = (int) ($failed->aggregate_count ?? 0);

printf("PENDING_COUNT=%d\n", $pendingCount);
printf("PENDING_AGE=%d\n", $pendingAge);
printf("RESERVED_COUNT=%d\n", $reservedCount);
printf("RESERVED_AGE=%d\n", $reservedAge);
printf("FAILED_COUNT=%d\n", $failedCount);
'@

    $tempPhpFile = [System.IO.Path]::ChangeExtension([System.IO.Path]::GetTempFileName(), ".php")
    try {
        Set-Content -LiteralPath $tempPhpFile -Value $phpScript -Encoding ascii
        $output = @(& $PhpExe $tempPhpFile)
        if ($LASTEXITCODE -ne 0) {
            throw "Failed to read queue metrics for $QueueName"
        }
    } finally {
        if (Test-Path -LiteralPath $tempPhpFile) {
            Remove-Item -LiteralPath $tempPhpFile -Force -ErrorAction SilentlyContinue
        }
    }

    $values = @{}
    foreach ($line in $output) {
        $text = ([string]$line).Trim()
        if ($text -match '^([A-Z_]+)=(.+)$') {
            $values[$matches[1]] = $matches[2]
        }
    }

    $pendingCount = if ($values.ContainsKey("PENDING_COUNT")) { [int]$values["PENDING_COUNT"] } else { 0 }
    $pendingAge = if ($values.ContainsKey("PENDING_AGE")) { [int]$values["PENDING_AGE"] } else { 0 }
    $reservedCount = if ($values.ContainsKey("RESERVED_COUNT")) { [int]$values["RESERVED_COUNT"] } else { 0 }
    $reservedAge = if ($values.ContainsKey("RESERVED_AGE")) { [int]$values["RESERVED_AGE"] } else { 0 }
    $failedCount = if ($values.ContainsKey("FAILED_COUNT")) { [int]$values["FAILED_COUNT"] } else { 0 }

    return [pscustomobject]@{
        PendingCount = $pendingCount
        PendingAge = $pendingAge
        ReservedCount = $reservedCount
        ReservedAge = $reservedAge
        FailedCount = $failedCount
        TotalJobs = $pendingCount + $reservedCount
    }
}

function Get-DesiredWorkerPlan {
    $metrics = Get-QueueMetrics
    $observedJobs = [int]$metrics.TotalJobs
    $rawTarget = [Math]::Max($MinWorkerCount, [Math]::Min($MaxWorkerCount, $observedJobs))
    $runningCount = Get-RunningWorkerCount

    $savedDesired = Read-IntegerFile -Path (Get-DesiredWorkerCountFile)
    if ($null -eq $savedDesired) {
        if ($runningCount -ge $MinWorkerCount -and $runningCount -le $MaxWorkerCount) {
            $savedDesired = $runningCount
        } else {
            $savedDesired = $rawTarget
        }
    }

    $savedDesired = [Math]::Max($MinWorkerCount, [Math]::Min($MaxWorkerCount, [int]$savedDesired))
    $desiredCount = $savedDesired
    $downscaleApplied = $false
    $candidateAgeSeconds = 0

    if ($rawTarget -gt $savedDesired) {
        $desiredCount = $rawTarget
        Clear-DownscaleCandidate
    } elseif ($rawTarget -lt $savedDesired) {
        $candidate = Read-DownscaleCandidate
        if ($null -eq $candidate) {
            Save-DownscaleCandidate -Count $rawTarget -SinceUtc ([datetime]::Now)
            if ($ScaleDownHoldSeconds -eq 0) {
                $desiredCount = $rawTarget
                $downscaleApplied = $true
                Clear-DownscaleCandidate
            }
        } else {
            if ($candidate.Count -ne $rawTarget) {
                Save-DownscaleCandidate -Count $rawTarget -SinceUtc $candidate.SinceUtc
            }
            $candidateAgeSeconds = [int][Math]::Floor(([datetime]::Now - $candidate.SinceUtc).TotalSeconds)
            if ($candidateAgeSeconds -ge $ScaleDownHoldSeconds) {
                $desiredCount = $rawTarget
                $downscaleApplied = $true
                Clear-DownscaleCandidate
            }
        }
    } else {
        Clear-DownscaleCandidate
    }

    Save-IntegerFile -Path (Get-DesiredWorkerCountFile) -Value $desiredCount

    return [pscustomobject]@{
        PendingCount = [int]$metrics.PendingCount
        PendingAge = [int]$metrics.PendingAge
        ReservedCount = [int]$metrics.ReservedCount
        ReservedAge = [int]$metrics.ReservedAge
        FailedCount = [int]$metrics.FailedCount
        ObservedJobs = $observedJobs
        RawTarget = $rawTarget
        PreviousDesiredCount = $savedDesired
        DesiredCount = $desiredCount
        RunningCount = $runningCount
        DownscaleApplied = $downscaleApplied
        CandidateAgeSeconds = $candidateAgeSeconds
    }
}

function Show-Status {
    $plan = Get-DesiredWorkerPlan
    $runnerSnapshot = Get-WorkerRunnerSnapshot
    "queue=$QueueName pending=$($plan.PendingCount) reserved=$($plan.ReservedCount) total_jobs=$($plan.ObservedJobs) desired=$($plan.DesiredCount) running=$($plan.RunningCount) min=$MinWorkerCount max=$MaxWorkerCount"

    foreach ($workerName in Get-WorkerNames -Count $MaxWorkerCount) {
        $processes = if ($runnerSnapshot.ContainsKey($workerName)) { @($runnerSnapshot[$workerName]) } else { @() }
        if ($processes.Count -eq 0) {
            "{0} status=down pid=none" -f $workerName
            continue
        }

        "{0} status=up pid={1}" -f $workerName, $processes[0].ProcessId
    }
}

function Start-WorkersUpTo {
    param([int]$TargetWorkerCount)

    foreach ($workerName in Get-WorkerNames -Count $TargetWorkerCount) {
        Start-Worker -WorkerName $workerName
    }
}

function Stop-AllWorkers {
    $runnerSnapshot = Get-WorkerRunnerSnapshot
    $phpSnapshot = Get-WorkerPhpSnapshot

    foreach ($workerName in Get-WorkerNames -Count $MaxWorkerCount) {
        $runnerProcesses = if ($runnerSnapshot.ContainsKey($workerName)) { @($runnerSnapshot[$workerName]) } else { @() }
        $phpProcesses = if ($phpSnapshot.ContainsKey($workerName)) { @($phpSnapshot[$workerName]) } else { @() }

        if ($runnerProcesses.Count -eq 0 -and $phpProcesses.Count -eq 0) {
            Write-ManagerLog "$workerName already stopped"
            continue
        }

        foreach ($process in $runnerProcesses) {
            try {
                & taskkill /PID $process.ProcessId /T /F | Out-Null
            } catch {
            }
        }

        foreach ($phpProcess in $phpProcesses) {
            try {
                Stop-Process -Id $phpProcess.ProcessId -Force -ErrorAction Stop
            } catch {
            }
        }

        Write-ManagerLog "stopped $workerName runners=$($runnerProcesses.Count) php=$($phpProcesses.Count)"
    }
}

function Stop-WorkersAbove {
    param([int]$TargetWorkerCount)

    $runnerSnapshot = Get-WorkerRunnerSnapshot
    $phpSnapshot = Get-WorkerPhpSnapshot

    foreach ($workerName in Get-WorkerNames -Count $MaxWorkerCount) {
        $workerIndex = 0
        if (-not [int]::TryParse(($workerName -replace '^\D+', ''), [ref]$workerIndex)) {
            continue
        }

        if ($workerIndex -le $TargetWorkerCount) {
            continue
        }

        $runnerProcesses = if ($runnerSnapshot.ContainsKey($workerName)) { @($runnerSnapshot[$workerName]) } else { @() }
        $phpProcesses = if ($phpSnapshot.ContainsKey($workerName)) { @($phpSnapshot[$workerName]) } else { @() }

        if ($runnerProcesses.Count -eq 0 -and $phpProcesses.Count -eq 0) {
            continue
        }

        foreach ($process in $runnerProcesses) {
            try {
                & taskkill /PID $process.ProcessId /T /F | Out-Null
            } catch {
            }
        }

        foreach ($phpProcess in $phpProcesses) {
            try {
                Stop-Process -Id $phpProcess.ProcessId -Force -ErrorAction Stop
            } catch {
            }
        }

        Write-ManagerLog "autoscale stopped $workerName runners=$($runnerProcesses.Count) php=$($phpProcesses.Count)"
    }
}

function Ensure-WorkersUpTo {
    param([int]$TargetWorkerCount)

    $runnerSnapshot = Get-WorkerRunnerSnapshot
    foreach ($workerName in Get-WorkerNames -Count $TargetWorkerCount) {
        $processes = if ($runnerSnapshot.ContainsKey($workerName)) { @($runnerSnapshot[$workerName]) } else { @() }
        if ($processes.Count -eq 0) {
            Write-ManagerLog "$workerName missing, starting"
            Start-Worker -WorkerName $workerName
        }
    }
}

function Restart-AllWorkers {
    param([int]$TargetWorkerCount)

    Stop-AllWorkers
    Start-WorkersUpTo -TargetWorkerCount $TargetWorkerCount
}

function Invoke-Watchdog {
    $plan = Get-DesiredWorkerPlan
    $restartReason = $null
    $restartRequestFile = Get-RestartRequestFile
    $revisionStateFile = Get-RevisionStateFile

    $currentHead = Get-GitHead
    $savedHead = $null

    if (Test-Path -LiteralPath $revisionStateFile) {
        $savedHead = ((Get-Content -LiteralPath $revisionStateFile -ErrorAction SilentlyContinue | Select-Object -First 1) -as [string]).Trim()
    }

    if (Test-Path -LiteralPath $restartRequestFile) {
        $restartReason = "restart request file detected"
        Remove-Item -LiteralPath $restartRequestFile -Force -ErrorAction SilentlyContinue
    } elseif (-not [string]::IsNullOrWhiteSpace($currentHead) -and -not [string]::IsNullOrWhiteSpace($savedHead) -and $currentHead -ne $savedHead) {
        $restartReason = "git head changed from $savedHead to $currentHead"
    }

    try {
        if (Invoke-RetryFailedJobsIfNeeded -FailedCount $plan.FailedCount) {
            $plan = Get-DesiredWorkerPlan
        }
    } catch {
        Write-ManagerLog "queue:retry all raised exception error=$($_.Exception.Message)"
    }

    if ($restartReason) {
        Write-ManagerLog "$restartReason; restarting all workers desired=$($plan.DesiredCount)"
        Restart-AllWorkers -TargetWorkerCount $plan.DesiredCount
    } else {
        if ($plan.DownscaleApplied) {
            Write-ManagerLog "autoscale down previous=$($plan.PreviousDesiredCount) desired=$($plan.DesiredCount) total_jobs=$($plan.ObservedJobs) pending=$($plan.PendingCount) reserved=$($plan.ReservedCount); stopping workers above desired"
            Stop-WorkersAbove -TargetWorkerCount $plan.DesiredCount
        } elseif ($plan.RunningCount -gt $plan.DesiredCount) {
            Write-ManagerLog "autoscale converge desired=$($plan.DesiredCount) running=$($plan.RunningCount); stopping workers above desired"
            Stop-WorkersAbove -TargetWorkerCount $plan.DesiredCount
        }

        Ensure-WorkersUpTo -TargetWorkerCount $plan.DesiredCount
        Write-ManagerLog "watchdog ok pending=$($plan.PendingCount) reserved=$($plan.ReservedCount) total_jobs=$($plan.ObservedJobs) desired=$($plan.DesiredCount) running=$($plan.RunningCount) failed=$($plan.FailedCount)"
    }

    if (-not [string]::IsNullOrWhiteSpace($currentHead)) {
        Save-GitHead -Head $currentHead
    }
}

function Update-TaskDescriptionViaCom {
    param(
        [string]$TaskName,
        [string]$Description
    )

    $service = New-Object -ComObject 'Schedule.Service'
    $service.Connect()
    $root = $service.GetFolder('\')
    $task = $root.GetTask($TaskName)
    if ($null -eq $task) {
        throw "Scheduled task not found for COM update: $TaskName"
    }

    $definition = $task.Definition
    $definition.RegistrationInfo.Description = $Description
    $userId = $definition.Principal.UserId
    $logonType = [int]$definition.Principal.LogonType
    $null = $root.RegisterTaskDefinition($TaskName, $definition, 6, $userId, $null, $logonType, $null)
}

function Register-OrRefreshTaskDescription {
    param(
        [string]$TaskName,
        [Microsoft.Management.Infrastructure.CimInstance]$Action,
        [Microsoft.Management.Infrastructure.CimInstance[]]$Trigger,
        [string]$Description,
        [string]$CurrentUser,
        [Microsoft.Management.Infrastructure.CimInstance]$TaskSettings
    )

    try {
        Register-ScheduledTask `
            -TaskName $TaskName `
            -Action $Action `
            -Trigger $Trigger `
            -Settings $TaskSettings `
            -Description $Description `
            -User $CurrentUser `
            -Force | Out-Null
        return
    } catch {
        if (-not (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue)) {
            throw
        }

        Set-ScheduledTask `
            -TaskName $TaskName `
            -Action $Action `
            -Trigger $Trigger `
            -Settings $TaskSettings | Out-Null

        Update-TaskDescriptionViaCom -TaskName $TaskName -Description $Description
        Write-ManagerLog "Register-ScheduledTask denied for $TaskName; updated the existing task via Set-ScheduledTask and refreshed the description via COM"
    }
}

function Install-WorkerTasks {
    $repoRoot = 'C:\www\blog'
    $hiddenRunnerPath = Join-Path $repoRoot 'scripts\run_hidden_task.vbs'
    $currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew
    $logonDescription = "At user logon, run the local telegram_filestore watchdog once so workers converge to the current desired count with min $MinWorkerCount and max $MaxWorkerCount."
    $watchdogDescription = "Every $WatchdogIntervalMinutes minutes, reconcile local telegram_filestore workers against queue load with min $MinWorkerCount, max $MaxWorkerCount, and a $ScaleDownHoldSeconds-second downscale hold."

    if (-not (Test-Path -LiteralPath $hiddenRunnerPath)) {
        throw "Hidden task runner not found: $hiddenRunnerPath"
    }

    Unregister-ScheduledTask -TaskName "Blog Telegram Filestore Local Workers Startup" -Confirm:$false -ErrorAction SilentlyContinue

    $logonAction = New-ScheduledTaskAction -Execute "wscript.exe" -Argument ('"{0}" "{1}"' -f $hiddenRunnerPath, $LogonWrapperPath)
    $logonTrigger = New-ScheduledTaskTrigger -AtLogOn

    Register-OrRefreshTaskDescription `
        -TaskName $LogonTaskName `
        -Action $logonAction `
        -Trigger @($logonTrigger) `
        -Description $logonDescription `
        -CurrentUser $currentUser `
        -TaskSettings $settings

    $watchdogAction = New-ScheduledTaskAction -Execute "wscript.exe" -Argument ('"{0}" "{1}"' -f $hiddenRunnerPath, $WatchdogWrapperPath)
    $watchdogTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes $WatchdogIntervalMinutes)

    Register-OrRefreshTaskDescription `
        -TaskName $WatchdogTaskName `
        -Action $watchdogAction `
        -Trigger @($watchdogTrigger) `
        -Description $watchdogDescription `
        -CurrentUser $currentUser `
        -TaskSettings $settings

    Write-ManagerLog "installed logon task $LogonTaskName"
    Write-ManagerLog "installed watchdog task $WatchdogTaskName"
    Write-Output "Registered logon task: $LogonTaskName"
    Write-Output "Registered watchdog task: $WatchdogTaskName"
}

Assert-Configuration
Assert-Prerequisites

switch ($Action) {
    "status" {
        Show-Status
    }
    "start" {
        $plan = Get-DesiredWorkerPlan
        Start-WorkersUpTo -TargetWorkerCount $plan.DesiredCount
    }
    "stop" {
        Stop-AllWorkers
    }
    "restart" {
        $plan = Get-DesiredWorkerPlan
        Restart-AllWorkers -TargetWorkerCount $plan.DesiredCount
    }
    "ensure" {
        $plan = Get-DesiredWorkerPlan
        Ensure-WorkersUpTo -TargetWorkerCount $plan.DesiredCount
    }
    "watchdog" {
        Invoke-Watchdog
    }
    "install-task" {
        Install-WorkerTasks
    }
}
