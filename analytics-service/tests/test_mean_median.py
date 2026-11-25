"""Unit tests for the mean / median demand models."""

from __future__ import annotations

import math
import os
import sys
import unittest
from datetime import date, timedelta

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from models.mean_median import (
    drop_iqr_outliers,
    forecast_mean_median,
    forecast_weekday_mean,
    median,
    quartiles,
    rse,
    sse,
)

WINTER = [{"name": "winter", "start": "12-01", "end": "02-28"}]


def _obs(d: date, demand: float) -> dict:
    return {"date": d, "demand": demand}


class TestMeanMedianModels(unittest.TestCase):
    def test_sse_and_rse_on_fixture(self) -> None:
        actual = [10, 12, 14, 11, 13]
        predicted = [11, 11, 13, 12, 12]
        self.assertEqual(sse(actual, predicted), 5.0)
        self.assertAlmostEqual(rse(actual, predicted), math.sqrt(5 / 3), places=6)
        self.assertIsNone(rse([1, 2], [1, 2]))

    def test_median_and_iqr_fences(self) -> None:
        self.assertEqual(median([3, 1, 2]), 2)
        self.assertEqual(median([4, 1, 3, 2]), 2.5)
        self.assertEqual(quartiles([10, 11, 12, 13, 14, 15, 60]), (11.5, 14.5))
        self.assertEqual(drop_iqr_outliers([10, 11, 12, 13, 14, 15, 60]), [10, 11, 12, 13, 14, 15])

    def test_recent_window_uses_last_30_days_and_drops_spike(self) -> None:
        start = date(2026, 8, 1)
        history = []
        for i in range(40):
            demand = 50.0 if i < 10 else 20.0
            if i == 25:
                demand = 100.0
            history.append(_obs(start + timedelta(days=i), demand))

        target = start + timedelta(days=40)
        mean_result = forecast_mean_median(history, [target], statistic="mean")
        self.assertEqual(mean_result["predictions"], [20.0])
        self.assertEqual(mean_result["details"][0]["basis"], "recent")
        self.assertEqual(mean_result["details"][0]["n"], 29)
        self.assertEqual(mean_result["details"][0]["outliers_dropped"], 1)

        median_result = forecast_mean_median(history, [target], statistic="median")
        self.assertEqual(median_result["predictions"], [20.0])

    def test_seasonal_days_use_same_season_in_past_years(self) -> None:
        history = [
            _obs(date(2024, 12, 5), 28.0),
            _obs(date(2024, 12, 10), 32.0),
            _obs(date(2024, 12, 25), 150.0),  # festival spike, dropped by IQR fences
            _obs(date(2025, 1, 15), 30.0),
            _obs(date(2025, 2, 20), 34.0),
        ]
        history += [_obs(date(2025, 11, 1) + timedelta(days=i), 10.0) for i in range(30)]

        seasonal = forecast_mean_median(history, [date(2025, 12, 10)], statistic="mean", season_windows=WINTER)
        self.assertEqual(seasonal["predictions"], [31.0])  # (28 + 30 + 32 + 34) / 4
        self.assertEqual(seasonal["details"][0]["basis"], "season")

        seasonal_median = forecast_mean_median(
            history, [date(2026, 1, 5)], statistic="median", season_windows=WINTER
        )
        self.assertEqual(seasonal_median["predictions"], [31.0])

        normal_day = forecast_mean_median(history, [date(2026, 3, 10)], statistic="mean", season_windows=WINTER)
        self.assertEqual(normal_day["predictions"], [10.0])
        self.assertEqual(normal_day["details"][0]["basis"], "recent")

    def test_same_weekday_mean_over_last_k_weeks(self) -> None:
        start = date(2026, 9, 7)  # Monday
        history = []
        for week in range(3):
            for wd in range(7):
                history.append(_obs(start + timedelta(days=7 * week + wd), 10.0 + 2 * wd + week))

        result = forecast_weekday_mean(history, [date(2026, 9, 28), date(2026, 10, 3)], k=2)
        self.assertEqual(result["predictions"], [11.5, 21.5])
        self.assertEqual(result["details"][0]["n"], 2)


if __name__ == "__main__":
    unittest.main()

