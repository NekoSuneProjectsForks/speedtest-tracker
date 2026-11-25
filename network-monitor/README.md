# Home Network Monitor

Home Network Monitor is disabled by default. It stores inventory, reachability, outages and optional Windows or Linux interface usage in the Speedtest Tracker database. Enable it from **Home Network Monitor → Monitoring Settings** after signing in as an administrator. Other Filament users cannot open the monitor. Grafana, Prometheus and InfluxDB are optional.

## Deploy on ARM64 or AMD64

The application Dockerfile extends the LinuxServer image to retain Speedtest Tracker's database migration, scheduler and queue startup. The collector Dockerfile uses a multi-architecture Python image. Both configurations use the architecture of the Docker host.

1. Use a separate Compose project, an unused local port and a fresh config volume for the first deployment. Set `APP_KEY` to a newly generated Laravel application key and `NETWORK_MONITOR_APP_PORT` to that port in a private environment file. The example uses SQLite.
2. From the repository root, build and start the application.

   ```sh
   docker compose --env-file /path/to/private/staging.env -p tracker-monitor-staging -f network-monitor/compose.app.yml up -d --build
   ```

3. Open the application through its local port or an authenticated HTTPS reverse proxy. Set up the administrator account if the database is new. Enable monitoring only once the migrations have run and the dashboard loads. The LinuxServer image starts the Laravel scheduler and worker. For non-Docker installs, run the branch's migrations and assets build, and run `php artisan schedule:run` every minute alongside the existing queue worker.
4. For a later upgrade of an existing installation, back up the database and config volume and preserve its application key. Use the built image in the existing deployment configuration at a scheduled maintenance window. The monitor can run against the existing SQLite, MySQL/MariaDB or PostgreSQL database.

The example binds the web application to loopback. Keep it there when using a reverse proxy on the same host. Remote agents require a TLS endpoint with a certificate trusted by the endpoint OS. Retain the application's allowlist where configured and include legitimate collector and agent sources. Configure the reverse proxy to avoid caching the monitor paths or logging request bodies and authorization headers. Use `APP_DEBUG=false` in deployed environments.

## Linux LAN collector

1. Enable the feature, then register a **LAN collector** under **Monitoring Settings**. Only one active collector is allowed per installation. Store its token in `network-monitor/secrets/collector-token` with owner-only read/write permissions. Tokens are shown once, stored hashed, and can be revoked from the same screen.
2. Set `NETWORK_MONITOR_URL` to the application URL, `NETWORK_MONITOR_INTERFACE` to the authorized LAN interface, and `NETWORK_MONITOR_UID` and `NETWORK_MONITOR_GID` to the owner of the token file. Use the Pi's Ethernet interface for the topology that was verified. Choose a Wi-Fi interface only if it is associated and client isolation allows the required visibility. For active IPv4 discovery, set `NETWORK_MONITOR_SUBNET` to a directly connected RFC1918 /24 or smaller that you administer.
3. Start the optional collector profile.

   ```sh
   docker compose --env-file /path/to/private/collector.env -p tracker-monitor-collector -f network-monitor/compose.collector.yml --profile network-monitor up -d --build
   ```

The collector joins the host network to read its neighbour tables and send local SSDP multicast. It runs as a non-root user with all Linux capabilities dropped and has no Docker socket or writable filesystem mount. For a least-privilege native alternative, install Python 3, iproute2 and optionally iputils-ping. Run `python3 network-monitor/collector.py` as a dedicated, unprivileged user with the same environment and an absolute `NETWORK_MONITOR_TOKEN_FILE` path. Avoid using host networking for the application itself.

On Linux, ICMP usually works without raw sockets when the collector GID is permitted by the host's existing `net.ipv4.ping_group_range` setting and iputils supports datagram ping. Check that permission before opting into active discovery. Passive ARP/NDP, SSDP, and router and internet HTTP checks use ordinary sockets. When ping is unavailable, leave active discovery disabled and use passive discovery. If active discovery is essential and datagram ping is unavailable, grant only `CAP_NET_RAW` to an isolated collector configured to use raw ping. No privileged Docker container is required.

