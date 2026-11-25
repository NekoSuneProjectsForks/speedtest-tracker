# Anonymous live speed metrics

Deploy the modified Laravel application first (the stock LinuxServer image does not contain these changes). Run the application's migrations after backing up the database. Create a Sanctum API token with results:read only. The v2 API requires this token; never embed it in public browser JavaScript. Endpoints: /api/v2/latest, /api/v2/history?limit=100&before_id=123&days=30, /api/v2/stats?days=7, /api/v2/health (public). Send Accept: application/json and Authorization: Bearer TOKEN. History is newest first; use next_before_id until null. Stats cache lasts 30 seconds.

The optional Node sidecar polls the sanitized latest endpoint once per five seconds regardless of client count, then pushes changed completed results over WebSocket. This is near-real-time completed-test reporting, not live measurement progress. Public WSS intentionally exposes the anonymous numeric metrics and timestamps to anyone able to connect. Origin checking is browser protection, not authentication. Use reverse-proxy authentication or private networking if the measurements must be private. Keep the existing dashboard private: it may display raw data to authorized users. Existing v1 result responses no longer include raw data, benchmarks, comments or dispatcher identifiers; the legacy latest route no longer includes server details or result URLs. This is a compatibility change. Other notification integrations and stored results are not modified.

From live-stats/, copy compose.example.yml to compose.yml, set TRACKER_URL to a reachable tracker URL (not localhost inside the sidecar), TRACKER_TOKEN, and PUBLIC_ORIGIN to the exact website origin, e.g. https://speed.example.com. Run docker compose up -d --build. Keep the token in an untracked environment file. For a local Nginx reverse proxy on the same host:

    location = /ws/speedtest {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 75s;
        proxy_buffering off;
    }

Use your existing TLS certificate and connect to wss://YOUR_DOMAIN/ws/speedtest. If Nginx is itself containerized, use a shared Docker network and service DNS instead. For public history, implement a server-side proxy that authenticates upstream and serves only the v2 endpoints; never publish the API token. Keep APP_DEBUG=false in production. Run node --test metrics.test.mjs to check the sidecar allowlist.
