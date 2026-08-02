param(
    [string]$ProjectRoot = 'C:\Users\USER\Documents\project\blog',
    [string]$ScanRoot = 'D:\tg暫存'
)

$ErrorActionPreference = 'Stop'
$runToken = ([guid]::NewGuid().ToString('N'))
$phpBin = $null
$exitCode = 1

try {
    $resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
    $resolvedRoot = (Resolve-Path -LiteralPath $ScanRoot).Path
    $phpLine = Get-Content -LiteralPath (Join-Path $resolvedProject '.env') |
        Where-Object { $_ -like 'FOLDER_VIDEO_PHP_BIN=*' } |
        Select-Object -First 1
    if (-not $phpLine) {
        throw 'FOLDER_VIDEO_PHP_BIN is missing from .env.'
    }

    $phpBin = ($phpLine -split '=', 2)[1].Trim().Trim('"')
    if (-not (Test-Path -LiteralPath $phpBin -PathType Leaf)) {
        throw "PHP does not exist: $phpBin"
    }

    $Host.UI.RawUI.WindowTitle = 'TG 暫存影片截圖'
    Write-Host 'TG 暫存影片截圖' -ForegroundColor Cyan
    Write-Host "目錄：$resolvedRoot"
    Write-Host '範圍：只掃描第一層，不包含子資料夾'
    Write-Host '順序：依影片建立日期，由舊到新'
    Write-Host '可隨時按 Ctrl+C 中斷；中斷後會自動清除本次圖片與資料。' -ForegroundColor Yellow
    Write-Host ''

    Push-Location $resolvedProject
    try {
        & $phpBin artisan tg-video-review:scan "--root=$resolvedRoot" "--run-token=$runToken" --no-interaction
        $exitCode = $LASTEXITCODE
    }
    finally {
        Pop-Location
    }
}
catch {
    Write-Host ''
    Write-Host "截圖失敗或已中斷：$($_.Exception.Message)" -ForegroundColor Red
}
finally {
    if ($phpBin -and (Test-Path -LiteralPath $phpBin -PathType Leaf) -and (Test-Path -LiteralPath $ProjectRoot -PathType Container)) {
        Push-Location $ProjectRoot
        try {
            & $phpBin artisan tg-video-review:scan "--cleanup-run=$runToken" --no-interaction 2>$null | Out-Null
        }
        catch {
            Write-Host '警告：自動清理未完成，請再次執行此腳本；下次啟動會先清理。' -ForegroundColor Yellow
        }
        finally {
            Pop-Location
        }
    }
}

Write-Host ''
if ($exitCode -eq 0) {
    Write-Host '截圖完成。' -ForegroundColor Green
} else {
    Write-Host '未完成；本次暫存圖片與 table 變更已回滾。' -ForegroundColor Yellow
}
Write-Host '按任意鍵關閉視窗。'
$null = $Host.UI.RawUI.ReadKey('NoEcho,IncludeKeyDown')
exit $exitCode
