"""Unit tests for Supplier Evaluation: Eligibility (#47), SAW Scoring (#48), and Context Weights (#68)."""

import os
import sys
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from analytics.supplier_eligibility import (
    NO_ELIGIBLE_SUPPLIERS_ALERT,
    filter_eligible_suppliers,
)
from analytics.supplier_scoring import evaluate_suppliers, score_and_rank_suppliers
from analytics.weight_modes import (
    MODE_NORMAL,
    MODE_PANIC,
    MODE_WARNING,
    select_context_weights,
)
from api.app import dispatch_request


class SupplierEvaluationTests(unittest.TestCase):
    def test_eligibility_filter_excludes_inactive_moq_and_lead_time_failures(self) -> None:
        """#47: Inactive, MOQ-failing, and lead-time-failing suppliers are excluded."""
        candidates = [
            {"supplier_id": 1, "name": "Active Eligible", "status": "active", "price": 95.0, "lead_time_days": 5, "moq": 50, "fulfillment_rate": 0.92},
            {"supplier_id": 2, "name": "Inactive Supplier", "status": "inactive", "price": 80.0, "lead_time_days": 4, "moq": 20, "fulfillment_rate": 0.98},
            {"supplier_id": 3, "name": "MOQ Too High", "status": "active", "price": 88.0, "lead_time_days": 5, "moq": 200, "fulfillment_rate": 0.95},
            {"supplier_id": 4, "name": "Lead Time Too Slow", "status": "active", "price": 85.0, "lead_time_days": 21, "moq": 30, "fulfillment_rate": 0.96},
        ]

        res = filter_eligible_suppliers(
            candidates=candidates,
            required_quantity=100,
            max_acceptable_lead_days=14,
        )

        self.assertTrue(res["has_eligible"])
        self.assertIsNone(res["alert"])
        self.assertEqual(len(res["eligible"]), 1)
        self.assertEqual(res["eligible"][0]["supplier_id"], 1)
        self.assertEqual(len(res["excluded"]), 3)

        # When none eligible, alert is returned
        none_res = filter_eligible_suppliers(
            candidates=[candidates[1], candidates[2]],
            required_quantity=100,
            max_acceptable_lead_days=14,
        )
        self.assertFalse(none_res["has_eligible"])
        self.assertEqual(none_res["alert"], NO_ELIGIBLE_SUPPLIERS_ALERT)

    def test_context_aware_weight_selector_modes(self) -> None:
        """
        #68:
        stock > ROP -> NORMAL (.70 / .10 / .20)
        stock <= ROP -> WARNING (.30 / .30 / .40)
        stock <= 10% of ROP -> PANIC (.10 / .70 / .20)
        """
        normal = select_context_weights(available_stock=120, reorder_point=100)
        self.assertEqual(normal["mode"], MODE_NORMAL)
        self.assertEqual(normal["weights"], {"price": 0.70, "speed": 0.10, "reliability": 0.20})

        warning_exact = select_context_weights(available_stock=100, reorder_point=100)
        self.assertEqual(warning_exact["mode"], MODE_WARNING)
        self.assertEqual(warning_exact["weights"], {"price": 0.30, "speed": 0.30, "reliability": 0.40})

        warning_mid = select_context_weights(available_stock=25, reorder_point=100)
        self.assertEqual(warning_mid["mode"], MODE_WARNING)

        panic_exact = select_context_weights(available_stock=10, reorder_point=100)
        self.assertEqual(panic_exact["mode"], MODE_PANIC)
        self.assertEqual(panic_exact["weights"], {"price": 0.10, "speed": 0.70, "reliability": 0.20})

        panic_zero = select_context_weights(available_stock=0, reorder_point=100)
        self.assertEqual(panic_zero["mode"], MODE_PANIC)

    def test_saw_worked_example_across_all_three_modes(self) -> None:
        """
        Section 4.4 Worked Example (Page 14 of Master Plan):
        Supplier A: Price=100, Lead=5, Fulfillment=0.90 -> NORMAL 0.893, WARNING 0.835, PANIC 0.701
        Supplier B: Price=92,  Lead=7, Fulfillment=0.95 -> NORMAL 0.943, WARNING 0.829, PANIC 0.600
        Supplier C: Price=105, Lead=3, Fulfillment=0.85 -> NORMAL 0.892, WARNING 0.921, PANIC 0.967
        """
        suppliers = [
            {"supplier_id": "A", "name": "Supplier A", "price": 100.0, "lead_time_days": 5, "fulfillment_rate": 0.90, "status": "active", "moq": 10},
            {"supplier_id": "B", "name": "Supplier B", "price": 92.0, "lead_time_days": 7, "fulfillment_rate": 0.95, "status": "active", "moq": 10},
            {"supplier_id": "C", "name": "Supplier C", "price": 105.0, "lead_time_days": 3, "fulfillment_rate": 0.85, "status": "active", "moq": 10},
        ]

        # 1. NORMAL mode (stock 150 > ROP 100) -> ranks B first
        normal_eval = evaluate_suppliers(
            product_id=1,
            branch_id=1,
            required_quantity=50,
            candidates=suppliers,
            available_stock=150,
            reorder_point=100,
        )
        self.assertEqual(normal_eval["mode"], "NORMAL")
        scores_normal = {r["supplier_id"]: r["score"] for r in normal_eval["ranking"]}
        self.assertAlmostEqual(scores_normal["A"], 0.893, places=3)
        self.assertAlmostEqual(scores_normal["B"], 0.943, places=3)
        self.assertAlmostEqual(scores_normal["C"], 0.892, places=3)
        self.assertEqual(normal_eval["ranking"][0]["supplier_id"], "B")

        # 2. WARNING mode (stock 50 <= ROP 100) -> ranks C first
        warning_eval = evaluate_suppliers(
            product_id=1,
            branch_id=1,
            required_quantity=50,
            candidates=suppliers,
            available_stock=50,
            reorder_point=100,
        )
        self.assertEqual(warning_eval["mode"], "WARNING")
        scores_warning = {r["supplier_id"]: r["score"] for r in warning_eval["ranking"]}
        self.assertAlmostEqual(scores_warning["A"], 0.835, places=3)
        self.assertAlmostEqual(scores_warning["B"], 0.829, places=3)
        self.assertAlmostEqual(scores_warning["C"], 0.921, places=3)
        self.assertEqual(warning_eval["ranking"][0]["supplier_id"], "C")

        # 3. PANIC mode (stock 5 <= 10% of ROP 100) -> ranks C first
        panic_eval = evaluate_suppliers(
            product_id=1,
            branch_id=1,
            required_quantity=50,
            candidates=suppliers,
            available_stock=5,
            reorder_point=100,
        )
        self.assertEqual(panic_eval["mode"], "PANIC")
        scores_panic = {r["supplier_id"]: r["score"] for r in panic_eval["ranking"]}
        self.assertAlmostEqual(scores_panic["A"], 0.701, places=3)
        self.assertAlmostEqual(scores_panic["B"], 0.600, places=3)
        self.assertAlmostEqual(scores_panic["C"], 0.967, places=3)
        self.assertEqual(panic_eval["ranking"][0]["supplier_id"], "C")

    def test_saw_tie_breaks_by_lower_price(self) -> None:
        """When composite SAW scores tie, lower price wins."""
        tied_candidates = [
            {"supplier_id": 10, "name": "Higher Price", "price": 100.0, "lead_time_days": 5, "fulfillment_rate": 0.90},
            {"supplier_id": 20, "name": "Lower Price", "price": 50.0, "lead_time_days": 10, "fulfillment_rate": 0.90},
        ]
        # With price=0.50, speed=0.50, reliability=0.0:
        # Supplier 10: r_p = 0.5, r_l = 1.0 -> S = 0.25 + 0.50 = 0.75
        # Supplier 20: r_p = 1.0, r_l = 0.5 -> S = 0.50 + 0.25 = 0.75
        ranked = score_and_rank_suppliers(
            tied_candidates,
            {"price": 0.50, "speed": 0.50, "reliability": 0.0},
        )
        self.assertEqual(ranked[0]["supplier_id"], 20)
        self.assertEqual(ranked[1]["supplier_id"], 10)

    def test_post_suppliers_evaluate_endpoint_contract(self) -> None:
        """Verify POST /suppliers/evaluate endpoint in analytics-service/api/app.py."""
        payload = {
            "product_id": 1,
            "branch_id": 1,
            "required_quantity": 50,
            "available_stock": 120,
            "reorder_point": 80,
            "candidates": [
                {"supplier_id": 1, "name": "Northstar", "price": 92.0, "lead_time_days": 7, "moq": 20, "fulfillment_rate": 0.95, "status": "active"},
                {"supplier_id": 2, "name": "Harvest", "price": 105.0, "lead_time_days": 3, "moq": 20, "fulfillment_rate": 0.85, "status": "active"},
            ],
        }
        status, body = dispatch_request("POST", "/suppliers/evaluate", payload)
        self.assertEqual(status, 200)
        for key in ("mode", "weights", "eligible", "ranking"):
            self.assertIn(key, body)
        self.assertEqual(body["mode"], "NORMAL")
        self.assertEqual(body["ranking"][0]["supplier_id"], 1)


if __name__ == "__main__":
    unittest.main()

