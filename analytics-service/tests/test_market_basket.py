"""Tests for Association Rule Mining (#55)."""

import unittest
from analytics.market_basket import mine_association_rules


class TestMarketBasketMining(unittest.TestCase):
    def test_support_confidence_lift_on_known_fixture(self):
        # Known transaction fixture:
        # T1: Milk, Bread
        # T2: Milk, Bread, Butter
        # T3: Milk
        # T4: Bread, Butter
        transactions = [
            ["Milk", "Bread"],
            ["Milk", "Bread", "Butter"],
            ["Milk"],
            ["Bread", "Butter"],
        ]

        rules = mine_association_rules(
            transactions,
            min_support=0.25,
            min_confidence=0.50,
            min_lift=0.50,
        )

        self.assertNotEmpty = self.assertGreater(len(rules), 0)

        # Find Butter -> Bread rule
        butter_bread = next(
            (r for r in rules if r["antecedent"] == "Butter" and r["consequent"] == "Bread"),
            None
        )
        self.assertIsNotNone(butter_bread)
        self.assertEqual(butter_bread["support"], 0.5000)      # 2/4
        self.assertEqual(butter_bread["confidence"], 1.0000)   # 2/2
        self.assertAlmostEqual(butter_bread["lift"], 1.3333, places=3)  # 1.0 / 0.75

        # Find Bread -> Butter rule
        bread_butter = next(
            (r for r in rules if r["antecedent"] == "Bread" and r["consequent"] == "Butter"),
            None
        )
        self.assertIsNotNone(bread_butter)
        self.assertEqual(bread_butter["support"], 0.5000)      # 2/4
        self.assertAlmostEqual(bread_butter["confidence"], 0.6667, places=3) # 2/3
        self.assertAlmostEqual(bread_butter["lift"], 1.3333, places=3)       # 0.6667 / 0.50

    def test_min_threshold_filtering(self):
        transactions = [
            ["A", "B"],
            ["A", "C"],
            ["B", "C"],
        ]

        # Require lift >= 1.5 (none should qualify here since lift is <= 1.0)
        rules = mine_association_rules(transactions, min_lift=1.5)
        self.assertEqual(len(rules), 0)


if __name__ == "__main__":
    unittest.main()
