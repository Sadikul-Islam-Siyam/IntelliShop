"""
Additive Holt-Winters triple exponential smoothing with 7-day seasonality.

Implements the state-update and forecast equations:
- Level:    l_t = alpha * (y_t - s_{t-m}) + (1 - alpha) * (l_{t-1} + b_{t-1})
- Trend:    b_t = beta * (l_t - l_{t-1}) + (1 - beta) * b_{t-1}
- Seasonal: s_t = gamma * (y_t - l_t) + (1 - gamma) * s_{t-m}
- Forecast: y_hat(t + h) = l_t + h * b_t + s_{t + h - m * (floor((h - 1) / m) + 1)}

Smoothing parameters (alpha, beta, gamma) are selected via grid search
minimizing one-step-ahead sum of squared errors (SSE) when not fixed.
"""

from __future__ import annotations

from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple


DEFAULT_ALPHA_GRID: Tuple[float, ...] = (0.1, 0.2, 0.3, 0.5, 0.7)
DEFAULT_BETA_GRID: Tuple[float, ...] = (0.05, 0.1, 0.2, 0.3)
DEFAULT_GAMMA_GRID: Tuple[float, ...] = (0.1, 0.2, 0.3, 0.5)


def holt_winters_step(
    y_t: float,
    l_prev: float,
    b_prev: float,
    s_lag: float,
    alpha: float,
    beta: float,
    gamma: float,
) -> Tuple[float, float, float]:
    """
    Executes a single additive Holt-Winters state update for observation y_t:
        l_t = alpha * (y_t - s_{t-m}) + (1 - alpha) * (l_{t-1} + b_{t-1})
        b_t = beta * (l_t - l_{t-1}) + (1 - beta) * b_{t-1}
        s_t = gamma * (y_t - l_t) + (1 - gamma) * s_{t-m}
    """
    l_t = alpha * (y_t - s_lag) + (1.0 - alpha) * (l_prev + b_prev)
    b_t = beta * (l_t - l_prev) + (1.0 - beta) * b_prev
    s_t = gamma * (y_t - l_t) + (1.0 - gamma) * s_lag
    return l_t, b_t, s_t


def _initialize_states(
    values: Sequence[float],
    season_length: int = 7,
    initial_level: Optional[float] = None,
    initial_trend: Optional[float] = None,
    initial_seasonals: Optional[Sequence[float]] = None,
) -> Tuple[float, float, List[float]]:
    """
    Initializes level l_0, trend b_0, and initial seasonal states s_{-m+1 .. 0}.
    """
    m = max(1, int(season_length))
    n = len(values)
    if n == 0:
        return 0.0, 0.0, [0.0] * m

    first_season = list(values[: min(m, n)])
    l_0 = float(initial_level) if initial_level is not None else (sum(first_season) / len(first_season))

    if initial_trend is not None:
        b_0 = float(initial_trend)
    elif n >= 2 * m:
        b_0 = sum(values[m + i] - values[i] for i in range(m)) / float(m * m)
    elif n > 1:
        b_0 = (values[-1] - values[0]) / float(n - 1)
    else:
        b_0 = 0.0

    if initial_seasonals is not None and len(initial_seasonals) == m:
        s_init = [float(x) for x in initial_seasonals]
    elif n >= m:
        s_init = [float(values[i]) - l_0 for i in range(m)]
    else:
        s_init = [(float(values[i % n]) - l_0) for i in range(m)]

    return l_0, b_0, s_init


def run_holt_winters_recursion(
    values: Sequence[float],
    alpha: float,
    beta: float,
    gamma: float,
    season_length: int = 7,
    initial_level: Optional[float] = None,
    initial_trend: Optional[float] = None,
    initial_seasonals: Optional[Sequence[float]] = None,
) -> Dict[str, Any]:
    """
    Runs the additive Holt-Winters recursion across values for given (alpha, beta, gamma).
    Returns the final states, full state trajectories, one-step predictions, and SSE.
    """
    m = max(1, int(season_length))
    cleaned = [float(v) for v in values]
    l_prev, b_prev, s_buffer = _initialize_states(
        cleaned,
        season_length=m,
        initial_level=initial_level,
        initial_trend=initial_trend,
        initial_seasonals=initial_seasonals,
    )

    levels: List[float] = []
    trends: List[float] = []
    seasonals: List[float] = list(s_buffer)
    one_step_preds: List[float] = []
    sse = 0.0

    for t, y_t in enumerate(cleaned):
        s_lag = seasonals[t]
        y_hat_one_step = l_prev + b_prev + s_lag
        one_step_preds.append(y_hat_one_step)
        err = y_t - y_hat_one_step
        sse += err * err

        l_t, b_t, s_t = holt_winters_step(
            y_t=y_t,
            l_prev=l_prev,
            b_prev=b_prev,
            s_lag=s_lag,
            alpha=alpha,
            beta=beta,
            gamma=gamma,
        )
        levels.append(l_t)
        trends.append(b_t)
        seasonals.append(s_t)
        l_prev, b_prev = l_t, b_t

    latest_seasonals = seasonals[-m:] if len(seasonals) >= m else [0.0] * m
    return {
        "alpha": float(alpha),
        "beta": float(beta),
        "gamma": float(gamma),
        "season_length": m,
        "level": l_prev,
        "trend": b_prev,
        "seasonals": latest_seasonals,
        "levels": levels,
        "trends": trends,
        "all_seasonals": seasonals,
        "one_step_predictions": one_step_preds,
        "sse": sse,
    }


