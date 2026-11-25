"""Discover neighbours on a single administrator-selected Linux LAN interface."""

import concurrent.futures
import http.client
import ipaddress
import itertools
import json
import os
import re
import socket
import subprocess
import time
import uuid
import xml.etree.ElementTree as ET
from urllib.parse import urljoin, urlsplit
from urllib.request import Request

from transport import Api, DIRECT, safe_error

PRIVATE_LANS = tuple(map(ipaddress.ip_network, ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")))
IGD = "urn:schemas-upnp-org:device:InternetGatewayDevice"
WAN = "urn:schemas-upnp-org:service:WANCommonInterfaceConfig"
TARGETS = ("www.google.com", "www.cloudflare.com")
MAC = re.compile(r"[0-9a-f][02468ace](?::[0-9a-f]{2}){5}")
RTT = re.compile(r"time=([0-9.]+)\s*ms")


def command(*args):
    result = subprocess.run(args, capture_output=True, check=True, timeout=3)
    return json.loads(result.stdout)


def probe(interface, address):
    try:
        family = "-6" if ":" in address else "-4"
        result = subprocess.run(
            ["ping", family, "-n", "-I", interface, "-c", "1", "-W", "1", address],
            capture_output=True, timeout=2, env={**os.environ, "LC_ALL": "C"},
        )
        latency = RTT.search(result.stdout.decode(errors="replace"))
        return result.returncode == 0, (round(float(latency[1]) * 1000) if latency and result.returncode == 0 else None)
    except (OSError, subprocess.TimeoutExpired):
        return False, None


def internet_status(source):
    if source is None:
        return "unknown"

    def check(host):
        connection = http.client.HTTPSConnection(host, timeout=3, source_address=(source, 0))
        try:
            connection.request("HEAD", "/")
            return 200 <= connection.getresponse().status < 400
        except (OSError, http.client.HTTPException):
            return False
        finally:
            connection.close()

    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
        answers = list(executor.map(check, TARGETS))
    return "up" if any(answers) else "down"


def pinned_url(base, reference, gateway):
    result = urljoin(base, reference)
    parsed = urlsplit(result)
    if parsed.scheme != "http" or parsed.hostname != gateway or parsed.username is not None or parsed.password is not None or parsed.fragment:
        raise ValueError("UPnP endpoint is outside the selected gateway")
    return result


def xml_request(url, data=None, headers=None):
    with DIRECT.open(Request(url, data, headers or {}), timeout=2) as response:
        payload = response.read(65537)
    if len(payload) > 65536 or b"\x00" in payload or b"<!DOCTYPE" in payload.upper() or b"<!ENTITY" in payload.upper():
        raise ValueError("Unsupported UPnP XML")
    return ET.fromstring(payload)


def find_service(gateway, source):
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.bind((source, 0))
        sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_IF, socket.inet_aton(source))
        sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 1)
        for search in ("upnp:rootdevice", f"{IGD}:1", f"{IGD}:2"):
            query = f'M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: "ssdp:discover"\r\nMX: 1\r\nST: {search}\r\n\r\n'
            sock.sendto(query.encode(), ("239.255.255.250", 1900))
        deadline = time.monotonic() + 2
        locations = set()
        while time.monotonic() < deadline and len(locations) < 8:
            sock.settimeout(max(0.01, deadline - time.monotonic()))
            try:
                packet, sender = sock.recvfrom(4096)
            except TimeoutError:
                break
            if sender[0] != gateway:
                continue
            for line in packet.decode(errors="replace").splitlines():
                if line.lower().startswith("location:"):
                    locations.add(line.split(":", 1)[1].strip())

    for location in sorted(locations):
        try:
            descriptor = pinned_url("", location, gateway)
            root = xml_request(descriptor)
            if not any((element.text or "") in {f"{IGD}:1", f"{IGD}:2"} for element in root.findall(".//{*}deviceType")):
                continue
            for service in root.findall(".//{*}service"):
                urn = service.findtext("{*}serviceType")
                control = service.findtext("{*}controlURL")
                if urn in {f"{WAN}:1", f"{WAN}:2"} and control:
                    return urn, pinned_url(descriptor, control, gateway)
        except (OSError, ET.ParseError, ValueError, http.client.HTTPException):
            continue
    return None


def upnp_link(service):
    urn, url = service
    action = "GetCommonLinkProperties"
    body = (
        '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
        f'<s:Body><u:{action} xmlns:u="{urn}"/></s:Body></s:Envelope>'
    ).encode()
    xml = xml_request(url, body, {"SOAPACTION": f'"{urn}#{action}"', "Content-Type": "text/xml; charset=utf-8"})
    state = (xml.findtext(".//{*}NewPhysicalLinkStatus") or "").lower()
    return state if state in {"up", "down"} else "unknown"


def lan_addresses(interface):
    records = command("ip", "-j", "addr", "show", "dev", interface)
    return [
        ipaddress.ip_interface(f"{entry['local']}/{entry['prefixlen']}")
        for record in records for entry in record.get("addr_info", [])
        if entry.get("family") in {"inet", "inet6"}
    ]


def neighbours(interface, local_networks):
    rows = []
    for entry in command("ip", "-j", "neigh", "show", "dev", interface):
        address = ipaddress.ip_address(entry["dst"])
        mac = entry.get("lladdr", "").lower()
        if not MAC.fullmatch(mac) or mac == "00:00:00:00:00:00" or address.is_multicast:
            continue
        if not any(address in network for network in local_networks):
            continue
        state = entry.get("state", [])
        rows.append((str(address), mac, "REACHABLE" in state))
    return rows


