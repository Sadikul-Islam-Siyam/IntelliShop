"""
Time-series feature engineering for demand forecasting.

Turns a cleaned product-branch daily series into model inputs using course
statistics only:

* weekday means            y_bar(d) = mean of all demand observed on weekday d
* promotion flag           1 when the day had a promotion / discount
* stock-out masking        stock-out days are excluded from demand
* demand variability       sigma_d = s = sqrt( sum (y - y_bar)^2 / (n - 1) )
* forecast interval        y_bar +/- t * s / sqrt(n)   (t-table, n - 1 d.f.)
* time-based split         chronological train / validation split
"""

from __future__ import annotations

import math
from datetime import date, datetime
from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple

# Two-sided Student t critical values by confidence level and degrees of freedom.
_T_TABLE_DF: Tuple[int, ...] = tuple(range(1, 31)) + (40, 60, 120)
_T_TABLE: Dict[float, Tuple[float, ...]] = {
    0.90: (
        6.314, 2.920, 2.353, 2.132, 2.015, 1.943, 1.895, 1.860, 1.833, 1.812,
        1.796, 1.782, 1.771, 1.761, 1.753, 1.746, 1.740, 1.734, 1.729, 1.725,
        1.721, 1.717, 1.714, 1.711, 1.708, 1.706, 1.703, 1.701, 1.699, 1.697,
        1.684, 1.671, 1.658,
    ),
    0.95: (
        12.706, 4.303, 3.182, 2.776, 2.571, 2.447, 2.365, 2.306, 2.262, 2.228,
        2.201, 2.179, 2.160, 2.145, 2.131, 2.120, 2.110, 2.101, 2.093, 2.086,
        2.080, 2.074, 2.069, 2.064, 2.060, 2.056, 2.052, 2.048, 2.045, 2.042,
        2.021, 2.000, 1.980,
    ),
    0.99: (
        63.657, 9.925, 5.841, 4.604, 4.032, 3.707, 3.499, 3.355, 3.250, 3.169,
        3.106, 3.055, 3.012, 2.977, 2.947, 2.921, 2.898, 2.878, 2.861, 2.845,
        2.831, 2.819, 2.807, 2.797, 2.787, 2.779, 2.771, 2.763, 2.756, 2.750,
        2.704, 2.660, 2.617,
    ),
}
_T_INFINITY: Dict[float, float] = {0.90: 1.645, 0.95: 1.960, 0.99: 2.576}


def parse_date(value: Any) -> date:
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    return date.fromisoformat(str(value)[:10])


def mean(values: Sequence[float]) -> float:
    """Arithmetic mean y_bar = sum(y) / n (0.0 for an empty sequence)."""
    vals = [float(v) for v in values]
    return sum(vals) / len(vals) if vals else 0.0


def sample_std(values: Sequence[float]) -> float:
    """Sample standard deviation s = sqrt( sum (y - y_bar)^2 / (n - 1) )."""
    vals = [float(v) for v in values]
    n = len(vals)
    if n < 2:
        return 0.0
    y_bar = sum(vals) / n
    return math.sqrt(sum((y - y_bar) ** 2 for y in vals) / (n - 1))


def _nearest_confidence(confidence: float) -> float:
    return min(_T_TABLE.keys(), key=lambda level: abs(level - float(confidence)))


def t_critical(df: int, confidence: float = 0.95) -> float:
    """
    Two-sided t-table lookup. Between tabulated rows the next lower degrees of
    freedom is used, which gives the slightly wider (conservative) interval.
    """
    level = _nearest_confidence(confidence)
    df = int(df)
    if df < 1:
        return _T_TABLE[level][0]
    if df > _T_TABLE_DF[-1]:
        return _T_INFINITY[level]
    row = 0
    for idx, tabulated in enumerate(_T_TABLE_DF):
        if tabulated <= df:
            row = idx
    return _T_TABLE[level][row]


def forecast_interval(
    point: float,
    s: float,
    n: int,
    confidence: float = 0.95,
) -> Tuple[float, float, float]:
    """
    Forecast interval point +/- t * s / sqrt(n) with n - 1 degrees of freedom.

    Returns (lower, upper, t_value); the lower bound is clamped at zero because
    demand cannot be negative.
    """
    if n < 2:
        return max(0.0, round(point, 4)), max(0.0, round(point, 4)), 0.0
    t_value = t_critical(n - 1, confidence)
    half_width = t_value * float(s) / math.sqrt(n)
    lower = max(0.0, round(float(point) - half_width, 4))
    upper = max(0.0, round(float(point) + half_width, 4))
    return lower, upper, t_value


