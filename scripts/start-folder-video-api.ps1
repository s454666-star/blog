param(
    [switch]$WarmCache,
    [string]$Host = "0.0.0.0",
    [int]$Port = 8090
)

Set-Location "C:\www\blog"

if ($WarmCache) {
    php artisan folder-video:warm-cache
}

php artisan serve --host=$Host --port=$Port
