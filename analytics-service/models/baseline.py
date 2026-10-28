"""
Baseline statistical demand forecasting models (Naive, Seasonal Naive, SMA, WMA).

Implements the baseline equations and accuracy metrics:
- Naive: y_hat(t+h) = y_t
- Seasonal Naive: y_hat(t+h) = y(t+h-7)
- Simple Moving Average (SMA): y_hat = (1/n) * sum_{i=0}^{n-1} y(t-i)
- Weighted Moving Average (WMA): y_hat = sum_{i=0}^{n-1} w_i * y(t-i)
  with w_i = (n - i) / (n*(n + 1)/2)
- Accuracy metrics: sMAPE (primary), MAE, RMSE, MAPE
- Demand variability: sigma_d = RMSE of one-step forecast errors
- Forecast interval: y_hat +/- z * sigma_d * sqrt(h)
"""

from __future__ import annotations

import math
from datetime import date, datetime, timedelta
from typing import Any, Callable, Dict, List, Optional, Sequence, Tuple

from pipeline.clean import clean_daily_series
from pipeline.features import (
    build_feature_set,
    compute_forecast_interval,
    compute_one_step_rmse,
)


def _parse_date(value: Any) -> date:
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    return date.fromisoformat(str(value)[:10])


def forecast_naive(train_values: Sequence[float], horizon: int) -> List[float]:
    """Naive forecast: y_hat(t+h) = y_t."""
    h = max(1, int(horizon))
    if not train_values:
        return [0.0] * h
    last_val = max(0.0, float(train_values[-1]))
    return [round(last_val, 4) for _ in range(h)]


def forecast_seasonal_naive(train_values: Sequence[float], horizon: int, season_length: int = 7) -> List[float]:
    """
    Seasonal naive forecast: y_hat(t+h) = y(t+h-7) repeating the latest 7-day cycle.
    Falls back to naive if training history is shorter than season_length.
    """
    h = max(1, int(horizon))
    s = max(1, int(season_length))
    if not train_values:
        return [0.0] * h
    if len(train_values) < s:
        return forecast_naive(train_values, h)

    last_season = [max(0.0, float(x)) for x in train_values[-s:]]
    return [round(last_season[(step - 1) % s], 4) for step in range(1, h + 1)]


def forecast_sma(train_values: Sequence[float], horizon: int, window: int = 7) -> List[float]:
    """Simple Moving Average (SMA): y_hat = (1/n) * sum_{i=0}^{n-1} y(t-i)."""
    h = max(1, int(horizon))
    if not train_values:
        return [0.0] * h
    n = min(max(1, int(window)), len(train_values))
    recent = [max(0.0, float(x)) for x in train_values[-n:]]
    avg = sum(recent) / n
    return [round(avg, 4) for _ in range(h)]


def forecast_wma(train_values: Sequence[float], horizon: int, window: int = 7) -> List[float]:
    """
    Weighted Moving Average (WMA): y_hat = sum_{i=0}^{n-1} w_i * y(t-i)
    where i=0 is the most recent observation y_t and w_i = (n - i) / (n*(n + 1)/2).
    """
    h = max(1, int(horizon))
    if not train_values:
        return [0.0] * h
    n = min(max(1, int(window)), len(train_values))
    denom = n * (n + 1) / 2.0
    recent = [max(0.0, float(x)) for x in train_values[-n:]]
    weighted_sum = 0.0
    for i in range(n):
        obs = recent[n - 1 - i]
        w_i = (n - i) / denom
        weighted_sum += w_i * obs

    return [round(weighted_sum, 4) for _ in range(h)]


