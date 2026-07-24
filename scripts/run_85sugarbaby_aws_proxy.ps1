[CmdletBinding()]
param(
    [string] $SshExecutable = "$env:WINDIR\System32\OpenSSH\ssh.exe",
    [string] $PrivateKeyPath = "$env:USERPROFILE\.ssh\aws-sky-lightsail.pem",
    [string] $RemoteHost = '13.114.44.241',
    [string] $RemoteUser = 'ubuntu',
    [int] $RemoteSocksPort = 10885,
    [int] $ReconnectDelaySeconds = 10
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$logDirectory = Join-Path $projectRoot 'storage\logs'
$logPath = Join-Path $logDirectory 'crawler_85sugarbaby_aws_proxy.log'
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null

function Write-ProxyLog {
    param([string] $Message)

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -LiteralPath $logPath -Value "[$timestamp] $Message" -Encoding UTF8
}

if (-not (Test-Path -LiteralPath $SshExecutable -PathType Leaf)) {
    Write-ProxyLog "SSH executable not found: $SshExecutable"
    exit 2
}

if (-not (Test-Path -LiteralPath $PrivateKeyPath -PathType Leaf)) {
    Write-ProxyLog "Lightsail key not found: $PrivateKeyPath"
    exit 3
}

$mutex = [Threading.Mutex]::new($false, 'Local\Blog85SugarbabyAwsEgress')
$ownsMutex = $false

try {
    $ownsMutex = $mutex.WaitOne(0, $false)
    if (-not $ownsMutex) {
        Write-ProxyLog 'Another proxy monitor is already running.'
        exit 0
    }

    $sshArguments = @(
        '-N',
        '-T',
        '-R', "127.0.0.1:$RemoteSocksPort",
        '-i', $PrivateKeyPath,
        '-o', 'IdentitiesOnly=yes',
        '-o', 'BatchMode=yes',
        '-o', 'ExitOnForwardFailure=yes',
        '-o', 'ServerAliveInterval=30',
        '-o', 'ServerAliveCountMax=3',
        '-o', 'TCPKeepAlive=yes',
        '-o', 'LogLevel=ERROR',
        "$RemoteUser@$RemoteHost"
    )

    Write-ProxyLog "Starting AWS reverse SOCKS monitor on remote localhost port $RemoteSocksPort."
    while ($true) {
        & $SshExecutable @sshArguments
        $exitCode = $LASTEXITCODE
        Write-ProxyLog "SSH tunnel exited with code $exitCode; reconnecting in $ReconnectDelaySeconds seconds."
        Start-Sleep -Seconds ([Math]::Max(3, $ReconnectDelaySeconds))
    }
} catch {
    Write-ProxyLog "Proxy monitor failed: $($_.Exception.Message)"
    exit 1
} finally {
    if ($ownsMutex) {
        $mutex.ReleaseMutex()
    }
    $mutex.Dispose()
}
