"""Dynamic replenishment, safety stock (ROP), and sequential constraint pipeline."""

from __future__ import annotations

import math
from typing import Any, Dict, List, Optional, Sequence, Tuple, Union


CONFLICT_FLAG_MESSAGE = "cannot satisfy MOQ within capacity"

REQUIRED_REORDER_FIELDS: Tuple[str, ...] = (
    "forecast_demand",
    "demand_stddev",
    "service_level_z",
    "lead_time_days",
    "review_period_days",
    "stock",
    "on_order",
    "moq",
    "order_multiple",
    "capacity",
    "shelf_life_days",
)


def _is_number(value: Any) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(float(value))


def _extract_daily_forecasts(
    forecast_demand: Union[float, int, Sequence[Any]],
    daily_forecasts: Optional[Sequence[Any]] = None,
) -> Tuple[float, Optional[List[float]]]:
    """Return (avg_daily_demand, optional_list_of_daily_demands)."""
    series_source = daily_forecasts if daily_forecasts is not None else (
        forecast_demand if isinstance(forecast_demand, (list, tuple)) else None
    )

    parsed_series: Optional[List[float]] = None
    if series_source is not None:
        parsed_series = []
        for entry in series_source:
            if isinstance(entry, dict):
                val = entry.get("predicted_demand", entry.get("demand", entry.get("value", 0.0)))
            else:
                val = entry
            parsed_series.append(max(0.0, float(val)))

    if _is_number(forecast_demand):
        avg_daily = max(0.0, float(forecast_demand))
    elif parsed_series:
        avg_daily = sum(parsed_series) / len(parsed_series)
    else:
        avg_daily = 0.0

    return avg_daily, parsed_series


def _cumulative_demand_over_days(
    days: int,
    avg_daily_demand: float,
    daily_series: Optional[Sequence[float]] = None,
) -> float:
    """Compute cumulative forecast demand over a given number of days."""
    if days <= 0:
        return 0.0
    if not daily_series:
        return avg_daily_demand * float(days)

    total = 0.0
    for day_idx in range(days):
        if day_idx < len(daily_series):
            total += max(0.0, float(daily_series[day_idx]))
        else:
            total += avg_daily_demand
    return total


