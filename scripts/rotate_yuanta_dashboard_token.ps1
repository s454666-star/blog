param(
    [string]$ProjectRoot = 'C:\www\blog',
    [string]$SettingsPath = '',
    [string]$AwsSession = 'aws',
    [string]$AwsProjectPath = '/var/www/html/blog',
    [string]$LocalBaseUrl = 'https://blog.test',
    [string]$AwsBaseUrl = 'https://mystar.monster',
    [string]$Token = '',
    [switch]$SkipLineNotification
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

if ([string]::IsNullOrWhiteSpace($SettingsPath)) {
    $settingsFileName = ([string][char]0x8a2d) + ([string][char]0x5b9a) + '.txt'
    $SettingsPath = Join-Path ([Environment]::GetFolderPath('MyDocuments')) $settingsFileName
}

$projectRootResolved = [System.IO.Path]::GetFullPath($ProjectRoot)
$localEnvPath = Join-Path $projectRootResolved '.env'
$dashboardUrlPath = Join-Path $projectRootResolved 'storage\app\yuanta\dashboard-url.txt'
$logPath = Join-Path $projectRootResolved 'storage\logs\yuanta_dashboard_token_rotation.log'
$tokenKey = 'YUANTA_PORTFOLIO_DASHBOARD_TOKEN'

function Write-Log {
    param([string]$Message)

    $line = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    $logDir = Split-Path -Parent $script:logPath
    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }
    Add-Content -LiteralPath $script:logPath -Value $line -Encoding UTF8
    Write-Host $line
}

function New-HexToken {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes)
    } finally {
        $rng.Dispose()
    }

    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

function Read-Text {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return ''
    }

    return [System.IO.File]::ReadAllText($Path, [System.Text.Encoding]::UTF8)
}

function Write-Utf8NoBom {
    param(
        [string]$Path,
        [string]$Content
    )

    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }

    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $encoding)
}

function Set-DotEnvValue {
    param(
        [string]$Content,
        [string]$Key,
        [string]$Value
    )

    $pattern = '(?m)^{0}=.*$' -f [regex]::Escape($Key)
    $replacement = '{0}={1}' -f $Key, $Value
    $regex = New-Object System.Text.RegularExpressions.Regex($pattern)

    if ($regex.IsMatch($Content)) {
        return $regex.Replace($Content, $replacement, 1)
    }

    return $Content.TrimEnd("`r", "`n") + "`r`n" + $replacement + "`r`n"
}

function Update-SettingsFile {
    param(
        [string]$Path,
        [string]$Section
    )

    $content = Read-Text -Path $Path
    $pattern = '(?s)file:///C:/www/blog/storage/app/yuanta/dashboard-url\.txt.*?AWS:\s*https://mystar\.monster/tw-stock/yuanta-portfolio\?token=[^\r\n]+'
    $regex = New-Object System.Text.RegularExpressions.Regex($pattern)

    if ($regex.IsMatch($content)) {
        $updated = $regex.Replace($content, $Section, 1)
    } else {
        $updated = $content.TrimEnd("`r", "`n") + "`r`n`r`n" + $Section + "`r`n"
    }

    Write-Utf8NoBom -Path $Path -Content $updated
}

function Invoke-LocalArtisanCacheRefresh {
    $phpCandidates = @('C:\php\php.exe', 'php')
    $php = $phpCandidates | Where-Object { $_ -eq 'php' -or (Test-Path -LiteralPath $_) } | Select-Object -First 1

    Push-Location $script:projectRootResolved
    try {
        & $php artisan optimize:clear | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw 'Local artisan optimize:clear failed.'
        }
        & $php artisan config:cache | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw 'Local artisan config:cache failed.'
        }
    } finally {
        Pop-Location
    }
}

