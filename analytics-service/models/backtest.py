"""
Hold-out validation and model selection for demand forecasting.

Course rules:
* Hold-out validation: a method is only fitted on data strictly before the
  period it is tested on (never trains on future data).
* ANOVA F-test decides whether weekday means show a significant weekday effect.
* Regression trend is selected ONLY IF it beats the mean (lower validation SSE)
  and r shows a real trend; otherwise the mean is used.
* SSE is the primary comparison metric, RSE is secondary.
* Metrics: SSE, RSE, R^2 (trend model), beats_baseline, selected flag.
"""

from __future__ import annotations

import math
from datetime import date, timedelta
from typing import Any, Dict, List, Optional, Sequence, Tuple

from models.linear_regression import fit_linear_trend, forecast_linear_trend, trend_is_real
from models.mean_median import (
    DEFAULT_IQR_K,
    DEFAULT_RECENT_DAYS,
    DEFAULT_WEEKDAY_K,
    drop_iqr_outliers,
    forecast_mean_median,
    forecast_weekday_mean,
    rse,
    sse,
    window_values,
)
from pipeline.clean import clean_daily_series
from pipeline.features import (
    build_feature_set,
    forecast_interval,
    mean,
    parse_date,
    sample_std,
    time_based_split,
)

# Standard one-way ANOVA F-distribution critical values for df1 = 6 (7 weekdays)
# at alpha = 0.05 across denominator degrees of freedom df2.
_F_CRITICAL_TABLE_DF2: Tuple[int, ...] = (
    6, 7, 8, 9, 10, 12, 14, 16, 18, 20, 24, 30, 40, 60, 120, 1000
)
_F_CRITICAL_TABLE: Tuple[float, ...] = (
    4.28, 3.87, 3.58, 3.37, 3.22, 3.00, 2.85, 2.74, 2.66, 2.60, 2.51, 2.42, 2.34, 2.25, 2.17, 2.10
)


def f_critical_6_df(df2: int) -> float:
    """Returns the F(0.05, 6, df2) critical value from the course table."""
    if df2 <= _F_CRITICAL_TABLE_DF2[0]:
        return _F_CRITICAL_TABLE[0]
    for idx, table_df in enumerate(_F_CRITICAL_TABLE_DF2):
        if df2 <= table_df:
            return _F_CRITICAL_TABLE[idx]
    return 2.10


def anova_weekday_f_test(observations: Sequence[Dict[str, Any]]) -> Dict[str, Any]:
    """
    Performs one-way ANOVA across the 7 weekdays (k = 7 groups) to test for
    a statistically significant weekday effect.

    SSB = sum_d n_d * (y_bar_d - y_bar)^2,   df1 = k - 1 = 6
    SSW = sum_d sum_i (y_di - y_bar_d)^2,    df2 = N - k
    MSB = SSB / 6
    MSW = SSW / (N - 7)
    F = MSB / MSW
    """
    groups: Dict[int, List[float]] = {d: [] for d in range(7)}
    all_values: List[float] = []

    for obs in observations:
        d = parse_date(obs["date"]).weekday()
        qty = float(obs["demand"])
        groups[d].append(qty)
        all_values.append(qty)

    n_total = len(all_values)
    populated_groups = sum(1 for g in groups.values() if len(g) > 0)

    if n_total <= 7 or populated_groups < 2:
        return {
            "has_weekday_effect": False,
            "f_stat": 0.0,
            "f_critical": 3.0,
            "p_significant": False,
            "ssb": 0.0,
            "ssw": 0.0,
        }

    overall_mean = mean(all_values)
    ssb = sum(len(g) * (mean(g) - overall_mean) ** 2 for g in groups.values() if g)
    ssw = sum(sum((y - mean(g)) ** 2 for y in g) for g in groups.values() if g)

    df1 = 6
    df2 = max(1, n_total - 7)
    msb = ssb / df1

    if ssw <= 1e-9:
        # Zero within-group variance means between-group variation is infinitely significant
        f_stat = 9999.0 if ssb > 1e-6 else 0.0
    else:
        msw = ssw / df2
        f_stat = msb / msw if msw > 1e-9 else 0.0

    f_crit = f_critical_6_df(df2)
    has_effect = bool(f_stat > f_crit and ssb > 1e-6)

    return {
        "has_weekday_effect": has_effect,
        "f_stat": round(f_stat, 4),
        "f_critical": round(f_crit, 4),
        "df1": df1,
        "df2": df2,
        "ssb": round(ssb, 4),
        "ssw": round(ssw, 4),
    }


