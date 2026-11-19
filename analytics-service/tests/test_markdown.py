"""Tests for Zero-Waste Markdown Recommendation (#69)."""

import unittest
from analytics.markdown import (
    calculate_markdown_for_batch,
    evaluate_batch_markdowns,
)


class TestMarkdownRecommendation(unittest.TestCase):
    def test_worked_example_matches_design_spec(self):
        # Spec example: batch qty 120, avg 4/day (120 over 30d), 10 days left -> 35% markdown
        res = calculate_markdown_for_batch(
            batch_qty=120.0,
            total_sales_in_baseline=120.0,  # 120 / 30 = 4/day
            days_remaining=10,
            baseline_days=30,
            horizon_days=14,
            max_markdown_pct=40.0,
            step_pct=5.0,
            approval_threshold_pct=30.0,
        )

        self.assertFalse(res["is_expired"])
        self.assertEqual(res["avg_daily_sales"], 4.0)
        self.assertEqual(res["projected_sales"], 40.0)
        self.assertEqual(res["surplus_qty"], 80.0)
        self.assertEqual(res["markdown_pct"], 35.0)
        self.assertEqual(res["approval_authority"], "Super Admin")  # 35% > 30% threshold

    def test_branch_manager_authority_below_threshold(self):
        # Small surplus yielding <= 30% markdown
        res = calculate_markdown_for_batch(
            batch_qty=50.0,
            total_sales_in_baseline=90.0,  # 3/day
            days_remaining=10,             # projected 30, surplus 20
            baseline_days=30,
            horizon_days=14,
            max_markdown_pct=40.0,
            step_pct=5.0,
            approval_threshold_pct=30.0,
        )

        self.assertLessEqual(res["markdown_pct"], 30.0)
        self.assertEqual(res["approval_authority"], "Branch Manager")

    def test_no_markdown_when_projected_exceeds_batch(self):
        # 5/day * 10 days = 50 projected >= 40 batch qty -> surplus 0
        res = calculate_markdown_for_batch(
            batch_qty=40.0,
            total_sales_in_baseline=150.0,
            days_remaining=10,
            baseline_days=30,
        )

        self.assertEqual(res["surplus_qty"], 0.0)
        self.assertEqual(res["markdown_pct"], 0.0)
        self.assertEqual(res["status"], "normal_sellthrough")

    def test_expired_batch_is_excluded(self):
        res = calculate_markdown_for_batch(
            batch_qty=50.0,
            total_sales_in_baseline=100.0,
            days_remaining=-1,
        )

        self.assertTrue(res["is_expired"])
        self.assertEqual(res["markdown_pct"], 0.0)
        self.assertEqual(res["status"], "expired_excluded")


if __name__ == "__main__":
    unittest.main()
