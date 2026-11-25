# Optional integrations

The original Laravel Speedtest Tracker, database, scheduler, dashboard and v2 public API do not require Grafana, Prometheus, InfluxDB, the WebSocket sidecar, home-monitor or device-monitor. Deploy only the components you need. The WSS sidecar reads the v2 REST API independently. Home-monitor exports optional metrics; historical home-probe graphs currently require an external time-series store. Existing speedtest history is stored in Laravel's own database.

Device monitoring is optional and private; see device-monitor/README.md. It requires router accounting support, and is not part of the public API. Grafana dashboard imports and monitoring integrations are entirely optional.

## Home Network Monitor

The administrator-only [Home Network Monitor](network-monitor/README.md) provides device inventory, connectivity history and optional endpoint bandwidth collection in the built-in Filament panel. It uses the Tracker database and can run independently of the services above.
