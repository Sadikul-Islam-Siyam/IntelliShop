"""Unit tests for hold-out validation and model selection (#41)."""

from __future__ import annotations

import os
import sys
import unittest
from datetime import date, timedelta

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from models.backtest import (
    anova_weekday_f_test,
    evaluate_candidate_on_holdout,
    generate_forecast,
    handle_forecast_request,
    select_best_model,
)


class HoldOutValidationTest(unittest.TestCase):
    def test_never_trains_on_future_data(self) -> None:
        """Verifies candidate models evaluated on holdout are fitted strictly on past observations."""
        start = date(2026, 8, 1)
        observations = [
            {"date": start + timedelta(days=i), "demand": 10.0 + (i % 7)}
            for i in range(40)
        ]

        # Validation window is the last 14 days
        cutoff = start + timedelta(days=25)
        train_obs = [o for o in observations if o["date"] <= cutoff]
        val_obs = [o for o in observations if o["date"] > cutoff]

        eval_result = evaluate_candidate_on_holdout("mean", train_obs, val_obs)

        # Mutate future validation observations massively
        tampered_val = [{"date": o["date"], "demand": o["demand"] + 1000.0} for o in val_obs]
        eval_tampered = evaluate_candidate_on_holdout("mean", train_obs, tampered_val)

        # Predictions for validation days must be identical since model was fitted only on train_obs
        self.assertEqual(eval_result["predictions"], eval_tampered["predictions"])

    def test_selects_linear_trend_only_if_it_beats_mean(self) -> None:
        """When linear trend beats baseline on validation SSE and r is real, select linear trend."""
        start = date(2026, 8, 1)
        # Clear upward linear trend: y = 5 + 3*t
        trend_obs = [
            {"date": start + timedelta(days=i), "demand": 5.0 + 3.0 * i}
            for i in range(35)
        ]

        result = select_best_model(trend_obs, validation_days=10)
        self.assertTrue(result["beats_baseline"])
        self.assertEqual(result["selected_model"], "linear_trend")
        self.assertLess(result["selected_eval"]["sse"], result["baseline_sse"])

    def test_falls_back_to_mean_when_trend_does_not_beat_mean(self) -> None:
        """When data is stationary/flat, linear trend should not beat mean."""
        start = date(2026, 8, 1)
        # Flat constant demand: y = 20
        flat_obs = [
            {"date": start + timedelta(days=i), "demand": 20.0}
            for i in range(30)
        ]

        result = select_best_model(flat_obs, validation_days=10)
        self.assertEqual(result["selected_model"], "mean")
        self.assertFalse(result["beats_baseline"])

    def test_anova_f_test_detects_significant_weekday_variation(self) -> None:
        start = date(2026, 8, 3)  # Monday
        # Heavy weekend spikes
        obs = []
        for i in range(42):
            d = start + timedelta(days=i)
            demand = 100.0 if d.weekday() in (4, 5) else 10.0
            obs.append({"date": d, "demand": float(demand)})

        anova = anova_weekday_f_test(obs)
        self.assertTrue(anova["has_weekday_effect"])
        self.assertGreater(anova["f_stat"], anova["f_critical"])

    def test_generate_forecast_returns_sse_rse_and_t_table_intervals(self) -> None:
        history = [
            {"date": (date(2026, 9, 1) + timedelta(days=i)).isoformat(), "quantity": 15.0 + (i % 5)}
            for i in range(45)
        ]
        res = generate_forecast(product_id=1, branch_id=1, history=history, horizon=7)

        self.assertEqual(len(res["forecast"]), 7)
        self.assertIn("sse", res["metrics"])
        self.assertIn("rse", res["metrics"])
        self.assertIn("baseline_sse", res)

        # Check bounds
        first_step = res["forecast"][0]
        self.assertLessEqual(first_step["lower_bound"], first_step["predicted_demand"])
        self.assertGreaterEqual(first_step["upper_bound"], first_step["predicted_demand"])

    def test_handle_forecast_request_validation(self) -> None:
        status, body = handle_forecast_request({})
        self.assertEqual(status, 422)

        valid_payload = {
            "product_id": 1,
            "branch_id": 1,
            "horizon": 7,
            "history": [{"date": "2026-09-01", "quantity": 10.0}],
        }
        status, body = handle_forecast_request(valid_payload)
        self.assertEqual(status, 200)
        self.assertIn("forecast", body)


if __name__ == "__main__":
    unittest.main()