def targets_for_round(network, cursor, known, gateway, limit):
    """Reserve at least half the budget for rotating discovery when a subnet is configured."""
    candidates = ([gateway] if gateway else []) + sorted(set(known) - {gateway})
    if network is None:
        return list(itertools.islice(itertools.cycle(candidates), cursor, cursor + min(limit, len(candidates))))
    hosts = list(map(str, network.hosts()))
    existing_limit = min(len(candidates), limit // 2)
    targets = list(itertools.islice(itertools.cycle(candidates), cursor, cursor + existing_limit))
    for index in range(len(hosts)):
        target = hosts[(cursor + index) % len(hosts)]
        if target not in targets:
            targets.append(target)
        if len(targets) == limit:
            break
    return targets


class Collector:
    def __init__(self, interface, subnet):
        self.interface = interface
        self.subnet = ipaddress.ip_network(subnet) if subnet else None
        if self.subnet is not None and (
            self.subnet.version != 4 or self.subnet.prefixlen < 24
            or not any(self.subnet.subnet_of(private) for private in PRIVATE_LANS)
        ):
            raise ValueError("Active discovery requires an RFC1918 IPv4 /24 or smaller")
        self.upnp = None
        self.upnp_retry_at = 0
        self.gateway = None
        self.cursor = 0
        self.reported = set()
        self.ping_verified = False
        self.session = str(uuid.uuid4())
        self.sequence = 0

    def round(self, settings):
        addresses = lan_addresses(self.interface)
        local = {str(address.ip) for address in addresses}
        networks = [address.network for address in addresses]
        if self.subnet and not any(self.subnet.subnet_of(network) for network in networks if network.version == 4):
            raise ValueError("The configured IPv4 subnet is not on the selected interface")

        routes = command("ip", "-j", "-4", "route", "show", "default", "dev", self.interface)
        gateway = routes[0].get("gateway") if routes else None
        source = next((str(address.ip) for address in addresses if gateway and ipaddress.ip_address(gateway) in address.network), None)
        seen = neighbours(self.interface, networks)
        results = {}
        if settings["active_discovery"]:
            candidates = targets_for_round(self.subnet, self.cursor, [row[0] for row in seen], gateway, settings["probes_per_round"])
            self.cursor += max(1, settings["probes_per_round"] // 2 - 1)
            with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
                futures = {}
                for address in candidates:
                    if address not in local and any(ipaddress.ip_address(address) in network for network in networks):
                        futures[address] = executor.submit(probe, self.interface, address)
                        time.sleep(0.125)
                results = {address: future.result() for address, future in futures.items()}
            seen = neighbours(self.interface, networks)
            self.ping_verified |= any(reachable for reachable, _ in results.values())

        devices = {}
        for address, mac, reachable in seen:
            if not reachable and address not in results and (mac, address) in self.reported:
                continue
            device = devices.setdefault(mac, {"mac": mac, "reachable": False})
            family = "ipv6" if ":" in address else "ipv4"
            responded, latency = results.get(address, (False, None))
            if responded or family not in device:
                device[family] = address
            device["reachable"] |= reachable or responded
            if latency is not None:
                device["latency_us"] = min(device.get("latency_us", latency), latency)

        if gateway != self.gateway:
            self.gateway, self.upnp, self.upnp_retry_at = gateway, None, 0
        wan = "unknown"
        if gateway and source:
            try:
                if self.upnp is None and time.monotonic() >= self.upnp_retry_at:
                    self.upnp_retry_at = time.monotonic() + 300
                    self.upnp = find_service(gateway, source)
                if self.upnp:
                    wan = upnp_link(self.upnp)
            except (OSError, ET.ParseError, ValueError, http.client.HTTPException):
                self.upnp = None
        gateway_seen = any(address == gateway and reachable for address, _, reachable in seen)
        router = "up" if wan != "unknown" or gateway_seen or results.get(gateway, (False, None))[0] else "unknown"

        self.sequence += 1
        report = {
            "session": self.session,
            "sequence": self.sequence,
            "internet": internet_status(source),
            "router": router,
            "upnp": wan,
            "ipv4": any(address.version == 4 for address in addresses),
            "ipv6": any(address.version == 6 for address in addresses),
            "ping": self.ping_verified,
            "devices": list(devices.values())[:512],
        }
        return report

    def acknowledge(self, report):
        for device in report["devices"]:
            for family in ("ipv4", "ipv6"):
                if family in device:
                    self.reported.add((device["mac"], device[family]))
        if len(self.reported) > 4096:
            self.reported.clear()


def main():
    api = Api()
    collector = Collector(os.environ["NETWORK_MONITOR_INTERFACE"], os.getenv("NETWORK_MONITOR_SUBNET"))
    while True:
        interval = 60
        try:
            settings = api.request("/collector/settings")
            interval = settings["interval_seconds"]
            if settings["enabled"]:
                report = collector.round(settings)
                api.request("/collector/reports", report)
                collector.acknowledge(report)
        except (OSError, ValueError, subprocess.SubprocessError, http.client.HTTPException) as error:
            print(f"Collector round failed ({safe_error(error)})", flush=True)
        time.sleep(interval)


if __name__ == "__main__":
    main()
