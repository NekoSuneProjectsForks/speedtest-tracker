# Raspberry Pi 5 production deployment

Use a 64-bit Linux OS with Docker Engine and Compose v2. The example runs a single Speedtest Tracker container with SQLite. It has no dependency on a collector, WebSocket sidecar, Grafana, Prometheus, InfluxDB, or a device agent. ARM64 and AMD64 images share a tag.

## Install

1. Create a new private, writable directory for `/config`. Use an SSD for the database if available. Copy `compose.env.example` and `tracker.env.example` to `compose.env` and `tracker.env`, restrict both files to the deployment account, single-quote runtime values containing literal dollar signs, and set `TRACKER_CONFIG_DIR` to the absolute path.
2. Set `APP_KEY` to `base64:` followed by the output of `openssl rand -base64 32`. For a fresh database, set `ADMIN_EMAIL` and a unique `ADMIN_PASSWORD` before first start. The base image creates an administrator during the initial migration. Existing databases retain their accounts and require the existing encryption key.
3. Set `TRACKER_IMAGE` to a verified published `sha-<commit>` tag or manifest digest. The example branch tag moves as builds pass. If the GHCR package is private, authenticate Docker with a package credential that can read it. From `deploy/`, run:

   ```sh
   docker compose --env-file compose.env -p speedtest-tracker pull tracker
   docker compose --env-file compose.env -p speedtest-tracker up -d tracker
   docker compose --env-file compose.env -p speedtest-tracker ps
   curl --fail http://127.0.0.1:8080/api/healthcheck
   ```

   Use the port from `TRACKER_HTTP_PORT`. For installations that use the application IP allowlist, include loopback for the in-container health check and the collector's actual source as seen by Laravel. The health check tests HTTP liveness. Review startup logs for migration failures.
4. Visit `/admin`. For remote access, place a reverse proxy with a trusted TLS certificate in front of the loopback port. Set `APP_URL` to the public HTTPS URL and enable `SESSION_SECURE_COOKIE`. Have the trusted proxy replace forwarded headers. Remove the fresh-install administrator password from the runtime environment after verifying login. Keep `APP_DEBUG=false`.

The optional `network-monitor` profile starts a non-root, capability-free collector on the host network. Enable monitoring in Filament, register a collector, and put its token in a file readable only by the configured collector UID/GID. Set `COLLECTOR_TOKEN_PATH`, then run:

```sh
docker compose --env-file compose.env -p speedtest-tracker --profile network-monitor up -d collector
```

The collector can still reach the local application during a WAN outage. Unlike the collector, the application stays on the Compose bridge. For agent enrollment, discovery permissions, and monitoring controls, see the [Home Network Monitor guide](../network-monitor/README.md).

## Back up, upgrade, and roll back

These steps are for a planned maintenance window. The original container's project, image digest, environment, mounts, port mappings, and application key let you restore the original deployment. Record and protect them before proceeding.

1. **Back up.** Stop the existing application and any other process writing to its database. With SQLite, archive the entire original `/config` directory to a private location outside `/config`. Include hidden files and any SQLite WAL/SHM files present. If `DB_DATABASE` points outside `/config`, include its directory separately. A stopped-writer copy prevents separating the database from a live WAL. If retaining records in MariaDB, MySQL or PostgreSQL, make and verify a native database backup as well. Store the application key and deployment environment with the backup and keep an independent copy of the backup.
2. **Rehearse.** Restore the backup into a new directory or a separate external database. Use a different Compose project and unused loopback port. Keep collectors, agents, speedtest schedules and notifications inactive while rehearsing. Never point staging at the live database. Start the pinned image. Check the migration log, administrator login, previous results and the first and last result dates. The added migrations create indexes and monitor tables. They do not replace existing result rows.
3. **Upgrade.** Take a fresh backup after stopping writers. Restore or copy it to the new deployment's configuration path. Keep the original directory and backup available. Preserve the existing database connection, credentials, and `APP_KEY`. Start the pinned candidate and repeat the rehearsal checks before changing the reverse proxy. For a locally attached external database, adapt the Compose network so the app can reach it. The supplied reference config is exercised with SQLite.
4. **Roll back.** Stop the candidate app and its collector. Route traffic back to the original image with the original config and matching pre-upgrade database snapshot. If you migrated an external database in place, restore its pre-upgrade backup before starting the original app. Keep a second backup of the candidate database before rollback. New records created after cutover will stay in that backup and cannot be assumed to exist in the restored version. Avoid running `migrate:fresh` or manually dropping migration records.

Older Prometheus time series or InfluxDB points may already contain private labels. Review their retention and access controls separately. Upgrading only changes newly exported values.

## Environment reference