def evaluate_candidate_on_holdout(
    model_name: str,
    train_obs: Sequence[Dict[str, Any]],
    val_obs: Sequence[Dict[str, Any]],
    season_windows: Optional[Sequence[Dict[str, Any]]] = None,
    recent_days: int = DEFAULT_RECENT_DAYS,
    iqr_k: float = DEFAULT_IQR_K,
    weekday_k: int = DEFAULT_WEEKDAY_K,
) -> Dict[str, Any]:
    """
    Fits the specified candidate strictly on train_obs, forecasts the dates in
    val_obs, and computes validation metrics (SSE primary, RSE, and R^2).
    """
    val_dates = [o["date"] for o in val_obs]
    actuals = [float(o["demand"]) for o in val_obs]

    if model_name == "mean":
        f_res = forecast_mean_median(
            train_obs, val_dates, statistic="mean", season_windows=season_windows,
            recent_days=recent_days, iqr_k=iqr_k
        )
    elif model_name == "median":
        f_res = forecast_mean_median(
            train_obs, val_dates, statistic="median", season_windows=season_windows,
            recent_days=recent_days, iqr_k=iqr_k
        )
    elif model_name == "weekday_mean":
        f_res = forecast_weekday_mean(train_obs, val_dates, k=weekday_k)
    elif model_name == "linear_trend":
        f_res = forecast_linear_trend(train_obs, val_dates, recent_days=recent_days)
    else:
        raise ValueError(f"Unknown candidate model: {model_name}")

    preds = f_res["predictions"]
    cand_sse = sse(actuals, preds)
    cand_rse = rse(actuals, preds)

    # Compute R^2 against validation mean
    val_mean = mean(actuals)
    sst = sum((y - val_mean) ** 2 for y in actuals)
    r_sq = (1.0 - cand_sse / sst) if sst > 1e-9 else 0.0

    return {
        "model": model_name,
        "predictions": preds,
        "sse": round(cand_sse, 4),
        "rse": round(cand_rse, 4) if cand_rse is not None else None,
        "r_squared": round(r_sq, 4) if model_name == "linear_trend" else None,
        "params": f_res.get("params", {}),
        "fit": f_res.get("fit"),
        "real_trend": f_res.get("real_trend", False) if model_name == "linear_trend" else None,
    }


