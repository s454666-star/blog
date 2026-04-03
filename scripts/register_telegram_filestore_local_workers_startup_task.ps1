param(
    [Alias("StartupTaskName")]
    [string]$LogonTaskName = "Blog Telegram Filestore Local Workers Logon",
    [string]$WatchdogTaskName = "Blog Telegram Filestore Local Workers Watchdog",
    [Alias("StartupWrapperPath")]
    [string]$LogonWrapperPath = "C:\www\blog\scripts\start_telegram_filestore_local_workers.bat",
    [string]$WatchdogWrapperPath = "C:\www\blog\scripts\watchdog_telegram_filestore_local_workers.bat",
    [int]$MinWorkerCount = 16,
    [int]$MaxWorkerCount = 200,
    [int]$WatchdogIntervalMinutes = 5,
    [int]$ScaleDownHoldSeconds = 300
)

$ErrorActionPreference = "Stop"

$repoRoot = 'C:\www\blog'
$hiddenRunnerPath = Join-Path $repoRoot 'scripts\run_hidden_task.ps1'
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew
$logonDescription = "At user logon, run the local telegram_filestore watchdog once so workers converge to the current desired count with min $MinWorkerCount and max $MaxWorkerCount."
$watchdogDescription = "Every $WatchdogIntervalMinutes minutes, reconcile local telegram_filestore workers against queue load with min $MinWorkerCount, max $MaxWorkerCount, and a $ScaleDownHoldSeconds-second downscale hold."

if (-not (Test-Path -LiteralPath $hiddenRunnerPath)) {
    throw "Hidden task runner not found: $hiddenRunnerPath"
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
        [string]$Description
    )

    try {
        Register-ScheduledTask `
            -TaskName $TaskName `
            -Action $Action `
            -Trigger $Trigger `
            -Settings $settings `
            -Description $Description `
            -User $currentUser `
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
            -Settings $settings | Out-Null

        Update-TaskDescriptionViaCom -TaskName $TaskName -Description $Description
        Write-Warning "Register-ScheduledTask was denied for '$TaskName'. Updated the existing task via Set-ScheduledTask and refreshed the description via COM instead."
    }
}

Unregister-ScheduledTask -TaskName "Blog Telegram Filestore Local Workers Startup" -Confirm:$false -ErrorAction SilentlyContinue

$logonAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -BatchPath "{1}"' -f $hiddenRunnerPath, $LogonWrapperPath)
$logonTrigger = New-ScheduledTaskTrigger -AtLogOn

Register-OrRefreshTaskDescription `
    -TaskName $LogonTaskName `
    -Action $logonAction `
    -Trigger @($logonTrigger) `
    -Description $logonDescription

$watchdogAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -BatchPath "{1}"' -f $hiddenRunnerPath, $WatchdogWrapperPath)
$watchdogTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes $WatchdogIntervalMinutes)

Register-OrRefreshTaskDescription `
    -TaskName $WatchdogTaskName `
    -Action $watchdogAction `
    -Trigger @($watchdogTrigger) `
    -Description $watchdogDescription

Write-Host "Registered logon task: $LogonTaskName"
Write-Host "Registered watchdog task: $WatchdogTaskName"
