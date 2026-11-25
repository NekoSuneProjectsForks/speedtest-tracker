# Public and private speed metrics

Deploy the modified Laravel application first (the stock LinuxServer image does not contain these changes). Back up the database and run migrations.

## Private API
The existing /api/v1/results, /api/v1/results/latest, /api/v1/results/{id} and /api/v1/stats routes retain Sanctum authentication and the results:read permission. Full result details, including stored IP, ISP and server location, remain available to authorized users. Send Accept: application/json and Authorization: Bearer TOKEN. Do not expose the token in public JavaScript. Keep the existing dashboard private (PUBLIC_DASHBOARD=false; verify your deployment settings).

## Public API
No token is required for /api/v2/latest, /api/v2/history?limit=100&before_id=123&days=30, /api/v2/stats?days=7 or /api/v2/health. These read-only, rate-limited endpoints serialize only numeric speed/latency measurements, result IDs and timestamps; never raw Ookla data, IP, ISP, server name, location, result URLs or comments. History is newest first; use next_before_id until null. Stats cache lasts 30 seconds. The legacy public latest endpoint also omits server details and result URLs. Public measurements and timestamps can still reveal usage patterns, so anonymity is limited to removal of direct identifiers. Disable public routes at the reverse proxy if you do not want to publish them.

## WebSocket
The optional Node sidecar polls the public latest endpoint once every five seconds regardless of client count, then pushes changed completed results over WebSocket. It independently allowlists numeric measurements and timestamps. This is near-real-time completed-test reporting, not live measurement progress. Origin checking is browser protection, not authentication.

From live-stats/, copy compose.example.yml to compose.yml, set TRACKER_URL to a reachable tracker URL (not localhost inside the sidecar), and PUBLIC_ORIGIN to the exact website origin, e.g. https://speed.example.com. Run docker compose up -d --build. For Nginx on the same host:

    location = /ws/speedtest {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 75s;
        proxy_buffering off;
    }

Use your existing TLS certificate and connect to wss://YOUR_DOMAIN/ws/speedtest. If Nginx is containerized, use a shared Docker network and service DNS instead. Public REST endpoints can be called directly on the tracker domain; cross-origin browser clients may require an explicit CORS policy. Keep APP_DEBUG=false in production. Run node --test metrics.test.mjs to check the sidecar allowlist.

The sidecar bounds upstream responses to 16 KiB and retains only the latest anonymous snapshot. After reconnecting, clients receive a new snapshot with its availability flag. Clients can fill gaps by walking the REST history cursor.
