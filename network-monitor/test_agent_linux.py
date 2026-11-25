import http.server
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import uuid


@unittest.skipUnless(Path("/sys/class/net/lo/statistics/rx_bytes").exists(), "Linux interface counters required")
class LinuxAgentTest(unittest.TestCase):
    def test_sends_only_counters_to_the_scoped_ingestion_endpoint(self):
        received = []

        class Receiver(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                received.append((self.path, self.headers["Authorization"], body))
                self.send_response(204)
                self.end_headers()

            def log_message(self, *_):
                pass

        with tempfile.TemporaryDirectory() as tmp, http.server.HTTPServer(("127.0.0.1", 0), Receiver) as server:
            token_file = Path(tmp) / "token"
            token_file.write_text("test-token")
            environment = {
                **os.environ,
                "NETWORK_MONITOR_URL": f"http://127.0.0.1:{server.server_port}",
                "NETWORK_MONITOR_INTERFACE": "lo",
                "NETWORK_MONITOR_TOKEN_FILE": str(token_file),
            }
            process = subprocess.Popen(
                [sys.executable, str(Path(__file__).with_name("agent-linux.py"))],
                env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            try:
                server.timeout = 5
                server.handle_request()
            finally:
                process.terminate()
                process.wait(timeout=5)

        self.assertEqual(len(received), 1)
        path, authorization, body = received[0]
        self.assertEqual(path, "/api/network-monitor/v1/agent/samples")
        self.assertEqual(authorization, "Bearer test-token")
        self.assertEqual(set(body), {"session", "sequence", "rx_bytes", "tx_bytes"})
        self.assertEqual(body["sequence"], 1)
        self.assertGreaterEqual(body["rx_bytes"], 0)
        self.assertGreaterEqual(body["tx_bytes"], 0)
        self.assertEqual(uuid.UUID(body["session"]).version, 4)


if __name__ == "__main__":
    unittest.main()
