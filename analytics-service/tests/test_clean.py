"""Unit tests for time-series cleaning and MAD outlier handling."""

from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from pipeline.clean import clean_daily_series


class TestCleanDailySeries(unittest.TestCase):
    def test_fills_missing_days_flags_stockouts_and_caps_outliers(self) -> None:
        fixture = [
            {"product_id": 1, "branch_id": 1, "date": "2026-10-01", "quantity": 10, "promotion": False, "stockout": False},
            {"product_id": 1, "branch_id": 1, "date": "2026-10-02", "quantity": 12, "promotion": False, "stockout": False},
            {"product_id": 1, "branch_id": 1, "date": "2026-10-04", "quantity": 11, "promotion": False, "stockout": False},
            {"product_id": 1, "branch_id": 1, "date": "2026-10-05", "quantity": 0, "promotion": False, "stockout": True, "closing_stock": 0},
            {"product_id": 1, "branch_id": 1, "date": "2026-10-06", "quantity": 9, "promotion": False, "stockout": False},
            {"product_id": 1, "branch_id": 1, "date": "2026-10-07", "quantity": 11, "promotion": False, "stockout": False},
            {"product_id": 1, "branch_id": 1, "date": "2026-10-08", "quantity": 85, "promotion": False, "stockout": False},
        ]

        cleaned = clean_daily_series(fixture, start_date="2026-10-01", end_date="2026-10-08")
        self.assertEqual(len(cleaned), 8)

        day_3 = next(r for r in cleaned if r["date"] == "2026-10-03")
        self.assertEqual(day_3["quantity"], 0.0)
        self.assertTrue(day_3["imputed_zero"])

        day_5 = next(r for r in cleaned if r["date"] == "2026-10-05")
        self.assertTrue(day_5["stockout"])
        self.assertTrue(day_5["excluded_from_demand"])

        day_8 = next(r for r in cleaned if r["date"] == "2026-10-08")
        self.assertTrue(day_8["outlier_capped"])
        self.assertLess(day_8["quantity"], 85.0)
        self.assertGreater(day_8["quantity"], 11.0)


if __name__ == "__main__":
    unittest.main()
