"""Statistical Anomaly Detection (Modified Z-Score + IQR Fences) (#52).

Algorithms:
  1. Modified Z-Score (Boris Iglewicz and David Hoaglin):
     M_i = 0.6745 * (x_i - median) / MAD
     Flagged when |M_i| > 3.5 (configurable via mad_threshold).
  2. Tukey IQR Fences:
     [Q1 - 1.5 * IQR, Q3 + 1.5 * IQR]
     Flagged when x_i < lower_fence or x_i > upper_fence.
"""

from __future__ import annotations

import math
from typing import Any, Dict, List, Optional, Sequence, Tuple


DEFAULT_MAD_THRESHOLD = 3.5
DEFAULT_IQR_K = 1.5


def _median(values: Sequence[float]) -> float:
    """Compute the median of a list of floats."""
    if not values:
        return 0.0
    sorted_vals = sorted(values)
    n = len(sorted_vals)
    mid = n // 2
    if n % 2 == 1:
        return float(sorted_vals[mid])
    return (float(sorted_vals[mid - 1]) + float(sorted_vals[mid])) / 2.0


def _percentile(sorted_vals: Sequence[float], p: float) -> float:
    """Compute the p-th percentile (0 <= p <= 1) of sorted values."""
    if not sorted_vals:
        return 0.0
    n = len(sorted_vals)
    if n == 1:
        return float(sorted_vals[0])
    rank = p * (n - 1)
    k = math.floor(rank)
    d = rank - k
    if k + 1 < n:
        return float(sorted_vals[k]) + d * (float(sorted_vals[k + 1]) - float(sorted_vals[k]))
    return float(sorted_vals[k])


def detect_anomalies(
    series: Sequence[Dict[str, Any]],
    mad_threshold: float = DEFAULT_MAD_THRESHOLD,
    iqr_k: float = DEFAULT_IQR_K,
) -> Dict[str, Any]:
    """Detect statistical anomalies in a daily sales time-series.

    Each item in `series` should have 'date' and 'quantity' (or 'value').
    """
    if len(series) < 3:
        return {
            "median": 0.0,
            "mad": 0.0,
            "q1": 0.0,
            "q3": 0.0,
            "iqr": 0.0,
            "lower_fence": 0.0,
            "upper_fence": 0.0,
            "anomalies": [],
            "total_points": len(series),
        }

    extracted = []
    for item in series:
        qty = float(item.get("quantity", item.get("value", 0.0)) or 0.0)
        date_str = str(item.get("date", item.get("sale_date", "")))
        extracted.append((date_str, qty, item))

    values = [val for _, val, _ in extracted]
    med = _median(values)

    # Median Absolute Deviation (MAD)
    abs_deviations = [abs(v - med) for v in values]
    mad = _median(abs_deviations)

    # Tukey IQR fences
    sorted_vals = sorted(values)
    q1 = _percentile(sorted_vals, 0.25)
    q3 = _percentile(sorted_vals, 0.75)
    iqr = max(0.0, q3 - q1)
    lower_fence = q1 - (iqr_k * iqr)
    upper_fence = q3 + (iqr_k * iqr)

    anomalies: List[Dict[str, Any]] = []

    for date_str, val, orig in extracted:
        # Modified Z-Score
        if mad > 0:
            mod_z = 0.6745 * (val - med) / mad
        else:
            # If MAD is 0 (over 50% identical values), fallback to mean/std if available
            mean_val = sum(values) / len(values)
            variance = sum((v - mean_val) ** 2 for v in values) / len(values)
            std_val = math.sqrt(variance)
            mod_z = (val - mean_val) / std_val if std_val > 0 else 0.0

        is_mad_anomaly = abs(mod_z) > mad_threshold
        is_iqr_anomaly = (val < lower_fence) or (val > upper_fence)

        if is_mad_anomaly or is_iqr_anomaly:
            anomalies.append({
                "date": date_str,
                "actual_value": round(val, 2),
                "expected_value": round(med, 2),
                "deviation_score": round(mod_z, 3),
                "flagged_by_mad": is_mad_anomaly,
                "flagged_by_iqr": is_iqr_anomaly,
                "lower_fence": round(lower_fence, 2),
                "upper_fence": round(upper_fence, 2),
                "product_id": orig.get("product_id"),
                "branch_id": orig.get("branch_id"),
                "status": "pending",
            })

    return {
        "median": round(med, 2),
        "mad": round(mad, 3),
        "q1": round(q1, 2),
        "q3": round(q3, 2),
        "iqr": round(iqr, 2),
        "lower_fence": round(lower_fence, 2),
        "upper_fence": round(upper_fence, 2),
        "mad_threshold": mad_threshold,
        "iqr_k": iqr_k,
        "total_points": len(series),
        "anomaly_count": len(anomalies),
        "anomalies": anomalies,
    }


def handle_anomaly_run_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """HTTP endpoint handler for POST /anomaly/run."""
    if not isinstance(payload, dict):
        return 422, {"status": "error", "message": "Payload must be a JSON dictionary."}

    series = payload.get("series", payload.get("sales", []))
    if not isinstance(series, list):
        return 422, {"status": "error", "message": "'series' must be a list of date/quantity objects."}

    mad_thresh = float(payload.get("mad_threshold", DEFAULT_MAD_THRESHOLD))
    iqr_k_val = float(payload.get("iqr_k", DEFAULT_IQR_K))

    result = detect_anomalies(series, mad_threshold=mad_thresh, iqr_k=iqr_k_val)

    return 200, {
        "status": "success",
        "data": result,
    }
