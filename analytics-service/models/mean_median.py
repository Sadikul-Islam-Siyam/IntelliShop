"""
Mean and median demand models (seasonal / recent).

Course formulas only:

* mean               y_hat = y_bar = sum(y) / n
* median             y_hat = median(y)
* same-weekday mean  y_hat(d) = sum(y_d) / k over the last k same weekdays

The window is the same season in past years when the target day falls inside a
configured season and history for that season exists; otherwise it is the last
30 days. Outliers are dropped with Tukey IQR fences (Q1 - 1.5*IQR, Q3 + 1.5*IQR)
before the statistic is taken.

Validation metrics: SSE = sum (y - y_hat)^2 (primary) and
RSE = sqrt(SSE / (n - 2)) (secondary).
"""

from __future__ import annotations

import math
from datetime import date, timedelta
from typing import Any, Dict, List, Optional, Sequence, Tuple

from pipeline.features import mean, parse_date, sample_std

DEFAULT_RECENT_DAYS = 30
DEFAULT_WEEKDAY_K = 4
DEFAULT_IQR_K = 1.5


def median(values: Sequence[float]) -> float:
    """Middle value of the sorted sample (average of the two middle values when n is even)."""
    ordered = sorted(float(v) for v in values)
    n = len(ordered)
    if n == 0:
        return 0.0
    mid = n // 2
    if n % 2 == 1:
        return ordered[mid]
    return (ordered[mid - 1] + ordered[mid]) / 2.0


def quartiles(values: Sequence[float]) -> Tuple[float, float]:
    """
    First and third quartiles by linear interpolation at position p * (n - 1)
    of the sorted sample.
    """
    ordered = sorted(float(v) for v in values)
    n = len(ordered)
    if n == 0:
        return 0.0, 0.0

    def _at(p: float) -> float:
        pos = p * (n - 1)
        lower = math.floor(pos)
        upper = min(lower + 1, n - 1)
        return ordered[lower] + (pos - lower) * (ordered[upper] - ordered[lower])

    return _at(0.25), _at(0.75)


def drop_iqr_outliers(values: Sequence[float], k: float = DEFAULT_IQR_K) -> List[float]:
    """Removes points outside the Tukey fences Q1 - k*IQR and Q3 + k*IQR."""
    vals = [float(v) for v in values]
    if len(vals) < 4:
        return vals
    q1, q3 = quartiles(vals)
    iqr = q3 - q1
    low, high = q1 - k * iqr, q3 + k * iqr
    kept = [v for v in vals if low <= v <= high]
    return kept or vals


def sse(actuals: Sequence[float], predictions: Sequence[float]) -> float:
    """Sum of squared errors SSE = sum (y - y_hat)^2."""
    return float(sum((float(a) - float(p)) ** 2 for a, p in zip(actuals, predictions)))


def rse(actuals: Sequence[float], predictions: Sequence[float]) -> Optional[float]:
    """Residual standard error RSE = sqrt(SSE / (n - 2)); None when n <= 2."""
    n = min(len(actuals), len(predictions))
    if n <= 2:
        return None
    return math.sqrt(sse(actuals, predictions) / (n - 2))


# ---------------------------------------------------------------------------
# Window selection
# ---------------------------------------------------------------------------


def _month_day(text: str) -> Tuple[int, int]:
    month, day = str(text).split("-")[:2]
    return int(month), int(day)


def _in_window(d: date, window: Dict[str, Any]) -> bool:
    start, end = _month_day(window["start"]), _month_day(window["end"])
    md = (d.month, d.day)
    if start <= end:
        return start <= md <= end
    return md >= start or md <= end  # window wraps the year end, e.g. 12-01 .. 02-28


def _season_year(d: date, window: Dict[str, Any]) -> int:
    start, end = _month_day(window["start"]), _month_day(window["end"])
    if start > end and (d.month, d.day) <= end:
        return d.year - 1
    return d.year


def season_for(d: date, season_windows: Optional[Sequence[Dict[str, Any]]]) -> Optional[Dict[str, Any]]:
    """Returns the first configured season window that contains ``d``."""
    for window in season_windows or []:
        if _in_window(d, window):
            return window
    return None


