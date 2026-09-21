Write-Host "===============================================================" -ForegroundColor Cyan
Write-Host "   SSO LARAVEL - CLOUDFLARE SECURE HIGH-SPEED TUNNEL           " -ForegroundColor Cyan
Write-Host "===============================================================" -ForegroundColor Cyan
Write-Host "Starting high-stability Cloudflare tunnel for your team..." -ForegroundColor Gray
Write-Host "Share the generated 'https://....trycloudflare.com' URL with your frontend team." -ForegroundColor Green
Write-Host "Press Ctrl + C to stop the tunnel.`n" -ForegroundColor Yellow

$exePath = Join-Path $PSScriptRoot "cloudflared.exe"

if (-not (Test-Path $exePath)) {
    Write-Host "Downloading cloudflared.exe to D: drive..." -ForegroundColor Yellow
    curl.exe -L "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe" -o $exePath
}

& $exePath tunnel --url http://ssolaravel.local --http-host-header ssolaravel.local

