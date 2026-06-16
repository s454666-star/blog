param(
    [string] $ProjectDir = 'C:\www\blog',
    [string] $RedisHome = 'C:\Users\User\Tools\redis\Redis-8.6.2-Windows-x64-msys2',
    [string] $CloudflaredServiceName = 'Cloudflared',
    [string] $CloudflaredExe = 'C:\Program Files (x86)\cloudflared\cloudflared.exe',
    [string] $UserCloudflaredConfig = 'C:\Users\User\.cloudflared\config.yml',
    [string] $UserCloudflaredLog = 'C:\Users\User\.cloudflared\cloudflared-user-mystar.log',
    [string] $AwsPuttySession = 'aws',
    [string] $AwsProjectDir = '/var/www/html/blog',
    [int] $RedisPort = 6379,
    [int] $RestartWaitSeconds = 5
)

$ErrorActionPreference = 'Stop'

$logDir = Join-Path $ProjectDir 'storage\logs'
$logPath = Join-Path $logDir 'cloudflared_redis_watchdog.log'
$redisExe = Join-Path $RedisHome 'redis-server.exe'
$redisCli = Join-Path $RedisHome 'redis-cli.exe'
$redisStartScript = Join-Path $RedisHome 'start-redis.ps1'
$redisConfig = 'redis-local.conf'
$plink = 'C:\Program Files\PuTTY\plink.exe'

function Write-WatchdogLog {
    param([string] $Message)

    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -LiteralPath $logPath -Value "[$timestamp] $Message" -Encoding UTF8
}

function Get-BlogEnvValue {
    param([string] $Name)

    $envPath = Join-Path $ProjectDir '.env'
    if (-not (Test-Path -LiteralPath $envPath)) {
        return $null
    }

    $line = Get-Content -LiteralPath $envPath | Where-Object { $_ -match "^$([regex]::Escape($Name))=" } | Select-Object -First 1
    if (-not $line) {
        return $null
    }

    $value = $line -replace "^$([regex]::Escape($Name))=", ''
    return $value.Trim().Trim('"').Trim("'")
}

function Get-RedisProcesses {
    Get-CimInstance Win32_Process -Filter "Name = 'redis-server.exe'" -ErrorAction SilentlyContinue |
        Where-Object {
            $path = if ($_.ExecutablePath) { $_.ExecutablePath } else { '' }
            $path -eq $redisExe
        }
}

function Get-MainCloudflaredUserProcesses {
    $configPattern = "*$($UserCloudflaredConfig)*"

    Get-CimInstance Win32_Process -Filter "Name = 'cloudflared.exe'" -ErrorAction SilentlyContinue |
        Where-Object {
            $commandLine = if ($_.CommandLine) { $_.CommandLine } else { '' }
            $commandLine -like $configPattern
        }
}

function Start-UserCloudflaredTunnel {
    if (-not (Test-Path -LiteralPath $CloudflaredExe)) {
        throw "cloudflared.exe not found at $CloudflaredExe"
    }

    if (-not (Test-Path -LiteralPath $UserCloudflaredConfig)) {
        throw "Cloudflared user config not found at $UserCloudflaredConfig"
    }

    if (Get-MainCloudflaredUserProcesses) {
        return
    }

    Write-WatchdogLog "starting user-mode Cloudflared tunnel from $UserCloudflaredConfig"
    Start-Process -FilePath $CloudflaredExe `
        -ArgumentList @('tunnel', '--config', $UserCloudflaredConfig, '--logfile', $UserCloudflaredLog, '--loglevel', 'info', 'run') `
        -WindowStyle Hidden
    Start-Sleep -Seconds ($RestartWaitSeconds * 2)
}

function Restart-UserCloudflaredTunnel {
    $processes = @(Get-MainCloudflaredUserProcesses)
    foreach ($process in $processes) {
        try {
            Stop-Process -Id $process.ProcessId -Force -ErrorAction Stop
        } catch {
            Write-WatchdogLog "failed to stop user-mode cloudflared pid=$($process.ProcessId): $($_.Exception.Message)"
        }
    }

    Start-Sleep -Seconds 2
    Start-UserCloudflaredTunnel
}

function Start-LocalRedis {
    if (Test-Path -LiteralPath $redisStartScript) {
        powershell.exe -NoProfile -ExecutionPolicy Bypass -File $redisStartScript | Out-Null
    } else {
        Start-Process -FilePath $redisExe -WorkingDirectory $RedisHome -ArgumentList @($redisConfig) -WindowStyle Hidden
    }

    Start-Sleep -Seconds $RestartWaitSeconds
}

