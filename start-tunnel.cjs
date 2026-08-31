const localtunnel = require('localtunnel');
const https = require('https');

const PORT = 80;
const LOCAL_HOST = 'ssolaravel.local';

console.log('===============================================================');
console.log('       SSO LARAVEL - ALL-DAY PERSISTENT PUBLIC TUNNEL           ');
console.log('===============================================================');

let currentTunnel = null;
let heartbeatTimer = null;

async function startTunnel() {
    if (heartbeatTimer) clearInterval(heartbeatTimer);

    try {
        console.log(`[${new Date().toLocaleTimeString()}] Establishing secure tunnel connection...`);
        
        // Connect without locked subdomain to ensure instant connection
        currentTunnel = await localtunnel({
            port: PORT,
            local_host: LOCAL_HOST
        });

        console.log('\n===============================================================');
        console.log(`>>> YOUR LIVE PUBLIC HTTPS URL : ${currentTunnel.url}`);
        console.log(`>>> API BASE URL FOR TEAM      : ${currentTunnel.url}/api`);
        console.log('===============================================================');
        console.log('Status: ONLINE (Active keep-alive heartbeat enabled)');
        console.log('Keep this terminal window OPEN. Press Ctrl + C to stop.\n');

        // Keep-alive heartbeat every 15 seconds to prevent idle disconnect
        heartbeatTimer = setInterval(() => {
            if (currentTunnel && currentTunnel.url) {
                const req = https.get(currentTunnel.url + '/up', {
                    headers: { 'Bypass-Tunnel-Reminder': 'true' }
                }, () => {});
                req.on('error', () => {});
                req.setTimeout(5000, () => req.destroy());
            }
        }, 15000);

        currentTunnel.on('close', () => {
            console.log(`\n[${new Date().toLocaleTimeString()}] Connection closed by upstream server. Reconnecting in 2s...`);
            clearInterval(heartbeatTimer);
            setTimeout(startTunnel, 2000);
        });

        currentTunnel.on('error', (err) => {
            console.log(`\n[${new Date().toLocaleTimeString()}] Network error: ${err.message}. Auto-reconnecting in 2s...`);
            clearInterval(heartbeatTimer);
            setTimeout(startTunnel, 2000);
        });

    } catch (err) {
        console.log(`[${new Date().toLocaleTimeString()}] Connection failed (${err.message}). Retrying in 2s...`);
        setTimeout(startTunnel, 2000);
    }
}

// Handle graceful exit
process.on('SIGINT', () => {
    console.log('\nStopping tunnel...');
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    if (currentTunnel) currentTunnel.close();
    process.exit(0);
});

startTunnel();
