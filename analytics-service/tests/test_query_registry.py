"""Tests for Whitelisted Query Registry (#61)."""

import unittest

from nlq.query_registry import (
    QUERY_REGISTRY,
    QueryDefinition,
    build_parameterized_query,
    get_query_definition,
    validate_query_safety,
)


class TestQueryRegistry(unittest.TestCase):
    def test_all_supported_intents_have_whitelisted_definitions(self):
        expected_intents = [
            "sales_summary",
            "top_selling_products",
            "inventory_summary",
            "low_stock_alerts",
            "stock_out_risk",
            "overstock_slow_moving",
            "expiring_batches_markdown",
            "supplier_ranking",
            "sales_anomalies",
            "phantom_inventory",
            "market_basket_cross_sell",
        ]
        for intent in expected_intents:
            defn = get_query_definition(intent)
            self.assertIsNotNone(defn, f"Missing query definition for intent: {intent}")
            self.assertIsInstance(defn, QueryDefinition)
            self.assertIn("SELECT ", defn.sql_template)
            self.assertNotIn("DROP ", defn.sql_template)
            self.assertNotIn("DELETE ", defn.sql_template)
            self.assertNotIn("UPDATE ", defn.sql_template)
            self.assertNotIn("INSERT ", defn.sql_template)
            self.assertGreater(defn.max_limit, 0)
            self.assertLessEqual(defn.timeout_seconds, 10.0)

    def test_row_limit_is_clamped_and_never_exceeds_max(self):
        sql, params, timeout = build_parameterized_query("top_selling_products", {"limit": 9999})
        defn = get_query_definition("top_selling_products")
        self.assertEqual(params["row_limit"], defn.max_limit)
        self.assertLessEqual(params["row_limit"], 50)
        self.assertIn(":row_limit", sql)
        self.assertEqual(timeout, 5.0)

    def test_unknown_intent_raises_value_error(self):
        with self.assertRaises(ValueError):
            build_parameterized_query("malicious_intent_drop_tables", {})

    def test_validate_query_safety_blocks_injection_strings(self):
        safe_slots = {"branch_id": 1, "start_date": "2026-11-01", "limit": 10}
        self.assertTrue(validate_query_safety("sales_summary", safe_slots))

        injection_slots = {"branch_id": "1; DROP TABLE users; --", "limit": 10}
        self.assertFalse(validate_query_safety("sales_summary", injection_slots))

        union_slots = {"risk_level": "HIGH' UNION SELECT password FROM users --"}
        self.assertFalse(validate_query_safety("stock_out_risk", union_slots))


if __name__ == "__main__":
    unittest.main()
