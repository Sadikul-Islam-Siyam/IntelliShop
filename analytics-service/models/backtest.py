"""
Walk-forward (rolling-origin) backtesting and model selection for demand forecasting.

Evaluates baseline and equation-based forecasting models across expanding training
windows so that model parameters are fitted strictly on observations prior to each
validation fold (zero future data leakage).

Selection rule:
- Primary metric: sMAPE (tie-breaking by RMSE, then MAE)
- Secondary metrics: MAE, RMSE, MAPE (computed on non-zero actual days)
- Baseline floor: best of Naive and Seasonal Naive on sMAPE
- A candidate model is selected only if it strictly beats the baseline floor on sMAPE;
  otherwise the baseline model is retained with beats_baseline = False.
"""

from __future__ import annotations

from typing import Any, Callable, Dict, List, Sequence, Tuple

from models.baseline import (
    compute_metrics,
    forecast_naive,
    forecast_seasonal_naive,
    forecast_sma,
    forecast_wma,
)
from models.croston import fit_croston_sba, forecast_croston_sba
from models.holt_winters import fit_holt_winters, forecast_holt_winters
from models.linear_trend import fit_linear_trend, forecast_linear_trend
from models.weekday_index import fit_weekday_index, forecast_weekday_index


CANDIDATE_MODEL_NAMES: Tuple[str, ...] = (
    "naive",
    "seasonal_naive",
    "sma",
    "wma",
    "weekday_index",
    "holt_winters",
    "croston_sba",
    "linear_trend",
)


