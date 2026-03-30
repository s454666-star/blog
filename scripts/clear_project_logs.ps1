$ErrorActionPreference = 'Stop'

$target = 'C:\www\blog\storage\logs'

if (-not (Test-Path -LiteralPath $target)) {
    Write-Warning "Skip missing path: $target"
    exit 0
}

$files = Get-ChildItem -LiteralPath $target -Recurse -File -Filter '*.log' -Force -ErrorAction SilentlyContinue
$deletedCount = 0
$truncatedCount = 0
$failedCount = 0
$bytesFreed = [int64]0

foreach ($file in $files) {
    try {
        $bytesFreed += $file.Length
        Remove-Item -LiteralPath $file.FullName -Force -ErrorAction Stop
        $deletedCount++
        continue
    } catch {
        $deleteMessage = $_.Exception.Message
    }

    try {
        $stream = [System.IO.File]::Open($file.FullName, [System.IO.FileMode]::Truncate, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite)
        $stream.Dispose()
        $truncatedCount++
    } catch {
        $failedCount++
        Write-Warning ("Failed to clear {0}: delete_error={1}; truncate_error={2}" -f $file.FullName, $deleteMessage, $_.Exception.Message)
    }
}

Write-Host ("[{0}] deleted={1} truncated={2} failed={3} freed_bytes={4}" -f $target, $deletedCount, $truncatedCount, $failedCount, $bytesFreed)
Write-Host ("Completed storage log cleanup. total_candidates={0}" -f $files.Count)
