# Optional home network monitor

This separate Node service checks configured HTTP endpoints, TCP ports and DNS resolution without running bandwidth tests. It does not modify the Laravel database. Copy targets.example.json to targets.json and compose.example.yml to compose.yml, edit the targets locally, then run docker compose up -d --build. Target names are local configuration labels. Metrics use probe_1, probe_2 and so on in target file order. Reordering targets changes those labels. The example router address and HTTPS port must be replaced with a reachable management port on your own router; no login or password is needed. Router TCP failure is not proof of gateway failure. HTTP uses HEAD, accepts 2xx/3xx, and does not follow redirects. Choose two independent internet endpoints to reduce false outage conclusions. DNS checks resolution of an A record, optionally through a configured resolver. TCP measures connection time, not ICMP ping.

The default 15-second interval and three-second timeout bound traffic; no overlapping rounds. Metrics are exposed locally on port 8090. Keep this endpoint private, or use a private Docker network or VPN for a remote Prometheus server. Do not publish raw scrape target addresses. Add the home-monitor job below to Prometheus (localhost works only if Prometheus runs on the same host outside Docker; otherwise use service DNS on a shared network).

    - job_name: home-network
      scrape_interval: 15s
      static_configs:
        - targets: ['127.0.0.1:8090']
      relabel_configs:
        - target_label: instance
          replacement: anonymous-home

Import grafana/home-network-dashboard.json using your Prometheus data source. History persists in Prometheus, not in the sidecar. Outage counters and cumulative time reset on process restart; use Prometheus increase() over ranges. Outages are estimated from probe observation times. Missing latency means a failed check, not zero latency. Failure ratio is probe failures, not actual IP packet loss. For actual packet loss and ICMP RTT, use a dedicated ICMP exporter with appropriate permissions. True WAN throughput requires router SNMP or equivalent interface counters; this service does not infer traffic from speed tests. The monitor detects outages locally, but remote Prometheus cannot scrape during an internet outage; an external availability check or local Prometheus is recommended.

Run node --test probes.test.mjs to check outage accounting and privacy. This service has not yet been tested on your network.
