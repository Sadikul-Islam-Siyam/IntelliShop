"""
Unit tests for Days-of-Cover Projection Algorithm (#43).

Verifies:
1. Projected_Stock(t) = Available + Sum(Receipts) - Sum(Demand)
2. Expected Stock-Out Date (ESD) equals the first zero-crossing date.
3. Risk classification:
   - No ESD -> LOW
   - ESD <= today + L -> HIGH
   - ESD <= today + L + R -> MEDIUM
   - Otherwise -> LOW
4. Impact of scheduled receipts on delaying or preventing ESD.
5. POST /projection endpoint dispatch and validation.
"""

import datetime
import unittest

from analytics.stock_projection import calculate_stock_projection, handle_projection_request
from api.app import dispatch_request


class TestStockProjection(unittest.TestCase):
    def test_first_zero_crossing_equals_esd(self):
        """Tests that ESD is exactly the first day where projected stock crosses zero."""
        # Available stock = 50, constant demand = 15/day, L=3, R=5
        # Day 1: 50 - 15 = 35
        # Day 2: 35 - 15 = 20
        # Day 3: 20 - 15 = 5
        # Day 4: 5 - 15 = -10 (Crosses zero!) -> ESD should be Day 4
        start_date = "2026-11-01"
        forecasts = [15.0, 15.0, 15.0, 15.0, 15.0, 15.0]

        result = calculate_stock_projection(
            available_stock=50.0,
            forecasts=forecasts,
            lead_time_days=3,
            review_period_days=5,
            start_date=start_date,
        )

        expected_esd = "2026-11-05"  # 4 days after 2026-11-01
        self.assertEqual(result["estimated_stockout_date"], expected_esd)
        self.assertEqual(result["esd"], expected_esd)
        self.assertEqual(result["days_to_stockout"], 4)

        # Day 4 stock is -10
        proj = result["projected_stock"]
        self.assertEqual(len(proj), 6)
        self.assertEqual(proj[0]["projected_stock"], 35.0)
        self.assertEqual(proj[1]["projected_stock"], 20.0)
        self.assertEqual(proj[2]["projected_stock"], 5.0)
        self.assertEqual(proj[3]["projected_stock"], -10.0)

    def test_risk_classification_high_medium_low(self):
        """Tests HIGH (<= L), MEDIUM (<= L + R), and LOW (> L + R or no ESD)."""
        start_date = "2026-11-01"
        lead_time = 3
        review_period = 4

        # Case 1: ESD at Day 2 (2 <= 3 -> HIGH)
        high_result = calculate_stock_projection(
            available_stock=25.0,
            forecasts=[15.0, 15.0, 15.0, 15.0, 15.0],
            lead_time_days=lead_time,
            review_period_days=review_period,
            start_date=start_date,
        )
        self.assertEqual(high_result["risk_level"], "high")
        self.assertEqual(high_result["days_to_stockout"], 2)

        # Case 2: ESD at Day 5 (3 < 5 <= 3+4=7 -> MEDIUM)
        # Day 1: 70 - 15 = 55
        # Day 2: 55 - 15 = 40
        # Day 3: 40 - 15 = 25
        # Day 4: 25 - 15 = 10
        # Day 5: 10 - 15 = -5 -> Day 5 ESD
        med_result = calculate_stock_projection(
            available_stock=70.0,
            forecasts=[15.0, 15.0, 15.0, 15.0, 15.0, 15.0, 15.0],
            lead_time_days=lead_time,
            review_period_days=review_period,
            start_date=start_date,
        )
        self.assertEqual(med_result["risk_level"], "medium")
        self.assertEqual(med_result["days_to_stockout"], 5)

        # Case 3: ESD at Day 9 (9 > 7 -> LOW)
        low_result = calculate_stock_projection(
            available_stock=130.0,
            forecasts=[15.0] * 12,
            lead_time_days=lead_time,
            review_period_days=review_period,
            start_date=start_date,
        )
        self.assertEqual(low_result["risk_level"], "low")
        self.assertEqual(low_result["days_to_stockout"], 9)

        # Case 4: No ESD in horizon -> LOW
        no_esd_result = calculate_stock_projection(
            available_stock=500.0,
            forecasts=[15.0] * 10,
            lead_time_days=lead_time,
            review_period_days=review_period,
            start_date=start_date,
        )
        self.assertEqual(no_esd_result["risk_level"], "low")
        self.assertIsNone(no_esd_result["estimated_stockout_date"])

    def test_scheduled_receipts_prevent_stockout(self):
        """Tests that scheduled receipts delay or eliminate stock-out."""
        start_date = "2026-11-01"
        # Without receipts: 40 stock - 15/day crosses zero on Day 3
        # With receipt of 50 on Day 3:
        # Day 1: 40 - 15 = 25
        # Day 2: 25 - 15 = 10
        # Day 3: 10 + 50 - 15 = 45 -> prevents stockout!
        receipts = {"2026-11-04": 50.0}
        forecasts = [
            {"date": "2026-11-02", "demand": 15.0},
            {"date": "2026-11-03", "demand": 15.0},
            {"date": "2026-11-04", "demand": 15.0},
            {"date": "2026-11-05", "demand": 15.0},
        ]

        result = calculate_stock_projection(
            available_stock=40.0,
            forecasts=forecasts,
            lead_time_days=2,
            review_period_days=3,
            scheduled_receipts=receipts,
            start_date=start_date,
        )

        self.assertIsNone(result["estimated_stockout_date"])
        self.assertEqual(result["risk_level"], "low")
        self.assertEqual(result["projected_stock"][2]["projected_stock"], 45.0)

    def test_post_projection_endpoint_dispatch(self):
        """Tests POST /projection through dispatch_request."""
        payload = {
            "available_stock": 60.0,
            "forecasts": [20.0, 20.0, 20.0, 20.0],
            "lead_time_days": 2,
            "review_period_days": 5,
        }

        status, resp = dispatch_request("POST", "/projection", body=payload)
        self.assertEqual(status, 200)
        self.assertIn("projected_stock", resp)
        self.assertIn("estimated_stockout_date", resp)
        self.assertIn("risk_level", resp)
        # 60 stock - 20/day crosses zero on day 3
        self.assertEqual(resp["days_to_stockout"], 3)
        self.assertEqual(resp["risk_level"], "medium")

    def test_post_projection_validation_error(self):
        """Tests validation error when required fields are missing."""
        status, resp = dispatch_request("POST", "/projection", body={"available_stock": 50})
        self.assertEqual(status, 422)
        self.assertEqual(resp["error"]["code"], "VALIDATION_FAILED")


if __name__ == "__main__":
    unittest.main()
