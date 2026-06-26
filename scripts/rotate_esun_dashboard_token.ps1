param(
    [string]$ProjectRoot = 'C:\www\blog',
    [string]$SettingsPath = '',
    [string]$AwsSession = 'aws',
    [string]$AwsProjectPath = '/var/www/html/blog',
    [string]$LocalBaseUrl = 'https://blog.test',
    [string]$AwsBaseUrl = 'https://mystar.monster',
    [string]$Token = '',
    [string]$LineTargetId = '',
    [switch]$SkipLineNotification,
    [switch]$LineNotificationOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

if ([string]::IsNullOrWhiteSpace($SettingsPath)) {
    $settingsFileName = ([string][char]0x8a2d) + ([string][char]0x5b9a) + '.txt'
    $SettingsPath = Join-Path ([Environment]::GetFolderPath('MyDocuments')) $settingsFileName
}

$projectRootResolved = [System.IO.Path]::GetFullPath($ProjectRoot)
$localEnvPath = Join-Path $projectRootResolved '.env'
$dashboardUrlPath = Join-Path $projectRootResolved 'storage\app\esun\dashboard-url.txt'
$logPath = Join-Path $projectRootResolved 'storage\logs\esun_dashboard_token_rotation.log'
$tokenKey = 'ESUN_PORTFOLIO_DASHBOARD_TOKEN'

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

function Get-DotEnvValue {
    param(
        [string]$Content,
        [string]$Key
    )

    $pattern = '(?m)^{0}=(.*)$' -f [regex]::Escape($Key)
    $match = [regex]::Match($Content, $pattern)
    if (-not $match.Success) {
        return ''
    }

    $value = $match.Groups[1].Value.Trim()
    if ($value.Length -ge 2) {
        $first = $value.Substring(0, 1)
        $last = $value.Substring($value.Length - 1, 1)
        if (($first -eq '"' -and $last -eq '"') -or ($first -eq "'" -and $last -eq "'")) {
            return $value.Substring(1, $value.Length - 2)
        }
    }

    return $value
}

function Update-SettingsFile {
    param(
        [string]$Path,
        [string]$Section
    )

    $content = Read-Text -Path $Path
    $pattern = '(?s)file:///C:/www/blog/storage/app/esun/dashboard-url\.txt.*?AWS:\s*https://mystar\.monster/tw-stock/esun-portfolio\?token=[^\r\n]+'
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
        [string]$ProjectPath
    )

    $python = @"
from pathlib import Path
import re

token = "$NewToken"
project = Path("$ProjectPath")
env_path = project / ".env"
dashboard_path = project / "storage" / "app" / "esun" / "dashboard-url.txt"
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
    "AWS:\nhttps://mystar.monster/tw-stock/esun-portfolio?token=" + token + "\n",
    encoding="utf-8",
)
print("aws_token_updated")
"@

    $remoteCommand = 'sudo -u www-data python3 - && cd /var/www/html/blog && sudo -u www-data env HOME=/tmp php artisan optimize:clear >/tmp/esun-token-optimize.log && sudo -u www-data env HOME=/tmp php artisan config:cache >/tmp/esun-token-config.log && echo aws_config_cached'
    $output = $python | & plink -load $Session -batch $remoteCommand 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ("AWS token update failed: " + ($output -join "`n"))
    }
}

function Get-LineAccessToken {
    param([string]$EnvContent)

    $channelId = Get-DotEnvValue -Content $EnvContent -Key 'LINE_CHANNEL_ID'
    $channelSecret = Get-DotEnvValue -Content $EnvContent -Key 'LINE_CHANNEL_SECRET'

    if (-not [string]::IsNullOrWhiteSpace($channelId) -and -not [string]::IsNullOrWhiteSpace($channelSecret)) {
        $response = Invoke-RestMethod `
            -Method Post `
            -Uri 'https://api.line.me/oauth2/v3/token' `
            -ContentType 'application/x-www-form-urlencoded' `
            -Body @{
                grant_type = 'client_credentials'
                client_id = $channelId
                client_secret = $channelSecret
            }

        if ([string]::IsNullOrWhiteSpace([string]$response.access_token)) {
            throw 'LINE stateless token response did not include access_token.'
        }

        return [string]$response.access_token
    }

    $token = Get-DotEnvValue -Content $EnvContent -Key 'LINE_CHANNEL_ACCESS_TOKEN'
    if ([string]::IsNullOrWhiteSpace($token)) {
        throw 'LINE credentials are missing from local .env.'
    }

    return $token
}

