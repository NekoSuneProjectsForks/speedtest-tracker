"""Report cumulative byte counters for one explicitly selected Linux interface."""

import os
from pathlib import Path
import re
import time
import uuid

from transport import Api, safe_error


def main():
    api = Api()
    interface = os.environ["NETWORK_MONITOR_INTERFACE"]
    if not re.fullmatch(r"[a-zA-Z0-9_.:-]{1,15}", interface) or interface in {".", ".."}:
        raise ValueError("Invalid interface name")
    device = Path("/sys/class/net") / interface
    interval = int(os.getenv("NETWORK_MONITOR_AGENT_INTERVAL", "60"))
    if not 10 <= interval <= 300:
        raise ValueError("Agent interval must be between 10 and 300 seconds")

    session, sequence, previous = str(uuid.uuid4()), 0, None
    while True:
        started = time.monotonic()
        try:
            identity = (device / "ifindex").read_text().strip()
            if identity != previous:
                session, sequence, previous = str(uuid.uuid4()), 0, identity
            stats = device / "statistics"
            rx, tx = (int((stats / name).read_text()) for name in ("rx_bytes", "tx_bytes"))
            if (device / "ifindex").read_text().strip() == identity:
                sequence += 1
                api.request("/agent/samples", {"session": session, "sequence": sequence, "rx_bytes": rx, "tx_bytes": tx})
        except (OSError, ValueError) as error:
            print(f"Agent sample failed ({safe_error(error)})", flush=True)
        time.sleep(max(1, interval - (time.monotonic() - started)))


if __name__ == "__main__":
    main()