function Invoke-AwsTokenUpdate {
    param(
        [string]$NewToken,
        [string]$Session,
        [string]$ProjectPath,
        [switch]$NotifyLine
    )

    $python = @"
from pathlib import Path
import re

token = "$NewToken"
project = Path("$ProjectPath")
env_path = project / ".env"
dashboard_path = project / "storage" / "app" / "yuanta" / "dashboard-url.txt"
key = "$tokenKey"
content = env_path.read_text(encoding="utf-8")
pattern = re.compile(r"(?m)^" + re.escape(key) + r"=.*$")
replacement = key + "=" + token
if pattern.search(content):
    content = pattern.sub(replacement, content, count=1)
else:
    content = content.rstrip("\r\n") + "\n" + replacement + "\n"
env_path.write_text(content, encoding="utf-8")
dashboard_path.parent.mkdir(parents=True, exist_ok=True)
dashboard_path.write_text(
    "AWS:\nhttps://mystar.monster/tw-stock/yuanta-portfolio?token=" + token + "\n",
    encoding="utf-8",
)
print("aws_yuanta_token_updated")
"@

    $notifyCommand = ''
    if ($NotifyLine) {
        $notifyCommand = ' && sudo -u www-data env HOME=/tmp php artisan line:send-yuanta-dashboard-url'
    }

    $remoteCommand = 'sudo -u www-data python3 - && cd ' + $ProjectPath + ' && sudo -u www-data env HOME=/tmp php artisan optimize:clear >/tmp/yuanta-token-optimize.log && sudo -u www-data env HOME=/tmp php artisan config:cache >/tmp/yuanta-token-config.log' + $notifyCommand + ' && echo aws_yuanta_config_cached'
    $output = $python | & plink.exe -load $Session -batch $remoteCommand 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ("AWS Yuanta token update failed: " + ($output -join "`n"))
    }

    foreach ($line in $output) {
        if ($line -match 'request_id=') {
            Write-Log $line
        }
    }
}

if (-not (Test-Path -LiteralPath $localEnvPath)) {
    throw "Local .env not found: $localEnvPath"
}

if ([string]::IsNullOrWhiteSpace($Token)) {
    $Token = New-HexToken
}

if ($Token -notmatch '^[a-f0-9]{64}$') {
    throw 'Token must be a 64-character lowercase hex string.'
}

$localUrl = '{0}/tw-stock/yuanta-portfolio?token={1}' -f $LocalBaseUrl.TrimEnd('/'), $Token
$awsUrl = '{0}/tw-stock/yuanta-portfolio?token={1}' -f $AwsBaseUrl.TrimEnd('/'), $Token
$section = "file:///C:/www/blog/storage/app/yuanta/dashboard-url.txt`r`nLocal:`r`n$localUrl`r`n`r`nAWS:`r`n$awsUrl"
$dashboardContent = "Local:`r`n$localUrl`r`n`r`nAWS:`r`n$awsUrl`r`n"

Write-Log 'Starting Yuanta dashboard token rotation.'

$localEnv = Read-Text -Path $localEnvPath
$localEnv = Set-DotEnvValue -Content $localEnv -Key $tokenKey -Value $Token
Write-Utf8NoBom -Path $localEnvPath -Content $localEnv
Write-Log 'Updated local Yuanta .env token.'

Write-Utf8NoBom -Path $dashboardUrlPath -Content $dashboardContent
Write-Log 'Updated local Yuanta dashboard-url.txt.'

Update-SettingsFile -Path $SettingsPath -Section $section
Write-Log 'Updated settings file Yuanta dashboard URLs.'

Invoke-LocalArtisanCacheRefresh
Write-Log 'Refreshed local Laravel config cache.'

Invoke-AwsTokenUpdate -NewToken $Token -Session $AwsSession -ProjectPath $AwsProjectPath -NotifyLine:(-not $SkipLineNotification)
Write-Log 'Updated AWS Yuanta token and refreshed AWS config cache.'

if ($SkipLineNotification) {
    Write-Log 'Skipped Yuanta LINE dashboard notification by request.'
} else {
    Write-Log 'Requested Yuanta LINE dashboard notification from AWS.'
}

Write-Log 'Finished Yuanta dashboard token rotation.'
