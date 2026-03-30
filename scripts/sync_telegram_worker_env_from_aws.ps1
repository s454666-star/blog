param(
    [string]$ProjectDir = "C:\www\blog",
    [string]$PuttySession = "aws",
    [string]$OutputPath = "C:\www\blog\storage\app\telegram-filestore-local-workers\worker.env",
    [string]$CaBundlePath = "C:\www\blog\storage\app\telegram-filestore-local-workers\cacert.pem"
)

$ErrorActionPreference = "Stop"

$plink = (Get-Command plink.exe -ErrorAction Stop).Source
$outputDirectory = Split-Path -Parent $OutputPath

if (-not (Test-Path -LiteralPath $outputDirectory)) {
    New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
}

Invoke-WebRequest -UseBasicParsing -Uri "https://curl.se/ca/cacert.pem" -OutFile $CaBundlePath

$remoteCommand = "cd /var/www/html/blog && grep -E '^(APP_URL|TELEGRAM_[A-Z0-9_]*BOT_TOKEN)=' .env"
$remoteLines = & $plink -load $PuttySession -batch $remoteCommand

if ($LASTEXITCODE -ne 0) {
    throw "Failed to read BOT_TOKEN values from AWS session '$PuttySession'."
}

$lines = New-Object System.Collections.Generic.List[string]
$lines.Add("# Generated from AWS on $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')")
$lines.Add("APP_ENV=production")
$lines.Add("APP_DEBUG=false")
$lines.Add("QUEUE_CONNECTION=database")
$lines.Add("CACHE_DRIVER=database")
$lines.Add("SSL_CERT_FILE=$CaBundlePath")
$lines.Add("CURL_CA_BUNDLE=$CaBundlePath")

foreach ($line in $remoteLines) {
    if ($line -match '^[A-Z0-9_]+=') {
        $lines.Add($line)
    }
}

$hasFilestoreToken = $false
foreach ($line in $lines) {
    if ($line -like "TELEGRAM_FILESTORE_BOT_TOKEN=*") {
        $hasFilestoreToken = $true
        break
    }
}

if (-not $hasFilestoreToken) {
    throw "AWS .env did not return TELEGRAM_FILESTORE_BOT_TOKEN."
}

Set-Content -LiteralPath $OutputPath -Value $lines -Encoding ascii
Set-Content -LiteralPath (Join-Path $outputDirectory "restart.request") -Value "env-updated $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -Encoding ascii
Write-Host "Wrote worker env: $OutputPath"
