"""Unit tests for time-series feature engineering."""

from __future__ import annotations

import math
import os
import sys
import unittest
from datetime import date, timedelta

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from pipeline.features import (
    build_feature_set,
    forecast_interval,
    sample_std,
    t_critical,
    time_based_split,
    weekday_means,
)


def _fixture_series():
    """Four weeks from Monday 2026-09-07: weekdays sell 10, weekends sell 20."""
    start = date(2026, 9, 7)
    series = []
    for i in range(28):
        d = start + timedelta(days=i)
        qty = 20.0 if d.weekday() >= 5 else 10.0
        promo = False
        stockout = False
        if i == 9:  # Wednesday promotion spike
            qty, promo = 50.0, True
        if i == 19:  # Saturday stock-out
            qty, stockout = 0.0, True
        series.append({"date": d.isoformat(), "quantity": qty, "promotion": promo, "stockout": stockout})
    return series


class TestFeatureEngineering(unittest.TestCase):
    def test_weekday_means_mask_promotions_and_stockouts(self) -> None:
        means = weekday_means(_fixture_series(), mask_promotions=True, mask_stockouts=True)
        self.assertEqual(means[0], 10.0)
        self.assertEqual(means[2], 10.0)  # promotion spike masked
        self.assertEqual(means[5], 20.0)  # stock-out zero masked
        self.assertEqual(means[6], 20.0)

        unmasked = weekday_means(_fixture_series(), mask_promotions=False, mask_stockouts=False)
        self.assertEqual(unmasked[2], 20.0)  # (10 + 50 + 10 + 10) / 4
        self.assertEqual(unmasked[5], 15.0)  # (20 + 20 + 0 + 20) / 4

    def test_promotion_flag_and_stockout_mask(self) -> None:
        features = build_feature_set(_fixture_series(), validation_days=7)
        rows = features["series"]
        self.assertEqual(rows[9]["promotion_flag"], 1)
        self.assertEqual(rows[19]["stockout_mask"], 1)
        self.assertIsNone(rows[19]["demand"])
        self.assertEqual(features["n"], 26)
        observed_dates = {o["date"] for o in features["observations"]}
        self.assertNotIn(date(2026, 9, 16), observed_dates)
        self.assertNotIn(date(2026, 9, 26), observed_dates)

    def test_sigma_d_is_sample_standard_deviation(self) -> None:
        self.assertAlmostEqual(sample_std([2, 4, 4, 4, 5, 5, 7, 9]), math.sqrt(32 / 7), places=6)
        self.assertEqual(sample_std([5.0]), 0.0)

        features = build_feature_set(_fixture_series(), validation_days=7)
        # 19 days at 10 and 7 days at 20: s^2 = (sum y^2 - (sum y)^2 / n) / (n - 1)
        expected = math.sqrt((4700 - 330 ** 2 / 26) / 25)
        self.assertAlmostEqual(features["sigma_d"], round(expected, 4), places=4)

    def test_forecast_interval_uses_t_table(self) -> None:
        self.assertEqual(t_critical(15, 0.95), 2.131)
        self.assertEqual(t_critical(35, 0.95), 2.042)
        self.assertEqual(t_critical(500, 0.95), 1.960)
        lower, upper, t_value = forecast_interval(20.0, s=2.0, n=16, confidence=0.95)
        self.assertEqual(t_value, 2.131)
        self.assertAlmostEqual(lower, 18.9345, places=4)
        self.assertAlmostEqual(upper, 21.0655, places=4)

    def test_time_based_split_is_chronological(self) -> None:
        features = build_feature_set(_fixture_series(), validation_days=7)
        self.assertEqual(len(features["validation"]), 7)
        self.assertEqual(len(features["train"]), 19)
        self.assertLess(max(o["date"] for o in features["train"]), min(o["date"] for o in features["validation"]))

        train, validation = time_based_split([], validation_days=7)
        self.assertEqual((train, validation), ([], []))


if __name__ == "__main__":
    unittest.main()
