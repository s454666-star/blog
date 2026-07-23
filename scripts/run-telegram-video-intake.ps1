param(
    [string]$ProjectRoot = 'C:\Users\USER\Documents\project\blog',
    [string]$InboxDirectory = (Join-Path $env:USERPROFILE 'Downloads\Telegram Desktop'),
    [string]$DestinationDirectory = (Join-Path $env:USERPROFILE ('Videos\' + [char]0x66AB)),
    [ValidateRange(1, 16)]
    [int]$Workers = 8,
    [ValidateRange(0, 3600)]
    [int]$MinAgeSeconds = 120,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

function Get-VideoInventory {
    param([string]$Path)

    $extensions = @(
        '.mp4', '.avi', '.mov', '.mkv', '.wmv', '.flv', '.webm',
        '.m4v', '.mpeg', '.mpg', '.3gp', '.ts', '.mts', '.m2ts'
    )
    $files = @(
        Get-ChildItem -LiteralPath $Path -File -Recurse |
            Where-Object { $extensions -contains $_.Extension.ToLowerInvariant() }
    )
    $bytes = ($files | Measure-Object -Property Length -Sum).Sum

    [pscustomobject]@{
        Count = $files.Count
        Bytes = if ($null -eq $bytes) { 0 } else { [int64]$bytes }
    }
}

function Invoke-Artisan {
    param(
        [string]$Label,
        [string[]]$Arguments
    )

    Write-Host ''
    Write-Host $Label -ForegroundColor Cyan
    & $script:PhpBin @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with exit code $LASTEXITCODE"
    }
}

$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedInbox = (Resolve-Path -LiteralPath $InboxDirectory).Path

if (-not (Test-Path -LiteralPath $DestinationDirectory -PathType Container)) {
    New-Item -ItemType Directory -Path $DestinationDirectory | Out-Null
}
$resolvedDestination = (Resolve-Path -LiteralPath $DestinationDirectory).Path

if ($resolvedInbox.TrimEnd('\') -eq $resolvedDestination.TrimEnd('\')) {
    throw 'InboxDirectory and DestinationDirectory must be different directories.'
}

$envPath = Join-Path $resolvedProject '.env'
$phpLine = Get-Content -LiteralPath $envPath |
    Where-Object { $_ -like 'FOLDER_VIDEO_PHP_BIN=*' } |
    Select-Object -First 1

if (-not $phpLine) {
    throw "FOLDER_VIDEO_PHP_BIN was not found in: $envPath"
}

$script:PhpBin = ($phpLine -split '=', 2)[1].Trim().Trim('"')
if (-not (Test-Path -LiteralPath $script:PhpBin -PathType Leaf)) {
    throw "PHP executable does not exist: $script:PhpBin"
}

$logDirectory = Join-Path $resolvedProject 'storage\logs'
if (-not (Test-Path -LiteralPath $logDirectory -PathType Container)) {
    New-Item -ItemType Directory -Path $logDirectory | Out-Null
}
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$logPath = Join-Path $logDirectory "telegram-video-intake-$timestamp.log"
$exitCode = 0
$transcriptStarted = $false

try {
    Start-Transcript -LiteralPath $logPath -Force | Out-Null
    $transcriptStarted = $true
    $Host.UI.RawUI.WindowTitle = 'Telegram video duplicate scan'

    $mode = if ($DryRun) { 'DRY RUN - no delete or move' } else { 'LIVE - delete duplicates and move unique videos' }
    Write-Host 'Telegram video intake' -ForegroundColor Green
    Write-Host "Mode: $mode"
    Write-Host "Inbox: $resolvedInbox"
    Write-Host "Destination: $resolvedDestination"
    Write-Host "Progress log: $logPath"
    Write-Host "Recent-file safety window: $MinAgeSeconds seconds"

    $beforeInbox = Get-VideoInventory -Path $resolvedInbox
    $beforeDestination = Get-VideoInventory -Path $resolvedDestination
    Write-Host "Initial inbox: $($beforeInbox.Count) videos, $($beforeInbox.Bytes) bytes"
    Write-Host "Initial destination: $($beforeDestination.Count) videos, $($beforeDestination.Bytes) bytes"

    Push-Location $resolvedProject
    try {
        Write-Progress -Activity 'Telegram video intake' -Status '1/4 Building reusable feature cache' -PercentComplete 10
        Invoke-Artisan -Label '[1/4] Build/reuse Telegram feature cache' -Arguments @(
            'artisan',
            'video:build-feature-index-fast',
            $resolvedInbox,
            "--workers=$Workers",
            '--no-interaction'
        )

        $commonArguments = @(
            '--recursive=1',
            '--threshold=80',
            '--min-match=2',
            '--window-seconds=3',
            '--max-candidates=250',
            '--repair-db-features=0',
            '--deep-single-frame=1',
            "--min-age-seconds=$MinAgeSeconds",
            '--reference-min-age-seconds=0',
            '--no-interaction'
        )
        if ($DryRun) {
            $commonArguments += '--dry-run'
        }

        Write-Progress -Activity 'Telegram video intake' -Status '2/4 Removing duplicates inside Telegram folder' -PercentComplete 30
        Invoke-Artisan -Label '[2/4] Scan duplicates inside Telegram folder first' -Arguments (@(
            'artisan',
            'video:move-duplicates',
            $resolvedInbox,
            "--reference-dir=$resolvedInbox",
            '--in-place-dedupe',
            '--skip-database'
        ) + $commonArguments)

        Write-Progress -Activity 'Telegram video intake' -Status '3/4 Comparing DB and destination; moving unique files' -PercentComplete 60
        Invoke-Artisan -Label '[3/4] Compare DB master files and destination; move unique videos' -Arguments (@(
            'artisan',
            'video:move-duplicates',
            $resolvedInbox,
            "--reference-dir=$resolvedDestination",
            '--reuse-source-index'
        ) + $commonArguments)
    } finally {
        Pop-Location
    }

    Write-Progress -Activity 'Telegram video intake' -Status '4/4 Complete' -PercentComplete 100
    $afterInbox = Get-VideoInventory -Path $resolvedInbox
    $afterDestination = Get-VideoInventory -Path $resolvedDestination

    Write-Host ''
    Write-Host '[4/4] Completed' -ForegroundColor Green
    Write-Host "Inbox before/after: $($beforeInbox.Count) -> $($afterInbox.Count) videos"
    Write-Host "Destination before/after: $($beforeDestination.Count) -> $($afterDestination.Count) videos"
    if ($afterInbox.Count -gt 0) {
        Write-Host 'Videos left in the inbox may be recent/deferred or failed. Check the log and run again later.' -ForegroundColor Yellow
    }
    Write-Host "Log: $logPath"
} catch {
    $exitCode = 1
    Write-Host ''
    Write-Host ('FAILED: ' + $_.Exception.Message) -ForegroundColor Red
    Write-Host "Log: $logPath"
} finally {
    Write-Progress -Activity 'Telegram video intake' -Completed
    if ($transcriptStarted) {
        Stop-Transcript | Out-Null
    }
}

exit $exitCode