def generate_walk_forward_splits(
    values: Sequence[float],
    validation_days: int = 14,
    folds: int = 3,
    min_train_size: int = 7,
) -> List[Dict[str, Any]]:
    """
    Constructs rolling-origin (expanding-window) walk-forward splits.
    Each split contains strictly disjoint training prefix values[:split_idx]
    and validation slice values[split_idx : split_idx + fold_horizon].
    """
    cleaned = [max(0.0, float(v)) for v in values]
    n = len(cleaned)
    if n < 2:
        return [
            {
                "fold": 1,
                "train_end_index": n,
                "test_start_index": 0,
                "test_end_index": n,
                "train": list(cleaned),
                "test": list(cleaned),
            }
        ]

    min_train = max(1, min(int(min_train_size), n - 1))
    val_total = min(max(1, int(validation_days)), n - min_train)
    val_start = n - val_total

    requested_folds = max(1, int(folds))
    if val_total >= 14 and requested_folds >= 2:
        # Use 7-day aligned folds when validation window is at least 14 days
        fold_horizon = max(1, val_total // min(requested_folds, val_total // 7 or 1))
    else:
        fold_horizon = max(1, val_total // requested_folds)

    splits: List[Dict[str, Any]] = []
    cursor = val_start
    fold_idx = 1

    while cursor < n:
        remaining = n - cursor
        if fold_idx == requested_folds or remaining < 2 * fold_horizon:
            end_idx = n
        else:
            end_idx = cursor + fold_horizon

        splits.append(
            {
                "fold": fold_idx,
                "train_end_index": cursor,
                "test_start_index": cursor,
                "test_end_index": end_idx,
                "train": list(cleaned[:cursor]),
                "test": list(cleaned[cursor:end_idx]),
            }
        )
        cursor = end_idx
        fold_idx += 1

    return splits


def _extract_model_params(
    model_name: str,
    train_values: Sequence[float],
    window: int = 7,
) -> Dict[str, Any]:
    """Extracts fitted model hyperparameters/coefficients on the full training series."""
    if model_name == "naive":
        return {"season_length": 1}
    if model_name == "seasonal_naive":
        return {"season_length": 7}
    if model_name in ("sma", "wma"):
        return {"window": int(window)}
    if model_name == "weekday_index":
        fitted = fit_weekday_index(train_values, season_length=7)
        return {
            "season_length": 7,
            "level": round(float(fitted["level"]), 4),
            "seasonal_indices": [round(float(x), 4) for x in fitted["seasonal_indices"]],
        }
    if model_name == "holt_winters":
        fitted = fit_holt_winters(train_values, season_length=7)
        return {
            "season_length": 7,
            "alpha": round(float(fitted["alpha"]), 4),
            "beta": round(float(fitted["beta"]), 4),
            "gamma": round(float(fitted["gamma"]), 4),
            "level": round(float(fitted["level"]), 4),
            "trend": round(float(fitted["trend"]), 4),
        }
    if model_name == "croston_sba":
        fitted = fit_croston_sba(train_values, method="sba")
        return {
            "method": "sba",
            "alpha": round(float(fitted["alpha"]), 4),
            "z_t": round(float(fitted["z_t"]), 4),
            "p_t": round(float(fitted["p_t"]), 4),
            "forecast_rate": round(float(fitted["forecast_rate"]), 4),
        }
    if model_name == "linear_trend":
        fitted = fit_linear_trend(train_values)
        return {
            "a": round(float(fitted["a"]), 4),
            "b": round(float(fitted["b"]), 4),
        }
    return {}


def forecast_with_candidate(
    model_name: str,
    train_values: Sequence[float],
    horizon: int,
    window: int = 7,
) -> List[float]:
    """Dispatches horizon forecasting to the specified candidate model."""
    if model_name == "naive":
        return forecast_naive(train_values, horizon)
    if model_name == "seasonal_naive":
        return forecast_seasonal_naive(train_values, horizon, season_length=7)
    if model_name == "sma":
        return forecast_sma(train_values, horizon, window=window)
    if model_name == "wma":
        return forecast_wma(train_values, horizon, window=window)
    if model_name == "weekday_index":
        return forecast_weekday_index(train_values, horizon, season_length=7)
    if model_name == "holt_winters":
        return forecast_holt_winters(train_values, horizon, season_length=7)
    if model_name in ("croston_sba", "croston"):
        return forecast_croston_sba(train_values, horizon, method="sba")
    if model_name == "linear_trend":
        return forecast_linear_trend(train_values, horizon)
    return forecast_seasonal_naive(train_values, horizon, season_length=7)


def run_walk_forward_backtest(
    values: Sequence[float],
    validation_days: int = 14,
    window: int = 7,
    folds: int = 3,
) -> Dict[str, Any]:
    """
    Executes rolling-origin walk-forward backtesting across all baseline and
    equation-based candidate models.

    Selects the candidate with the lowest validation sMAPE only if it strictly
    beats the Naive / Seasonal Naive baseline floor; otherwise falls back to
    the baseline model with beats_baseline = False.
    """
    cleaned = [max(0.0, float(v)) for v in values]
    splits = generate_walk_forward_splits(
        cleaned,
        validation_days=validation_days,
        folds=folds,
        min_train_size=max(7, int(window)),
    )

    candidates_summary: List[Dict[str, Any]] = []
    for model_name in CANDIDATE_MODEL_NAMES:
        pooled_actuals: List[float] = []
        pooled_preds: List[float] = []
        fold_metrics: List[Dict[str, float]] = []

        for split in splits:
            train_slice: List[float] = split["train"]
            test_slice: List[float] = split["test"]
            preds = forecast_with_candidate(
                model_name=model_name,
                train_values=train_slice,
                horizon=len(test_slice),
                window=window,
            )
            pooled_actuals.extend(test_slice)
            pooled_preds.extend(preds)
            fold_metrics.append(compute_metrics(test_slice, preds))

        overall_metrics = compute_metrics(pooled_actuals, pooled_preds)
        params = _extract_model_params(model_name, cleaned, window=window)

        candidates_summary.append(
            {
                "model": model_name,
                "mae": overall_metrics["mae"],
                "rmse": overall_metrics["rmse"],
                "smape": overall_metrics["smape"],
                "mape": overall_metrics["mape"],
                "params": params,
                "fold_metrics": fold_metrics,
            }
        )

    floor_models = [c for c in candidates_summary if c["model"] in ("naive", "seasonal_naive")]
    floor_best = min(floor_models, key=lambda c: (c["smape"], c["rmse"], c["mae"]))
    baseline_model_name = str(floor_best["model"])
    baseline_smape = float(floor_best["smape"])

    best_overall = min(candidates_summary, key=lambda c: (c["smape"], c["rmse"], c["mae"]))
    beats_baseline = bool(
        best_overall["model"] not in ("naive", "seasonal_naive")
        and float(best_overall["smape"]) < baseline_smape - 1e-6
    )
    selected_entry = best_overall if beats_baseline else floor_best

    for c in candidates_summary:
        c["baseline_model"] = baseline_model_name
        c["baseline_smape"] = baseline_smape
        c["beats_baseline"] = bool(
            c["model"] not in ("naive", "seasonal_naive")
            and float(c["smape"]) < baseline_smape - 1e-6
        )
        c["selected"] = c["model"] == selected_entry["model"]

    return {
        "selected_model": selected_entry["model"],
        "selected_metrics": {
            "mae": selected_entry["mae"],
            "rmse": selected_entry["rmse"],
            "smape": selected_entry["smape"],
            "mape": selected_entry["mape"],
        },
        "baseline_model": baseline_model_name,
        "baseline_smape": baseline_smape,
        "beats_baseline": beats_baseline,
        "folds": len(splits),
        "params": selected_entry["params"],
        "candidates": candidates_summary,
    }

