Write-Host "===============================================================" -ForegroundColor Cyan
Write-Host "   SSO LARAVEL - PUBLIC HTTPS TUNNEL (AUTO-RECONNECT)          " -ForegroundColor Cyan
Write-Host "===============================================================" -ForegroundColor Cyan
Write-Host "Keep this window open. Press Ctrl + C to stop." -ForegroundColor Gray
Write-Host ""

while ($true) {
    lt --port 80 --local-host ssolaravel.local
    Write-Host ""
    Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Connection dropped. Auto-reconnecting in 2 seconds..." -ForegroundColor Yellow
    Start-Sleep -Seconds 2
}
