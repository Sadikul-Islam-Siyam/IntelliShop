"""
Lightweight REST/JSON HTTP server for the Python analytics service.

Provides health checks, bearer token authentication, and route dispatching
for analytical and forecasting endpoints.
"""

from __future__ import annotations

import json
import os
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Any, Callable, Dict, Optional, Tuple
from urllib.parse import urlparse

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

SERVICE_VERSION = "1.0.0"

_POST_HANDLERS: Dict[str, Callable[[Dict[str, Any]], Tuple[int, Dict[str, Any]]]] = {}


def register_post_handler(
    path: str,
    handler: Callable[[Dict[str, Any]], Tuple[int, Dict[str, Any]]],
) -> None:
    _POST_HANDLERS[path] = handler


def make_validation_error(field: str, issue: str) -> Tuple[int, Dict[str, Any]]:
    return 422, {
        "error": {
            "code": "VALIDATION_FAILED",
            "message": "The given data was invalid.",
            "details": [{"field": field, "issue": issue}],
        }
    }


def verify_service_token(headers: Dict[str, str], expected_token: Optional[str] = None) -> bool:
    token = expected_token if expected_token is not None else os.environ.get("ANALYTICS_SERVICE_TOKEN", "")
    if not token:
        return True
    auth_header = headers.get("Authorization") or headers.get("authorization") or ""
    if auth_header.startswith("Bearer "):
        provided = auth_header[7:].strip()
        return provided == token
    custom_header = headers.get("X-Analytics-Token") or headers.get("x-analytics-token") or ""
    return custom_header == token


def dispatch_request(
    method: str,
    path: str,
    body: Optional[Dict[str, Any]] = None,
    headers: Optional[Dict[str, str]] = None,
    expected_token: Optional[str] = None,
) -> Tuple[int, Dict[str, Any]]:
    """Dispatches an incoming request to the appropriate endpoint handler."""
    parsed_path = urlparse(path).path.rstrip("/") or "/"
    hdrs = headers or {}

    if not verify_service_token(hdrs, expected_token=expected_token):
        return 401, {
            "error": {
                "code": "UNAUTHENTICATED",
                "message": "Invalid or missing analytics service token.",
            }
        }

    if method.upper() == "GET" and parsed_path == "/health":
        return 200, {
            "status": "ok",
            "version": SERVICE_VERSION,
            "service": "intellishop-analytics",
        }

    if method.upper() == "POST":
        payload = body if isinstance(body, dict) else {}

        if parsed_path in ("/forecast", "/forecast/select") and parsed_path not in _POST_HANDLERS:
            try:
                from models.baseline import handle_forecast_request  # type: ignore

                register_post_handler("/forecast", handle_forecast_request)
                register_post_handler("/forecast/select", handle_forecast_request)
            except ImportError:
                pass

        if parsed_path in _POST_HANDLERS:
            return _POST_HANDLERS[parsed_path](payload)

        if parsed_path in ("/forecast", "/forecast/select"):
            for req_field in ("product_id", "branch_id", "horizon", "history"):
                if req_field not in payload:
                    return make_validation_error(req_field, f"The {req_field} field is required.")
            return 200, {
                "forecast": [],
                "model_used": "naive",
                "metrics": {"mae": 0.0, "rmse": 0.0, "smape": 0.0},
                "beats_baseline": False,
            }

    return 404, {
        "error": {
            "code": "NOT_FOUND",
            "message": f"Endpoint {method.upper()} {parsed_path} not found.",
        }
    }


class AnalyticsRequestHandler(BaseHTTPRequestHandler):
    def _send_json(self, status_code: int, payload: Dict[str, Any]) -> None:
        encoded = json.dumps(payload).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def do_GET(self) -> None:  # noqa: N802
        headers = {k: v for k, v in self.headers.items()}
        status, response = dispatch_request("GET", self.path, headers=headers)
        self._send_json(status, response)

    def do_POST(self) -> None:  # noqa: N802
        headers = {k: v for k, v in self.headers.items()}
        length = int(self.headers.get("Content-Length", "0") or "0")
        raw_body = self.rfile.read(length).decode("utf-8") if length > 0 else "{}"
        try:
            body = json.loads(raw_body) if raw_body.strip() else {}
        except json.JSONDecodeError:
            self._send_json(
                422,
                {
                    "error": {
                        "code": "INVALID_JSON",
                        "message": "Request body must be valid JSON.",
                    }
                },
            )
            return

        status, response = dispatch_request("POST", self.path, body=body, headers=headers)
        self._send_json(status, response)

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A002
        if os.environ.get("ANALYTICS_LOG", "0") == "1":
            super().log_message(format, *args)


def run_server(host: str = "0.0.0.0", port: int = 8001) -> None:
    server = HTTPServer((host, port), AnalyticsRequestHandler)
    print(f"IntelliShop Analytics Service v{SERVICE_VERSION} listening on http://{host}:{port}")
    server.serve_forever()


if __name__ == "__main__":
    cli_args = sys.argv[1:]
    if "--cli" in cli_args:
        idx = cli_args.index("--cli")
        endpoint = cli_args[idx + 1] if len(cli_args) > idx + 1 else "/health"
        raw_input = sys.stdin.read()
        payload = json.loads(raw_input) if raw_input.strip() else {}
        method = "GET" if endpoint == "/health" else "POST"
        status_code, result = dispatch_request(method, endpoint, body=payload)
        sys.stdout.write(json.dumps({"status_code": status_code, "body": result}))
        sys.exit(0)

    port_num = int(os.environ.get("ANALYTICS_PORT", "8001"))
    run_server(port=port_num)
