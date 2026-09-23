$ErrorActionPreference = 'Stop'
$packageRoot = Join-Path $PSScriptRoot 'dist/FrameJournal-win32-x64'
$sourceExe = Join-Path $packageRoot 'FrameJournal.exe'
if (!(Test-Path -LiteralPath $sourceExe)) { throw 'Run npm run package first.' }
$installRoot = Join-Path $env:LOCALAPPDATA 'Programs/FrameJournal'
if (Get-Process FrameJournal -ErrorAction SilentlyContinue) { throw 'Please close FrameJournal before updating.' }
New-Item -ItemType Directory -Force -Path $installRoot | Out-Null
Copy-Item -Path (Join-Path $packageRoot '*') -Destination $installRoot -Recurse -Force
$installedExe = Join-Path $installRoot 'FrameJournal.exe'
$desktopRoot = [Environment]::GetFolderPath('Desktop')
$shortcut = (New-Object -ComObject WScript.Shell).CreateShortcut((Join-Path $desktopRoot '美少女映像管.lnk'))
$shortcut.TargetPath = $installedExe
$shortcut.WorkingDirectory = $installRoot
$shortcut.Description = '美少女映像管 - 本機甜蜜映像庫，拖曳影片自動辨識來源路徑'
$shortcut.Save()
Write-Output "Installed: $installedExe"
Write-Output "Shortcut: $(Join-Path $desktopRoot '美少女映像管.lnk')"