def compute_metrics(actuals: Sequence[float], predictions: Sequence[float]) -> Dict[str, float]:
    """
    Computes forecast accuracy metrics:
    - sMAPE (primary): (100/n) * sum( 2 * |y - y_hat| / (|y| + |y_hat|) )
    - MAE: (1/n) * sum( |y - y_hat| )
    - RMSE: sqrt( (1/n) * sum( (y - y_hat)^2 ) )
    - MAPE: (100/n_pos) * sum_{y > 0}( |y - y_hat| / y )
    """
    pairs = [(float(a), float(p)) for a, p in zip(actuals, predictions)]
    if not pairs:
        return {"mae": 0.0, "rmse": 0.0, "smape": 0.0, "mape": 0.0}

    n = len(pairs)
    abs_errors = [abs(a - p) for a, p in pairs]
    sq_errors = [(a - p) ** 2 for a, p in pairs]

    smape_terms: List[float] = []
    mape_terms: List[float] = []
    for a, p in pairs:
        denom = abs(a) + abs(p)
        if denom <= 1e-9:
            smape_terms.append(0.0)
        else:
            smape_terms.append((2.0 * abs(a - p) / denom) * 100.0)

        if abs(a) > 1e-9:
            mape_terms.append((abs(a - p) / abs(a)) * 100.0)

    mae = sum(abs_errors) / n
    rmse = math.sqrt(sum(sq_errors) / n)
    smape = sum(smape_terms) / n
    mape = (sum(mape_terms) / len(mape_terms)) if mape_terms else 0.0

    return {
        "mae": round(mae, 3),
        "rmse": round(rmse, 3),
        "smape": round(smape, 3),
        "mape": round(mape, 3),
    }


def one_step_predictions_for_model(values: Sequence[float], model_name: str, window: int = 7) -> Tuple[List[float], List[float]]:
    """
    Generates walk-forward one-step-ahead predictions fitted strictly on observations
    prior to step t to compute demand variability sigma_d.
    """
    from models.croston import fit_croston_sba, forecast_croston_sba
    from models.holt_winters import fit_holt_winters, forecast_holt_winters
    from models.linear_trend import forecast_linear_trend
    from models.weekday_index import forecast_weekday_index

    n = len(values)
    start_idx = 7 if model_name in ("seasonal_naive", "weekday_index", "holt_winters") and n > 7 else max(1, min(window, n - 1))
    actuals: List[float] = []
    preds: List[float] = []

    hw_params: Optional[Dict[str, float]] = None
    if model_name == "holt_winters" and n > start_idx:
        fitted_hw = fit_holt_winters(values[:start_idx], season_length=7)
        hw_params = {
            "alpha": float(fitted_hw["alpha"]),
            "beta": float(fitted_hw["beta"]),
            "gamma": float(fitted_hw["gamma"]),
        }

    croston_alpha: Optional[float] = None
    if model_name in ("croston_sba", "croston") and n > start_idx:
        fitted_cr = fit_croston_sba(values[:start_idx], method="sba")
        croston_alpha = float(fitted_cr["alpha"])

    for t in range(start_idx, n):
        history_up_to_t = values[:t]
        if model_name == "naive":
            pred = forecast_naive(history_up_to_t, 1)[0]
        elif model_name == "seasonal_naive":
            pred = forecast_seasonal_naive(history_up_to_t, 1, season_length=7)[0]
        elif model_name == "sma":
            pred = forecast_sma(history_up_to_t, 1, window=window)[0]
        elif model_name == "wma":
            pred = forecast_wma(history_up_to_t, 1, window=window)[0]
        elif model_name == "weekday_index":
            pred = forecast_weekday_index(history_up_to_t, 1, season_length=7)[0]
        elif model_name == "holt_winters":
            pred = forecast_holt_winters(
                history_up_to_t,
                1,
                season_length=7,
                alpha=hw_params["alpha"] if hw_params else None,
                beta=hw_params["beta"] if hw_params else None,
                gamma=hw_params["gamma"] if hw_params else None,
            )[0]
        elif model_name in ("croston_sba", "croston"):
            pred = forecast_croston_sba(history_up_to_t, 1, alpha=croston_alpha, method="sba")[0]
        elif model_name == "linear_trend":
            pred = forecast_linear_trend(history_up_to_t, 1)[0]
        else:
            pred = forecast_naive(history_up_to_t, 1)[0]

        actuals.append(float(values[t]))
        preds.append(float(pred))

    return actuals, preds


