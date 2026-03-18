$ErrorActionPreference = 'Stop'

$targets = @(
    'C:\www\blog',
    'C:\Users\User\Pictures\train'
)

$totalDeleted = 0
$totalBytesFreed = [int64]0

foreach ($target in $targets) {
    if (-not (Test-Path -LiteralPath $target)) {
        Write-Warning "Skip missing path: $target"
        continue
    }

    $trackedLogs = @{}
    if (Test-Path -LiteralPath (Join-Path $target '.git')) {
        $gitTracked = & git -C $target ls-files -- '*.log' 2>$null
        foreach ($relativePath in $gitTracked) {
            if ([string]::IsNullOrWhiteSpace($relativePath)) {
                continue
            }

            $fullPath = [System.IO.Path]::GetFullPath((Join-Path $target $relativePath))
            $trackedLogs[$fullPath] = $true
        }
    }

    $files = Get-ChildItem -LiteralPath $target -Recurse -File -Filter '*.log' -Force -ErrorAction SilentlyContinue

    $deletedForTarget = 0
    $bytesFreedForTarget = [int64]0
    $skippedTrackedForTarget = 0

    foreach ($file in $files) {
        if ($trackedLogs.ContainsKey($file.FullName)) {
            $skippedTrackedForTarget++
            continue
        }

        try {
            $bytesFreedForTarget += $file.Length
            Remove-Item -LiteralPath $file.FullName -Force
            $deletedForTarget++
        } catch {
            Write-Warning "Failed to delete $($file.FullName): $($_.Exception.Message)"
        }
    }

    $totalDeleted += $deletedForTarget
    $totalBytesFreed += $bytesFreedForTarget

    Write-Host ("[{0}] deleted={1} skipped_tracked={2} freed_bytes={3}" -f $target, $deletedForTarget, $skippedTrackedForTarget, $bytesFreedForTarget)
}

Write-Host ("Completed log cleanup. total_deleted={0} total_freed_bytes={1}" -f $totalDeleted, $totalBytesFreed)
