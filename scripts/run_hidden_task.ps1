param(
    [Parameter(Mandatory = $true)]
    [string]$BatchPath,
    [string]$WorkingDirectory
)

$ErrorActionPreference = 'Stop'

$resolvedBatchPath = [System.IO.Path]::GetFullPath($BatchPath)
if (-not (Test-Path -LiteralPath $resolvedBatchPath)) {
    throw "Batch file not found: $resolvedBatchPath"
}

if ([string]::IsNullOrWhiteSpace($WorkingDirectory)) {
    $WorkingDirectory = Split-Path -Parent $resolvedBatchPath
}

$resolvedWorkingDirectory = [System.IO.Path]::GetFullPath($WorkingDirectory)
if (-not (Test-Path -LiteralPath $resolvedWorkingDirectory)) {
    throw "Working directory not found: $resolvedWorkingDirectory"
}

$process = Start-Process `
    -FilePath $env:ComSpec `
    -ArgumentList @('/d', '/c', "`"$resolvedBatchPath`"") `
    -WorkingDirectory $resolvedWorkingDirectory `
    -WindowStyle Hidden `
    -Wait `
    -PassThru

exit $process.ExitCode