def calculate_reorder_recommendation(
    forecast_demand: Union[float, int, Sequence[Any]],
    demand_stddev: float,
    service_level_z: float = 1.645,
    lead_time_days: int = 1,
    review_period_days: int = 7,
    stock: float = 0.0,
    on_order: float = 0.0,
    moq: int = 0,
    order_multiple: int = 1,
    capacity: Optional[float] = None,
    shelf_life_days: Optional[int] = None,
    reserved: float = 0.0,
    available_stock: Optional[float] = None,
    forecast_demand_l_plus_r: Optional[float] = None,
    daily_forecasts: Optional[Sequence[Any]] = None,
) -> Dict[str, Any]:
    """
    Calculate Safety Stock (SS), Reorder Point (ROP), Target Stock, Raw Quantity,
    and Final Recommended Reorder Quantity after applying sequential constraints:
      1. Minimum Order Quantity (MOQ)
      2. Order Multiple (Packaging Multiple)
      3. Max Warehouse Capacity
      4. Perishable Shelf-Life Limit
    """
    avg_daily_demand, daily_series = _extract_daily_forecasts(forecast_demand, daily_forecasts)
    sigma_d = max(0.0, float(demand_stddev))
    z_score = max(0.0, float(service_level_z))
    lead_time = max(0, int(lead_time_days))
    review_period = max(1, int(review_period_days)) if int(review_period_days) >= 0 else 7
    if int(review_period_days) == 0:
        review_period = 0

    on_hand = float(stock)
    reserved_qty = max(0.0, float(reserved))
    open_po_qty = max(0.0, float(on_order))

    if available_stock is not None and _is_number(available_stock):
        available = float(available_stock)
    else:
        available = on_hand - reserved_qty + open_po_qty

    moq_val = max(0, int(moq)) if moq is not None else 0
    multiple_val = max(1, int(order_multiple)) if (order_multiple is not None and int(order_multiple) > 0) else 1

    # 1. Expected Demand During Lead Time = d_bar * L
    lead_time_demand = avg_daily_demand * float(lead_time)

    # 2. Safety Stock (SS) = z * sigma_d * sqrt(L)
    safety_stock = z_score * sigma_d * math.sqrt(float(lead_time))

    # 3. Reorder Point (ROP) = d_bar * L + SS
    reorder_point = lead_time_demand + safety_stock

    # 4. Target Stock = Forecast Demand(L + R) + SS
    horizon_days = lead_time + review_period
    if forecast_demand_l_plus_r is not None and _is_number(forecast_demand_l_plus_r):
        demand_l_plus_r = max(0.0, float(forecast_demand_l_plus_r))
    elif daily_series is not None:
        demand_l_plus_r = _cumulative_demand_over_days(horizon_days, avg_daily_demand, daily_series)
    else:
        demand_l_plus_r = avg_daily_demand * float(horizon_days)

    target_stock = demand_l_plus_r + safety_stock

    # 5. Raw Order Quantity = max(0, Target Stock - Available)
    raw_quantity = max(0.0, target_stock - available)

    # 6. Sequential Constraint Pipeline
    constraints: List[str] = []
    after_moq = 0.0
    after_multiple = 0
    after_capacity = 0
    after_shelf_life = 0
    capacity_limit: Optional[int] = None
    shelf_life_limit: Optional[int] = None
    conflict = False
    conflict_flag: Optional[str] = None

    if capacity is not None and _is_number(capacity):
        capacity_limit = max(0, int(math.floor(float(capacity) - available)))

    if shelf_life_days is not None and _is_number(shelf_life_days) and int(shelf_life_days) > 0:
        expiry_demand = _cumulative_demand_over_days(int(shelf_life_days), avg_daily_demand, daily_series)
        shelf_life_limit = max(0, int(math.floor(expiry_demand)))

    if raw_quantity > 0:
        # Step 6.1: Minimum Order Quantity (MOQ)
        q = max(raw_quantity, float(moq_val))
        if float(moq_val) > raw_quantity:
            constraints.append("moq")
        after_moq = round(q, 2)

        # Step 6.2: Order Multiple (Packaging Multiple)
        q_multiple = int(math.ceil(q / float(multiple_val)) * multiple_val)
        if q_multiple != after_moq and multiple_val > 1:
            constraints.append("order_multiple")
        q = float(q_multiple)
        after_multiple = q_multiple

        # Step 6.3: Max Warehouse Capacity
        capped_by_upper_bound = False
        if capacity_limit is not None:
            if q > capacity_limit:
                q = float(capacity_limit)
                constraints.append("capacity")
                capped_by_upper_bound = True
        after_capacity = int(q)

        # Step 6.4: Perishable Shelf-Life Limit
        if shelf_life_limit is not None:
            if q > shelf_life_limit:
                q = float(shelf_life_limit)
                constraints.append("shelf_life")
                capped_by_upper_bound = True
        after_shelf_life = int(q)

        # Conflict check: If constraint (3) or (4) reduces Q below MOQ
        if capped_by_upper_bound and moq_val > 0 and q < moq_val:
            conflict = True
            conflict_flag = CONFLICT_FLAG_MESSAGE
            constraints.append(CONFLICT_FLAG_MESSAGE)
            final_quantity = 0
            capped_quantity = int(q)
        else:
            final_quantity = int(q)
            capped_quantity = int(q)
    else:
        final_quantity = 0
        capped_quantity = 0

    return {
        "safety_stock": round(safety_stock, 2),
        "reorder_point": round(reorder_point, 2),
        "target_stock": round(target_stock, 2),
        "raw_quantity": round(raw_quantity, 2),
        "final_quantity": int(final_quantity),
        "constraints": constraints,
        # Extended transparency fields for Manager Review Screen & auditing
        "avg_daily_demand": round(avg_daily_demand, 2),
        "demand_stddev": round(sigma_d, 2),
        "service_level_z": round(z_score, 4),
        "lead_time_days": lead_time,
        "review_period_days": review_period,
        "lead_time_demand": round(lead_time_demand, 2),
        "forecast_demand_l_plus_r": round(demand_l_plus_r, 2),
        "available_stock": round(available, 2),
        "stock": round(on_hand, 2),
        "reserved": round(reserved_qty, 2),
        "on_order": round(open_po_qty, 2),
        "moq": moq_val,
        "order_multiple": multiple_val,
        "capacity": float(capacity) if (capacity is not None and _is_number(capacity)) else None,
        "capacity_headroom": capacity_limit,
        "shelf_life_days": int(shelf_life_days) if (shelf_life_days is not None and _is_number(shelf_life_days)) else None,
        "shelf_life_limit": shelf_life_limit,
        "after_moq": after_moq,
        "after_multiple": after_multiple,
        "after_capacity": after_capacity,
        "after_shelf_life": after_shelf_life,
        "capped_quantity": capped_quantity,
        "conflict": conflict,
        "conflict_flag": conflict_flag,
    }


