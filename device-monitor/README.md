# Optional private per-device traffic monitoring

Requires a router with per-client traffic accounting. The included adapter targets OpenWrt nlbwmon; other routers require an adapter. This is not a packet sniffer. It cannot discover traffic on unrelated switched devices. Neither Grafana nor Prometheus is required.

On a trusted home host, run the following via a dedicated authorized SSH connection to your own OpenWrt router, every 30 seconds. Requires jq on the host, nlbwmon on the router, and an existing SSH configuration named openwrt-monitor. Use a restricted read-only command account where possible; do not expose SSH publicly.

    mkdir -p config
    umask 077
    ssh -o BatchMode=yes -o ConnectTimeout=5 openwrt-monitor 'nlbw -c json -g mac' | jq --argjson t "$(date +%s)" '. + {sampled_at:$t}' > config/counters.tmp && mv config/counters.tmp config/counters.json

Ensure the non-root container user can read the file (use an appropriate local group/ACL). Keep this directory private. Copy compose.example.yml to compose.yml; set a strong DEVICE_MONITOR_TOKEN in an untracked environment file. Run docker compose up -d --build.

    curl -H "Authorization: Bearer $DEVICE_MONITOR_TOKEN" http://127.0.0.1:8091/devices

Returns pseudonymous device IDs, cumulative upload/download bytes and estimated Mbps between router snapshots, sorted by combined throughput. First sample and counter resets produce null rates. Totals are for the router accounting period, not lifetime. Router accounting refresh and offloading affect accuracy. This is sampled traffic, not instantaneous wire speed. No MAC/IP/hostname is returned, and the device API requires a separate bearer token. It is not connected to public REST/WSS feeds. Do not expose this port publicly. This endpoint is not yet integrated into the Laravel login/dashboard; use a private backend or authenticated proxy, never embed the token into public JS. The token also salts device IDs; changing it changes IDs. This implementation is untested against actual router hardware.

For UniFi, MikroTik, pfSense or other routers, supply compatible cumulative per-device counters through an adapter. Do not claim support until verified.
