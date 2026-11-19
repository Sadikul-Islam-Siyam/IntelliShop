"""Phantom Inventory Detection (#70).

Screens for phantom inventory (inventory recorded on books but physically missing):
  Baseline window 30 days:
    mu = (1/n) * sum(x_i)
    sigma = sqrt((1/n) * sum((x_i - mu)^2))
    z = (x - mu) / sigma

  Candidate condition:
    recorded_stock > 0
    AND z <= z_threshold (default -2.0) for k consecutive days (default 3)
    AND history >= baseline_window (default 30 days)
    AND no recorded intentional unavailability (stockout/closure)
    AND no active promotion / price spike explaining the drop

  Action:
    Trigger cycle-count task (status: open).
    NEVER silently changes stock directly.
"""

from __future__ import annotations

import math
from typing import Any, Dict, List, Optional, Sequence, Tuple


DEFAULT_BASELINE_DAYS = 30
DEFAULT_Z_THRESHOLD = -2.0
DEFAULT_PERSISTENCE_DAYS = 3
DEFAULT_MIN_HISTORY_DAYS = 30


def detect_phantom_inventory(
    daily_sales: Sequence[float],
    recorded_stock: float,
    baseline_days: int = DEFAULT_BASELINE_DAYS,
    z_threshold: float = DEFAULT_Z_THRESHOLD,
    persistence_days: int = DEFAULT_PERSISTENCE_DAYS,
    min_history_days: int = DEFAULT_MIN_HISTORY_DAYS,
    has_active_promotion_or_price_spike: bool = False,
    has_recorded_unavailability: bool = False,
) -> Dict[str, Any]:
    """Detect whether an item exhibits phantom inventory behavior."""
    n_days = len(daily_sales)

    # Guard 1: Positive recorded stock required
    if recorded_stock <= 0:
        return {
            "is_phantom_candidate": False,
            "reason": "recorded_stock_zero_or_negative",
            "recorded_stock": recorded_stock,
            "z_score": 0.0,
        }

    # Guard 2: Enough history required
    if n_days < min_history_days:
        return {
            "is_phantom_candidate": False,
            "reason": "insufficient_history",
            "days_available": n_days,
            "min_history_days": min_history_days,
            "recorded_stock": recorded_stock,
            "z_score": 0.0,
        }

    # Guard 3: Recorded intentional unavailability guard
    if has_recorded_unavailability:
        return {
            "is_phantom_candidate": False,
            "reason": "recorded_unavailability",
            "recorded_stock": recorded_stock,
            "z_score": 0.0,
        }

    # Guard 4: Active promotion / price change guard
    if has_active_promotion_or_price_spike:
        return {
            "is_phantom_candidate": False,
            "reason": "external_pricing_or_promo_change",
            "recorded_stock": recorded_stock,
            "z_score": 0.0,
        }

    # Calculate baseline mean and standard deviation over baseline window prior to persistence days
    k = max(1, persistence_days)
    if n_days <= k:
        return {
            "is_phantom_candidate": False,
            "reason": "insufficient_baseline_points",
            "recorded_stock": recorded_stock,
            "z_score": 0.0,
        }

    baseline_data = daily_sales[:-k]
    recent_k_days = daily_sales[-k:]

    n_baseline = len(baseline_data)
    if n_baseline == 0:
        return {
            "is_phantom_candidate": False,
            "reason": "empty_baseline",
            "recorded_stock": recorded_stock,
            "z_score": 0.0,
        }

    mu = sum(baseline_data) / n_baseline
    variance = sum((x - mu) ** 2 for x in baseline_data) / n_baseline
    sigma = math.sqrt(variance)

    if sigma <= 1e-9:
        return {
            "is_phantom_candidate": False,
            "reason": "zero_sales_variance",
            "baseline_mean": round(mu, 2),
            "baseline_std": 0.0,
            "recorded_stock": recorded_stock,
            "z_score": 0.0,
        }

    # Check persistence: z <= z_threshold for all k consecutive recent days
    recent_z_scores = [(x - mu) / sigma for x in recent_k_days]
    all_exceed_threshold = all(z <= z_threshold for z in recent_z_scores)

    avg_recent_z = sum(recent_z_scores) / len(recent_z_scores)

    if all_exceed_threshold:
        return {
            "is_phantom_candidate": True,
            "trigger_action": "create_cycle_count",
            "never_change_stock": True,
            "baseline_mean": round(mu, 2),
            "baseline_std": round(sigma, 3),
            "z_score": round(avg_recent_z, 3),
            "recent_z_scores": [round(z, 3) for z in recent_z_scores],
            "persistence_days": k,
            "recorded_stock": recorded_stock,
            "message": f"Phantom inventory suspected: stock on hand is {recorded_stock}, but zero/low sales persisted for {k} consecutive days with z <= {z_threshold}.",
        }

    return {
        "is_phantom_candidate": False,
        "reason": "persistence_criteria_not_met",
        "baseline_mean": round(mu, 2),
        "baseline_std": round(sigma, 3),
        "z_score": round(avg_recent_z, 3),
        "recent_z_scores": [round(z, 3) for z in recent_z_scores],
        "recorded_stock": recorded_stock,
    }


def handle_phantom_run_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """HTTP endpoint handler for POST /phantom/run."""
    if not isinstance(payload, dict):
        return 422, {"status": "error", "message": "Payload must be a JSON dictionary."}

    daily_sales = payload.get("daily_sales", [])
    recorded_stock = float(payload.get("recorded_stock", payload.get("system_quantity", 0.0)))
    b_days = int(payload.get("baseline_days", DEFAULT_BASELINE_DAYS))
    z_thresh = float(payload.get("z_threshold", DEFAULT_Z_THRESHOLD))
    k = int(payload.get("persistence_days", DEFAULT_PERSISTENCE_DAYS))
    min_hist = int(payload.get("min_history_days", DEFAULT_MIN_HISTORY_DAYS))
    promo_guard = bool(payload.get("has_active_promotion_or_price_spike", False))
    unavail_guard = bool(payload.get("has_recorded_unavailability", False))

    res = detect_phantom_inventory(
        daily_sales=daily_sales,
        recorded_stock=recorded_stock,
        baseline_days=b_days,
        z_threshold=z_thresh,
        persistence_days=k,
        min_history_days=min_hist,
        has_active_promotion_or_price_spike=promo_guard,
        has_recorded_unavailability=unavail_guard,
    )

    return 200, {
        "status": "success",
        "data": res,
    }
