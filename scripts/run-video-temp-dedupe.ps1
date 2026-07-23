param(
    [string]$ProjectRoot = 'C:\Users\USER\Documents\project\blog',
    [string]$TargetDirectory = (Join-Path $env:USERPROFILE ('Videos\' + [char]0x66AB)),
    [string]$SourceRoot = ('H:\video(' + [char]0x91CD + [char]0x8DD1 + ')'),
    [ValidateRange(1, 16)]
    [int]$Workers = 8,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedTarget = (Resolve-Path -LiteralPath $TargetDirectory).Path
$resolvedSource = (Resolve-Path -LiteralPath $SourceRoot).Path

if (-not (Test-Path -LiteralPath $resolvedTarget -PathType Container)) {
    throw "TargetDirectory is not a directory: $resolvedTarget"
}

if (-not (Test-Path -LiteralPath $resolvedSource -PathType Container)) {
    throw "SourceRoot is not a directory: $resolvedSource"
}

$envPath = Join-Path $resolvedProject '.env'
$phpLine = Get-Content -LiteralPath $envPath |
    Where-Object { $_ -like 'FOLDER_VIDEO_PHP_BIN=*' } |
    Select-Object -First 1

if (-not $phpLine) {
    throw "FOLDER_VIDEO_PHP_BIN was not found in: $envPath"
}

$phpBin = ($phpLine -split '=', 2)[1].Trim().Trim('"')
if (-not (Test-Path -LiteralPath $phpBin -PathType Leaf)) {
    throw "PHP executable does not exist: $phpBin"
}

Write-Host "Database source root (read-only): $resolvedSource"
Write-Host "Deduplication target (only deletion scope): $resolvedTarget"
Write-Host ("Mode: " + $(if ($DryRun) { 'dry-run; no video deletion' } else { 'delete confirmed duplicates' }))

$artisanArgs = @(
    'artisan',
    'video:move-duplicates',
    $resolvedTarget,
    '--recursive=1',
    "--reference-dir=$resolvedTarget",
    '--in-place-dedupe',
    '--threshold=80',
    '--min-match=2',
    '--window-seconds=3',
    '--max-candidates=250',
    '--repair-db-features=0',
    '--deep-single-frame=1',
    '--min-age-seconds=0',
    '--no-interaction'
)

if ($DryRun) {
    $artisanArgs += '--dry-run'
}

Push-Location $resolvedProject
try {
    & $phpBin artisan video:build-feature-index-fast $resolvedTarget "--workers=$Workers" --no-interaction
    if ($LASTEXITCODE -ne 0) {
        throw "Fast feature cache build failed with exit code $LASTEXITCODE"
    }

    & $phpBin @artisanArgs
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
