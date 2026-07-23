$ErrorActionPreference = 'Stop'

$projectPath = 'C:\Users\USER\Documents\project\blog'
$phpCgi = 'C:\Users\USER\AppData\Local\Programs\PHP\8.4.20\php-cgi.exe'
$caddy = 'C:\Users\USER\AppData\Local\Microsoft\WinGet\Packages\CaddyServer.Caddy_Microsoft.Winget.Source_8wekyb3d8bbwe\caddy.exe'
$caddyConfig = Join-Path $projectPath 'Caddyfile.local'
$logPath = Join-Path $projectPath 'storage\logs'

& 'C:\Users\USER\.codex\skills\aws-sky\scripts\ensure_db_tunnel.ps1'

$phpListener = Get-NetTCPConnection -State Listen -LocalPort 9000 -ErrorAction SilentlyContinue
if (-not $phpListener) {
    $env:PHP_FCGI_CHILDREN = '16'
    $env:PHP_FCGI_MAX_REQUESTS = '10000'
    Start-Process -FilePath $phpCgi `
        -ArgumentList @('-b', '127.0.0.1:9000') `
        -WorkingDirectory $projectPath `
        -RedirectStandardOutput (Join-Path $logPath 'php-cgi.out.log') `
        -RedirectStandardError (Join-Path $logPath 'php-cgi.err.log') `
        -WindowStyle Hidden
}

$httpsListener = Get-NetTCPConnection -State Listen -LocalPort 443 -ErrorAction SilentlyContinue
if (-not $httpsListener) {
    Start-Process -FilePath $caddy `
        -ArgumentList @('run', '--config', $caddyConfig, '--adapter', 'caddyfile') `
        -WorkingDirectory $projectPath `
        -RedirectStandardOutput (Join-Path $logPath 'caddy.out.log') `
        -RedirectStandardError (Join-Path $logPath 'caddy.err.log') `
        -WindowStyle Hidden
}
