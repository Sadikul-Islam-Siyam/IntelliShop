"""
Closed-form ordinary least-squares (OLS) linear trend demand forecasting model.

Implements the exact least-squares linear regression equations over time index t = 1..n:
- Slope:     b = sum((t - t_bar) * (y_t - y_bar)) / sum((t - t_bar)^2)
- Intercept: a = y_bar - b * t_bar
- Forecast:  y_hat(n + h) = a + b * (n + h)
"""

from __future__ import annotations

from typing import Any, Dict, List, Optional, Sequence


def fit_linear_trend(train_values: Sequence[float]) -> Dict[str, Any]:
    """
    Fits closed-form least-squares linear trend parameters (a, b) over t = 1..n.
    """
    cleaned = [float(v) for v in train_values]
    n = len(cleaned)
    if n == 0:
        return {
            "a": 0.0,
            "b": 0.0,
            "t_bar": 0.0,
            "y_bar": 0.0,
            "n": 0,
        }

    if n == 1:
        y_0 = max(0.0, cleaned[0])
        return {
            "a": y_0,
            "b": 0.0,
            "t_bar": 1.0,
            "y_bar": y_0,
            "n": 1,
        }

    t_bar = (n + 1) / 2.0
    y_bar = sum(cleaned) / float(n)

    num = 0.0
    den = 0.0
    for idx, y_val in enumerate(cleaned, start=1):
        dt = float(idx) - t_bar
        dy = y_val - y_bar
        num += dt * dy
        den += dt * dt

    b = (num / den) if den > 1e-12 else 0.0
    a = y_bar - b * t_bar

    return {
        "a": a,
        "b": b,
        "t_bar": t_bar,
        "y_bar": y_bar,
        "n": n,
    }


def forecast_linear_trend(
    train_values: Sequence[float],
    horizon: int,
    clamp_non_negative: bool = True,
    round_digits: Optional[int] = 4,
) -> List[float]:
    """
    Forecasts h steps ahead at t = n + 1 .. n + h using y_hat(n + h) = a + b * (n + h).
    """
    h = max(1, int(horizon))
    if not train_values:
        return [0.0] * h

    fitted = fit_linear_trend(train_values)
    a = float(fitted["a"])
    b = float(fitted["b"])
    n = int(fitted["n"])

    preds: List[float] = []
    for step in range(1, h + 1):
        t_future = n + step
        raw_val = a + b * float(t_future)
        if clamp_non_negative:
            raw_val = max(0.0, raw_val)
        if round_digits is not None:
            raw_val = round(raw_val, round_digits)
        preds.append(raw_val)

    return preds
