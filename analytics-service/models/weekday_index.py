"""
Weekday Index seasonal demand forecasting model.

Implements the multiplicative weekday seasonality equation:
    y_hat(t + h) = L * S_d
where:
- L is the baseline demand level (mean daily demand over the training window)
- S_d = mean(demand on weekday d) / mean(all demand), normalized so the
  average across the 7 weekday indices equals 1.0.
"""

from __future__ import annotations

from typing import Any, Dict, List, Optional, Sequence


def compute_weekday_factors(
    train_values: Sequence[float],
    season_length: int = 7,
) -> List[float]:
    """
    Computes normalized seasonal indices S_0 .. S_{m-1} across a cycle of
    length season_length (default 7 days) such that (1/m) * sum(S_d) = 1.0.
    """
    m = max(1, int(season_length))
    cleaned = [max(0.0, float(v)) for v in train_values]
    if not cleaned:
        return [1.0] * m

    overall_mean = sum(cleaned) / len(cleaned)
    if overall_mean <= 1e-9:
        return [1.0] * m

    buckets: List[List[float]] = [[] for _ in range(m)]
    for idx, val in enumerate(cleaned):
        buckets[idx % m].append(val)

    raw_factors: List[float] = []
    for bucket in buckets:
        if bucket:
            bucket_mean = sum(bucket) / len(bucket)
            raw_factors.append(bucket_mean / overall_mean)
        else:
            raw_factors.append(1.0)

    factor_sum = sum(raw_factors)
    if factor_sum <= 1e-9:
        return [1.0] * m

    scale = m / factor_sum
    return [f * scale for f in raw_factors]


def fit_weekday_index(
    train_values: Sequence[float],
    season_length: int = 7,
    level_window: Optional[int] = None,
) -> Dict[str, Any]:
    """
    Fits the Weekday Index model parameters:
    - level (L): mean demand over the training series (or recent level_window)
    - seasonal_indices (S_d): normalized weekday factors of length season_length
    """
    m = max(1, int(season_length))
    cleaned = [max(0.0, float(v)) for v in train_values]
    if not cleaned:
        return {
            "level": 0.0,
            "season_length": m,
            "seasonal_indices": [1.0] * m,
        }

    if level_window is not None and level_window > 0:
        w = min(int(level_window), len(cleaned))
        level = sum(cleaned[-w:]) / w
    else:
        level = sum(cleaned) / len(cleaned)

    factors = compute_weekday_factors(cleaned, season_length=m)
    return {
        "level": level,
        "season_length": m,
        "seasonal_indices": factors,
    }


def forecast_weekday_index(
    train_values: Sequence[float],
    horizon: int,
    season_length: int = 7,
    level_window: Optional[int] = None,
    round_digits: Optional[int] = 4,
) -> List[float]:
    """
    Generates h-step ahead forecasts using y_hat(t + h) = L * S_d.
    """
    h = max(1, int(horizon))
    if not train_values:
        return [0.0] * h

    fitted = fit_weekday_index(
        train_values,
        season_length=season_length,
        level_window=level_window,
    )
    level = float(fitted["level"])
    m = int(fitted["season_length"])
    indices: List[float] = fitted["seasonal_indices"]
    n = len(train_values)

    preds: List[float] = []
    for step in range(1, h + 1):
        day_idx = (n + step - 1) % m
        y_hat = max(0.0, level * indices[day_idx])
        if round_digits is not None:
            y_hat = round(y_hat, round_digits)
        preds.append(y_hat)

    return preds
