"""Smoke-test one real agent POST on Windows, or use mocked NetAdapter cmdlets on Linux."""

import contextlib
import http.server
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import uuid

POWERSHELL = os.getenv("TEST_PWSH") or shutil.which("pwsh")


@unittest.skipUnless(POWERSHELL, "PowerShell 7 is required")
class WindowsAgentTest(unittest.TestCase):
    def test_collector_posts_only_counters(self):
        received = []

        class Receiver(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                received.append((self.path, self.headers["Authorization"], body))
                self.send_response(204)
                self.end_headers()

            def log_message(self, *_):
                pass

        with tempfile.TemporaryDirectory() as path, http.server.HTTPServer(("127.0.0.1", 0), Receiver) as server:
            token = Path(path) / "token"
            token.write_text("windows-test-token")
            wrapper = Path(path) / "run.ps1"
            wrapper.write_text("""
$ErrorActionPreference = 'Stop'
if ($IsWindows) {
    $adapter = Get-NetAdapter | Where-Object Status -eq Up | Select-Object -First 1
    if (!$adapter) { throw 'No active Windows adapter available' }
    $interfaceName = $adapter.Name
} else {
    $interfaceName = 'Private mock adapter'
    function Get-NetAdapter {
        param([string] $Name)
        [pscustomobject]@{ Name = $Name; InterfaceGuid = 'private-mock-id' }
    }
    function Get-NetAdapterStatistics {
        param([string] $Name)
        [pscustomobject]@{ ReceivedBytes = 100; SentBytes = 202 }
    }
}
& $env:TEST_AGENT_SCRIPT -Server $env:TEST_AGENT_URL -InterfaceName $interfaceName -TokenFile $env:TEST_AGENT_TOKEN
""")
            environment = {
                **os.environ,
                "TEST_AGENT_SCRIPT": str(Path(__file__).with_name("agent-windows.ps1").resolve()),
                "TEST_AGENT_URL": f"http://127.0.0.1:{server.server_port}",
                "TEST_AGENT_TOKEN": str(token),
            }
            process = subprocess.Popen(
                [POWERSHELL, "-NoProfile", "-NonInteractive", "-File", str(wrapper)],
                env=environment, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            )
            try:
                server.timeout = 30
                server.handle_request()
            finally:
                with contextlib.suppress(ProcessLookupError):
                    process.terminate()
                output, _ = process.communicate(timeout=10)

        self.assertEqual(len(received), 1, output.decode(errors="replace"))
        path, header, body = received[0]
        self.assertEqual(path, "/api/network-monitor/v1/agent/samples")
        self.assertEqual(header, "Bearer windows-test-token")
        self.assertEqual(set(body), {"session", "sequence", "rx_bytes", "tx_bytes"})
        self.assertEqual(body["sequence"], 1)
        self.assertEqual(uuid.UUID(body["session"]).version, 4)
        self.assertGreaterEqual(body["rx_bytes"], 0)
        self.assertGreaterEqual(body["tx_bytes"], 0)
        if os.name != "nt":
            self.assertEqual((body["rx_bytes"], body["tx_bytes"]), (100, 202))


if __name__ == "__main__":
    unittest.main()
