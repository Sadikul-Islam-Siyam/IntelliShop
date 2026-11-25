"""Stage 1 Supplier Evaluation: Eligibility Filter (#47)."""

from __future__ import annotations

from typing import Any, Dict, List, Optional, Sequence, Tuple


DEFAULT_MAX_ACCEPTABLE_LEAD_DAYS = 14
NO_ELIGIBLE_SUPPLIERS_ALERT = (
    "No eligible suppliers found meeting active status, MOQ, and acceptable lead time requirements."
)


def _normalize_candidate(candidate: Dict[str, Any]) -> Dict[str, Any]:
    """Normalize supplier candidate dictionary keys."""
    supplier_id = candidate.get("supplier_id", candidate.get("id"))
    name = str(candidate.get("supplier_name", candidate.get("name", f"Supplier #{supplier_id}")))
    status = str(candidate.get("status", "active")).strip().lower()
    if "is_active" in candidate and not bool(candidate["is_active"]):
        status = "inactive"

    price = float(candidate.get("supply_price", candidate.get("price", 0.0)) or 0.0)
    lead_time = int(candidate.get("lead_time_days", candidate.get("lead_time", 7)) or 7)
    moq = int(candidate.get("min_order_qty", candidate.get("moq", 1)) or 1)
    order_multiple = int(candidate.get("order_multiple", 1) or 1)

    raw_fulfillment = candidate.get("fulfillment_rate")
    fulfillment_rate = float(raw_fulfillment) if raw_fulfillment is not None else 0.90

    return {
        "supplier_id": supplier_id,
        "name": name,
        "status": status,
        "price": round(price, 2),
        "supply_price": round(price, 2),
        "lead_time_days": lead_time,
        "moq": moq,
        "min_order_qty": moq,
        "order_multiple": max(1, order_multiple),
        "fulfillment_rate": round(max(0.0, min(1.0, fulfillment_rate)), 4),
        "is_preferred": bool(candidate.get("is_preferred", False)),
    }


def filter_eligible_suppliers(
    candidates: Sequence[Dict[str, Any]],
    required_quantity: float = 0.0,
    max_acceptable_lead_days: int = DEFAULT_MAX_ACCEPTABLE_LEAD_DAYS,
    enforce_moq: bool = True,
) -> Dict[str, Any]:
    """
    Filter candidate suppliers according to Stage 1 Eligibility rules (#47):
      1. Supplier status must be ACTIVE ('active').
      2. Supplier MOQ must be satisfied (required_quantity >= moq when required_quantity > 0 and enforce_moq is True).
      3. Supplier lead_time_days must be <= max_acceptable_lead_days and > 0.
      4. Supplier price must be > 0.

    Returns dict with 'eligible', 'excluded', 'has_eligible', and 'alert'.
    """
    req_qty = float(required_quantity or 0.0)
    max_lead = max(1, int(max_acceptable_lead_days))

    eligible: List[Dict[str, Any]] = []
    excluded: List[Dict[str, Any]] = []

    for raw in candidates:
        item = _normalize_candidate(raw)
        reasons: List[str] = []

        if item["status"] != "active":
            reasons.append("inactive_status")

        if item["price"] <= 0:
            reasons.append("invalid_price")

        if item["lead_time_days"] <= 0 or item["lead_time_days"] > max_lead:
            reasons.append("lead_time_exceeded")

        if enforce_moq and req_qty > 0 and item["moq"] > req_qty:
            reasons.append("moq_exceeded")

        if reasons:
            excluded_item = dict(item)
            excluded_item["exclusion_reasons"] = reasons
            excluded.append(excluded_item)
        else:
            eligible.append(item)

    alert: Optional[str] = None if eligible else NO_ELIGIBLE_SUPPLIERS_ALERT

    return {
        "eligible": eligible,
        "excluded": excluded,
        "has_eligible": len(eligible) > 0,
        "alert": alert,
    }

