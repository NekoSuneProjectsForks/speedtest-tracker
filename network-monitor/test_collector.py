import ipaddress
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

from collector import Collector, neighbours, pinned_url, targets_for_round, upnp_link


class CollectorTest(unittest.TestCase):
    def test_bounded_scan_rotates_and_includes_known_neighbours(self):
        subnet = ipaddress.ip_network("192.0.2.0/24")
        first = targets_for_round(subnet, 0, ["192.0.2.240", "fe80::1"], "192.0.2.254", 8)
        later = targets_for_round(subnet, 8, ["192.0.2.240", "fe80::1"], "192.0.2.254", 8)
        self.assertEqual(len(first), 8)
        self.assertEqual(len(first), len(set(first)))
        self.assertIn("fe80::1", first)
        self.assertIn("192.0.2.254", first)
        self.assertIn("192.0.2.10", later)
        with self.assertRaises(ValueError):
            Collector("eth0", "192.0.2.0/24")

    def test_neighbours_are_scoped_and_stale_is_unconfirmed(self):
        sample = [
            {"dst": "192.0.2.1", "lladdr": "02:11:22:33:44:55", "state": ["STALE"]},
            {"dst": "fe80::1", "lladdr": "02:11:22:33:44:55", "state": ["REACHABLE"]},
            {"dst": "198.51.100.1", "lladdr": "02:11:22:33:44:55", "state": ["REACHABLE"]},
            {"dst": "192.0.2.2", "lladdr": "01:11:22:33:44:55", "state": ["REACHABLE"]},
        ]
        with patch("collector.command", return_value=sample):
            entries = neighbours("eth0", [ipaddress.ip_network(cidr) for cidr in ("192.0.2.0/24", "fe80::/64")])
        self.assertEqual(entries, [
            ("192.0.2.1", "02:11:22:33:44:55", False),
            ("fe80::1", "02:11:22:33:44:55", True),
        ])

    def test_upnp_is_pinned_to_gateway_and_requests_link_status_only(self):
        base = "http://192.0.2.1:8020/description"
        control = pinned_url(base, "/control", "192.0.2.1")
        self.assertEqual(control, "http://192.0.2.1:8020/control")
        for url in ("http://192.0.2.2/test", "http://private@192.0.2.1/test", "https://192.0.2.1/test"):
            with self.assertRaises(ValueError):
                pinned_url(base, url, "192.0.2.1")
        response = ET.fromstring("<result><NewPhysicalLinkStatus>Up</NewPhysicalLinkStatus></result>")
        with patch("collector.xml_request", return_value=response) as request:
            self.assertEqual(upnp_link(("urn:schemas-upnp-org:service:WANCommonInterfaceConfig:1", control)), "up")
            self.assertIn("#GetCommonLinkProperties", request.call_args.args[2]["SOAPACTION"])
            self.assertNotIn(b"Bytes", request.call_args.args[1])


if __name__ == "__main__":
    unittest.main()
