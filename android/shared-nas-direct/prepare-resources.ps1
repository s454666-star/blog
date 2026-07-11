param(
    [Parameter(Mandatory = $true)]
    [string]$BuildDir,

    [Parameter(Mandatory = $true)]
    [string]$RepoRoot
)

$ErrorActionPreference = 'Stop'

$sharedRoot = Join-Path $RepoRoot 'android\shared-nas-direct'
$sourceResources = Join-Path $sharedRoot 'res'
$credentialFile = Join-Path $sharedRoot 'nas-credentials.local.xml'
$targetResources = Join-Path $BuildDir 'shared-res'
$targetValues = Join-Path $targetResources 'values'

if (-not (Test-Path -LiteralPath $credentialFile)) {
    throw "Missing local NAS credentials resource: $credentialFile"
}

if (Test-Path -LiteralPath $targetResources) {
    Remove-Item -LiteralPath $targetResources -Recurse -Force
}

New-Item -ItemType Directory -Force -Path $targetResources, $targetValues | Out-Null
Copy-Item -Path (Join-Path $sourceResources '*') -Destination $targetResources -Recurse -Force
Copy-Item -LiteralPath $credentialFile -Destination (Join-Path $targetValues 'nas_credentials.xml') -Force

Write-Output $targetResources
