"""Slow-Moving Detection Rules (#50) and Overstock Evaluation (#51)."""

from __future__ import annotations

from typing import Any, Dict, List, Optional, Sequence, Tuple, Union


DEFAULT_WINDOW_DAYS = 30
DEFAULT_COVER_HORIZON_DAYS = 14
DEFAULT_SLOW_MOVING_THRESHOLD = 0.20

REQUIRED_OVERSTOCK_FIELDS: Tuple[str, ...] = (
    "product_id",
    "branch_id",
)


def _extract_total_sold(
    total_sold: Optional[float] = None,
    sales_history: Optional[Sequence[Any]] = None,
) -> float:
    if total_sold is not None:
        return max(0.0, float(total_sold))
    if not sales_history:
        return 0.0

    total = 0.0
    for item in sales_history:
        if isinstance(item, dict):
            qty = item.get("quantity", item.get("demand", item.get("sold", 0.0)))
        else:
            qty = item
        total += max(0.0, float(qty or 0.0))
    return total


def _extract_forecast_cover_demand(
    forecast_demand: Optional[Union[float, int, Sequence[Any]]] = None,
    avg_daily_forecast: Optional[float] = None,
    cover_horizon_days: int = DEFAULT_COVER_HORIZON_DAYS,
) -> Tuple[float, float]:
    """Return (forecast_cover_demand_total, avg_daily_forecast)."""
    horizon = max(1, int(cover_horizon_days))

    if isinstance(forecast_demand, (list, tuple)):
        vals: List[float] = []
        for entry in forecast_demand:
            if isinstance(entry, dict):
                v = entry.get("predicted_demand", entry.get("demand", entry.get("value", 0.0)))
            else:
                v = entry
            vals.append(max(0.0, float(v or 0.0)))
        if not vals:
            return 0.0, 0.0
        avg_daily = sum(vals) / len(vals)
        taken = vals[:horizon]
        if len(taken) < horizon:
            taken.extend([avg_daily] * (horizon - len(taken)))
        return sum(taken), avg_daily

    if forecast_demand is not None:
        total = max(0.0, float(forecast_demand))
        avg_daily = float(avg_daily_forecast) if avg_daily_forecast is not None else (total / horizon)
        return total, max(0.0, avg_daily)

    if avg_daily_forecast is not None:
        avg_d = max(0.0, float(avg_daily_forecast))
        return avg_d * horizon, avg_d

    return 0.0, 0.0


def evaluate_slow_moving_and_overstock(
    product_id: int,
    branch_id: int,
    available_stock: float = 0.0,
    total_sold: Optional[float] = None,
    sales_history: Optional[Sequence[Any]] = None,
    window_days: int = DEFAULT_WINDOW_DAYS,
    slow_moving_threshold: float = DEFAULT_SLOW_MOVING_THRESHOLD,
    forecast_demand: Optional[Union[float, int, Sequence[Any]]] = None,
    avg_daily_forecast: Optional[float] = None,
    cover_horizon_days: int = DEFAULT_COVER_HORIZON_DAYS,
) -> Dict[str, Any]:
    """
    Evaluate a product-branch for:
      1. Slow-Moving Detection (#50):
         sell_through_rate = total_sold / (total_sold + available_stock)
         Flagged when available_stock > 0 and sell_through_rate < slow_moving_threshold.
      2. Overstock Detection (#51):
         Flagged when on-hand (available_stock) > forecast_cover_demand over cover_horizon_days.
         surplus_quantity = max(0, available_stock - forecast_cover_demand).
    """
    on_hand = max(0.0, float(available_stock))
    win_days = max(1, int(window_days))
    horizon_days = max(1, int(cover_horizon_days))
    threshold = max(0.0, min(1.0, float(slow_moving_threshold)))

    sold_qty = _extract_total_sold(total_sold=total_sold, sales_history=sales_history)
    avg_daily_sales = sold_qty / float(win_days)

    denominator = sold_qty + on_hand
    sell_through_rate = (sold_qty / denominator) if denominator > 0 else 0.0

    is_slow_moving = bool(on_hand > 0 and sell_through_rate < threshold)

    forecast_cover_total, avg_daily_pred = _extract_forecast_cover_demand(
        forecast_demand=forecast_demand,
        avg_daily_forecast=avg_daily_forecast,
        cover_horizon_days=horizon_days,
    )

    surplus_quantity = max(0.0, on_hand - forecast_cover_total)
    is_overstock = bool(on_hand > 0 and on_hand > forecast_cover_total)

    effective_daily_velocity = avg_daily_pred if avg_daily_pred > 0 else avg_daily_sales
    days_of_supply: Optional[float] = (
        round(on_hand / effective_daily_velocity, 1) if effective_daily_velocity > 0 else (999.0 if on_hand > 0 else 0.0)
    )

    if is_overstock and is_slow_moving:
        severity = "HIGH"
    elif is_overstock or is_slow_moving:
        severity = "MEDIUM"
    else:
        severity = "LOW"

    return {
        "product_id": int(product_id),
        "branch_id": int(branch_id),
        "available_stock": round(on_hand, 2),
        "window_days": win_days,
        "total_sold": round(sold_qty, 2),
        "avg_daily_sales": round(avg_daily_sales, 2),
        "sell_through_rate": round(sell_through_rate, 4),
        "slow_moving_threshold": round(threshold, 4),
        "is_slow_moving": is_slow_moving,
        "cover_horizon_days": horizon_days,
        "forecast_cover_demand": round(forecast_cover_total, 2),
        "avg_daily_forecast": round(avg_daily_pred, 2),
        "surplus_quantity": round(surplus_quantity, 2),
        "is_overstock": is_overstock,
        "days_of_supply": days_of_supply,
        "severity": severity,
    }


def handle_overstock_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """HTTP handler for POST /overstock/run."""
    if not isinstance(payload, dict):
        return 422, {"status": "error", "message": "Request body must be a JSON object."}

    missing = [f for f in REQUIRED_OVERSTOCK_FIELDS if f not in payload]
    if missing:
        return 422, {
            "status": "error",
            "message": f"Missing required fields: {', '.join(missing)}",
        }

    result = evaluate_slow_moving_and_overstock(
        product_id=int(payload["product_id"]),
        branch_id=int(payload["branch_id"]),
        available_stock=float(payload.get("available_stock", payload.get("stock", 0.0)) or 0.0),
        total_sold=float(payload["total_sold"]) if payload.get("total_sold") is not None else None,
        sales_history=payload.get("sales_history", payload.get("history")),
        window_days=int(payload.get("window_days", DEFAULT_WINDOW_DAYS)),
        slow_moving_threshold=float(payload.get("slow_moving_threshold", DEFAULT_SLOW_MOVING_THRESHOLD)),
        forecast_demand=payload.get("forecast_demand"),
        avg_daily_forecast=(
            float(payload["avg_daily_forecast"]) if payload.get("avg_daily_forecast") is not None else None
        ),
        cover_horizon_days=int(payload.get("cover_horizon_days", DEFAULT_COVER_HORIZON_DAYS)),
    )

    return 200, result