def _validate_reorder_payload(payload: Dict[str, Any]) -> Optional[str]:
    if not isinstance(payload, dict):
        return "Request body must be a JSON object."

    missing = [field for field in REQUIRED_REORDER_FIELDS if field not in payload]
    if missing:
        return f"Missing required fields: {', '.join(missing)}"

    forecast_demand = payload.get("forecast_demand")
    if isinstance(forecast_demand, (list, tuple)):
        if len(forecast_demand) == 0:
            return "Field 'forecast_demand' list cannot be empty."
        for idx, item in enumerate(forecast_demand):
            val = item.get("predicted_demand", item.get("demand", item.get("value"))) if isinstance(item, dict) else item
            if not _is_number(val) or float(val) < 0:
                return f"Field 'forecast_demand[{idx}]' must be a non-negative number."
    elif not _is_number(forecast_demand) or float(forecast_demand) < 0:
        return "Field 'forecast_demand' must be a non-negative number or list of daily forecasts."

    if not _is_number(payload.get("demand_stddev")) or float(payload["demand_stddev"]) < 0:
        return "Field 'demand_stddev' must be a non-negative number."

    if not _is_number(payload.get("service_level_z")) or float(payload["service_level_z"]) <= 0:
        return "Field 'service_level_z' must be a positive number."

    if not _is_number(payload.get("lead_time_days")) or int(payload["lead_time_days"]) < 0:
        return "Field 'lead_time_days' must be a non-negative integer."

    if not _is_number(payload.get("review_period_days")) or int(payload["review_period_days"]) <= 0:
        return "Field 'review_period_days' must be a positive integer."

    if not _is_number(payload.get("stock")):
        return "Field 'stock' must be a valid number."

    if not _is_number(payload.get("on_order")) or float(payload["on_order"]) < 0:
        return "Field 'on_order' must be a non-negative number."

    if not _is_number(payload.get("moq")) or int(payload["moq"]) < 0:
        return "Field 'moq' must be a non-negative integer."

    if not _is_number(payload.get("order_multiple")) or int(payload["order_multiple"]) <= 0:
        return "Field 'order_multiple' must be a positive integer."

    capacity = payload.get("capacity")
    if capacity is not None and (not _is_number(capacity) or float(capacity) < 0):
        return "Field 'capacity' must be null or a non-negative number."

    shelf_life = payload.get("shelf_life_days")
    if shelf_life is not None and (not _is_number(shelf_life) or int(shelf_life) <= 0):
        return "Field 'shelf_life_days' must be null or a positive integer."

    return None


def handle_reorder_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """HTTP handler entry point for POST /reorder."""
    validation_error = _validate_reorder_payload(payload)
    if validation_error is not None:
        return 422, {
            "status": "error",
            "message": validation_error,
        }

    result = calculate_reorder_recommendation(
        forecast_demand=payload["forecast_demand"],
        demand_stddev=float(payload["demand_stddev"]),
        service_level_z=float(payload["service_level_z"]),
        lead_time_days=int(payload["lead_time_days"]),
        review_period_days=int(payload["review_period_days"]),
        stock=float(payload["stock"]),
        on_order=float(payload["on_order"]),
        moq=int(payload["moq"]),
        order_multiple=int(payload["order_multiple"]),
        capacity=float(payload["capacity"]) if payload.get("capacity") is not None else None,
        shelf_life_days=int(payload["shelf_life_days"]) if payload.get("shelf_life_days") is not None else None,
        reserved=float(payload.get("reserved", 0.0)),
        available_stock=float(payload["available_stock"]) if payload.get("available_stock") is not None else None,
        forecast_demand_l_plus_r=(
            float(payload["forecast_demand_l_plus_r"])
            if payload.get("forecast_demand_l_plus_r") is not None
            else None
        ),
        daily_forecasts=payload.get("daily_forecasts"),
    )

    return 200, result

