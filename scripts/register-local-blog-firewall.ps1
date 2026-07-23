$ErrorActionPreference = 'Stop'

$caddyPath = 'C:\Users\USER\AppData\Local\Microsoft\WinGet\Packages\CaddyServer.Caddy_Microsoft.Winget.Source_8wekyb3d8bbwe\caddy.exe'
$publicProfiles = Get-NetConnectionProfile | Where-Object {
    $_.NetworkCategory -eq 'Public' -and $_.IPv4Connectivity -ne 'Disconnected'
}
foreach ($profile in $publicProfiles) {
    Set-NetConnectionProfile -InterfaceIndex $profile.InterfaceIndex -NetworkCategory Private
}

$rules = @(
    @{
        DisplayName = 'Blog local server HTTP'
        Protocol = 'TCP'
        LocalPort = '80'
    },
    @{
        DisplayName = 'Blog local server HTTPS'
        Protocol = 'TCP'
        LocalPort = '443'
    },
    @{
        DisplayName = 'Blog local server HTTP3'
        Protocol = 'UDP'
        LocalPort = '443'
    }
)

foreach ($rule in $rules) {
    $existing = Get-NetFirewallRule -DisplayName $rule.DisplayName -ErrorAction SilentlyContinue
    if ($existing) {
        Set-NetFirewallRule -DisplayName $rule.DisplayName `
            -Enabled True `
            -Profile Private `
            -Direction Inbound `
            -Action Allow
        $existing | Get-NetFirewallPortFilter | Set-NetFirewallPortFilter `
            -Protocol $rule.Protocol `
            -LocalPort $rule.LocalPort
    } else {
        New-NetFirewallRule -DisplayName $rule.DisplayName `
            -Program $caddyPath `
            -Direction Inbound `
            -Action Allow `
            -Protocol $rule.Protocol `
            -LocalPort $rule.LocalPort `
            -Profile Private
    }
}