def fit_holt_winters(
    train_values: Sequence[float],
    season_length: int = 7,
    alpha: Optional[float] = None,
    beta: Optional[float] = None,
    gamma: Optional[float] = None,
    alpha_grid: Iterable[float] = DEFAULT_ALPHA_GRID,
    beta_grid: Iterable[float] = DEFAULT_BETA_GRID,
    gamma_grid: Iterable[float] = DEFAULT_GAMMA_GRID,
    initial_level: Optional[float] = None,
    initial_trend: Optional[float] = None,
    initial_seasonals: Optional[Sequence[float]] = None,
) -> Dict[str, Any]:
    """
    Fits additive Holt-Winters on train_values. If alpha, beta, and gamma are all
    provided, uses those directly; otherwise selects (alpha, beta, gamma) by grid
    search minimizing one-step-ahead SSE.
    """
    cleaned = [max(0.0, float(v)) for v in train_values]
    m = max(1, int(season_length))
    if not cleaned:
        return {
            "alpha": float(alpha or 0.2),
            "beta": float(beta or 0.1),
            "gamma": float(gamma or 0.2),
            "season_length": m,
            "level": 0.0,
            "trend": 0.0,
            "seasonals": [0.0] * m,
            "levels": [],
            "trends": [],
            "all_seasonals": [0.0] * m,
            "one_step_predictions": [],
            "sse": 0.0,
        }

    alphas = [float(alpha)] if alpha is not None else [float(x) for x in alpha_grid]
    betas = [float(beta)] if beta is not None else [float(x) for x in beta_grid]
    gammas = [float(gamma)] if gamma is not None else [float(x) for x in gamma_grid]

    best_result: Optional[Dict[str, Any]] = None
    for a in alphas:
        for b in betas:
            for g in gammas:
                candidate = run_holt_winters_recursion(
                    cleaned,
                    alpha=a,
                    beta=b,
                    gamma=g,
                    season_length=m,
                    initial_level=initial_level,
                    initial_trend=initial_trend,
                    initial_seasonals=initial_seasonals,
                )
                if best_result is None or candidate["sse"] < best_result["sse"] - 1e-12:
                    best_result = candidate

    assert best_result is not None
    return best_result


def forecast_holt_winters(
    train_values: Sequence[float],
    horizon: int,
    season_length: int = 7,
    alpha: Optional[float] = None,
    beta: Optional[float] = None,
    gamma: Optional[float] = None,
    initial_level: Optional[float] = None,
    initial_trend: Optional[float] = None,
    initial_seasonals: Optional[Sequence[float]] = None,
    clamp_non_negative: bool = True,
    round_digits: Optional[int] = 4,
) -> List[float]:
    """
    Forecasts h steps ahead using additive Holt-Winters:
        y_hat(t + h) = l_t + h * b_t + s_{t + h - m}
    repeating the latest m seasonal components across the forecast horizon.
    """
    h = max(1, int(horizon))
    if not train_values:
        return [0.0] * h

    fitted = fit_holt_winters(
        train_values,
        season_length=season_length,
        alpha=alpha,
        beta=beta,
        gamma=gamma,
        initial_level=initial_level,
        initial_trend=initial_trend,
        initial_seasonals=initial_seasonals,
    )
    l_t = float(fitted["level"])
    b_t = float(fitted["trend"])
    m = int(fitted["season_length"])
    seasonals: List[float] = fitted["seasonals"]

    preds: List[float] = []
    for step in range(1, h + 1):
        s_idx = (step - 1) % m
        raw_val = l_t + step * b_t + seasonals[s_idx]
        if clamp_non_negative:
            raw_val = max(0.0, raw_val)
        if round_digits is not None:
            raw_val = round(raw_val, round_digits)
        preds.append(raw_val)

    return preds
