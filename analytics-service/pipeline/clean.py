"""
Daily sales time-series cleaning and outlier handling.

Aggregates daily sales observations by product-branch-day, imputes missing
calendar days with zero sales, flags stock-out days so they are excluded from
baseline demand estimation, and caps extreme positive outliers using the
Modified Z-Score (MAD) rule.
"""

from __future__ import annotations

from datetime import date, datetime, timedelta
from statistics import median
from typing import Any, Dict, Iterable, List, Optional


def _parse_date(value: Any) -> date:
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    return date.fromisoformat(str(value)[:10])


def clean_daily_series(
    raw_records: Iterable[Dict[str, Any]],
    start_date: Optional[str] = None,
    end_date: Optional[str] = None,
    mad_threshold: float = 3.5,
    cap_promotion_days: bool = False,
) -> List[Dict[str, Any]]:
    """
    Cleans a product-branch daily sales series:
    1. Aggregates duplicate same-day records.
    2. Fills missing calendar days between start_date and end_date with quantity = 0.0.
    3. Flags stock-out days (when stockout is True or closing_stock <= 0 with zero sales).
    4. Caps extreme positive outliers using the MAD rule (0.6745 * |x - med| / MAD > mad_threshold).
    """
    records = list(raw_records)
    if not records and not (start_date and end_date):
        return []

    by_day: Dict[date, Dict[str, Any]] = {}
    product_id: Optional[int] = None
    branch_id: Optional[int] = None

    for row in records:
        d = _parse_date(row["date"])
        if product_id is None and "product_id" in row:
            product_id = int(row["product_id"])
        if branch_id is None and "branch_id" in row:
            branch_id = int(row["branch_id"])

        qty = max(0.0, float(row.get("quantity", 0.0) or 0.0))
        promo = bool(row.get("promotion", False))
        closing_stock = row.get("closing_stock", None)
        is_stockout = bool(row.get("stockout", False)) or (
            closing_stock is not None and float(closing_stock) <= 0 and qty == 0.0
        )

        if d in by_day:
            by_day[d]["raw_quantity"] += qty
            by_day[d]["promotion"] = by_day[d]["promotion"] or promo
            by_day[d]["stockout"] = by_day[d]["stockout"] or is_stockout
            if closing_stock is not None:
                by_day[d]["closing_stock"] = int(closing_stock)
        else:
            by_day[d] = {
                "date": d.isoformat(),
                "raw_quantity": qty,
                "promotion": promo,
                "stockout": is_stockout,
                "closing_stock": int(closing_stock) if closing_stock is not None else None,
                "imputed_zero": False,
            }

    first_day = _parse_date(start_date) if start_date else min(by_day.keys())
    last_day = _parse_date(end_date) if end_date else max(by_day.keys())

    continuous: List[Dict[str, Any]] = []
    cursor = first_day
    while cursor <= last_day:
        if cursor in by_day:
            entry = dict(by_day[cursor])
        else:
            entry = {
                "date": cursor.isoformat(),
                "raw_quantity": 0.0,
                "promotion": False,
                "stockout": False,
                "closing_stock": None,
                "imputed_zero": True,
            }
        if product_id is not None:
            entry["product_id"] = product_id
        if branch_id is not None:
            entry["branch_id"] = branch_id
        continuous.append(entry)
        cursor += timedelta(days=1)

    eligible_values = [
        row["raw_quantity"]
        for row in continuous
        if not row["stockout"] and (cap_promotion_days or not row["promotion"])
    ]
    if not eligible_values:
        eligible_values = [row["raw_quantity"] for row in continuous if not row["stockout"]]
    if not eligible_values:
        eligible_values = [row["raw_quantity"] for row in continuous]

    med = float(median(eligible_values)) if eligible_values else 0.0
    abs_devs = [abs(x - med) for x in eligible_values]
    mad = float(median(abs_devs)) if abs_devs else 0.0

    if mad > 1e-9:
        scale = mad / 0.6745
    else:
        mean_abs_dev = (sum(abs_devs) / len(abs_devs)) if abs_devs else 0.0
        scale = (mean_abs_dev / 0.79788456) if mean_abs_dev > 1e-9 else 0.0

    upper_cap = med + mad_threshold * scale if scale > 0.0 else None

    for row in continuous:
        raw_qty = row["raw_quantity"]
        is_outlier = False
        cleaned_qty = raw_qty

        if scale > 0.0:
            mod_z = abs(raw_qty - med) / scale
        else:
            mod_z = 0.0

        should_check_cap = not row["stockout"] and (cap_promotion_days or not row["promotion"])
        if should_check_cap and upper_cap is not None and raw_qty > upper_cap and mod_z > mad_threshold:
            is_outlier = True
            cleaned_qty = round(upper_cap, 4)

        row["quantity"] = round(cleaned_qty, 4)
        row["outlier_capped"] = is_outlier
        row["mad_z_score"] = round(mod_z, 4)
        row["excluded_from_demand"] = bool(row["stockout"])

    return continuous
