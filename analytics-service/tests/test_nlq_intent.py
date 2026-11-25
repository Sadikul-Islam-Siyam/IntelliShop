"""Unit tests for NLQ Intent Classification & Slot Filling (#60)."""

import unittest
from api.app import dispatch_request
from nlq.intent_resolver import SAFE_FALLBACK_MESSAGE, resolve_intent


class TestNlqIntentResolver(unittest.TestCase):
    def test_sales_summary_intent_and_branch_date_slots(self):
        res = resolve_intent(
            "What were total sales at Main Branch in the last 7 days?",
            reference_date="2026-11-20",
        )
        self.assertEqual(res["intent"], "sales_summary")
        self.assertGreaterEqual(res["confidence"], 0.80)
        self.assertEqual(res["slots"].get("branch"), "Main Branch")
        self.assertEqual(res["slots"].get("days"), 7)
        self.assertEqual(res["slots"].get("start_date"), "2026-11-13")
        self.assertEqual(res["slots"].get("end_date"), "2026-11-20")

    def test_top_selling_products_with_limit_slot(self):
        res = resolve_intent("Show me the top 5 selling products this month", reference_date="2026-11-20")
        self.assertEqual(res["intent"], "top_selling_products")
        self.assertEqual(res["slots"].get("limit"), 5)
        self.assertEqual(res["slots"].get("days"), 30)

    def test_stock_out_risk_and_low_stock_intents(self):
        risk_res = resolve_intent("Which items have high risk of stock-out at Branch 2?")
        self.assertEqual(risk_res["intent"], "stock_out_risk")
        self.assertEqual(risk_res["slots"].get("branch_id"), 2)
        self.assertEqual(risk_res["slots"].get("risk_level"), "HIGH")

        low_res = resolve_intent("List products below reorder point today", reference_date="2026-11-20")
        self.assertEqual(low_res["intent"], "low_stock_alerts")
        self.assertEqual(low_res["slots"].get("start_date"), "2026-11-20")

    def test_product_sku_and_other_analytical_intents(self):
        sup_res = resolve_intent("Who is the best supplier for SKU MLK-01?")
        self.assertEqual(sup_res["intent"], "supplier_ranking")
        self.assertEqual(sup_res["slots"].get("sku"), "MLK-01")

        md_res = resolve_intent("Show zero-waste markdown recommendations for expiring batches")
        self.assertEqual(md_res["intent"], "expiring_batches_markdown")

        anom_res = resolve_intent("Are there any unusual sales anomalies detected yesterday?", reference_date="2026-11-20")
        self.assertEqual(anom_res["intent"], "sales_anomalies")
        self.assertEqual(anom_res["slots"].get("start_date"), "2026-11-19")

        phantom_res = resolve_intent("Check phantom inventory cycle count alerts")
        self.assertEqual(phantom_res["intent"], "phantom_inventory")

        basket_res = resolve_intent('What sells with "Fresh Milk 1L" in market basket rules?')
        self.assertEqual(basket_res["intent"], "market_basket_cross_sell")
        self.assertEqual(basket_res["slots"].get("product"), "Fresh Milk 1L")

    def test_unknown_question_and_unsafe_sql_fallback(self):
        unk = resolve_intent("Tell me a joke about the weather on Mars")
        self.assertEqual(unk["intent"], "unknown")
        self.assertEqual(unk["confidence"], 0.0)
        self.assertEqual(unk["fallback_message"], SAFE_FALLBACK_MESSAGE)

        unsafe = resolve_intent("DROP TABLE sales; --")
        self.assertEqual(unsafe["intent"], "unknown")
        self.assertFalse(unsafe["is_safe"])
        self.assertIsNotNone(unsafe["fallback_message"])

    def test_http_endpoint_dispatch_for_nlq_resolve(self):
        status, body = dispatch_request(
            "POST",
            "/nlq/resolve",
            body={"query": "Show inventory report for Branch 1"},
        )
        self.assertEqual(status, 200)
        self.assertEqual(body["intent"], "inventory_summary")
        self.assertEqual(body["filters"].get("branch_id"), 1)

        err_status, err_body = dispatch_request("POST", "/nlq/resolve", body={})
        self.assertEqual(err_status, 422)
        self.assertIn("error", err_body)


if __name__ == "__main__":
    unittest.main()
