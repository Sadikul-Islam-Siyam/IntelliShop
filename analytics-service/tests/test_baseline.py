"""Unit tests for baseline forecasting models (Naive, Seasonal Naive, SMA, WMA)."""

from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from api.app import dispatch_request
from models.baseline import (
    compute_metrics,
    forecast_naive,
    forecast_seasonal_naive,
    forecast_sma,
    forecast_wma,
)


class TestBaselineModels(unittest.TestCase):
    def test_naive_seasonal_naive_sma_and_wma_equations_on_fixture(self) -> None:
        series = [10.0, 12.0, 14.0, 16.0, 18.0, 20.0, 22.0]

        naive_preds = forecast_naive(series, horizon=3)
        self.assertEqual(naive_preds, [22.0, 22.0, 22.0])

        snaive_preds = forecast_seasonal_naive(series, horizon=3, season_length=7)
        self.assertEqual(snaive_preds, [10.0, 12.0, 14.0])

        sma_preds = forecast_sma(series, horizon=2, window=4)
        self.assertEqual(sma_preds, [19.0, 19.0])

        wma_preds = forecast_wma(series, horizon=2, window=4)
        self.assertEqual(wma_preds, [20.0, 20.0])

    def test_mae_rmse_and_smape_on_fixture(self) -> None:
        actuals = [20.0, 0.0, 10.0]
        preds = [18.0, 0.0, 14.0]
        metrics = compute_metrics(actuals, preds)
        self.assertAlmostEqual(metrics["mae"], 2.0, places=3)
        self.assertAlmostEqual(metrics["rmse"], 2.582, places=3)
        self.assertAlmostEqual(metrics["smape"], 14.620, places=2)

    def test_forecast_endpoint_contract_response(self) -> None:
        history = [
            {"date": f"2026-10-{day:02d}", "quantity": 15 + (day % 7), "promotion": False, "stockout": False}
            for day in range(1, 29)
        ]
        status, body = dispatch_request(
            "POST",
            "/forecast",
            body={
                "product_id": 1,
                "branch_id": 1,
                "horizon": 7,
                "history": history,
            },
        )
        self.assertEqual(status, 200)
        self.assertEqual(len(body["forecast"]), 7)
        self.assertIn(body["model_used"], ("naive", "seasonal_naive", "sma", "wma"))
        self.assertIn("smape", body["metrics"])
        self.assertIn("beats_baseline", body)


if __name__ == "__main__":
    unittest.main()
