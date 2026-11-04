"""Hand-computed checks for the forecasting equations (mean, median, weekday mean, regression)."""

from __future__ import annotations

import math
import os
import sys
import unittest
from datetime import date, timedelta

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from models.linear_regression import fit_linear_trend, forecast_linear_trend, trend_is_real
from models.mean_median import forecast_mean_median, forecast_weekday_mean, median
from pipeline.features import mean


class TestEquationModels(unittest.TestCase):
    def test_mean_and_median_match_hand_computed_values(self) -> None:
        values = [4.0, 8.0, 6.0, 5.0, 7.0]
        self.assertEqual(mean(values), 6.0)  # 30 / 5
        self.assertEqual(median(values), 6.0)  # sorted 4 5 6 7 8

        start = date(2026, 9, 1)
        history = [{"date": start + timedelta(days=i), "demand": v} for i, v in enumerate(values)]
        target = [start + timedelta(days=5)]
        self.assertEqual(forecast_mean_median(history, target, "mean")["predictions"], [6.0])
        self.assertEqual(forecast_mean_median(history, target, "median")["predictions"], [6.0])

    def test_weekday_mean_matches_hand_computed_value(self) -> None:
        mondays = [date(2026, 9, 7), date(2026, 9, 14), date(2026, 9, 21)]
        history = [{"date": d, "demand": v} for d, v in zip(mondays, [12.0, 15.0, 18.0])]
        history.append({"date": date(2026, 9, 22), "demand": 40.0})  # Tuesday, ignored for Mondays
        result = forecast_weekday_mean(history, [date(2026, 9, 28)], k=3)
        self.assertEqual(result["predictions"], [15.0])  # (12 + 15 + 18) / 3

    def test_regression_coefficients_match_hand_computed_values(self) -> None:
        # t = 1..5, y = 2 4 5 4 5: t_bar = 3, y_bar = 4, Sty = 6, Stt = 10
        fit = fit_linear_trend([1, 2, 3, 4, 5], [2, 4, 5, 4, 5])
        self.assertAlmostEqual(fit["b1"], 0.6, places=9)
        self.assertAlmostEqual(fit["b0"], 2.2, places=9)
        self.assertAlmostEqual(fit["sse"], 2.4, places=9)
        self.assertAlmostEqual(fit["sst"], 6.0, places=9)
        self.assertAlmostEqual(fit["r_squared"], 0.6, places=9)
        self.assertAlmostEqual(fit["r"], math.sqrt(0.6), places=9)
        self.assertAlmostEqual(fit["rse"], math.sqrt(2.4 / 3), places=9)
        # t = r * sqrt(3) / sqrt(1 - r^2) = 2.12 < t(0.975, 3) = 3.182: not a real trend
        self.assertFalse(trend_is_real(fit))

    def test_strong_trend_is_detected_and_extrapolated(self) -> None:
        start = date(2026, 9, 1)
        history = [{"date": start + timedelta(days=t), "demand": 5.0 + 2.0 * t} for t in range(10)]
        result = forecast_linear_trend(history, [start + timedelta(days=12)])
        self.assertTrue(result["real_trend"])
        self.assertAlmostEqual(result["fit"]["b0"], 5.0, places=9)
        self.assertAlmostEqual(result["fit"]["b1"], 2.0, places=9)
        self.assertAlmostEqual(result["fit"]["r_squared"], 1.0, places=9)
        self.assertEqual(result["predictions"], [29.0])

    def test_flat_series_has_no_trend(self) -> None:
        start = date(2026, 9, 1)
        history = [{"date": start + timedelta(days=t), "demand": 10.0} for t in range(14)]
        result = forecast_linear_trend(history, [start + timedelta(days=15)])
        self.assertFalse(result["real_trend"])
        self.assertEqual(result["predictions"], [10.0])


if __name__ == "__main__":
    unittest.main()