function Test-LocalRedisPing {
    if (-not (Test-Path -LiteralPath $redisCli)) {
        throw "redis-cli not found at $redisCli"
    }

    $password = Get-BlogEnvValue 'REDIS_PASSWORD'
    $arguments = @('-h', '127.0.0.1', '-p', $RedisPort, '--no-auth-warning')

    if ($password) {
        $arguments += @('-a', $password)
    }

    $arguments += 'ping'
    $output = & $redisCli @arguments 2>&1

    return ($LASTEXITCODE -eq 0 -and (($output -join "`n").Trim() -eq 'PONG'))
}

function Restart-LocalRedis {
    $password = Get-BlogEnvValue 'REDIS_PASSWORD'

    if ((Test-Path -LiteralPath $redisCli) -and $password) {
        & $redisCli -h 127.0.0.1 -p $RedisPort -a $password --no-auth-warning shutdown save 2>$null | Out-Null
        Start-Sleep -Seconds 2
    }

    $processes = @(Get-RedisProcesses)
    foreach ($process in $processes) {
        try {
            Stop-Process -Id $process.ProcessId -Force -ErrorAction Stop
        } catch {
            Write-WatchdogLog "failed to stop redis-server pid=$($process.ProcessId): $($_.Exception.Message)"
        }
    }

    Start-LocalRedis
}

function Ensure-CloudflaredService {
    $service = Get-Service -Name $CloudflaredServiceName -ErrorAction SilentlyContinue
    if ($service.Status -eq 'Running') {
        return
    }

    if (Get-MainCloudflaredUserProcesses) {
        return
    }

    if ($null -eq $service) {
        Write-WatchdogLog "$CloudflaredServiceName service is not available, using user-mode tunnel"
        Start-UserCloudflaredTunnel
        return
    }

    Write-WatchdogLog "$CloudflaredServiceName service was $($service.Status), starting"
    try {
        Start-Service -Name $CloudflaredServiceName -ErrorAction Stop
        Start-Sleep -Seconds $RestartWaitSeconds
    } catch {
        Write-WatchdogLog "could not start $CloudflaredServiceName service: $($_.Exception.Message); using user-mode tunnel"
        Start-UserCloudflaredTunnel
    }
}

function Restart-CloudflaredService {
    $service = Get-Service -Name $CloudflaredServiceName -ErrorAction SilentlyContinue

    if ($service.Status -eq 'Running') {
        Write-WatchdogLog "restarting $CloudflaredServiceName service"
        try {
            Restart-Service -Name $CloudflaredServiceName -Force -ErrorAction Stop
            Start-Sleep -Seconds ($RestartWaitSeconds * 2)
            return
        } catch {
            Write-WatchdogLog "could not restart $CloudflaredServiceName service: $($_.Exception.Message); restarting user-mode tunnel"
        }
    } else {
        Write-WatchdogLog "$CloudflaredServiceName service is not running, restarting user-mode tunnel"
    }

    Restart-UserCloudflaredTunnel
}

function Test-AwsRedisPing {
    if (-not (Test-Path -LiteralPath $plink)) {
        Write-WatchdogLog "plink not found at $plink; skipping AWS Redis ping"
        return $true
    }

    $php = @'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $pong = Illuminate\Support\Facades\Redis::connection()->ping();
    echo is_bool($pong) ? ($pong ? 'PONG' : 'FALSE') : (string) $pong;
    echo PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
}
'@

    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($php))
    $remoteCommand = "cd $AwsProjectDir && printf '%s' '$encoded' | base64 -d | sudo -u www-data env HOME=/tmp php"
    $output = & $plink -batch $AwsPuttySession $remoteCommand 2>&1

    if ($LASTEXITCODE -eq 0 -and (($output -join "`n") -match 'PONG')) {
        return $true
    }

    Write-WatchdogLog "AWS Redis ping failed: $($output -join ' ')"
    return $false
}

function Restart-AwsRedisTunnel {
    if (-not (Test-Path -LiteralPath $plink)) {
        return
    }

    Write-WatchdogLog 'restarting AWS blog-redis-tunnel.service'
    & $plink -batch $AwsPuttySession 'sudo -n systemctl restart blog-redis-tunnel.service' 2>&1 | Out-Null
    Start-Sleep -Seconds ($RestartWaitSeconds * 2)
}

try {
    Ensure-CloudflaredService

    if (-not (Get-RedisProcesses)) {
        Write-WatchdogLog 'redis-server process missing, starting'
        Start-LocalRedis
    }

    if (-not (Test-LocalRedisPing)) {
        Write-WatchdogLog 'local Redis ping failed, restarting redis-server'
        Restart-LocalRedis
    }

    if (-not (Test-AwsRedisPing)) {
        Restart-CloudflaredService
        Restart-AwsRedisTunnel

        if (-not (Test-AwsRedisPing)) {
            throw 'AWS still cannot ping Redis after restarting Cloudflared and the AWS tunnel'
        }
    }
} catch {
    Write-WatchdogLog "ERROR: $($_.Exception.Message)"
    exit 1
}
