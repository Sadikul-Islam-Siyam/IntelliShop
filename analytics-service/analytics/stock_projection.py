"""
Stock projection and stock-out risk algorithm.

Calculates the daily discrete inventory projection timeline:
    Projected_Stock(t) = Available_Stock + Sum_{tau <= t} Scheduled_Receipts(tau) - Sum_{tau <= t} Predicted_Demand(tau)

Identifies the Expected Stock-Out Date (ESD) as the first date where
Projected_Stock(t) crosses zero (<= 0), and computes the risk classification:
    - No ESD in horizon        -> LOW
    - ESD <= today + L          -> HIGH    (stockout within lead time L, cannot replenish in time)
    - ESD <= today + L + R      -> MEDIUM  (stockout within L + R, order this review cycle)
    - Otherwise                 -> LOW
"""

from __future__ import annotations

import datetime
from typing import Any, Dict, List, Optional, Tuple, Union


def parse_date(value: Union[str, datetime.date, datetime.datetime]) -> datetime.date:
    """Parses a date string in YYYY-MM-DD format or returns the date object."""
    if isinstance(value, datetime.datetime):
        return value.date()
    if isinstance(value, datetime.date):
        return value
    return datetime.date.fromisoformat(str(value).strip().split("T")[0])


def calculate_stock_projection(
    available_stock: float,
    forecasts: List[Union[Dict[str, Any], float, int]],
    lead_time_days: int,
    review_period_days: int,
    scheduled_receipts: Optional[Union[Dict[str, float], List[Dict[str, Any]], List[float]]] = None,
    start_date: Optional[Union[str, datetime.date]] = None,
) -> Dict[str, Any]:
    """
    Computes the daily projected stock, Expected Stock-Out Date (ESD),
    days of cover, and stock-out risk level.

    Args:
        available_stock: Current on-hand available stock.
        forecasts: List of daily forecast records or daily demand numbers.
        lead_time_days: Supplier lead time L in days.
        review_period_days: Periodic review interval R in days.
        scheduled_receipts: Receipts scheduled to arrive on specific dates.
        start_date: Starting anchor date for the projection (defaults to today).
    """
    base_date = parse_date(start_date) if start_date else datetime.date.today()
    receipts_by_date: Dict[str, float] = {}

    if scheduled_receipts:
        if isinstance(scheduled_receipts, dict):
            for d_str, qty in scheduled_receipts.items():
                receipts_by_date[str(d_str)] = float(qty)
        elif isinstance(scheduled_receipts, list):
            for item in scheduled_receipts:
                if isinstance(item, dict):
                    d_key = str(item.get("date") or item.get("delivery_date") or item.get("expected_date"))
                    q_val = float(item.get("quantity") or item.get("qty") or 0.0)
                    receipts_by_date[d_key] = receipts_by_date.get(d_key, 0.0) + q_val
                elif isinstance(item, (int, float)):
                    # Positional daily receipts
                    idx = len(receipts_by_date)
                    day_key = (base_date + datetime.timedelta(days=idx + 1)).isoformat()
                    receipts_by_date[day_key] = float(item)

    # Normalize forecasts into daily series
    series: List[Dict[str, Any]] = []
    total_horizon_demand = 0.0

    for idx, item in enumerate(forecasts):
        step = idx + 1
        if isinstance(item, dict):
            item_date = parse_date(item.get("date") or (base_date + datetime.timedelta(days=step)))
            demand = float(item.get("predicted_demand") or item.get("demand") or 0.0)
        else:
            item_date = base_date + datetime.timedelta(days=step)
            demand = float(item)

        date_str = item_date.isoformat()
        receipt = receipts_by_date.get(date_str, 0.0)
        total_horizon_demand += demand

        series.append({
            "step": step,
            "date": date_str,
            "predicted_demand": round(demand, 2),
            "scheduled_receipts": round(receipt, 2),
        })

    # Compute cumulative projection
    # Projected_Stock(t) = Available + Sum_{tau <= t} Receipts(tau) - Sum_{tau <= t} Demand(tau)
    cum_receipts = 0.0
    cum_demand = 0.0
    current_proj = float(available_stock)
    esd_date: Optional[str] = None
    esd_step: Optional[int] = None
    daily_projections: List[Dict[str, Any]] = []

    # If already out of stock at t = 0
    if current_proj <= 0 and len(series) > 0:
        esd_date = series[0]["date"]
        esd_step = 0

    for row in series:
        cum_receipts += row["scheduled_receipts"]
        cum_demand += row["predicted_demand"]
        proj_stock = available_stock + cum_receipts - cum_demand

        row_copy = {
            "step": row["step"],
            "date": row["date"],
            "predicted_demand": row["predicted_demand"],
            "scheduled_receipts": row["scheduled_receipts"],
            "cumulative_demand": round(cum_demand, 2),
            "cumulative_receipts": round(cum_receipts, 2),
            "projected_stock": round(proj_stock, 2),
        }
        daily_projections.append(row_copy)

        # First zero-crossing is ESD
        if proj_stock <= 0 and esd_date is None:
            esd_date = row["date"]
            esd_step = row["step"]

    # Calculate days of cover
    horizon_days = len(series)
    avg_daily_demand = (total_horizon_demand / horizon_days) if horizon_days > 0 else 0.0

    if esd_step is not None:
        days_of_cover = max(0, esd_step)
        days_to_stockout: Optional[int] = max(0, esd_step)
    else:
        # Stock covers the entire horizon; approximate remaining cover if demand continues
        if avg_daily_demand > 0 and daily_projections:
            final_stock = daily_projections[-1]["projected_stock"]
            additional_days = max(0.0, final_stock / avg_daily_demand)
            days_of_cover = round(horizon_days + additional_days, 1)
        else:
            days_of_cover = float(horizon_days)
        days_to_stockout = None

    # Risk Classification:
    # No ESD in horizon   -> LOW
    # ESD <= today + L     -> HIGH (cannot replenish in time)
    # ESD <= today + L + R -> MEDIUM (order this review cycle)
    # Otherwise            -> LOW
    lead_time_days = max(0, int(lead_time_days))
    review_period_days = max(0, int(review_period_days))

    if esd_step is None:
        risk_level = "low"
    elif esd_step <= lead_time_days:
        risk_level = "high"
    elif esd_step <= (lead_time_days + review_period_days):
        risk_level = "medium"
    else:
        risk_level = "low"

    return {
        "available_stock": round(float(available_stock), 2),
        "lead_time_days": lead_time_days,
        "review_period_days": review_period_days,
        "horizon_days": horizon_days,
        "total_predicted_demand": round(total_horizon_demand, 2),
        "average_daily_demand": round(avg_daily_demand, 2),
        "estimated_stockout_date": esd_date,
        "esd": esd_date,
        "days_to_stockout": days_to_stockout,
        "days_of_cover": days_of_cover,
        "risk_level": risk_level,
        "projected_stock": daily_projections,
    }


