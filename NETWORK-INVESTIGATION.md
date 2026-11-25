# Home network monitoring investigation

## Decision

**Software-only device discovery is feasible now for part of the home LAN. Whole-network per-device bandwidth is unverified and cannot be measured from an ordinary Pi Ethernet or Wi-Fi connection alone.** The gateway answered UPnP queries, but its aggregate byte counters were unusable during testing. Administrator-only inventory, reachability, and outage history are viable without additional hardware or Grafana. Optional endpoint agents can add interface usage for participating Windows and Linux computers.

## 1. Detected network architecture

The Raspberry Pi used Ethernet and a private IPv4 /24 with a statically configured default route through the ISP gateway. Wi-Fi was down. Ethernet had an IPv6 link-local /64 and an IPv6 neighbour marked as a router. The host had no Ethernet global IPv6 address or IPv6 default route in the inspected routing table. Container bridges, a virtual-machine bridge, and an overlay VPN were outside the discovery scope.

The user reports clients attached to both the ISP router and TP-Link equipment. The ISP router is the Pi's default gateway. Client attachment and any downstream routed, guest, or isolated networks were unverified.

## 2. Router manufacturers and models

- **Sagemcom F5464** was verified from the default gateway's UPnP device description. The gateway's HTTP and HTTPS endpoints redirected to a local management UI. The shareable report omits the ISP name.
- **TP-Link** was identified on the LAN by a matching IEEE vendor prefix and a branded unauthenticated HTTP response. The response revealed no model or operating mode. Router, extender and access-point modes remain unresolved. [IEEE public MAC assignment registry](https://standards-oui.ieee.org/oui/oui.csv)

## 3. Monitoring protocols and inventory sources

| Source | Observation |
| --- | --- |
| IPv4 ARP and ICMP | Initial Ethernet cache showed eight entries. A bounded pass using one ICMP echo per usable peer address yielded ten echo replies and sixteen MAC-bearing neighbour entries. The counts represent observations, including the gateway, and can include stale entries. |
| IPv6 neighbour discovery | Six link-local neighbour entries were present in the Ethernet snapshot, all stale at the time of the count. No IPv6 address sweep was run. |
| SSDP and UPnP | LAN devices responded to IPv4 SSDP. The Sagemcom descriptor verified IGD v1, WANCommonInterfaceConfig v1 and WANIPConnection v1. IGD v2 was unverified. Only read-only gateway actions were invoked. |
| DHCP leases and Wi-Fi client inventory | No authorized authenticated router session or lease export was available for inspection. ARP and IPv6 neighbour entries give a partial inventory and cannot identify the access point a client uses. The Pi's static route was the source of gateway information. |
| Documented router management API | UPnP was the only documented management interface verified on this gateway. The manufacturer's material reviewed did not establish a supported client inventory or traffic API for this firmware. TP-Link API compatibility is unknown until the model and mode are known. |
| SNMP | Enablement was unverified. No known-enabled read-only SNMP service or authorized credentials were supplied, so no SNMP query was sent. |

## 4. Available traffic counters and link status

| Measurement | Result |
| --- | --- |
| Aggregate WAN download and upload | The gateway successfully answered `GetTotalBytesReceived` and `GetTotalBytesSent`. Both directions returned 4,294,967,295 on both reads, about two seconds apart. This is the maximum unsigned 32-bit value defined by the service. Treat these samples as unavailable for rates and totals. The test does not establish how the firmware handles overflow or whether another interface exposes working counters. |
| WAN packet counts | The gateway advertised sent and received packet count actions. Their output was untested. Packet counts alone cannot provide byte totals. |
| WAN link | `GetCommonLinkProperties` reported the link as up. Its reported link properties do not measure internet reachability, observed throughput, or usage. |
| Per-device byte counts | No working source was verified on the Sagemcom or TP-Link equipment. |

[UPnP WANCommonInterfaceConfig v1 service specification](https://upnp.org/specs/gw/UPnP-gw-WANCommonInterfaceConfig-v1-Service.pdf)

## 5. Per-device bandwidth and clients attached to the ISP router

A normal Pi Ethernet or managed Wi-Fi connection sees traffic addressed to the Pi and shared discovery traffic. Other clients' switched unicast traffic does not pass through it. An inventory, response times and speed-test results cannot provide a device's byte totals.

Clients directly attached to the ISP router can be discovered when reachable from the same LAN. A TP-Link integration would cover only the clients or traffic that the equipment itself accounts for. Directly attached clients need counters from the main router or a source on each participating device. Endpoint agents can sample their own interface counters, which normally include LAN and internet traffic. Label the scope accordingly, and avoid summing agent readings into whole-home WAN usage. Verified per-client accounting on the main router could cover traffic routed through it. A downstream NAT router may appear upstream as a single client.

## 6. Wi-Fi coverage and limitations

A Pi connected by Wi-Fi could poll reachable read-only router endpoints, collect its own interface counters, and discover some peers on its local segment if client isolation and multicast policies permit. This investigation ran over Ethernet to preserve remote access. Discovery across Wi-Fi, guest networks, isolated clients, or a downstream NAT was untested.

The sweep established partial IPv4 inventory, with no way to count all Wi-Fi clients or assign them to access points. Sleeping devices, filtered ICMP, private MAC rotation, and stale neighbour entries all affect coverage. Record a missed observation as unknown until a configured timeout and repeat checks justify an offline state. An authenticated router or AP station list, if legitimately accessible, could improve visibility into attached Wi-Fi clients.

## 7. Recommended implementation

Start with an opt-in administrator-only inventory and reachability monitor. Use a small collector to read host ARP and IPv6 neighbour data, and add bounded, configurable probes for administrator-selected interfaces and targets. Poll gateway reachability and independent internet targets separately to distinguish local, WAN and probe-specific failures. Store history and outage events locally and keep Grafana, Prometheus and InfluxDB optional.

Track adapter capabilities independently for inventory, presence, WAN link, aggregate bytes and per-device bytes. Verify counters across samples before enabling usage display. Treat unsupported, unverified, stale and temporarily failed readings explicitly. Where a router supplies no usable per-device bandwidth, display **Not supported by router** and leave usage values empty. Add Windows or Linux agents only for participating devices. The branch's existing OpenWrt sidecar expects nlbwmon on a compatible router. No OpenWrt source was verified here.

## 8. Remaining capability gaps

The TP-Link model and operating mode, authenticated DHCP and station tables, SNMP availability, coverage from Wi-Fi, and working WAN or per-device counters remain unverified. A read-only session with an authorized administrator would allow inspection of the gateway and TP-Link device for a model/mode label, station and lease lists, and any documented counter export already enabled. No unpublished management endpoints or credentials were tried.

## 9. Docker networking and permissions

The running Speedtest Tracker container uses a non-internal user-defined bridge with IPv6 disabled on that Docker network. It is unprivileged and has no explicitly added capabilities. Its network namespace has its own neighbour table. Routed unicast polling can use ordinary sockets on a bridge if the destination is reachable. This investigation verified gateway HTTP and UPnP from the host only. LAN SSDP multicast and host neighbour visibility should be collected on the host network rather than assumed to pass through the bridge.

Keep Laravel on its bridge and use an optional host-side collector with restricted snapshot handoff or authenticated ingestion. A host-network collector is also possible, with reduced network isolation. Reading host neighbour tables and using UDP/HTTP polling generally requires no added Linux capabilities. ICMP permissions depend on Linux ping socket policy and the probing method. Direct raw ARP or raw ICMP would need narrowly scoped `CAP_NET_RAW` where required and can be omitted initially. Avoid a privileged container, Docker socket mounts, and host networking for Laravel. [Docker network driver documentation](https://docs.docker.com/engine/network/drivers/)

## 10. Recommended database and API architecture

| Record | Suggested fields and indexes |
| --- | --- |
| Devices and identities | Stable internal ID, administrator alias, observed manufacturer, monitoring enabled, first and last seen. Keep observed addresses and identifier source in restricted identity records with timestamps. Index last seen and identity lookup using a keyed fingerprint for encrypted identifiers. |
| Integrations | Adapter type, encrypted credentials, capability status, interval, last success and sanitized status. Use allowlisted destinations and fixed read-only adapter operations. |
| Presence, traffic and outage history | Record source, target, timestamp, outcome and optional latency or byte counters. Index by target and timestamp, and by timestamp for retention. Keep counter scope and direction, detect resets or invalid samples, and derive rates only from valid deltas over elapsed time. Roll up daily, weekly and monthly usage only when sufficient samples exist. |
| Alerts | Policy, state changes and delivery records. Restrict details to administrators and omit identifiers from public notifications and telemetry. |

Use queued reads, per-integration overlap locks and idempotent writes. Cache latest state, paginate history, and prune or roll up records on a configurable schedule. The current Filament panel allows authenticated users regardless of role, so every Network Monitor page and action needs explicit administrator authorization. Protect private read and management API routes with Sanctum and a separate administrator policy. A future agent ingestion channel should use narrowly scoped, revocable write-only credentials and offer no inventory reads. Encrypt stored credential values and keep secrets and private device data out of logs and telemetry.

Keep network data off public APIs, WebSockets, dashboards and anonymous exporters. Public endpoints should continue to expose only anonymous speed-test measurements. Verify authorization and serialization with tests when implementation begins.

## Scope

This investigation used host interface, route and neighbour metadata, bounded LAN discovery, unauthenticated local HTTP and read-only UPnP queries, and Docker metadata inspection. The running containers, installed application, router settings, host networking, firewall and remote desktop services were untouched. This report was the only deliverable from the investigation. During that phase no feature was implemented, committed or pushed.