Use `compose.env` for Compose interpolation and `tracker.env` for settings passed to the application. Preserve pre-existing Speedtest Tracker, database, notification and LinuxServer settings when migrating. The base application retains its [configuration reference](https://docs.speedtest-tracker.dev/getting-started/environment-variables).

| Variable | Purpose and default |
| --- | --- |
| `TRACKER_IMAGE` | Required pinned tracker image. |
| `TRACKER_CONFIG_DIR` | Required existing host directory mounted as `/config`. |
| `TRACKER_RUNTIME_ENV` | Runtime file path, default `./tracker.env`. |
| `TRACKER_HTTP_PORT` | Local host HTTP port, default `8080`. |
| `COLLECTOR_IMAGE` | Optional collector image, defaults to the GHCR branch tag. Pin it alongside the app. |
| `COLLECTOR_TOKEN_PATH` | Collector token mount source, default `./secrets/collector-token`. |
| `NETWORK_MONITOR_UID`, `NETWORK_MONITOR_GID` | Non-root collector identity, both default `65532`. |
| `NETWORK_MONITOR_INTERFACE` | Collector LAN interface, default `eth0`. Linux agent requires its own interface selection. |
| `NETWORK_MONITOR_SUBNET` | Empty by default. Optional on-link RFC1918 IPv4 /24 or smaller. Active probing also requires opt-in in Filament. |
| `NETWORK_MONITOR_URL` | Collector or Linux agent server URL. Production Compose supplies the tracker loopback URL to the collector. Remote agents use HTTPS. |
| `NETWORK_MONITOR_TOKEN_FILE` | Credential file path for native collector or Linux agent. Compose sets the in-container path. |
| `NETWORK_MONITOR_AGENT_INTERVAL` | Linux agent sampling seconds, default `60`, range `10–300`. |
| `PUID`, `PGID`, `TZ` | LinuxServer file ownership and container timezone. Example uses `1000`, `1000` and `Etc/UTC`. |
| `APP_KEY`, `APP_PREVIOUS_KEYS` | Laravel encryption key and optional historical keys. Preserve across upgrades. |
| `APP_URL`, `SESSION_SECURE_COOKIE` | Canonical application URL and secure session-cookie setting. |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Fresh-database administrator seed. Supply a strong unique password. Existing accounts are unaffected. |
| `DB_CONNECTION`, `DB_DATABASE` | Example uses `sqlite` and the base image's `/config/database.sqlite`. Set database-specific variables for external engines. |
| `APP_ENV`, `APP_DEBUG`, `QUEUE_CONNECTION` | Production Compose sets `production`, `false` and `database`. The async queue supports delayed Discord delivery. |
| `LOG_CHANNEL`, `LOG_DAILY_DAYS`, `LOG_LEVEL` | Example sends warning-level Laravel logs to stderr with Docker size-based rotation. LOG_DAILY_DAYS applies if using Laravel’s daily channel. |
| `PUBLIC_DASHBOARD`, `ALLOWED_IPS`, `API_RATE_LIMIT`, `PRUNE_RESULTS_OLDER_THAN` | Existing application controls. Public dashboard defaults off, API limit defaults to 60 per minute, and result pruning defaults off to preserve history. Device history retention is a separate Filament setting. |
| `NETWORK_MONITOR_APP_BASE`, `NETWORK_MONITOR_APP_PORT` | Build base and port for the development Compose sample in `network-monitor/`. Equivalent Docker build argument is `APP_BASE`. |
| `TRACKER_URL`, `PUBLIC_ORIGIN`, `POLL_MS`, `PORT` | Optional WebSocket sidecar upstream URL, exact browser origin, polling milliseconds (5000–300000, default 5000), and internal listener port (default 8080). |
| `TARGETS_FILE`, `INTERVAL_MS` | Optional private Node probe service configuration file (default `/config/targets.json`) and polling milliseconds (10000–300000, default 15000). |
| `COUNTERS_FILE`, `DEVICE_MONITOR_TOKEN`, `INTERVAL_MS` | Optional legacy OpenWrt adapter input (default `/config/counters.json`), separate private bearer token, and polling milliseconds (10000–300000, default 30000). Keep this adapter on a private internal network. |

The Windows agent takes `-Server`, `-InterfaceName`, `-TokenFile` and optional `-IntervalSeconds` (10–300, default 60). Monitoring intervals, active discovery, Discord selection and retention are stored in the database, configured in Filament, and start disabled.

## Image publishing

The Production images workflow builds and smoke-tests the app and collector on native AMD64 and ARM64 runners. Its Windows job tests the monitoring agent against a runner network adapter and a local receiver. Branch pushes publish image tags to GHCR and assemble a manifest only after the build and smoke-test jobs succeed. Pull requests build and test without publishing. Package visibility and GitHub Actions permissions are controlled in GitHub.
