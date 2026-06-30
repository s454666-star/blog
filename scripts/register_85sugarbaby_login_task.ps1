Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$taskName = 'Blog 85Sugarbaby Login'
$scriptPath = Join-Path $PSScriptRoot 'start_85sugarbaby_login_visible.ps1'

if (-not (Test-Path -LiteralPath $scriptPath)) {
    throw "Missing login script: $scriptPath"
}

$action = New-ScheduledTaskAction `
    -Execute 'powershell.exe' `
    -Argument ('-NoProfile -ExecutionPolicy Bypass -File "{0}"' -f $scriptPath)
$principal = New-ScheduledTaskPrincipal `
    -UserId $env:USERNAME `
    -LogonType Interactive `
    -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Principal $principal `
    -Settings $settings `
    -Description 'Open a visible Chrome login flow for the 85sugarbaby crawler session.' `
    -Force | Out-Null

Write-Host "Registered task: $taskName"
