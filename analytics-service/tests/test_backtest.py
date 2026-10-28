from __future__ import annotations

import os
import sys
import unittest
from datetime import date, timedelta

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from api.app import dispatch_request
from models.backtest import (
    CANDIDATE_MODEL_NAMES,
    forecast_with_candidate,
    generate_walk_forward_splits,
    run_walk_forward_backtest,
)
from models.baseline import generate_product_branch_forecast


class WalkForwardBacktestTest(unittest.TestCase):
    def test_walk_forward_splits_never_leak_future_data(self) -> None:
        base_series = [20.0 + 0.8 * t + (t % 7) * 1.5 for t in range(35)]
        splits = generate_walk_forward_splits(base_series, validation_days=14, folds=2)

        self.assertEqual(len(splits), 2)
        for split in splits:
            cutoff = split["train_end_index"]
            test_start = split["test_start_index"]
            test_end = split["test_end_index"]

            self.assertEqual(cutoff, test_start)
            self.assertLess(cutoff, test_end)
            self.assertEqual(split["train"], base_series[:cutoff])
            self.assertEqual(split["test"], base_series[test_start:test_end])

            # Mutate all future observations from cutoff onward and confirm
            # every candidate model's predictions on this fold remain identical
            tampered_series = list(base_series[:cutoff]) + [v + 9999.0 for v in base_series[cutoff:]]
            tampered_splits = generate_walk_forward_splits(tampered_series, validation_days=14, folds=2)
            matching_split = [s for s in tampered_splits if s["fold"] == split["fold"]][0]

            for model_name in CANDIDATE_MODEL_NAMES:
                orig_preds = forecast_with_candidate(
                    model_name, split["train"], horizon=len(split["test"]), window=7
                )
                tampered_preds = forecast_with_candidate(
                    model_name, matching_split["train"], horizon=len(matching_split["test"]), window=7
                )
                self.assertEqual(orig_preds, tampered_preds)

    def test_selects_linear_trend_when_it_strictly_beats_baseline_on_smape(self) -> None:
        # Pure linear upward trend: y_t = 12 + 3 * t for t = 1..28
        series = [12.0 + 3.0 * t for t in range(1, 29)]
        result = run_walk_forward_backtest(series, validation_days=14, window=7, folds=2)

        self.assertEqual(len(result["candidates"]), 8)
        self.assertTrue(result["beats_baseline"])
        self.assertEqual(result["selected_model"], "linear_trend")
        self.assertAlmostEqual(result["selected_metrics"]["smape"], 0.0, places=3)
        self.assertGreater(result["baseline_smape"], 10.0)

    def test_selects_holt_winters_on_additive_trend_plus_weekly_seasonality(self) -> None:
        # Additive trend + strong 7-day seasonal pattern over 35 days
        seasonal = [-8.0, -5.0, -2.0, 0.0, 3.0, 7.0, 5.0]
        series = [30.0 + 1.5 * t + seasonal[t % 7] for t in range(35)]
        result = run_walk_forward_backtest(series, validation_days=14, window=7, folds=2)

        self.assertTrue(result["beats_baseline"])
        self.assertEqual(result["selected_model"], "holt_winters")
        self.assertLess(result["selected_metrics"]["smape"], result["baseline_smape"])

    def test_falls_back_to_baseline_when_candidates_do_not_beat_baseline_floor(self) -> None:
        # Pure 7-day stationary cycle: seasonal_naive has 0.0 sMAPE and cannot be beaten
        periodic_series = [15.0 + float(t % 7) for t in range(28)]
        periodic_res = run_walk_forward_backtest(periodic_series, validation_days=14, window=7, folds=2)

        self.assertFalse(periodic_res["beats_baseline"])
        self.assertEqual(periodic_res["selected_model"], "seasonal_naive")
        self.assertEqual(periodic_res["baseline_model"], "seasonal_naive")
        self.assertAlmostEqual(periodic_res["baseline_smape"], 0.0, places=3)

        # Step-change series where post-jump validation matches the last training point (naive = 0 error)
        step_series = [10.0 + float(t % 3) for t in range(13)] + [50.0] * 15
        step_res = run_walk_forward_backtest(step_series, validation_days=14, window=7, folds=1)

        self.assertFalse(step_res["beats_baseline"])
        self.assertEqual(step_res["selected_model"], "naive")
        self.assertEqual(step_res["baseline_model"], "naive")

    def test_forecast_select_endpoint_and_pipeline_use_winning_equation_model(self) -> None:
        start = date(2026, 2, 1)
        history = [
            {
                "date": (start + timedelta(days=idx)).isoformat(),
                "quantity": 15.0 + 2.5 * (idx + 1),
                "stockout": False,
                "promotion": False,
            }
            for idx in range(28)
        ]

        status, body = dispatch_request(
            "POST",
            "/forecast/select",
            {
                "product_id": 10,
                "branch_id": 1,
                "horizon": 7,
                "history": history,
            },
            headers={},
        )

        self.assertEqual(status, 200)
        self.assertEqual(body["model_used"], "linear_trend")
        self.assertTrue(body["beats_baseline"])
        self.assertEqual(len(body["candidates"]), 8)
        self.assertEqual(len(body["forecast"]), 7)
        # Day 29 prediction should be 15 + 2.5 * 29 = 87.5
        self.assertAlmostEqual(body["forecast"][0]["predicted_demand"], 87.5, places=2)


if __name__ == "__main__":
    unittest.main()