def evaluate_baseline_candidates(
    values: Sequence[float],
    validation_days: int = 14,
    window: int = 7,
) -> Dict[str, Any]:
    """
    Evaluates forecasting candidates on the validation split and selects a candidate
    only if it outperforms the naive / seasonal-naive baseline floor on sMAPE.
    """
    try:
        from models.backtest import run_walk_forward_backtest  # type: ignore

        return run_walk_forward_backtest(values, validation_days=validation_days, window=window)
    except ImportError:
        pass

    n = len(values)
    val_len = min(max(1, int(validation_days)), max(1, n // 3)) if n >= 4 else 1
    train_vals = list(values[:-val_len]) if n > val_len else list(values)
    val_vals = list(values[-val_len:]) if n > val_len else list(values)

    models: Dict[str, Callable[[Sequence[float], int], List[float]]] = {
        "naive": lambda tr, h: forecast_naive(tr, h),
        "seasonal_naive": lambda tr, h: forecast_seasonal_naive(tr, h, season_length=7),
        "sma": lambda tr, h: forecast_sma(tr, h, window=window),
        "wma": lambda tr, h: forecast_wma(tr, h, window=window),
    }

    candidates_summary: List[Dict[str, Any]] = []
    for name, fn in models.items():
        preds = fn(train_vals, len(val_vals))
        metrics = compute_metrics(val_vals, preds)
        candidates_summary.append(
            {
                "model": name,
                "mae": metrics["mae"],
                "rmse": metrics["rmse"],
                "smape": metrics["smape"],
                "mape": metrics["mape"],
                "params": {"window": window} if name in ("sma", "wma") else {"season_length": 7 if name == "seasonal_naive" else 1},
            }
        )

    floor_models = [c for c in candidates_summary if c["model"] in ("naive", "seasonal_naive")]
    floor_best = min(floor_models, key=lambda c: (c["smape"], c["rmse"], c["mae"]))
    baseline_model_name = floor_best["model"]
    baseline_smape = floor_best["smape"]

    best_overall = min(candidates_summary, key=lambda c: (c["smape"], c["rmse"], c["mae"]))
    beats_baseline = best_overall["smape"] < baseline_smape - 1e-6
    selected_entry = best_overall if beats_baseline else floor_best

    for c in candidates_summary:
        c["baseline_model"] = baseline_model_name
        c["baseline_smape"] = baseline_smape
        c["beats_baseline"] = bool(c["smape"] < baseline_smape - 1e-6)
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
        "folds": 1,
        "params": selected_entry["params"],
        "candidates": candidates_summary,
    }


def generate_product_branch_forecast(
    product_id: int,
    branch_id: int,
    history: Sequence[Dict[str, Any]],
    horizon: int = 14,
    service_level_z: float = 1.645,
    preferred_model: Optional[str] = None,
) -> Dict[str, Any]:
    """
    Executes the demand forecasting pipeline for a product-branch pair and returns
    horizon projections with demand variability sigma_d and forecast intervals.
    """
    cleaned = clean_daily_series(history)
    features = build_feature_set(cleaned, validation_days=min(14, max(7, len(cleaned) // 4)))
    enriched_series = features["series"]

    from models.croston import forecast_croston_sba
    from models.holt_winters import forecast_holt_winters
    from models.linear_trend import forecast_linear_trend
    from models.weekday_index import forecast_weekday_index

    effective_values = [float(r["effective_quantity"]) for r in enriched_series]
    eval_result = evaluate_baseline_candidates(effective_values, validation_days=14, window=7)

    supported_models = (
        "naive",
        "seasonal_naive",
        "sma",
        "wma",
        "weekday_index",
        "holt_winters",
        "croston_sba",
        "croston",
        "linear_trend",
    )
    normalized_preferred = "croston_sba" if preferred_model == "croston" else preferred_model
    model_used = normalized_preferred if normalized_preferred in supported_models else eval_result["selected_model"]

    if model_used == "naive":
        horizon_preds = forecast_naive(effective_values, horizon)
    elif model_used == "seasonal_naive":
        horizon_preds = forecast_seasonal_naive(effective_values, horizon, season_length=7)
    elif model_used == "sma":
        horizon_preds = forecast_sma(effective_values, horizon, window=7)
    elif model_used == "wma":
        horizon_preds = forecast_wma(effective_values, horizon, window=7)
    elif model_used == "weekday_index":
        horizon_preds = forecast_weekday_index(effective_values, horizon, season_length=7)
    elif model_used == "holt_winters":
        horizon_preds = forecast_holt_winters(effective_values, horizon, season_length=7)
    elif model_used in ("croston_sba", "croston"):
        horizon_preds = forecast_croston_sba(effective_values, horizon, method="sba")
    elif model_used == "linear_trend":
        horizon_preds = forecast_linear_trend(effective_values, horizon)
    else:
        horizon_preds = forecast_seasonal_naive(effective_values, horizon, season_length=7)

    os_actuals, os_preds = one_step_predictions_for_model(effective_values, model_used, window=7)
    sigma_d = compute_one_step_rmse(os_actuals, os_preds) if os_actuals else float(features["sigma_d"])

    last_date = _parse_date(enriched_series[-1]["date"]) if enriched_series else date.today()
    metrics = eval_result["selected_metrics"]
    smape_val = float(metrics.get("smape", 0.0))
    confidence_score = round(max(5.0, min(99.5, 100.0 - min(95.0, smape_val / 2.0))), 2)

    forecast_rows: List[Dict[str, Any]] = []
    for step_idx, y_hat in enumerate(horizon_preds, start=1):
        f_date = last_date + timedelta(days=step_idx)
        low, high = compute_forecast_interval(y_hat, sigma_d=sigma_d, horizon_step=step_idx, z_value=service_level_z)
        forecast_rows.append(
            {
                "date": f_date.isoformat(),
                "step": step_idx,
                "weekday": f_date.strftime("%a"),
                "predicted_demand": round(float(y_hat), 2),
                "lower_bound": round(low, 2),
                "upper_bound": round(high, 2),
                "confidence_score": confidence_score,
            }
        )

    return {
        "product_id": int(product_id),
        "branch_id": int(branch_id),
        "horizon": int(horizon),
        "forecast": forecast_rows,
        "model_used": model_used,
        "confidence_score": confidence_score,
        "sigma_d": round(sigma_d, 4),
        "service_level_z": service_level_z,
        "train_end_date": last_date.isoformat(),
        "folds": eval_result["folds"],
        "metrics": metrics,
        "baseline_model": eval_result["baseline_model"],
        "baseline_smape": eval_result["baseline_smape"],
        "beats_baseline": bool(eval_result["beats_baseline"]),
        "params": eval_result["params"],
        "candidates": eval_result["candidates"],
        "weekday_indices": features["weekday_indices"],
        "pipeline_summary": {
            "total_days": len(cleaned),
            "stockout_days_flagged": sum(1 for r in cleaned if r.get("stockout")),
            "outliers_capped": sum(1 for r in cleaned if r.get("outlier_capped")),
            "promotion_days": sum(1 for r in cleaned if r.get("promotion")),
            "baseline_level": features["baseline_level"],
        },
    }


def handle_forecast_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """Handles POST /forecast and POST /forecast/select requests."""
    for req_field in ("product_id", "branch_id", "horizon", "history"):
        if req_field not in payload:
            return 422, {
                "error": {
                    "code": "VALIDATION_FAILED",
                    "message": "The given data was invalid.",
                    "details": [{"field": req_field, "issue": f"The {req_field} field is required."}],
                }
            }

    history = payload.get("history")
    if not isinstance(history, list) or len(history) == 0:
        return 422, {
            "error": {
                "code": "VALIDATION_FAILED",
                "message": "History must be a non-empty list of daily sales observations.",
                "details": [{"field": "history", "issue": "At least one historical observation is required."}],
            }
        }

    result = generate_product_branch_forecast(
        product_id=int(payload["product_id"]),
        branch_id=int(payload["branch_id"]),
        history=history,
        horizon=int(payload.get("horizon", 14)),
        service_level_z=float(payload.get("service_level_z", 1.645)),
        preferred_model=payload.get("preferred_model"),
    )
    return 200, result