function Invoke-LineDashboardNotification {
    param(
        [string]$EnvContent,
        [string]$Message,
        [string]$TargetId = ''
    )

    $lineToken = Get-LineAccessToken -EnvContent $EnvContent
    if ([string]::IsNullOrWhiteSpace($TargetId)) {
        $TargetId = Get-DotEnvValue -Content $EnvContent -Key 'LINE_DASHBOARD_NOTIFY_TARGET_ID'
    }

    if ([string]::IsNullOrWhiteSpace($TargetId)) {
        Write-Log 'Skipped LINE dashboard notification because LINE_DASHBOARD_NOTIFY_TARGET_ID is not configured.'
        return
    }

    $payload = @{
        to = $TargetId
        messages = @(
            @{
                type = 'text'
                text = $Message
            }
        )
        notificationDisabled = $false
    } | ConvertTo-Json -Compress -Depth 5

    $headers = @{
        Authorization = 'Bearer {0}' -f $lineToken
        'X-Line-Retry-Key' = [guid]::NewGuid().ToString()
    }
    $body = [System.Text.Encoding]::UTF8.GetBytes($payload)

    $response = Invoke-WebRequest `
        -UseBasicParsing `
        -Method Post `
        -Uri 'https://api.line.me/v2/bot/message/push' `
        -Headers $headers `
        -ContentType 'application/json; charset=utf-8' `
        -Body $body

    if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 300) {
        throw ('LINE push failed with HTTP {0}.' -f $response.StatusCode)
    }

    $requestId = [string]$response.Headers['x-line-request-id']
    if (-not [string]::IsNullOrWhiteSpace($requestId)) {
        Write-Log ('Sent LINE dashboard notification. request_id={0}' -f $requestId)
    } else {
        Write-Log 'Sent LINE dashboard notification.'
    }
}

if (-not (Test-Path -LiteralPath $localEnvPath)) {
    throw "Local .env not found: $localEnvPath"
}

$initialLocalEnv = Read-Text -Path $localEnvPath

if ($LineNotificationOnly) {
    $existingToken = Get-DotEnvValue -Content $initialLocalEnv -Key $tokenKey
    if ([string]::IsNullOrWhiteSpace($existingToken) -and (Test-Path -LiteralPath $dashboardUrlPath)) {
        $dashboardText = Read-Text -Path $dashboardUrlPath
        $dashboardMatch = [regex]::Match($dashboardText, 'https://mystar\.monster/tw-stock/esun-portfolio\?token=([a-f0-9]{64})')
        if ($dashboardMatch.Success) {
            $existingToken = $dashboardMatch.Groups[1].Value
        }
    }

    if ([string]::IsNullOrWhiteSpace($existingToken)) {
        throw 'Could not find the current E.SUN dashboard token.'
    }

    $notifyAwsUrl = '{0}/tw-stock/esun-portfolio?token={1}' -f $AwsBaseUrl.TrimEnd('/'), $existingToken
    Write-Log 'Sending E.SUN dashboard LINE notification without rotating token.'
    Invoke-LineDashboardNotification -EnvContent $initialLocalEnv -Message ("E.SUN dashboard URL test`n{0}" -f $notifyAwsUrl) -TargetId $LineTargetId
    Write-Log 'Finished E.SUN dashboard LINE notification test.'
    exit 0
}

if ([string]::IsNullOrWhiteSpace($Token)) {
    $Token = New-HexToken
}

if ($Token -notmatch '^[a-f0-9]{64}$') {
    throw 'Token must be a 64-character lowercase hex string.'
}

$localUrl = '{0}/tw-stock/esun-portfolio?token={1}' -f $LocalBaseUrl.TrimEnd('/'), $Token
$awsUrl = '{0}/tw-stock/esun-portfolio?token={1}' -f $AwsBaseUrl.TrimEnd('/'), $Token
$section = "file:///C:/www/blog/storage/app/esun/dashboard-url.txt`r`nLocal:`r`n$localUrl`r`n`r`nAWS:`r`n$awsUrl"
$dashboardContent = "Local:`r`n$localUrl`r`n`r`nAWS:`r`n$awsUrl`r`n"

Write-Log 'Starting E.SUN dashboard token rotation.'

$localEnv = Read-Text -Path $localEnvPath
$localEnv = Set-DotEnvValue -Content $localEnv -Key $tokenKey -Value $Token
Write-Utf8NoBom -Path $localEnvPath -Content $localEnv
Write-Log 'Updated local .env token.'

Write-Utf8NoBom -Path $dashboardUrlPath -Content $dashboardContent
Write-Log 'Updated local dashboard-url.txt.'

Update-SettingsFile -Path $SettingsPath -Section $section
Write-Log 'Updated settings file dashboard URLs.'

Invoke-LocalArtisanCacheRefresh
Write-Log 'Refreshed local Laravel config cache.'

Invoke-AwsTokenUpdate -NewToken $Token -Session $AwsSession -ProjectPath $AwsProjectPath
Write-Log 'Updated AWS token and refreshed AWS config cache.'

if (-not $SkipLineNotification) {
    Invoke-LineDashboardNotification -EnvContent $localEnv -Message ("E.SUN dashboard URL updated`n{0}" -f $awsUrl) -TargetId $LineTargetId
}

Write-Log 'Finished E.SUN dashboard token rotation.'
