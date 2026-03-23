param(
    [string]$TaskName = "Blog Folder Video API Caddy",
    [string]$WrapperPath = "C:\www\blog\scripts\start-folder-video-api.bat"
)

$ErrorActionPreference = "Stop"

$action = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$WrapperPath`""
$trigger = New-ScheduledTaskTrigger -AtStartup

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Description "Start the folder video LAN API through Caddy at system startup." `
    -Force

Write-Host "Registered startup task: $TaskName"
