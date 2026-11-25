# Grafana integration

## Prometheus (pull)
Enable Prometheus in the tracker Data Integration settings. Configure allowed scraper IPs and restrict /prometheus at the reverse proxy to the monitoring network or authenticated scraper. Copy the scrape job from prometheus.example.yml into your existing Prometheus configuration, replacing the example target. Add Prometheus as a Grafana data source and import speedtest-dashboard.json, selecting that data source. Scrapes record the latest test, not retroactive history. The fixed instance label avoids displaying the target hostname in dashboards. Do not share Prometheus configuration or target diagnostics publicly.

## InfluxDB v2 (push and historical backfill)
Enable InfluxDB in Data Integration settings and enter your InfluxDB URL, organization, dedicated bucket and write token. Existing completion jobs push points, and the existing bulk-write job can backfill completed results. This fork writes to the new speedtest_anonymous measurement, omitting IP, ISP, server metadata, app name and error strings. Use a new dedicated bucket to avoid mixing previously exported identifying information with anonymous data. Existing points are NOT deleted or anonymized retroactively. Configure Grafana's InfluxDB data source with a separate read-only token and keep both tokens server-side.

Example Flux query for historical download Mbps (replace BUCKET):
```flux
from(bucket: "BUCKET")
  |> range(start: v.timeRangeStart, stop: v.timeRangeStop)
  |> filter(fn: (r) => r._measurement == "speedtest_anonymous" and r._field == "download_bits" and r.status == "completed")
  |> map(fn: (r) => ({r with _value: float(v: r._value) / 1000000.0}))
```
Use upload_bits for upload, ping for ping milliseconds, ping_jitter for jitter and packet_loss for percent (no division for latency/loss). Points use the original test timestamp. The Prometheus dashboard JSON is for Prometheus only; InfluxDB panels can use these Flux queries.

## Privacy
The authenticated v1 REST API retains full details. Public v2 and WebSocket responses remain allowlisted. Both metrics exporters now omit identifying tags by default. Existing Prometheus and InfluxDB data may retain old labels; use a fresh data source or isolated storage for public dashboards. Grafana itself should be authenticated unless intentionally publishing a restricted dashboard. Never grant public access to private data sources or raw queries. Changes have not yet been validated against a running Grafana/InfluxDB instance.
