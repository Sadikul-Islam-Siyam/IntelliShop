"""Stage 2 Supplier Evaluation: Simple Additive Weighting (SAW) Scoring & Ranking (#48)."""

from __future__ import annotations

from typing import Any, Dict, List, Optional, Sequence, Tuple

from analytics.supplier_eligibility import (
    DEFAULT_MAX_ACCEPTABLE_LEAD_DAYS,
    filter_eligible_suppliers,
)
from analytics.weight_modes import normalize_weights, select_context_weights


REQUIRED_EVALUATE_FIELDS: Tuple[str, ...] = (
    "product_id",
    "branch_id",
    "required_quantity",
    "candidates",
)


def score_and_rank_suppliers(
    eligible_suppliers: Sequence[Dict[str, Any]],
    weights: Dict[str, float],
) -> List[Dict[str, Any]]:
    """
    Compute Simple Additive Weighting (SAW) scores and rank eligible suppliers (#48):
      - Cost criteria (price, lead_time_days):   r = min(x_j) / x_ij
      - Benefit criterion (fulfillment_rate):    r = x_ij / max(x_j)
      - Score S_i = w_p * r_price + w_l * r_leadtime + w_r * r_fulfillment
      - Rank by S_i descending; tie-breaker: lower price, then lower lead time.
    """
    if not eligible_suppliers:
        return []

    norm_w = normalize_weights(weights)
    w_p = norm_w["price"]
    w_l = norm_w["speed"]
    w_r = norm_w["reliability"]

    prices = [float(s.get("price", s.get("supply_price", 0.0))) for s in eligible_suppliers]
    lead_times = [float(s.get("lead_time_days", 1.0)) for s in eligible_suppliers]
    fulfillments = [float(s.get("fulfillment_rate", 0.90)) for s in eligible_suppliers]

    min_price = min(prices) if prices else 0.0
    min_lead = min(lead_times) if lead_times else 1.0
    max_fulfillment = max(fulfillments) if fulfillments else 1.0

    scored: List[Dict[str, Any]] = []
    for s in eligible_suppliers:
        price = float(s.get("price", s.get("supply_price", 0.0)))
        lead_time = float(s.get("lead_time_days", 1.0))
        fulfillment = float(s.get("fulfillment_rate", 0.90))

        r_price = (min_price / price) if price > 0 else 0.0
        r_lead = (min_lead / lead_time) if lead_time > 0 else 0.0
        r_fulfillment = (fulfillment / max_fulfillment) if max_fulfillment > 0 else 1.0

        raw_score = (w_p * r_price) + (w_l * r_lead) + (w_r * r_fulfillment)

        entry = dict(s)
        entry["r_price"] = round(r_price, 4)
        entry["r_leadtime"] = round(r_lead, 4)
        entry["r_fulfillment"] = round(r_fulfillment, 4)
        entry["weighted_price"] = round(w_p * r_price, 4)
        entry["weighted_leadtime"] = round(w_l * r_lead, 4)
        entry["weighted_fulfillment"] = round(w_r * r_fulfillment, 4)
        entry["score"] = round(raw_score, 3)
        entry["raw_score"] = raw_score
        scored.append(entry)

    # Sort descending by score (rounded to 6 decimals to avoid float noise), tie-break by lower price, then lower lead_time
    scored.sort(
        key=lambda item: (
            -round(float(item["raw_score"]), 6),
            float(item["price"]),
            int(item["lead_time_days"]),
        )
    )

    ranking: List[Dict[str, Any]] = []
    for idx, item in enumerate(scored, start=1):
        clean_item = dict(item)
        clean_item.pop("raw_score", None)
        clean_item["rank"] = idx
        clean_item["is_recommended"] = idx == 1
        ranking.append(clean_item)

    return ranking


