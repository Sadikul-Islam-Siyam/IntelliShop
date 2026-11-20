"""Tests for Phantom Inventory Detection (#70)."""

import unittest
from analytics.phantom_inventory import detect_phantom_inventory


class TestPhantomInventoryDetection(unittest.TestCase):
    def test_phantom_candidate_detected_with_persistence_and_stock(self):
        # 30 days of baseline sales averaging 15 units/day (std ~ 2.0)
        baseline = [15.0, 16.0, 14.0, 15.0, 17.0, 13.0, 15.0, 16.0, 14.0, 15.0] * 3  # 30 days
        # Followed by 3 consecutive days of 0 sales
        recent = [0.0, 0.0, 0.0]
        daily_sales = baseline + recent
        recorded_stock = 45.0  # System claims we have 45 on hand

        res = detect_phantom_inventory(
            daily_sales=daily_sales,
            recorded_stock=recorded_stock,
            baseline_days=30,
            z_threshold=-2.0,
            persistence_days=3,
            min_history_days=30,
        )

        self.assertTrue(res["is_phantom_candidate"])
        self.assertEqual(res["trigger_action"], "create_cycle_count")
        self.assertTrue(res["never_change_stock"])
        self.assertLessEqual(res["z_score"], -2.0)
        self.assertEqual(res["persistence_days"], 3)

    def test_stock_zero_guard(self):
        daily_sales = [15.0] * 30 + [0.0, 0.0, 0.0]
        res = detect_phantom_inventory(daily_sales, recorded_stock=0.0)

        self.assertFalse(res["is_phantom_candidate"])
        self.assertEqual(res["reason"], "recorded_stock_zero_or_negative")

    def test_insufficient_history_guard(self):
        # Only 10 days history
        daily_sales = [15.0] * 7 + [0.0, 0.0, 0.0]
        res = detect_phantom_inventory(daily_sales, recorded_stock=20.0, min_history_days=30)

        self.assertFalse(res["is_phantom_candidate"])
        self.assertEqual(res["reason"], "insufficient_history")

    def test_promo_and_unavailability_guards(self):
        daily_sales = [15.0] * 30 + [0.0, 0.0, 0.0]

        # Blocked by active price increase / promo change
        res1 = detect_phantom_inventory(
            daily_sales,
            recorded_stock=25.0,
            has_active_promotion_or_price_spike=True,
        )
        self.assertFalse(res1["is_phantom_candidate"])
        self.assertEqual(res1["reason"], "external_pricing_or_promo_change")

        # Blocked by recorded intentional unavailability
        res2 = detect_phantom_inventory(
            daily_sales,
            recorded_stock=25.0,
            has_recorded_unavailability=True,
        )
        self.assertFalse(res2["is_phantom_candidate"])
        self.assertEqual(res2["reason"], "recorded_unavailability")

    def test_persistence_guard_fails_when_not_consecutive(self):
        # 2 days of 0 sales, but day 3 bounced back to 15
        daily_sales = [14.0, 16.0] * 15 + [0.0, 0.0, 15.0]
        res = detect_phantom_inventory(daily_sales, recorded_stock=25.0, persistence_days=3)

        self.assertFalse(res["is_phantom_candidate"])
        self.assertEqual(res["reason"], "persistence_criteria_not_met")


if __name__ == "__main__":
    unittest.main()