def select_best_model(
    observations: Sequence[Dict[str, Any]],
    validation_days: int = 14,
    season_windows: Optional[Sequence[Dict[str, Any]]] = None,
    preferred_model: Optional[str] = None,
) -> Dict[str, Any]:
    """
    Executes hold-out validation strictly fitting on training observations
    prior to the validation window.

    Candidate selection rules:
    - Baseline is always 'mean'.
    - 'weekday_mean' is evaluated when ANOVA F-test confirms weekday effect.
    - 'linear_trend' is evaluated if correlation r confirms a real trend.
    - 'median' is evaluated.
    - Linear trend is selected ONLY IF it beats the baseline mean (SSE < baseline_sse).
      Otherwise, the baseline mean (or weekday_mean/median if they beat mean) is used.
    """
    train_obs, val_obs = time_based_split(observations, validation_days=validation_days)
    if not val_obs:
        # Fallback if series is extremely short
        val_obs = train_obs[-max(1, len(train_obs) // 3):]
        train_obs = train_obs[:-len(val_obs)]

    anova_res = anova_weekday_f_test(train_obs)
    has_weekday_effect = anova_res["has_weekday_effect"]

    # 1. Baseline is always Mean
    baseline_eval = evaluate_candidate_on_holdout("mean", train_obs, val_obs, season_windows=season_windows)
    baseline_sse = float(baseline_eval["sse"])
    baseline_eval["beats_baseline"] = False
    baseline_eval["selected"] = False

    candidates: List[Dict[str, Any]] = [baseline_eval]

    # 2. Evaluate Median
    median_eval = evaluate_candidate_on_holdout("median", train_obs, val_obs, season_windows=season_windows)
    median_eval["beats_baseline"] = bool(median_eval["sse"] < baseline_sse)
    median_eval["selected"] = False
    candidates.append(median_eval)

    # 3. Evaluate Weekday Mean (when ANOVA confirms weekday effect, or if forced)
    if has_weekday_effect or preferred_model == "weekday_mean":
        wd_eval = evaluate_candidate_on_holdout("weekday_mean", train_obs, val_obs)
        wd_eval["beats_baseline"] = bool(wd_eval["sse"] < baseline_sse)
        wd_eval["selected"] = False
        candidates.append(wd_eval)

    # 4. Evaluate Linear Trend
    trend_eval = evaluate_candidate_on_holdout("linear_trend", train_obs, val_obs)
    trend_beats = bool(trend_eval["sse"] < baseline_sse and trend_eval.get("real_trend"))
    trend_eval["beats_baseline"] = trend_beats
    trend_eval["selected"] = False
    candidates.append(trend_eval)

    # Model Selection logic:
    # Manual Section 4.1: "The regression trend is selected only if it beats the mean; otherwise the mean is used."
    selected_name = "mean"

    if preferred_model and any(c["model"] == preferred_model for c in candidates):
        selected_name = preferred_model
    else:
        # Check if trend qualifies and beats baseline
        if trend_eval["beats_baseline"]:
            selected_name = "linear_trend"
        else:
            # Check among baselines (weekday_mean or median) if they beat mean
            eligible = [c for c in candidates if c["model"] != "linear_trend"]
            best_baseline = min(eligible, key=lambda c: (c["sse"], c["rse"] or 999999))
            selected_name = best_baseline["model"]

    # Mark the selected candidate
    selected_eval = baseline_eval
    for cand in candidates:
        if cand["model"] == selected_name:
            cand["selected"] = True
            selected_eval = cand

    return {
        "selected_model": selected_name,
        "selected_eval": selected_eval,
        "baseline_model": "mean",
        "baseline_sse": baseline_sse,
        "beats_baseline": bool(selected_eval.get("beats_baseline", False)),
        "candidates": candidates,
        "anova": anova_res,
        "train_count": len(train_obs),
        "val_count": len(val_obs),
    }


def generate_forecast(
    product_id: int,
    branch_id: int,
    history: Sequence[Dict[str, Any]],
    horizon: int = 14,
    service_level_z: float = 1.645,
    preferred_model: Optional[str] = None,
    season_windows: Optional[Sequence[Dict[str, Any]]] = None,
) -> Dict[str, Any]:
    """
    Full demand forecasting pipeline per Master Implementation Plan Section 4.1:
    1. Clean history (missing days -> 0, flag stock-outs, cap MAD outliers)
    2. Extract usable demand observations & feature statistics
    3. Run hold-out validation and select best equation model (SSE primary, RSE)
    4. Generate horizon predictions with forecast intervals (ȳ ± t·s/√n)
    """
    cleaned = clean_daily_series(history)
    features = build_feature_set(cleaned, validation_days=min(14, max(7, len(cleaned) // 4)))
    observations = features["observations"]

    if not observations:
        # Fallback for empty history
        today = date.today()
        forecast_rows = []
        for h in range(1, horizon + 1):
            d_h = today + timedelta(days=h)
            forecast_rows.append({
                "date": d_h.isoformat(),
                "step": h,
                "weekday": d_h.strftime("%a"),
                "predicted_demand": 0.0,
                "lower_bound": 0.0,
                "upper_bound": 0.0,
                "confidence_score": 50.0,
            })
        return {
            "product_id": product_id,
            "branch_id": branch_id,
            "horizon": horizon,
            "forecast": forecast_rows,
            "model_used": "mean",
            "confidence_score": 50.0,
            "sigma_d": 0.0,
            "service_level_z": service_level_z,
            "folds": 1,
            "metrics": {"sse": 0.0, "rse": 0.0, "r_squared": 0.0},
            "baseline_model": "mean",
            "baseline_sse": 0.0,
            "beats_baseline": False,
            "params": {},
            "candidates": [],
        }

    # Model selection on hold-out
    val_days = min(14, max(5, len(observations) // 4))
    sel_result = select_best_model(
        observations,
        validation_days=val_days,
        season_windows=season_windows,
        preferred_model=preferred_model,
    )

    model_used = sel_result["selected_model"]
    selected_eval = sel_result["selected_eval"]

    # Target forecast dates
    last_date = observations[-1]["date"]
    target_dates = [last_date + timedelta(days=step) for step in range(1, horizon + 1)]

    # Forecast with the selected model trained on all available observations
    if model_used == "mean":
        full_res = forecast_mean_median(observations, target_dates, "mean", season_windows=season_windows)
    elif model_used == "median":
        full_res = forecast_mean_median(observations, target_dates, "median", season_windows=season_windows)
    elif model_used == "weekday_mean":
        full_res = forecast_weekday_mean(observations, target_dates)
    elif model_used == "linear_trend":
        full_res = forecast_linear_trend(observations, target_dates)
    else:
        full_res = forecast_mean_median(observations, target_dates, "mean", season_windows=season_windows)

    preds = full_res["predictions"]
    details = full_res.get("details", [])

    # Sample std-dev of daily demand
    sigma_d = float(features["sigma_d"])
    n_obs = len(observations)

    # Confidence score derived from RSE / mean demand ratio
    mean_val = float(features["mean_demand"]) or 1.0
    rse_val = selected_eval.get("rse") or (selected_eval["sse"] / max(1, len(observations))) ** 0.5
    coeff_var = (rse_val / mean_val) if mean_val > 0 else 0.5
    confidence_score = round(max(10.0, min(99.0, 100.0 * (1.0 - min(0.9, coeff_var)))), 2)

    forecast_rows: List[Dict[str, Any]] = []
    for step_idx, (t_date, y_hat) in enumerate(zip(target_dates, preds), start=1):
        # Forecast interval ȳ ± t·s/√n
        det = details[step_idx - 1] if step_idx - 1 < len(details) else {}
        s_step = float(det.get("s", sigma_d)) or sigma_d
        n_step = int(det.get("n", n_obs)) or n_obs

        low, high, _ = forecast_interval(y_hat, s=s_step, n=max(2, n_step), confidence=0.95)

        forecast_rows.append({
            "date": t_date.isoformat(),
            "step": step_idx,
            "weekday": t_date.strftime("%a"),
            "predicted_demand": round(float(y_hat), 2),
            "lower_bound": round(float(low), 2),
            "upper_bound": round(float(high), 2),
            "confidence_score": confidence_score,
        })

    metrics = {
        "sse": selected_eval["sse"],
        "rse": selected_eval["rse"],
        "r_squared": selected_eval.get("r_squared"),
    }

    return {
        "product_id": int(product_id),
        "branch_id": int(branch_id),
        "horizon": int(horizon),
        "forecast": forecast_rows,
        "model_used": model_used,
        "confidence_score": confidence_score,
        "sigma_d": sigma_d,
        "service_level_z": service_level_z,
        "train_end_date": last_date.isoformat(),
        "folds": 1,
        "metrics": metrics,
        "baseline_model": sel_result["baseline_model"],
        "baseline_sse": sel_result["baseline_sse"],
        "beats_baseline": sel_result["beats_baseline"],
        "params": selected_eval.get("params", {}),
        "candidates": sel_result["candidates"],
        "weekday_means": features.get("weekday_means", {}),
        "anova": sel_result["anova"],
        "pipeline_summary": {
            "total_days": len(cleaned),
            "usable_demand_days": len(observations),
            "stockout_days_flagged": sum(1 for r in cleaned if r.get("stockout")),
            "outliers_capped": sum(1 for r in cleaned if r.get("outlier_capped")),
            "promotion_days": sum(1 for r in cleaned if r.get("promotion")),
        },
    }


def handle_forecast_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """Handles POST /forecast requests."""
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

    result = generate_forecast(
        product_id=int(payload["product_id"]),
        branch_id=int(payload["branch_id"]),
        history=history,
        horizon=int(payload.get("horizon", 14)),
        service_level_z=float(payload.get("service_level_z", 1.645)),
        preferred_model=payload.get("preferred_model"),
        season_windows=payload.get("season_windows"),
    )
    return 200, result


def handle_select_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """Handles POST /forecast/select requests."""
    return handle_forecast_request(payload)
