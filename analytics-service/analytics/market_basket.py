"""Association Rule Mining for Market Basket Analysis (#55).

Calculates itemset support, confidence, and lift across transaction baskets:
  support(A -> B) = P(A and B) = count(A and B) / N
  confidence(A -> B) = P(B | A) = count(A and B) / count(A)
  lift(A -> B) = confidence(A -> B) / P(B) = P(A and B) / (P(A) * P(B))
"""

from __future__ import annotations

from collections import Counter, defaultdict
from typing import Any, Dict, List, Sequence, Set, Tuple


DEFAULT_MIN_SUPPORT = 0.01
DEFAULT_MIN_CONFIDENCE = 0.10
DEFAULT_MIN_LIFT = 1.00


def mine_association_rules(
    transactions: Sequence[Sequence[Any]],
    min_support: float = DEFAULT_MIN_SUPPORT,
    min_confidence: float = DEFAULT_MIN_CONFIDENCE,
    min_lift: float = DEFAULT_MIN_LIFT,
) -> List[Dict[str, Any]]:
    """Mine 2-item association rules from transaction baskets."""
    n_transactions = len(transactions)
    if n_transactions == 0:
        return []

    item_counts: Counter = Counter()
    pair_counts: Counter = Counter()

    for basket in transactions:
        unique_items = sorted(list(set(basket)))
        for item in unique_items:
            item_counts[item] += 1

        # Count item pairs
        for i in range(len(unique_items)):
            for j in range(i + 1, len(unique_items)):
                pair_counts[(unique_items[i], unique_items[j])] += 1

    rules: List[Dict[str, Any]] = []

    for (item_a, item_b), count_ab in pair_counts.items():
        support_ab = count_ab / n_transactions
        if support_ab < min_support:
            continue

        p_a = item_counts[item_a] / n_transactions
        p_b = item_counts[item_b] / n_transactions

        # Rule 1: A -> B
        conf_a_b = count_ab / item_counts[item_a] if item_counts[item_a] > 0 else 0.0
        lift_a_b = conf_a_b / p_b if p_b > 0 else 0.0

        if conf_a_b >= min_confidence and lift_a_b >= min_lift:
            rules.append({
                "antecedent": item_a,
                "consequent": item_b,
                "support": round(support_ab, 4),
                "confidence": round(conf_a_b, 4),
                "lift": round(lift_a_b, 4),
                "count": count_ab,
            })

        # Rule 2: B -> A
        conf_b_a = count_ab / item_counts[item_b] if item_counts[item_b] > 0 else 0.0
        lift_b_a = conf_b_a / p_a if p_a > 0 else 0.0

        if conf_b_a >= min_confidence and lift_b_a >= min_lift:
            rules.append({
                "antecedent": item_b,
                "consequent": item_a,
                "support": round(support_ab, 4),
                "confidence": round(conf_b_a, 4),
                "lift": round(lift_b_a, 4),
                "count": count_ab,
            })

    # Sort descending by lift, then confidence, then support
    rules.sort(key=lambda r: (-r["lift"], -r["confidence"], -r["support"]))

    return rules


def handle_basket_run_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """HTTP endpoint handler for POST /basket/run."""
    if not isinstance(payload, dict):
        return 422, {"status": "error", "message": "Payload must be a JSON dictionary."}

    transactions = payload.get("transactions", [])
    if not isinstance(transactions, list):
        return 422, {"status": "error", "message": "'transactions' must be a list of item lists."}

    min_sup = float(payload.get("min_support", DEFAULT_MIN_SUPPORT))
    min_conf = float(payload.get("min_confidence", DEFAULT_MIN_CONFIDENCE))
    min_lft = float(payload.get("min_lift", DEFAULT_MIN_LIFT))

    rules = mine_association_rules(
        transactions=transactions,
        min_support=min_sup,
        min_confidence=min_conf,
        min_lift=min_lft,
    )

    return 200, {
        "status": "success",
        "total_transactions": len(transactions),
        "rule_count": len(rules),
        "rules": rules,
    }
