"""
Regression trend model (simple linear regression by least squares).

    y_hat = b0 + b1 * t
    b1    = sum (t - t_bar)(y - y_bar) / sum (t - t_bar)^2
    b0    = y_bar - b1 * t_bar

Fit is checked with the correlation coefficient r, R^2 = 1 - SSE / SST and
RSE = sqrt(SSE / (n - 2)). The trend is only offered as a candidate when r
shows a real trend: the t statistic t = r * sqrt(n - 2) / sqrt(1 - r^2) must
exceed the two-sided t-table value with n - 2 degrees of freedom.
"""

from __future__ import annotations

import math
from datetime import timedelta
from typing import Any, Dict, List, Optional, Sequence

from models.mean_median import DEFAULT_RECENT_DAYS, rse, sse
from pipeline.features import parse_date, t_critical


def fit_linear_trend(ts: Sequence[float], ys: Sequence[float]) -> Dict[str, Any]:
    """Least-squares fit of y on t with r, R^2, SSE, SST and RSE."""
    t_vals = [float(t) for t in ts]
    y_vals = [float(y) for y in ys]
    n = min(len(t_vals), len(y_vals))
    t_vals, y_vals = t_vals[:n], y_vals[:n]

    if n == 0:
        return {"n": 0, "b0": 0.0, "b1": 0.0, "r": 0.0, "r_squared": 0.0,
                "sse": 0.0, "sst": 0.0, "rse": None, "t_bar": 0.0, "y_bar": 0.0}

    t_bar = sum(t_vals) / n
    y_bar = sum(y_vals) / n
    s_ty = sum((t - t_bar) * (y - y_bar) for t, y in zip(t_vals, y_vals))
    s_tt = sum((t - t_bar) ** 2 for t in t_vals)
    s_yy = sum((y - y_bar) ** 2 for y in y_vals)

    b1 = s_ty / s_tt if s_tt > 0 else 0.0
    b0 = y_bar - b1 * t_bar
    fitted = [b0 + b1 * t for t in t_vals]
    sse_val = sse(y_vals, fitted)
    r = s_ty / math.sqrt(s_tt * s_yy) if s_tt > 0 and s_yy > 0 else 0.0
    r_squared = 1.0 - sse_val / s_yy if s_yy > 0 else 0.0

    return {
        "n": n,
        "b0": b0,
        "b1": b1,
        "r": r,
        "r_squared": r_squared,
        "sse": sse_val,
        "sst": s_yy,
        "rse": rse(y_vals, fitted),
        "t_bar": t_bar,
        "y_bar": y_bar,
    }


def trend_is_real(fit: Dict[str, Any], confidence: float = 0.95) -> bool:
    """True when the correlation r is significant at the given confidence level."""
    n = int(fit.get("n", 0))
    r = float(fit.get("r", 0.0))
    if n < 3 or abs(fit.get("b1", 0.0)) < 1e-12:
        return False
    if abs(r) >= 1.0 - 1e-12:
        return True
    t_stat = abs(r) * math.sqrt(n - 2) / math.sqrt(1.0 - r * r)
    return t_stat > t_critical(n - 2, confidence)


def forecast_linear_trend(
    observations: Sequence[Dict[str, Any]],
    target_dates: Sequence[Any],
    recent_days: int = DEFAULT_RECENT_DAYS,
    confidence: float = 0.95,
) -> Dict[str, Any]:
    """
    Fits the trend on the last ``recent_days`` days of history (t = days since
    the first day in that window) and extrapolates to every target date.
    Negative extrapolations are clamped to zero demand.
    """
    history = sorted(
        (
            {"date": parse_date(o["date"]), "demand": float(o["demand"])}
            for o in observations
            if o.get("demand") is not None
        ),
        key=lambda o: o["date"],
    )
    if not history:
        empty_fit = fit_linear_trend([], [])
        return {"model": "linear_trend", "predictions": [0.0 for _ in target_dates],
                "details": [{"basis": "trend", "n": 0, "s": 0.0, "outliers_dropped": 0} for _ in target_dates],
                "fit": empty_fit, "real_trend": False, "params": {"recent_days": int(recent_days)}}

    cutoff = history[-1]["date"]
    first_day = cutoff - timedelta(days=int(recent_days) - 1)
    window = [o for o in history if o["date"] >= first_day]
    origin = window[0]["date"]
    ts = [(o["date"] - origin).days for o in window]
    ys = [o["demand"] for o in window]
    fit = fit_linear_trend(ts, ys)
    residual_s = float(fit["rse"] or 0.0)

    predictions: List[float] = []
    details: List[Dict[str, Any]] = []
    for raw_target in target_dates:
        t_target = (parse_date(raw_target) - origin).days
        predictions.append(round(max(0.0, fit["b0"] + fit["b1"] * t_target), 4))
        details.append({"basis": "trend", "n": fit["n"], "s": round(residual_s, 4), "outliers_dropped": 0})

    return {
        "model": "linear_trend",
        "predictions": predictions,
        "details": details,
        "fit": fit,
        "real_trend": trend_is_real(fit, confidence),
        "params": {
            "recent_days": int(recent_days),
            "origin_date": origin.isoformat(),
            "b0": round(fit["b0"], 6),
            "b1": round(fit["b1"], 6),
            "r": round(fit["r"], 6),
            "r_squared": round(fit["r_squared"], 6),
        },
    }


def regression_summary(fit: Dict[str, Any]) -> Dict[str, Optional[float]]:
    """Rounded b0, b1, r, R^2 and RSE for reporting."""
    return {
        "b0": round(float(fit["b0"]), 4),
        "b1": round(float(fit["b1"]), 4),
        "r": round(float(fit["r"]), 4),
        "r_squared": round(float(fit["r_squared"]), 4),
        "rse": round(float(fit["rse"]), 4) if fit.get("rse") is not None else None,
    }

