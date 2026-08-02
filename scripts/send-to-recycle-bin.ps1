param(
    [Parameter(Mandatory = $true)]
    [string]$ManifestPath
)

$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = $utf8
$OutputEncoding = $utf8
Add-Type -AssemblyName Microsoft.VisualBasic

$manifest = (Resolve-Path -LiteralPath $ManifestPath -ErrorAction Stop).Path
$decodedPaths = Get-Content -LiteralPath $manifest -Raw -Encoding UTF8 | ConvertFrom-Json
$paths = @()
foreach ($decodedPath in $decodedPaths) {
    $paths += [string]$decodedPath
}
if ($paths.Count -eq 0) {
    throw 'Recycle manifest does not contain any paths.'
}

foreach ($pathValue in $paths) {
    $path = [string]$pathValue
    $resolved = (Resolve-Path -LiteralPath $path -ErrorAction Stop).Path
    if (-not (Test-Path -LiteralPath $resolved -PathType Leaf)) {
        throw "Not a file: $resolved"
    }

    [Microsoft.VisualBasic.FileIO.FileSystem]::DeleteFile(
        $resolved,
        [Microsoft.VisualBasic.FileIO.UIOption]::OnlyErrorDialogs,
        [Microsoft.VisualBasic.FileIO.RecycleOption]::SendToRecycleBin
    )
}
