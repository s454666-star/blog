$ErrorActionPreference = 'Stop'

$sourcePath = 'G:\video'
$targetPath = 'E:\video'
$logPath = 'C:\Users\USER\Documents\project\blog\storage\logs\video-move-robocopy.log'
$pausePath = 'C:\Users\USER\Documents\project\blog\storage\app\video-move.paused'

if (Test-Path -LiteralPath $pausePath) {
    return
}

if (-not (Test-Path -LiteralPath $sourcePath)) {
    return
}

$sourceItem = Get-Item -LiteralPath $sourcePath
$targetItem = Get-Item -LiteralPath $targetPath
if ($sourceItem.FullName -ne $sourcePath -or $targetItem.FullName -ne $targetPath) {
    throw "Unexpected video move path: $($sourceItem.FullName) -> $($targetItem.FullName)"
}

$hasRemainingItems = Get-ChildItem -LiteralPath $sourcePath -Force -ErrorAction Stop |
    Select-Object -First 1
$moveAlreadyRunning = Get-Process -Name Robocopy -ErrorAction SilentlyContinue

if ($hasRemainingItems -and -not $moveAlreadyRunning) {
    $moveProcess = Start-Process -FilePath 'robocopy.exe' `
        -ArgumentList @(
            $sourcePath,
            $targetPath,
            '/E',
            '/MOVE',
            '/COPY:DAT',
            '/DCOPY:DAT',
            '/R:2',
            '/W:2',
            '/MT:128',
            '/J',
            '/XJ',
            '/NP',
            "/LOG+:$logPath"
        ) `
        -WindowStyle Hidden `
        -PassThru
    $moveProcess.PriorityClass = 'High'
}
