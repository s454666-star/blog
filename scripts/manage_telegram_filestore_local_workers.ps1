param(
    [ValidateSet("status", "start", "stop", "restart", "ensure", "watchdog", "install-task")]
    [string]$Action = "status",
    [int]$WorkerCount = 100,
    [string]$WorkerPrefix = "ltf",
    [string]$ProjectDir = "C:\www\blog",
    [string]$PhpExe = "C:\php\php.exe",
    [string]$WorkerScript = "C:\www\blog\scripts\run_telegram_filestore_local_worker.ps1",
    [string]$EnvFile = "C:\www\blog\storage\app\telegram-filestore-local-workers\worker.env",
    [string]$StateDir = "C:\www\blog\storage\app\telegram-filestore-local-workers",
    [string]$LogDir = "C:\www\blog\storage\logs\telegram_filestore_local_workers",
    [string]$ManagerLogFile = "C:\www\blog\storage\logs\telegram_filestore_local_workers\manager.log",
    [string]$StartupTaskName = "Blog Telegram Filestore Local Workers Startup",
    [string]$WatchdogTaskName = "Blog Telegram Filestore Local Workers Watchdog",
    [string]$StartupWrapperPath = "C:\www\blog\scripts\start_telegram_filestore_local_workers.bat",
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

function Get-WorkerName {
    param([int]$Index)
    return "{0}{1:D3}" -f $WorkerPrefix, $Index
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
            $commandLine -like "*--queue=telegram_filestore*" -and
            $commandLine -like "*--name=$WorkerName*"
        }
    )
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
        "-LogFile", $workerLogFile
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

function Get-WorkerNames {
    for ($i = 1; $i -le $WorkerCount; $i++) {
        Get-WorkerName -Index $i
    }
}

function Get-RevisionStateFile {
    return Join-Path $StateDir "last_head.txt"
}

function Get-RestartRequestFile {
    return Join-Path $StateDir "restart.request"
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

    Set-Content -LiteralPath (Get-RevisionStateFile) -Value $Head -Encoding ascii
}

function Show-Status {
    foreach ($workerName in Get-WorkerNames) {
        $process = Get-WorkerProcess -WorkerName $workerName
        if ($null -eq $process) {
            "{0} status=down pid=none" -f $workerName
            continue
        }

        "{0} status=up pid={1}" -f $workerName, $process.ProcessId
    }
}

function Start-AllWorkers {
    foreach ($workerName in Get-WorkerNames) {
        Start-Worker -WorkerName $workerName
    }
}

function Stop-AllWorkers {
    foreach ($workerName in Get-WorkerNames) {
        Stop-Worker -WorkerName $workerName
    }
}

function Ensure-AllWorkers {
    foreach ($workerName in Get-WorkerNames) {
        $process = Get-WorkerProcess -WorkerName $workerName
        if ($null -eq $process) {
            Write-ManagerLog "$workerName missing, starting"
            Start-Worker -WorkerName $workerName
        }
    }
}

function Restart-AllWorkers {
    Stop-AllWorkers
    Start-AllWorkers
}

function Invoke-Watchdog {
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

    if ($restartReason) {
        Write-ManagerLog "$restartReason; restarting all workers"
        Restart-AllWorkers
    } else {
        Ensure-AllWorkers
    }

    if (-not [string]::IsNullOrWhiteSpace($currentHead)) {
        Save-GitHead -Head $currentHead
    }
}

function Install-StartupTask {
    $principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

    $startupAction = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$StartupWrapperPath`""
    $startupTrigger = New-ScheduledTaskTrigger -AtStartup

    Register-ScheduledTask `
        -TaskName $StartupTaskName `
        -Action $startupAction `
        -Trigger $startupTrigger `
        -Principal $principal `
        -Description "Start 100 local telegram_filestore queue workers at system startup." `
        -Force | Out-Null

    $watchdogAction = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$WatchdogWrapperPath`""
    $watchdogTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date)
    $watchdogTrigger.Repetition.Interval = (New-TimeSpan -Minutes 1)
    $watchdogTrigger.Repetition.Duration = (New-TimeSpan -Days 3650)

    Register-ScheduledTask `
        -TaskName $WatchdogTaskName `
        -Action $watchdogAction `
        -Trigger $watchdogTrigger `
        -Principal $principal `
        -Description "Watchdog for 100 local telegram_filestore queue workers; restarts missing workers and reloads on git head changes." `
        -Force | Out-Null

    Write-ManagerLog "installed startup task $StartupTaskName"
    Write-ManagerLog "installed watchdog task $WatchdogTaskName"
    Write-Output "Registered startup task: $StartupTaskName"
    Write-Output "Registered watchdog task: $WatchdogTaskName"
}

Assert-Prerequisites

switch ($Action) {
    "status" {
        Show-Status
    }
    "start" {
        Start-AllWorkers
    }
    "stop" {
        Stop-AllWorkers
    }
    "restart" {
        Restart-AllWorkers
    }
    "ensure" {
        Ensure-AllWorkers
    }
    "watchdog" {
        Invoke-Watchdog
    }
    "install-task" {
        Install-StartupTask
    }
}
