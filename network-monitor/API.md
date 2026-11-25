# Home Network Monitor API

Base path `/api/network-monitor/v1`. Send `Accept: application/json` and use TLS except on loopback. There are no public inventory endpoints, broadcasts or exporters.

## Administrator endpoints

Authenticate with a Laravel Sanctum personal token created by an administrator with the explicit `network-monitor:admin` ability. A speed-test token cannot access these endpoints. The administrator role is checked on every request.

| Method and path | Request and result |
| --- | --- |
| `GET /devices?page=1` | Paginated device inventory. Includes ID, label, last known IPv4/IPv6 and MAC, monitoring flag, and first/last seen. |
| `GET /rankings?days=7&page=1` | Paginated observed download and upload bytes by device across the past 1, 7 or 30 days. |
| `GET /sources?page=1` | Paginated active collector and agent metadata. Tokens are omitted. |
| `POST /sources` | Send `kind` (`collector` or `agent`), `label` (up to 100 characters), and optional numeric `device_id` for an agent. Returns `201` with `id` and the one-time `token`. An agent with no device ID gets a new inventory record. A second active collector or a second active agent for a device returns `409`. |
| `DELETE /sources/{id}` | Revoke a registered credential. Returns `204`. Historical measurements remain subject to retention. |

## Collector and agent endpoints

These endpoints use scoped bearer credentials returned at registration. Collector credentials can read the limited collection policy only and submit observations. An agent credential can submit samples only for its registered device. It cannot read inventory or choose a target device in its requests.

| Method and path | Request and result |
| --- | --- |
| `GET /collector/settings` | Returns `enabled`, `interval_seconds`, `active_discovery` and `probes_per_round`. A disabled collector should stop polling the network and continue checking settings periodically. |
| `POST /collector/reports` | Send the collection shape below. Returns `204`. |
| `POST /agent/samples` | Send `session` (UUID), positive `sequence`, `rx_bytes` and `tx_bytes` (cumulative integers up to 9,007,199,254,740,991). Returns `204`. |

Example collector report using documentation-only addresses.

```json
{
  "session": "e940c7e2-627d-4260-ac76-e19f55528cef",
  "sequence": 1,
  "internet": "up",
  "router": "up",
  "upnp": "up",
  "ipv4": true,
  "ipv6": true,
  "ping": true,
  "devices": [
    {
      "mac": "02:00:00:00:00:01",
      "ipv4": "192.0.2.5",
      "ipv6": "2001:db8::5",
      "reachable": true,
      "latency_us": 4200
    }
  ]
}
```

The status fields accept `up`, `down` or `unknown`. The devices array has a 512-entry limit and requires unique unicast MAC addresses. Address and latency fields are optional. When `reachable` is false, it represents an unconfirmed observation and any submitted latency is ignored. The collector does not submit UPnP byte counters.

Use a fresh session UUID for each process start and monotonically increase sequence within a session. The server ignores same-session retries with an earlier or equal sequence and sets the observation time on receipt. A new agent session starts a fresh counter baseline. Invalid or revoked source credentials return `401`, disabled collection returns `403`, malformed requests return `422`, and reports sent too quickly return `429`.