def handle_projection_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """Handler for POST /projection endpoint."""
    required = ["available_stock", "forecasts", "lead_time_days", "review_period_days"]
    # Allow alias "forecast" for "forecasts"
    if "forecast" in payload and "forecasts" not in payload:
        payload["forecasts"] = payload["forecast"]

    for req in required:
        if req not in payload:
            return 422, {
                "error": {
                    "code": "VALIDATION_FAILED",
                    "message": "The given data was invalid.",
                    "details": [{"field": req, "issue": f"The {req} field is required."}],
                }
            }

    try:
        avail_stock = float(payload.get("available_stock", 0.0))
        forecast_list = payload.get("forecasts")
        if not isinstance(forecast_list, list) or len(forecast_list) == 0:
            return 422, {
                "error": {
                    "code": "VALIDATION_FAILED",
                    "message": "The given data was invalid.",
                    "details": [{"field": "forecasts", "issue": "The forecasts list cannot be empty."}],
                }
            }

        lead_time = int(payload.get("lead_time_days", 0))
        review_period = int(payload.get("review_period_days", 0))
        scheduled = payload.get("scheduled_receipts")
        start_date = payload.get("start_date")

        result = calculate_stock_projection(
            available_stock=avail_stock,
            forecasts=forecast_list,
            lead_time_days=lead_time,
            review_period_days=review_period,
            scheduled_receipts=scheduled,
            start_date=start_date,
        )

        return 200, result

    except Exception as exc:
        return 500, {
            "error": {
                "code": "PROJECTION_FAILED",
                "message": f"Failed to compute stock projection: {str(exc)}",
            }
        }
