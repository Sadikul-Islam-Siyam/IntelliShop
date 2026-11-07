"""Unit tests for Slow-Moving Detection Rules (#50) and Overstock Evaluation (#51)."""

import os
import sys
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from analytics.slow_moving import evaluate_slow_moving_and_overstock
from api.app import dispatch_request


class SlowMovingAndOverstockTests(unittest.TestCase):
    def test_low_sell_through_is_flagged_as_slow_moving(self) -> None:
        """#50: Product with sell-through below threshold (0.20) is flagged."""
        result = evaluate_slow_moving_and_overstock(
            product_id=10,
            branch_id=1,
            available_stock=100.0,
            total_sold=10.0,  # sell-through = 10 / (10 + 100) = 0.0909 < 0.20
            window_days=30,
            slow_moving_threshold=0.20,
            forecast_demand=40.0,
            cover_horizon_days=14,
        )
        self.assertTrue(result["is_slow_moving"])
        self.assertAlmostEqual(result["sell_through_rate"], 10.0 / 110.0, places=4)
        self.assertTrue(result["is_overstock"])
        self.assertEqual(result["surplus_quantity"], 60.0)
        self.assertEqual(result["severity"], "HIGH")

    def test_healthy_sell_through_and_balanced_stock_not_flagged(self) -> None:
        """Healthy velocity and stock within cover horizon is not flagged."""
        result = evaluate_slow_moving_and_overstock(
            product_id=11,
            branch_id=1,
            available_stock=50.0,
            total_sold=90.0,  # sell-through = 90 / 140 = 0.6429 >= 0.20
            window_days=30,
            slow_moving_threshold=0.20,
            forecast_demand=70.0,  # stock 50 <= forecast 70 -> no overstock
            cover_horizon_days=14,
        )
        self.assertFalse(result["is_slow_moving"])
        self.assertFalse(result["is_overstock"])
        self.assertEqual(result["surplus_quantity"], 0.0)
        self.assertEqual(result["severity"], "LOW")

    def test_post_overstock_run_endpoint_dispatch(self) -> None:
        """Verify POST /overstock/run in analytics-service/api/app.py."""
        payload = {
            "product_id": 5,
            "branch_id": 1,
            "available_stock": 120.0,
            "total_sold": 5.0,
            "window_days": 30,
            "forecast_demand": 28.0,
            "cover_horizon_days": 14,
        }
        status, body = dispatch_request("POST", "/overstock/run", payload)
        self.assertEqual(status, 200)
        self.assertTrue(body["is_slow_moving"])
        self.assertTrue(body["is_overstock"])
        self.assertEqual(body["surplus_quantity"], 92.0)


if __name__ == "__main__":
    unittest.main()

