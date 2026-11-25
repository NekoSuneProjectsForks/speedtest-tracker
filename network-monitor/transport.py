"""HTTP transport shared by the Linux collector and bandwidth agent."""

import json
import os
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


DIRECT = build_opener(ProxyHandler({}), NoRedirect())


class Api:
    def __init__(self):
        base = os.environ["NETWORK_MONITOR_URL"].rstrip("/")
        parsed = urlsplit(base)
        if not parsed.hostname or parsed.username or parsed.query or parsed.fragment or (
            parsed.scheme != "https" and not (
                parsed.scheme == "http" and parsed.hostname in {"127.0.0.1", "::1", "localhost"}
            )
        ):
            raise ValueError("Supply an HTTPS URL or an HTTP loopback URL")
        self.base = base + "/api/network-monitor/v1"
        self.token = Path(os.environ["NETWORK_MONITOR_TOKEN_FILE"]).read_text().strip()

    def request(self, path, payload=None):
        body = None if payload is None else json.dumps(payload).encode()
        headers = {
            "Accept": "application/json",
            "Authorization": "Bearer " + self.token,
            "Content-Type": "application/json",
        }
        with DIRECT.open(Request(self.base + path, body, headers), timeout=8) as response:
            return json.load(response) if response.status == 200 else None


def safe_error(error):
    if isinstance(error, HTTPError):
        return f"HTTP {error.code}"
    return type(error).__name__
