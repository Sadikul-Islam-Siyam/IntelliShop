"""
Croston and Syntetos-Boylan Approximation (SBA) intermittent demand forecasting model.

Implements separate exponential smoothing of non-zero demand sizes (z_t) and
inter-demand intervals (p_t) updated only when positive demand occurs (y_t > 0):
- Demand size update:      z_t = alpha * y_t + (1 - alpha) * z_{t-1}
- Interval length update:  p_t = alpha * q + (1 - alpha) * p_{t-1}
  where q is the number of periods elapsed since the previous positive demand.
- Croston forecast rate:   y_hat = z_t / p_t
- SBA bias-corrected rate: y_hat = (1 - alpha / 2) * (z_t / p_t)
"""

from __future__ import annotations

from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple


DEFAULT_CROSTON_ALPHA_GRID: Tuple[float, ...] = (0.05, 0.1, 0.15, 0.2, 0.3, 0.4)


def run_croston_recursion(
    values: Sequence[float],
    alpha: float = 0.2,
    method: str = "sba",
    initial_z: Optional[float] = None,
    initial_p: Optional[float] = None,
) -> Dict[str, Any]:
    """
    Runs Croston / Syntetos-Boylan Approximation recursion across values.
    Updates smoothed demand size z_t and inter-arrival interval p_t whenever y_t > 0.
    """
    a = max(1e-4, min(0.9999, float(alpha)))
    use_sba = method.lower() in ("sba", "croston_sba", "syntetos_boylan")
    bias_factor = (1.0 - a / 2.0) if use_sba else 1.0

    cleaned = [max(0.0, float(v)) for v in values]
    if not cleaned or all(v <= 0.0 for v in cleaned):
        return {
            "alpha": a,
            "method": "sba" if use_sba else "croston",
            "z_t": 0.0,
            "p_t": 1.0,
            "croston_rate": 0.0,
            "sba_rate": 0.0,
            "forecast_rate": 0.0,
            "z_history": [],
            "p_history": [],
            "one_step_predictions": [0.0] * len(cleaned),
            "sse": 0.0,
        }

    z_curr = float(initial_z) if initial_z is not None else None
    p_curr = float(initial_p) if initial_p is not None else None
    q = 1

    z_history: List[float] = []
    p_history: List[float] = []
    one_step_preds: List[float] = []
    sse = 0.0

    for y_t in cleaned:
        if z_curr is not None and p_curr is not None and p_curr > 1e-9:
            y_hat_step = bias_factor * (z_curr / p_curr)
        else:
            y_hat_step = 0.0

        one_step_preds.append(y_hat_step)
        err = y_t - y_hat_step
        sse += err * err

        if y_t > 0.0:
            if z_curr is None or p_curr is None:
                z_curr = y_t
                p_curr = float(q)
            else:
                z_curr = a * y_t + (1.0 - a) * z_curr
                p_curr = a * float(q) + (1.0 - a) * p_curr
            q = 1
        else:
            q += 1

        z_history.append(float(z_curr if z_curr is not None else 0.0))
        p_history.append(float(p_curr if p_curr is not None else 1.0))

    final_z = float(z_curr if z_curr is not None else 0.0)
    final_p = max(1e-9, float(p_curr if p_curr is not None else 1.0))
    croston_rate = final_z / final_p
    sba_rate = (1.0 - a / 2.0) * croston_rate
    forecast_rate = sba_rate if use_sba else croston_rate

    return {
        "alpha": a,
        "method": "sba" if use_sba else "croston",
        "z_t": final_z,
        "p_t": final_p,
        "croston_rate": croston_rate,
        "sba_rate": sba_rate,
        "forecast_rate": forecast_rate,
        "z_history": z_history,
        "p_history": p_history,
        "one_step_predictions": one_step_preds,
        "sse": sse,
    }


def fit_croston_sba(
    train_values: Sequence[float],
    alpha: Optional[float] = None,
    method: str = "sba",
    alpha_grid: Iterable[float] = DEFAULT_CROSTON_ALPHA_GRID,
    initial_z: Optional[float] = None,
    initial_p: Optional[float] = None,
) -> Dict[str, Any]:
    """
    Fits Croston / SBA on train_values. If alpha is provided, uses that fixed
    smoothing constant; otherwise selects alpha from alpha_grid by minimizing
    one-step-ahead SSE.
    """
    if alpha is not None:
        return run_croston_recursion(
            train_values,
            alpha=float(alpha),
            method=method,
            initial_z=initial_z,
            initial_p=initial_p,
        )

    best_result: Optional[Dict[str, Any]] = None
    for candidate_alpha in alpha_grid:
        res = run_croston_recursion(
            train_values,
            alpha=float(candidate_alpha),
            method=method,
            initial_z=initial_z,
            initial_p=initial_p,
        )
        if best_result is None or res["sse"] < best_result["sse"] - 1e-12:
            best_result = res

    assert best_result is not None
    return best_result


def forecast_croston_sba(
    train_values: Sequence[float],
    horizon: int,
    alpha: Optional[float] = None,
    method: str = "sba",
    initial_z: Optional[float] = None,
    initial_p: Optional[float] = None,
    round_digits: Optional[int] = 4,
) -> List[float]:
    """
    Generates h-step ahead intermittent demand forecasts using Syntetos-Boylan
    Approximation:
        y_hat = (1 - alpha / 2) * (z_t / p_t)
    """
    h = max(1, int(horizon))
    if not train_values:
        return [0.0] * h

    fitted = fit_croston_sba(
        train_values,
        alpha=alpha,
        method=method,
        initial_z=initial_z,
        initial_p=initial_p,
    )
    rate = max(0.0, float(fitted["forecast_rate"]))
    if round_digits is not None:
        rate = round(rate, round_digits)
    return [rate for _ in range(h)]
