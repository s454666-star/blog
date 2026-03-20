param(
    [string] $TargetPath
)

$ErrorActionPreference = 'Stop'

[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)

$appDir = 'C:\www\blog'
$phpExe = 'C:\php\php.exe'
$artisanPath = Join-Path $appDir 'artisan'
$logDir = Join-Path $appDir 'storage\logs'
$logPath = Join-Path $logDir 'run_extract_failures_then_move_duplicates.log'
$defaultTargetPath = [string]::Concat(
    'C:\Users\User\Pictures\train\downloads\group_3406828124_xsmyyds',
    [char] 0x4F1A,
    [char] 0x5458,
    [char] 0x7FA4,
    '\videos\tmp'
)

if (-not (Test-Path -LiteralPath $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$script:logStream = [System.IO.File]::Open($logPath, [System.IO.FileMode]::Create, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite)
$script:logWriter = New-Object System.IO.StreamWriter($script:logStream, $utf8NoBom)
$script:logWriter.AutoFlush = $true

function Write-LogLine {
    param(
        [string] $Line
    )

    $script:logWriter.WriteLine($Line)
}

function Write-Log {
    param(
        [string] $Message
    )

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $line = "[${timestamp}] $Message"
    Write-Host $line
    Write-LogLine -Line $line
}

function Format-ArgumentList {
    param(
        [string[]] $Arguments
    )

    return ($Arguments | ForEach-Object {
        if ($_ -match '\s') {
            '"' + $_ + '"'
        } else {
            $_
        }
    }) -join ' '
}

function Invoke-Artisan {
    param(
        [string] $StepName,
        [string[]] $Arguments
    )

    $renderedArgs = Format-ArgumentList -Arguments $Arguments
    Write-Log "Start ${StepName}: php artisan $renderedArgs"

    Push-Location $appDir
    try {
        & $phpExe 'artisan' @Arguments 2>&1 | ForEach-Object {
            $line = $_.ToString()
            Write-Host $line
            Write-LogLine -Line $line
        }
        $exitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }

    Write-Log "Finish ${StepName}: exit_code=$exitCode"
    return $exitCode
}

if (-not (Test-Path -LiteralPath $phpExe -PathType Leaf)) {
    Write-Log "PHP not found: $phpExe"
    exit 1
}

if (-not (Test-Path -LiteralPath $artisanPath -PathType Leaf)) {
    Write-Log "Artisan not found: $artisanPath"
    exit 1
}

if ([string]::IsNullOrWhiteSpace($TargetPath)) {
    $TargetPath = $defaultTargetPath
}

if (-not (Test-Path -LiteralPath $TargetPath -PathType Container)) {
    Write-Log "Target folder not found: $TargetPath"
    exit 1
}

try {
    Write-Log "Target folder: $TargetPath"

    $extractExit = Invoke-Artisan -StepName 'extract needed features (video_type=1)' -Arguments @(
        'video:extract-features',
        '--video-type=1'
    )

    if ($extractExit -ne 0) {
        Write-Log 'Extract step reported failures. Continue to move-duplicates.'
    }

    $moveExit = Invoke-Artisan -StepName 'move duplicates' -Arguments @(
        'video:move-duplicates',
        $TargetPath
    )

    if ($moveExit -ne 0) {
        exit $moveExit
    }

    if ($extractExit -ne 0) {
        Write-Log 'Finished with extract warnings.'
    }

    exit 0
} finally {
    if ($null -ne $script:logWriter) {
        $script:logWriter.Dispose()
    }
    if ($null -ne $script:logStream) {
        $script:logStream.Dispose()
    }
}
