"""Unit tests for dynamic replenishment, safety stock (ROP), and sequential constraints."""

import os
import sys
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from analytics.replenishment import (
    CONFLICT_FLAG_MESSAGE,
    calculate_reorder_recommendation,
    handle_reorder_request,
)
from api.app import dispatch_request


class ReplenishmentCalculationTests(unittest.TestCase):
    def test_worked_example_exact_values(self) -> None:
        """
        Section 4.3 Worked Example:
        z = 1.645 (95% service level), d_bar = 20, sigma_d = 5, L = 4, R = 7,
        Available = 60, MOQ = 100, Multiple = 12:
          SS = 1.645 * 5 * sqrt(4) = 16.45
          ROP = 20 * 4 + 16.45 = 96.45
          Target = 20 * 11 + 16.45 = 236.45
          Raw = 236.45 - 60 = 176.45
          After MOQ: 176.45
          After Multiple of 12: ceil(176.45 / 12) * 12 = 15 * 12 = 180
        """
        result = calculate_reorder_recommendation(
            forecast_demand=20.0,
            demand_stddev=5.0,
            service_level_z=1.645,
            lead_time_days=4,
            review_period_days=7,
            stock=60.0,
            on_order=0.0,
            moq=100,
            order_multiple=12,
            capacity=None,
            shelf_life_days=None,
        )

        self.assertAlmostEqual(result["safety_stock"], 16.45, places=2)
        self.assertAlmostEqual(result["reorder_point"], 96.45, places=2)
        self.assertAlmostEqual(result["target_stock"], 236.45, places=2)
        self.assertAlmostEqual(result["raw_quantity"], 176.45, places=2)
        self.assertAlmostEqual(result["after_moq"], 176.45, places=2)
        self.assertEqual(result["after_multiple"], 180)
        self.assertEqual(result["final_quantity"], 180)
        self.assertFalse(result["conflict"])
        self.assertIsNone(result["conflict_flag"])
        self.assertIn("order_multiple", result["constraints"])

    def test_available_stock_accounts_for_reserved_and_open_po(self) -> None:
        """Available = on-hand - reserved + open PO quantity."""
        result = calculate_reorder_recommendation(
            forecast_demand=20.0,
            demand_stddev=5.0,
            service_level_z=1.645,
            lead_time_days=4,
            review_period_days=7,
            stock=50.0,
            reserved=15.0,
            on_order=25.0,  # Available = 50 - 15 + 25 = 60
            moq=100,
            order_multiple=12,
        )
        self.assertAlmostEqual(result["available_stock"], 60.0, places=2)
        self.assertAlmostEqual(result["raw_quantity"], 176.45, places=2)
        self.assertEqual(result["final_quantity"], 180)

    def test_no_order_when_available_exceeds_target_stock(self) -> None:
        """When Available >= Target Stock, Raw Qty = 0 and MOQ is not applied."""
        result = calculate_reorder_recommendation(
            forecast_demand=10.0,
            demand_stddev=2.0,
            service_level_z=1.645,
            lead_time_days=4,
            review_period_days=7,
            stock=200.0,
            on_order=0.0,
            moq=50,
            order_multiple=10,
        )
        self.assertEqual(result["raw_quantity"], 0.0)
        self.assertEqual(result["final_quantity"], 0)
        self.assertEqual(result["constraints"], [])

    def test_sequential_constraints_moq_and_multiple(self) -> None:
        """Step 1 (MOQ) raises Q to MOQ, Step 2 rounds up to nearest order multiple."""
        result = calculate_reorder_recommendation(
            forecast_demand=10.0,
            demand_stddev=2.0,
            service_level_z=1.645,
            lead_time_days=4,
            review_period_days=7,
            stock=90.0,
            on_order=0.0,
            moq=50,
            order_multiple=12,
        )
        # SS = 1.645 * 2 * 2 = 6.58, Target = 110 + 6.58 = 116.58, Raw = 26.58
        # Step 1 (MOQ): max(26.58, 50) = 50
        # Step 2 (Multiple): ceil(50 / 12) * 12 = 5 * 12 = 60
        self.assertAlmostEqual(result["raw_quantity"], 26.58, places=2)
        self.assertEqual(result["after_moq"], 50.0)
        self.assertEqual(result["after_multiple"], 60)
        self.assertEqual(result["final_quantity"], 60)
        self.assertIn("moq", result["constraints"])
        self.assertIn("order_multiple", result["constraints"])

    def test_capacity_constraint_caps_order_above_moq(self) -> None:
        """Step 3 caps Q at (max_capacity - Available) when still >= MOQ."""
        result = calculate_reorder_recommendation(
            forecast_demand=20.0,
            demand_stddev=5.0,
            service_level_z=1.645,
            lead_time_days=4,
            review_period_days=7,
            stock=60.0,
            on_order=0.0,
            moq=100,
            order_multiple=12,
            capacity=200.0,  # max order = 200 - 60 = 140 (>= MOQ 100)
        )
        self.assertEqual(result["after_multiple"], 180)
        self.assertEqual(result["after_capacity"], 140)
        self.assertEqual(result["final_quantity"], 140)
        self.assertFalse(result["conflict"])
        self.assertIn("capacity", result["constraints"])

    def test_perishable_shelf_life_constraint_caps_order(self) -> None:
        """Step 4 caps Q at cumulative forecast demand until expiry date."""
        result = calculate_reorder_recommendation(
            forecast_demand=20.0,
            demand_stddev=5.0,
            service_level_z=1.645,
            lead_time_days=4,
            review_period_days=7,
            stock=60.0,
            on_order=0.0,
            moq=100,
            order_multiple=12,
            capacity=300.0,
            shelf_life_days=6,  # 6 * 20 = 120 (>= MOQ 100)
        )
        self.assertEqual(result["after_multiple"], 180)
        self.assertEqual(result["after_capacity"], 180)
        self.assertEqual(result["after_shelf_life"], 120)
        self.assertEqual(result["final_quantity"], 120)
        self.assertFalse(result["conflict"])
        self.assertIn("shelf_life", result["constraints"])

    def test_conflict_flag_when_capacity_reduces_below_moq(self) -> None:
        """
        If constraint (3) reduces Q below MOQ, flag with
        'cannot satisfy MOQ within capacity' and do not silently place an invalid order.
        """
        result = calculate_reorder_recommendation(
            forecast_demand=20.0,
            demand_stddev=5.0,
            service_level_z=1.645,
            lead_time_days=4,
            review_period_days=7,
            stock=60.0,
            on_order=0.0,
            moq=100,
            order_multiple=12,
            capacity=130.0,  # max capacity headroom = 130 - 60 = 70 < MOQ 100
            shelf_life_days=None,
        )
        self.assertTrue(result["conflict"])
        self.assertEqual(result["conflict_flag"], CONFLICT_FLAG_MESSAGE)
        self.assertIn(CONFLICT_FLAG_MESSAGE, result["constraints"])
        self.assertEqual(result["final_quantity"], 0)
        self.assertEqual(result["capped_quantity"], 70)

    def test_conflict_flag_when_shelf_life_reduces_below_moq(self) -> None:
        """
        If constraint (4) reduces Q below MOQ, flag with
        'cannot satisfy MOQ within capacity' and do not silently place an invalid order.
        """
        result = calculate_reorder_recommendation(
            forecast_demand=20.0,
            demand_stddev=5.0,
            service_level_z=1.645,
            lead_time_days=4,
            review_period_days=7,
            stock=60.0,
            on_order=0.0,
            moq=100,
            order_multiple=12,
            capacity=500.0,
            shelf_life_days=4,  # 4 * 20 = 80 < MOQ 100
        )
        self.assertTrue(result["conflict"])
        self.assertEqual(result["conflict_flag"], CONFLICT_FLAG_MESSAGE)
        self.assertIn("shelf_life", result["constraints"])
        self.assertIn(CONFLICT_FLAG_MESSAGE, result["constraints"])
        self.assertEqual(result["final_quantity"], 0)
        self.assertEqual(result["capped_quantity"], 80)

    def test_post_reorder_endpoint_contract_and_validation(self) -> None:
        """Verify POST /reorder in analytics-service/api/app.py."""
        payload = {
            "forecast_demand": 20.0,
            "demand_stddev": 5.0,
            "service_level_z": 1.645,
            "lead_time_days": 4,
            "review_period_days": 7,
            "stock": 60.0,
            "on_order": 0.0,
            "moq": 100,
            "order_multiple": 12,
            "capacity": None,
            "shelf_life_days": None,
        }
        status, body = dispatch_request("POST", "/reorder", payload)
        self.assertEqual(status, 200)
        for required_key in (
            "safety_stock",
            "reorder_point",
            "target_stock",
            "raw_quantity",
            "final_quantity",
            "constraints",
        ):
            self.assertIn(required_key, body)
        self.assertEqual(body["final_quantity"], 180)

        # Missing required fields returns 422
        bad_status, bad_body = handle_reorder_request({"forecast_demand": 20.0})
        self.assertEqual(bad_status, 422)
        self.assertEqual(bad_body["status"], "error")


if __name__ == "__main__":
    unittest.main()

