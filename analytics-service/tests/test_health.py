"""Integration tests for the Python analytics HTTP service."""

from __future__ import annotations

import json
import os
import sys
import threading
import unittest
import urllib.error
import urllib.request
from http.server import HTTPServer

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from api.app import AnalyticsRequestHandler, dispatch_request


class TestAnalyticsServiceHealth(unittest.TestCase):
    def test_health_endpoint_returns_ok_and_version(self) -> None:
        status, body = dispatch_request("GET", "/health")
        self.assertEqual(status, 200)
        self.assertEqual(body["status"], "ok")
        self.assertIn("version", body)

    def test_service_token_authentication_is_enforced_when_configured(self) -> None:
        status, body = dispatch_request(
            "GET",
            "/health",
            headers={},
            expected_token="secret-service-token",
        )
        self.assertEqual(status, 401)
        self.assertEqual(body["error"]["code"], "UNAUTHENTICATED")

        status_ok, body_ok = dispatch_request(
            "GET",
            "/health",
            headers={"Authorization": "Bearer secret-service-token"},
            expected_token="secret-service-token",
        )
        self.assertEqual(status_ok, 200)
        self.assertEqual(body_ok["status"], "ok")

    def test_http_server_health_integration(self) -> None:
        server = HTTPServer(("127.0.0.1", 0), AnalyticsRequestHandler)
        port = server.server_address[1]
        thread = threading.Thread(target=server.handle_request, daemon=True)
        thread.start()

        with urllib.request.urlopen(f"http://127.0.0.1:{port}/health", timeout=5) as resp:
            self.assertEqual(resp.status, 200)
            payload = json.loads(resp.read().decode("utf-8"))
            self.assertEqual(payload["status"], "ok")

        server.server_close()


if __name__ == "__main__":
    unittest.main()
