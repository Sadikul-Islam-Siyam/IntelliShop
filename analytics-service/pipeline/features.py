"""
Time-series feature engineering for demand forecasting.

Computes normalized weekday seasonality indices S_d = mean(day d) / mean(all),
applies promotion and stock-out masking, performs chronological time-based
splitting, and calculates demand variability sigma_d (one-step RMSE) and
forecast intervals y_hat +/- z * sigma_d * sqrt(h).
"""

from __future__ import annotations

import math
from datetime import date, datetime
from typing import Any, Dict, Iterable, List, Sequence, Tuple


def _parse_date(value: Any) -> date:
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    return date.fromisoformat(str(value)[:10])


def compute_weekday_indices(
    cleaned_series: Iterable[Dict[str, Any]],
    mask_promotions: bool = True,
    mask_stockouts: bool = True,
) -> Dict[int, float]:
    """
    Computes weekday seasonality indices S_d = mean(day d) / mean(all)
    for d in 0..6 (0 = Monday .. 6 = Sunday).
    Stock-out days and optionally promotion days are masked out so they do not
    distort the baseline weekday seasonality profile.
    Indices are normalized so that their average across the 7 weekdays equals 1.0.
    """
    records = list(cleaned_series)
    eligible: List[Tuple[int, float]] = []

    for row in records:
        if mask_stockouts and bool(row.get("stockout", False)):
            continue
        if mask_promotions and bool(row.get("promotion", False)):
            continue
        d = _parse_date(row["date"]).weekday()
        qty = float(row.get("quantity", row.get("raw_quantity", 0.0)) or 0.0)
        eligible.append((d, qty))

    if not eligible:
        for row in records:
            if mask_stockouts and bool(row.get("stockout", False)):
                continue
            d = _parse_date(row["date"]).weekday()
            qty = float(row.get("quantity", row.get("raw_quantity", 0.0)) or 0.0)
            eligible.append((d, qty))

    if not eligible:
        return {d: 1.0 for d in range(7)}

    overall_mean = sum(q for _, q in eligible) / len(eligible)
    if overall_mean <= 1e-9:
        return {d: 1.0 for d in range(7)}

    by_weekday: Dict[int, List[float]] = {d: [] for d in range(7)}
    for d, q in eligible:
        by_weekday[d].append(q)

    raw_indices: Dict[int, float] = {}
    for d in range(7):
        if by_weekday[d]:
            day_mean = sum(by_weekday[d]) / len(by_weekday[d])
            raw_indices[d] = day_mean / overall_mean
        else:
            raw_indices[d] = 1.0

    index_mean = sum(raw_indices.values()) / 7.0
    if index_mean > 1e-9:
        return {d: round(raw_indices[d] / index_mean, 6) for d in range(7)}
    return {d: 1.0 for d in range(7)}


def compute_one_step_rmse(actuals: Sequence[float], one_step_predictions: Sequence[float]) -> float:
    """
    Computes demand variability sigma_d as the RMSE of one-step forecast errors:
    sigma_d = sqrt( (1/n) * sum( (y_t - y_hat_t)^2 ) ).
    """
    pairs = [
        (float(a), float(p))
        for a, p in zip(actuals, one_step_predictions)
        if a is not None and p is not None and not math.isnan(float(a)) and not math.isnan(float(p))
    ]
    if not pairs:
        return 0.0
    mse = sum((a - p) ** 2 for a, p in pairs) / len(pairs)
    return round(math.sqrt(mse), 6)


def compute_forecast_interval(
    predicted_demand: float,
    sigma_d: float,
    horizon_step: int,
    z_value: float = 1.645,
) -> Tuple[float, float]:
    """
    Computes the forecast interval y_hat +/- z * sigma_d * sqrt(h),
    clamping the lower bound at 0.0.
    """
    h = max(1, int(horizon_step))
    half_width = float(z_value) * float(sigma_d) * math.sqrt(h)
    lower = max(0.0, round(float(predicted_demand) - half_width, 4))
    upper = max(0.0, round(float(predicted_demand) + half_width, 4))
    return lower, upper


def time_based_split(
    series: Sequence[Dict[str, Any]],
    validation_days: int = 14,
) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
    """
    Performs a chronological time-based split into (train_series, validation_series).
    """
    ordered = sorted(series, key=lambda r: str(r["date"]))
    n = len(ordered)
    if n <= validation_days:
        split_idx = max(1, n // 2)
    else:
        split_idx = n - int(validation_days)
    return list(ordered[:split_idx]), list(ordered[split_idx:])


def build_feature_set(
    cleaned_series: Sequence[Dict[str, Any]],
    validation_days: int = 14,
) -> Dict[str, Any]:
    """
    Prepares model inputs from a cleaned daily sales series:
    - Annotates each row with weekday (0..6), weekday_index S_d, promotion_flag,
      stockout_mask, and effective_quantity.
    - Computes one-step forecast error RMSE (sigma_d).
    - Performs a chronological time-based split.
    """
    ordered = sorted([dict(r) for r in cleaned_series], key=lambda r: str(r["date"]))
    weekday_indices = compute_weekday_indices(ordered, mask_promotions=True, mask_stockouts=True)

    non_stockout_qtys = [
        float(r.get("quantity", 0.0))
        for r in ordered
        if not bool(r.get("stockout", False))
    ]
    baseline_level = (
        sum(non_stockout_qtys) / len(non_stockout_qtys)
        if non_stockout_qtys
        else 0.0
    )

    enriched: List[Dict[str, Any]] = []
    one_step_actuals: List[float] = []
    one_step_preds: List[float] = []
    prev_valid_qty: float | None = None

    for idx, row in enumerate(ordered):
        d_obj = _parse_date(row["date"])
        wd = d_obj.weekday()
        s_d = weekday_indices[wd]
        is_stockout = bool(row.get("stockout", False))
        is_promo = bool(row.get("promotion", False))
        qty = float(row.get("quantity", 0.0))

        effective_qty = round(baseline_level * s_d, 4) if is_stockout else qty

        if not is_stockout:
            if prev_valid_qty is not None:
                one_step_actuals.append(qty)
                one_step_preds.append(prev_valid_qty)
            prev_valid_qty = qty

        enriched_row = dict(row)
        enriched_row.update(
            {
                "t": idx,
                "weekday": wd,
                "weekday_index": s_d,
                "promotion_flag": 1 if is_promo else 0,
                "stockout_mask": 1 if is_stockout else 0,
                "effective_quantity": effective_qty,
            }
        )
        enriched.append(enriched_row)

    sigma_d = compute_one_step_rmse(one_step_actuals, one_step_preds)
    train_split, val_split = time_based_split(enriched, validation_days=validation_days)

    return {
        "series": enriched,
        "train": train_split,
        "validation": val_split,
        "weekday_indices": weekday_indices,
        "baseline_level": round(baseline_level, 4),
        "sigma_d": sigma_d,
    }