## Coverage and measurements

- Passive discovery reads MAC-bearing entries on the selected interface from Linux ARP and IPv6 neighbour tables. Active discovery is a separate opt-in. It checks known neighbours and rotates through the optional scoped IPv4 range with at most 32 pings per round, four in flight and eight starts per second. IPv6 addresses are learned from the neighbour table. No IPv6 subnet sweep is performed.
- A stale neighbour, unanswered ping, or missing row does not establish that a device is offline. The inventory records the most recent positive evidence and the most recent measured ICMP latency. A cache-only device can have a first sighting with no confirmed last-seen time. Devices on isolated segments or behind downstream NAT may be invisible.
- The collector polls read-only UPnP WAN link status on the selected IPv4 default gateway and detects capabilities as it reports. The Sagemcom F5464 returned unusable aggregate byte counters in the investigation. WAN usage and router-sourced per-device bandwidth are unavailable. For measurements and scope, see the [redacted investigation](../NETWORK-INVESTIGATION.md).
- Internet outages use HTTPS requests bound to the selected interface's IPv4 source toward two independent origins. At least one successful target counts as reachable. Two consecutive rounds in which both targets fail open a possible outage, and a successful round closes it. A missing default route or a collector gap makes coverage uncertain. Report timestamps are approximate, and origin outages, DNS or filtering can affect them. Place the collector and tracker on the same host or LAN so observations can still reach the database when the internet is down.
- History retention is configurable from 1 to 365 days and pruned by the Laravel scheduler. Pausing the monitor stops collection while preserving inventory and allowing historical retention cleanup to continue. Device-level pause preserves previous observations and stops ingesting measurements for that device. A subnet discovery pass may still encounter it. An authorized administrator can restart monitoring and revoke each credential individually.

## Optional Windows and Linux agent

A registered credential is bound to one device. Choose an existing inventory entry or let registration create one. Install one agent per device and choose the adapter whose usage you want to measure. Keep the token in a file readable only by the agent account. Revoke and register again if the credential is lost or exposed.

Linux reads the selected adapter's counters under `/sys/class/net`. Python and ordinary permission to read those files are sufficient.

```sh
export NETWORK_MONITOR_URL=https://tracker.example.test
export NETWORK_MONITOR_INTERFACE=eth0
export NETWORK_MONITOR_TOKEN_FILE=/path/to/private/agent-token
python3 network-monitor/agent-linux.py
```

Windows requires PowerShell 7 and the Windows NetAdapter cmdlets. Use an account with permission to query the adapter statistics. [Windows adapter statistics documentation](https://learn.microsoft.com/en-us/powershell/module/netadapter/get-netadapterstatistics)

```powershell
pwsh -File .\network-monitor\agent-windows.ps1 -Server https://tracker.example.test -InterfaceName 'Wi-Fi' -TokenFile C:\Private\agent-token
```

The default sampling interval is 60 seconds and can be set to 10–300 seconds in either agent using `NETWORK_MONITOR_AGENT_INTERVAL` (Linux) or `-IntervalSeconds` (Windows). An agent starts a new baseline on restart or adapter change. The server skips negative deltas, implied rates above 100 Gbit/s per direction, and gaps longer than 15 minutes. Usage is sampled interface traffic, which can include local transfers, VPN overhead and traffic generated by the agent. Rankings show rolling 1-, 7- and 30-day recorded totals alongside recent valid sampled rates. Device history groups retained observations by calendar day, week or month. Missing samples leave gaps.

## Discord notifications

Enable Discord in the existing notification settings, enter a supported Discord webhook URL, and then enable Network Monitor Discord alerts. A queued notification is sent for a new device discovery or a newly detected outage. With an asynchronous queue such as the default database queue, transient delivery failures are retried per webhook for roughly a day, so an outage can be reported after connectivity returns. The notification text omits names, addresses, identifiers and traffic statistics.

## API and tests

See the [private API reference](API.md). The Python collector tests run with `python3 -m unittest discover -s network-monitor`. Laravel tests use the same test database setup as the repository, with `php artisan test --filter=NetworkMonitor`.