def evaluate_suppliers(
    product_id: int,
    branch_id: int,
    required_quantity: float,
    candidates: Sequence[Dict[str, Any]],
    available_stock: float = 100.0,
    reorder_point: float = 50.0,
    max_acceptable_lead_days: int = DEFAULT_MAX_ACCEPTABLE_LEAD_DAYS,
    panic_ratio_of_rop: float = 0.10,
    custom_mode_weights: Optional[Dict[str, Dict[str, float]]] = None,
    explicit_mode: Optional[str] = None,
    explicit_weights: Optional[Dict[str, float]] = None,
    enforce_moq: bool = True,
) -> Dict[str, Any]:
    """
    Full 2-stage supplier evaluation pipeline (#47 + #48 + #68):
      1. Select context-aware weights (NORMAL / WARNING / PANIC) from stock vs. ROP.
      2. Filter eligible suppliers (active, MOQ, max acceptable lead time).
      3. Score and rank eligible suppliers with SAW.
    """
    context = select_context_weights(
        available_stock=available_stock,
        reorder_point=reorder_point,
        panic_ratio_of_rop=panic_ratio_of_rop,
        custom_mode_weights=custom_mode_weights,
        explicit_mode=explicit_mode,
    )

    weights = normalize_weights(explicit_weights) if explicit_weights else context["weights"]

    eligibility = filter_eligible_suppliers(
        candidates=candidates,
        required_quantity=required_quantity,
        max_acceptable_lead_days=max_acceptable_lead_days,
        enforce_moq=enforce_moq,
    )

    ranking = score_and_rank_suppliers(eligibility["eligible"], weights)

    return {
        "product_id": int(product_id),
        "branch_id": int(branch_id),
        "required_quantity": float(required_quantity),
        "mode": context["mode"],
        "weights": weights,
        "available_stock": context["available_stock"],
        "stock": context["available_stock"],
        "reorder_point": context["reorder_point"],
        "rop": context["reorder_point"],
        "panic_threshold": context["panic_threshold"],
        "eligible": eligibility["eligible"],
        "excluded": eligibility["excluded"],
        "ineligible": eligibility["excluded"],
        "ranking": ranking,
        "top_supplier": ranking[0] if ranking else None,
        "recommended_supplier": ranking[0] if ranking else None,
        "candidates_count": len(candidates),
        "eligible_count": len(eligibility["eligible"]),
        "alert": eligibility["alert"],
    }


def handle_supplier_evaluate_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """HTTP handler for POST /suppliers/evaluate."""
    if not isinstance(payload, dict):
        return 422, {"status": "error", "message": "Request body must be a JSON object."}

    missing = [f for f in REQUIRED_EVALUATE_FIELDS if f not in payload]
    if missing:
        return 422, {
            "status": "error",
            "message": f"Missing required fields: {', '.join(missing)}",
        }

    candidates = payload.get("candidates")
    if not isinstance(candidates, list):
        return 422, {
            "status": "error",
            "message": "Field 'candidates' must be a list of supplier objects.",
        }

    raw_stock = payload.get("available_stock", payload.get("stock", 100.0))
    raw_rop = payload.get("reorder_point", payload.get("rop", 50.0))

    result = evaluate_suppliers(
        product_id=int(payload["product_id"]),
        branch_id=int(payload["branch_id"]),
        required_quantity=float(payload["required_quantity"] or 0.0),
        candidates=candidates,
        available_stock=float(raw_stock if raw_stock is not None else 100.0),
        reorder_point=float(raw_rop if raw_rop is not None else 50.0),
        max_acceptable_lead_days=int(payload.get("max_acceptable_lead_days", DEFAULT_MAX_ACCEPTABLE_LEAD_DAYS)),
        panic_ratio_of_rop=float(payload.get("panic_ratio_of_rop", 0.10)),
        custom_mode_weights=payload.get("custom_mode_weights", payload.get("weights_override")),
        explicit_mode=payload.get("mode"),
        explicit_weights=payload.get("weights"),
        enforce_moq=bool(payload.get("enforce_moq", True)),
    )

    return 200, result

