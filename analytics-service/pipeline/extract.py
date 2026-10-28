"""Data extraction client and series normalizer for the analytics pipeline."""

from __future__ import annotations

import json
import urllib.request
from typing import Any, Dict, List, Optional


def fetch_sales_export(
    base_url: str,
    token: str,
    product_id: Optional[int] = None,
    branch_id: Optional[int] = None,
    window_days: int = 180,
    timeout: int = 15,
) -> List[Dict[str, Any]]:
    """Fetches daily sales export records from the backend export API."""
    params = [f"window_days={int(window_days)}"]
    if product_id is not None:
        params.append(f"product_id={int(product_id)}")
    if branch_id is not None:
        params.append(f"branch_id={int(branch_id)}")

    query = "&".join(params)
    url = f"{base_url.rstrip('/')}/api/v1/analytics/export/sales?{query}"
    req = urllib.request.Request(
        url,
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
        },
        method="GET",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        payload = json.loads(resp.read().decode("utf-8"))
        return list(payload.get("data", []))
