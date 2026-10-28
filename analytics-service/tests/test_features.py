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
    compute_forecast_interval,
    compute_one_step_rmse,
    compute_weekday_indices,
)


class TestFeatureEngineering(unittest.TestCase):
    def test_weekday_indices_promo_flag_stockout_masking_and_sigma_d(self) -> None:
        start = date(2026, 9, 7)
        pattern = [10.0, 10.0, 10.0, 10.0, 10.0, 20.0, 20.0]
        series = []
        for i in range(28):
            d = start + timedelta(days=i)
            wd = d.weekday()
            qty = pattern[wd]
            promo = False
            stockout = False
            if i == 9:
                qty = 50.0
                promo = True
            if i == 19:
                qty = 0.0
                stockout = True

            series.append(
                {
                    "date": d.isoformat(),
                    "quantity": qty,
                    "promotion": promo,
                    "stockout": stockout,
                }
            )

        indices = compute_weekday_indices(series, mask_promotions=True, mask_stockouts=True)
        self.assertAlmostEqual(indices[5] / indices[0], 2.0, places=4)
        self.assertAlmostEqual(indices[6] / indices[0], 2.0, places=4)
        self.assertAlmostEqual(sum(indices.values()) / 7.0, 1.0, places=5)

        feature_set = build_feature_set(series, validation_days=7)
        self.assertEqual(len(feature_set["train"]), 21)
        self.assertEqual(len(feature_set["validation"]), 7)

        enriched = feature_set["series"]
        self.assertEqual(enriched[9]["promotion_flag"], 1)
        self.assertEqual(enriched[19]["stockout_mask"], 1)
        self.assertGreater(enriched[19]["effective_quantity"], 15.0)

        actuals = [10.0, 12.0, 14.0, 10.0]
        preds = [10.0, 10.0, 12.0, 14.0]
        rmse = compute_one_step_rmse(actuals, preds)
        self.assertAlmostEqual(rmse, math.sqrt(6.0), places=5)

        low, high = compute_forecast_interval(predicted_demand=20.0, sigma_d=2.0, horizon_step=4, z_value=1.645)
        self.assertAlmostEqual(low, 13.42, places=2)
        self.assertAlmostEqual(high, 26.58, places=2)


if __name__ == "__main__":
    unittest.main()