def _quantity(row: Dict[str, Any]) -> float:
    return float(row.get("quantity", row.get("raw_quantity", 0.0)) or 0.0)


def weekday_means(
    cleaned_series: Iterable[Dict[str, Any]],
    mask_promotions: bool = True,
    mask_stockouts: bool = True,
) -> Dict[int, Optional[float]]:
    """
    Weekday means y_bar(d) for d in 0..6 (0 = Monday .. 6 = Sunday).

    Stock-out days (and promotion days when requested) are masked so that they
    do not distort normal weekday demand. A weekday with no usable observation
    maps to None.
    """
    groups: Dict[int, List[float]] = {d: [] for d in range(7)}
    for row in cleaned_series:
        if mask_stockouts and bool(row.get("stockout", False)):
            continue
        if mask_promotions and bool(row.get("promotion", False)):
            continue
        groups[parse_date(row["date"]).weekday()].append(_quantity(row))
    return {d: (round(mean(vals), 4) if vals else None) for d, vals in groups.items()}


def demand_observations(
    cleaned_series: Iterable[Dict[str, Any]],
    mask_promotions: bool = True,
    min_observations: int = 14,
) -> List[Dict[str, Any]]:
    """
    Usable demand observations {date, weekday, demand} for model fitting.

    Stock-out days are always excluded (sales on those days were limited by
    supply, not demand). Promotion days are excluded as well, unless masking
    them would leave fewer than ``min_observations`` points.
    """
    rows = sorted(cleaned_series, key=lambda r: str(r["date"]))
    in_stock = [r for r in rows if not bool(r.get("stockout", False))]
    usable = in_stock
    if mask_promotions:
        non_promo = [r for r in in_stock if not bool(r.get("promotion", False))]
        if len(non_promo) >= min_observations:
            usable = non_promo

    observations: List[Dict[str, Any]] = []
    for row in usable:
        d = parse_date(row["date"])
        observations.append({"date": d, "weekday": d.weekday(), "demand": _quantity(row)})
    return observations


def time_based_split(
    series: Sequence[Dict[str, Any]],
    validation_days: int = 14,
) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
    """
    Chronological split: every validation row is dated strictly after every
    training row, so no model ever sees the period it is tested on.
    """
    ordered = sorted(series, key=lambda r: str(r["date"]))
    if not ordered:
        return [], []
    last_day = parse_date(ordered[-1]["date"])
    cutoff_ordinal = last_day.toordinal() - int(validation_days)
    train = [r for r in ordered if parse_date(r["date"]).toordinal() <= cutoff_ordinal]
    validation = [r for r in ordered if parse_date(r["date"]).toordinal() > cutoff_ordinal]
    if not train:
        split_idx = max(1, len(ordered) // 2)
        train, validation = list(ordered[:split_idx]), list(ordered[split_idx:])
    return train, validation


def build_feature_set(
    cleaned_series: Sequence[Dict[str, Any]],
    validation_days: int = 14,
    mask_promotions: bool = True,
) -> Dict[str, Any]:
    """
    Prepares model inputs from a cleaned daily series:

    * annotates each row with t, weekday, promotion_flag and stockout_mask,
    * computes weekday means and sigma_d = s over usable demand days,
    * returns the usable demand observations and their chronological split.
    """
    ordered = sorted([dict(r) for r in cleaned_series], key=lambda r: str(r["date"]))

    enriched: List[Dict[str, Any]] = []
    for idx, row in enumerate(ordered):
        d = parse_date(row["date"])
        is_stockout = bool(row.get("stockout", False))
        row.update(
            {
                "t": idx,
                "weekday": d.weekday(),
                "promotion_flag": 1 if bool(row.get("promotion", False)) else 0,
                "stockout_mask": 1 if is_stockout else 0,
                "demand": None if is_stockout else _quantity(row),
            }
        )
        enriched.append(row)

    observations = demand_observations(ordered, mask_promotions=mask_promotions)
    demand_values = [o["demand"] for o in observations]
    train, validation = time_based_split(observations, validation_days=validation_days)

    return {
        "series": enriched,
        "observations": observations,
        "train": train,
        "validation": validation,
        "weekday_means": weekday_means(ordered, mask_promotions=mask_promotions),
        "mean_demand": round(mean(demand_values), 4),
        "sigma_d": round(sample_std(demand_values), 4),
        "n": len(demand_values),
    }
