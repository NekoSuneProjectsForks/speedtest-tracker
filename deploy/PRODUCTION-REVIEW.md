# PR #1 production-readiness review

The Raspberry Pi's running installation and remote access were untouched. The review used isolated containers, synthetic records, and loopback test receivers.

## Privacy and access

- Public legacy REST, REST v2, the speed-test WebSocket feed, and Laravel's Prometheus and InfluxDB output contain explicit metric fields. Regression fixtures included public and private IPv4, IPv6, MAC, a hostname, ISP, location and a private device name. None appeared in a public response or new exporter point. Prometheus's optional home-probe sidecar now uses fixed ordinal labels and keeps configured target aliases local. Scrape configuration in this branch replaces Prometheus's generated `instance` label with an anonymous value. Existing external time series and InfluxDB points may still contain identifying labels from older releases.
- Anonymous requests received 401 on every network administrator API route. An ordinary account received 403 even with a token carrying the monitor ability. The Filament page rejected guests and ordinary accounts, including a Livewire request after a role change. Collector and endpoint-agent credentials are scoped separately. The legacy authenticated speed-test v1 result endpoints retain their existing `results:read` authorization and can return raw speed-test data. They are private and contain no network-monitor inventory.
- The WebSocket test covered invalid origin rejection, a sanitized update, an oversized upstream reply, an unavailable reconnect snapshot, and a recovered update. It tested the sidecar directly, without terminating TLS at a production proxy. Public REST history used backwards ID cursors with tied timestamps, a concurrent new result, filtering and invalid limit/cursor inputs.

## Database migration and retention

On the Pi, the stock LinuxServer image initialized a fresh SQLite database. The review inserted 10,000 synthetic results, stopped the isolated writer, copied the database and started the PR application image with the copy. The row count and SHA-256 of the full result rows in ID order matched before and after startup. A Laravel regression test also checked down/up migrations on existing result rows. Production rollback uses the matching pre-upgrade backup. The optional network tables do not have to be dropped to recover the old image.

Monitor observations and measured traffic are pruned daily on the configured retention window. Abandoned discovery-only identities expire when they have no current observations or agent registration. Named and paused devices and administrative source records persist. Existing speed-test history remains independent and grows if its own optional retention is disabled. At a one-minute interval, a continuously reported device can add up to 1,440 observations per day. Large inventories and short intervals can still use substantial storage within the retention window. No MariaDB or PostgreSQL restore rehearsal was performed.

The host collector runs one serial polling loop and the server admits one active collector credential. Per-source report throttling, transactional writes and sequence checks protect against concurrent reports and repeated samples. Optional Node services serialize poll rounds and bound poll intervals and open connections. The WebSocket sidecar has a 16 KiB upstream payload limit, an upstream timeout, ping/pong cleanup and a capped per-client buffer. The legacy OpenWrt sidecar bounds the disk snapshot before decoding it. These checks do not replace a sustained workload or storage soak test.

## Raspberry Pi 5 benchmark

Stock LinuxServer 1.15.1 versus the PR app image with the same 10,000 synthetic SQLite results. Each route had one warm-up and 30 successful serial HTTP requests from inside the container. Both images used the same dummy session setup and an isolated network namespace. Table values are server time to first byte, in milliseconds. Dashboard rows measure initial HTML delivery before browser rendering and lazy Livewire requests. Monitoring, optional services and agents were disabled. AMD64 emulated builds and other workloads were running on the host during this test, so small timing differences and tail latencies are not a reliable release-to-release comparison.

| Route | Stock p50 / p95 | PR p50 / p95 |
| --- | ---: | ---: |
| Filament dashboard | 781 / 1,403 | 775 / 1,337 |
| Network Monitor | — | 733 / 2,204 |
| Legacy latest REST | 458 / 755 | 414 / 946 |
| REST v2 latest | — | 258 / 599 |
| REST v2 history, 100 results | — | 424 / 980 |
| REST v2 stats, 30 days | — | 319 / 610 |

Initial dashboard HTML decreased from 99,791 to 81,917 bytes. Docker reported 124.7 MiB stock and 117.3 MiB candidate RAM before load. During the serial request runs, median CPU and RAM were 18.4% and 150.9 MiB stock, and 18.2% and 135.8 MiB candidate. Peak sample values were 49.4% and 196.8 MiB stock, and 47.4% and 182.7 MiB candidate. One full CPU core is 100% in Docker's output. The candidate run includes additional routes. Idle and load numbers are measurements of those runs, not sizing claims for active monitoring.

## Deployment

The [Raspberry Pi guide](README.md) covers configuration, variables, staged installation, backup, upgrade and rollback. The Compose configuration starts only the app unless the collector profile is selected. The built-in application and retained history work without Grafana, Prometheus, InfluxDB, WebSockets or endpoint agents.


## Platform verification and published images

The [production workflow run](https://github.com/NekoSuneProjectsForks/speedtest-tracker/actions/runs/45642822907) built and smoke-tested both images on native AMD64 and ARM64 Linux. The Windows job used a real runner adapter and a local HTTP receiver to verify an authenticated POST containing only counters and sequencing metadata. PowerShell 7.6 also passed the loopback test on the Pi with mocked Windows adapter cmdlets. Laravel passed 88 tests, the Node sidecars passed six, and Python passed five including the PowerShell harness.

GHCR published both platforms under the branch tag and the code commit tag. The workflow inspected both manifests after uploading them. Use the following digests to deploy the verified image set.

| Image | Pinned reference |
| --- | --- |
| Tracker | `ghcr.io/nekosuneprojectsforks/speedtest-tracker@sha256:166399f4416f7fb38cce2a855b5e3bc1cab0ec3865305cb3db79da7a6e648dbd` |
| Optional LAN collector | `ghcr.io/nekosuneprojectsforks/speedtest-tracker-collector@sha256:c154e01b13057dfddc02c2af858cbc1a2c974c999583540ae3e4b76c7f7c1c1` |

Production-specific verification still outstanding includes the actual router and Windows endpoint deployment, external database restoration, reverse-proxy WebSocket reconnects, full browser rendering performance and a sustained monitor load test.