def window_values(
    observations: Sequence[Dict[str, Any]],
    target: date,
    season_windows: Optional[Sequence[Dict[str, Any]]] = None,
    recent_days: int = DEFAULT_RECENT_DAYS,
) -> Tuple[List[float], str]:
    """
    Demand values used to forecast ``target`` and the basis that produced them.

    * ``season``  - same season in earlier years (seasonal days)
    * ``recent``  - the last ``recent_days`` days of history (normal days)

    Only observations already in ``observations`` are used, so callers control
    the training cutoff.
    """
    if not observations:
        return [], "recent"

    window = season_for(target, season_windows)
    if window is not None:
        target_season = _season_year(target, window)
        seasonal = [
            float(o["demand"])
            for o in observations
            if _in_window(o["date"], window) and _season_year(o["date"], window) < target_season
        ]
        if seasonal:
            return seasonal, "season"

    cutoff = max(o["date"] for o in observations)
    first_day = cutoff - timedelta(days=int(recent_days) - 1)
    recent = [float(o["demand"]) for o in observations if first_day <= o["date"] <= cutoff]
    return recent, "recent"


# ---------------------------------------------------------------------------
# Forecasters
# ---------------------------------------------------------------------------


def _normalize(observations: Sequence[Dict[str, Any]]) -> List[Dict[str, Any]]:
    rows = [
        {"date": parse_date(o["date"]), "demand": float(o["demand"])}
        for o in observations
        if o.get("demand") is not None
    ]
    return sorted(rows, key=lambda o: o["date"])


def forecast_mean_median(
    observations: Sequence[Dict[str, Any]],
    target_dates: Sequence[Any],
    statistic: str = "mean",
    season_windows: Optional[Sequence[Dict[str, Any]]] = None,
    recent_days: int = DEFAULT_RECENT_DAYS,
    iqr_k: float = DEFAULT_IQR_K,
) -> Dict[str, Any]:
    """
    Mean or median forecast for every target date.

    Each prediction is computed from the season window (seasonal days) or the
    last ``recent_days`` days (normal days) after dropping IQR outliers.
    Returns predictions plus per-day details (basis, n, s) for the interval.
    """
    if statistic not in ("mean", "median"):
        raise ValueError("statistic must be 'mean' or 'median'")

    history = _normalize(observations)
    predictions: List[float] = []
    details: List[Dict[str, Any]] = []
    for raw_target in target_dates:
        target = parse_date(raw_target)
        values, basis = window_values(history, target, season_windows, recent_days)
        kept = drop_iqr_outliers(values, k=iqr_k)
        point = mean(kept) if statistic == "mean" else median(kept)
        predictions.append(round(max(0.0, point), 4))
        details.append(
            {
                "basis": basis,
                "n": len(kept),
                "s": round(sample_std(kept), 4),
                "outliers_dropped": len(values) - len(kept),
            }
        )

    return {
        "model": statistic,
        "predictions": predictions,
        "details": details,
        "params": {
            "statistic": statistic,
            "recent_days": int(recent_days),
            "iqr_k": float(iqr_k),
            "season_windows": [dict(w) for w in season_windows or []],
        },
    }


def forecast_weekday_mean(
    observations: Sequence[Dict[str, Any]],
    target_dates: Sequence[Any],
    k: int = DEFAULT_WEEKDAY_K,
) -> Dict[str, Any]:
    """
    Same-weekday mean y_hat(d) = sum(y_d) / k over the last k observed days
    that share the target's weekday. Falls back to the overall mean of the last
    k weeks when that weekday has never been observed.
    """
    history = _normalize(observations)
    by_weekday: Dict[int, List[float]] = {d: [] for d in range(7)}
    for o in history:
        by_weekday[o["date"].weekday()].append(o["demand"])

    fallback = [o["demand"] for o in history[-7 * int(k):]]
    predictions: List[float] = []
    details: List[Dict[str, Any]] = []
    for raw_target in target_dates:
        target = parse_date(raw_target)
        same_day = by_weekday[target.weekday()][-int(k):]
        values = same_day or fallback
        predictions.append(round(max(0.0, mean(values)), 4))
        details.append(
            {
                "basis": "weekday" if same_day else "recent",
                "n": len(values),
                "s": round(sample_std(values), 4),
                "outliers_dropped": 0,
            }
        )

    return {
        "model": "weekday_mean",
        "predictions": predictions,
        "details": details,
        "params": {"k": int(k)},
    }
