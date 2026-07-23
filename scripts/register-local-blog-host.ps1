$ErrorActionPreference = 'Stop'

$hostsPath = Join-Path $env:WINDIR 'System32\drivers\etc\hosts'
$hostsLines = Get-Content -LiteralPath $hostsPath
$hasBlogEntry = $hostsLines | Where-Object {
    $_ -match '^\s*127\.0\.0\.1\s+.*(?:^|\s)blog(?:\s|$)'
}

if (-not $hasBlogEntry) {
    Add-Content -LiteralPath $hostsPath -Value "`r`n127.0.0.1 blog"
}

Clear-DnsClientCache
