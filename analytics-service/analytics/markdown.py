"""Zero-Waste Markdown Recommendation (#69).

Calculates batch-level clearance markdowns for perishable products near expiry:
  AvgDaily = Total / BaselineDays
  Projected = AvgDaily * DaysRemaining
  Surplus = max(0, BatchQty - Projected)
  Markdown% = f(Surplus, DaysRemaining) bounded by Max%
  f = Max% * min(1, (Surplus/BatchQty) * (1 + (1 - DaysRemaining/Horizon))) rounded to step
"""

from __future__ import annotations

from typing import Any, Dict, List, Optional


DEFAULT_BASELINE_DAYS = 30
DEFAULT_HORIZON_DAYS = 14
DEFAULT_MAX_MARKDOWN_PCT = 40.0
DEFAULT_STEP_PCT = 5.0
DEFAULT_APPROVAL_THRESHOLD_PCT = 30.0


def calculate_markdown_for_batch(
    batch_qty: float,
    total_sales_in_baseline: float,
    days_remaining: int,
    baseline_days: int = DEFAULT_BASELINE_DAYS,
    horizon_days: int = DEFAULT_HORIZON_DAYS,
    max_markdown_pct: float = DEFAULT_MAX_MARKDOWN_PCT,
    step_pct: float = DEFAULT_STEP_PCT,
    approval_threshold_pct: float = DEFAULT_APPROVAL_THRESHOLD_PCT,
) -> Dict[str, Any]:
    """Calculate the zero-waste markdown recommendation for a single batch."""
    # Expired batches are excluded from sale
    if days_remaining <= 0:
        return {
            "is_expired": True,
            "days_remaining": days_remaining,
            "avg_daily_sales": 0.0,
            "projected_sales": 0.0,
            "surplus_qty": 0.0,
            "markdown_pct": 0.0,
            "max_markdown_pct": max_markdown_pct,
            "status": "expired_excluded",
            "approval_authority": "Super Admin",
            "recommendation": "Batch expired. Must be written off and excluded from sale.",
        }

    b_days = max(1, baseline_days)
    h_days = max(1, horizon_days)
    avg_daily = float(total_sales_in_baseline) / b_days
    projected = avg_daily * days_remaining
    surplus = max(0.0, float(batch_qty) - projected)

    if surplus <= 0.0 or batch_qty <= 0:
        return {
            "is_expired": False,
            "days_remaining": days_remaining,
            "avg_daily_sales": round(avg_daily, 2),
            "projected_sales": round(projected, 2),
            "surplus_qty": 0.0,
            "markdown_pct": 0.0,
            "max_markdown_pct": max_markdown_pct,
            "status": "normal_sellthrough",
            "approval_authority": "Branch Manager",
            "recommendation": "Projected demand covers batch quantity before expiry. No markdown required.",
        }

    surplus_ratio = surplus / float(batch_qty)
    urgency = max(0.0, 1.0 - (float(days_remaining) / float(h_days)))
    multiplier = min(1.0, surplus_ratio * (1.0 + urgency))

    raw_pct = max_markdown_pct * multiplier
    step = max(1.0, step_pct)
    rounded_pct = round(raw_pct / step) * step
    markdown_pct = min(float(max_markdown_pct), max(0.0, float(rounded_pct)))

    authority = "Super Admin" if markdown_pct > approval_threshold_pct else "Branch Manager"

    return {
        "is_expired": False,
        "days_remaining": days_remaining,
        "avg_daily_sales": round(avg_daily, 2),
        "projected_sales": round(projected, 2),
        "surplus_qty": round(surplus, 2),
        "markdown_pct": markdown_pct,
        "max_markdown_pct": max_markdown_pct,
        "status": "markdown_recommended",
        "approval_authority": authority,
        "recommendation": f"Recommend {markdown_pct:.0f}% markdown to accelerate sell-through before expiry.",
    }


def evaluate_batch_markdowns(
    batches: List[Dict[str, Any]],
    baseline_days: int = DEFAULT_BASELINE_DAYS,
    horizon_days: int = DEFAULT_HORIZON_DAYS,
    max_markdown_pct: float = DEFAULT_MAX_MARKDOWN_PCT,
    step_pct: float = DEFAULT_STEP_PCT,
    approval_threshold_pct: float = DEFAULT_APPROVAL_THRESHOLD_PCT,
) -> List[Dict[str, Any]]:
    """Evaluate a collection of batches for markdown recommendations."""
    results = []
    for b in batches:
        calc = calculate_markdown_for_batch(
            batch_qty=float(b.get("quantity_remaining", b.get("available_qty", 0))),
            total_sales_in_baseline=float(b.get("total_sales_in_baseline", 0.0)),
            days_remaining=int(b.get("days_remaining", 0)),
            baseline_days=baseline_days,
            horizon_days=horizon_days,
            max_markdown_pct=max_markdown_pct,
            step_pct=step_pct,
            approval_threshold_pct=approval_threshold_pct,
        )
        res = dict(b)
        res.update(calc)
        results.append(res)
    return results
