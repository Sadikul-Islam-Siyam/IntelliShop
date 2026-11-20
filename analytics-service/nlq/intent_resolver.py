"""Intent Classification & Slot Filling (#60).

Maps natural-language business questions to a fixed whitelist of supported
read-only business intents and extracts structured slots (branch, date range,
product, category, risk level, limit).

Never generates or executes free-form SQL. Unknown or unsafe questions
return the 'unknown' intent with a safe fallback message.
"""

from __future__ import annotations

import re
from datetime import date, datetime, timedelta
from typing import Any, Dict, List, Optional, Tuple


SUPPORTED_INTENTS: Tuple[str, ...] = (
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
)

SAFE_FALLBACK_MESSAGE = (
    "I could not match your question to a supported read-only business query. "
    "You can ask about sales summaries, top-selling products, inventory stock balances, "
    "low-stock alerts, stock-out risk, slow-moving/overstock items, expiring batches & markdowns, "
    "supplier rankings, sales anomalies, phantom inventory, or cross-sell rules."
)

# Forbidden SQL injection / mutation tokens
_UNSAFE_PATTERNS: List[re.Pattern[str]] = [
    re.compile(r"\b(drop|truncate|alter|grant|revoke)\s+(table|database|schema|user)\b", re.I),
    re.compile(r"\bdelete\s+from\b", re.I),
    re.compile(r"\binsert\s+into\b", re.I),
    re.compile(r"\bupdate\s+[a-z0-9_]+\s+set\b", re.I),
    re.compile(r";\s*--", re.I),
    re.compile(r"\bunion\s+select\b", re.I),
]

# Intent keyword and phrase scoring patterns
_INTENT_RULES: List[Tuple[str, List[Tuple[re.Pattern[str], float]]]] = [
    (
        "market_basket_cross_sell",
        [
            (re.compile(r"\b(cross[\s\-]?sell|market\s+basket|association\s+rule|bought\s+together|sells?\s+with|paired\s+with|affinity)\b", re.I), 0.90),
            (re.compile(r"\b(frequently\s+bought|bundle|complementary\s+products?)\b", re.I), 0.80),
        ],
    ),
    (
        "phantom_inventory",
        [
            (re.compile(r"\b(phantom\s+inventory|phantom\s+stock|cycle[\s\-]?count|unrecorded\s+shrinkage|ghost\s+stock)\b", re.I), 0.92),
            (re.compile(r"\b(zero\s+sales\s+with\s+stock|missing\s+from\s+shelf|variance\s+reconciliation)\b", re.I), 0.82),
        ],
    ),
    (
        "sales_anomalies",
        [
            (re.compile(r"\b(anomal(?:y|ies)|unusual\s+sales|sales\s+spikes?|abnormal\s+sales|modified\s+z[\s\-]?score|iqr\s+fences?|outlier\s+sales)\b", re.I), 0.92),
            (re.compile(r"\b(unexpected\s+surge|sudden\s+drop|weird\s+sales)\b", re.I), 0.78),
        ],
    ),
    (
        "expiring_batches_markdown",
        [
            (re.compile(r"\b(zero[\s\-]?waste|markdown|expir(?:ing|y|ed)|near\s+expiry|clearance\s+discount|perishable\s+surplus)\b", re.I), 0.92),
            (re.compile(r"\b(batches?\s+expiring|shelf\s+life\s+risk|spoil(?:age|ing)?)\b", re.I), 0.84),
        ],
    ),
    (
        "overstock_slow_moving",
        [
            (re.compile(r"\b(overstock(?:ed)?|slow[\s\-]?moving|dead\s+stock|low\s+sell[\s\-]?through|excess\s+stock|excess\s+inventory)\b", re.I), 0.92),
            (re.compile(r"\b(not\s+selling|stagnant\s+stock|surplus\s+inventory)\b", re.I), 0.80),
        ],
    ),
    (
        "supplier_ranking",
        [
            (re.compile(r"\b(supplier\s+ranking|best\s+suppliers?|top\s+suppliers?|evaluate\s+suppliers?|saw\s+score|vendor\s+ranking|fulfillment\s+rate)\b", re.I), 0.92),
            (re.compile(r"\b(which\s+supplier|preferred\s+supplier|cheapest\s+supplier|fastest\s+supplier)\b", re.I), 0.84),
        ],
    ),
    (
        "stock_out_risk",
        [
            (re.compile(r"\b(stock[\s\-]?out\s+risk|risk\s+of\s+stock[\s\-]?out|days\s+of\s+cover|expected\s+stock[\s\-]?out|run\s+out\s+of\s+stock|depletion\s+date|stock\s+projection)\b", re.I), 0.92),
            (re.compile(r"\b(high\s+risk|stock[\s\-]?outs?|risk\s+classification|replenishment\s+risk)\b", re.I), 0.82),
        ],
    ),
    (
        "low_stock_alerts",
        [
            (re.compile(r"\b(low[\s\-]?stock|below\s+reorder\s+point|reorder\s+alerts?|running\s+low|need\s+restock(?:ing)?|out\s+of\s+stock)\b", re.I), 0.88),
            (re.compile(r"\b(shortage|minimum\s+stock)\b", re.I), 0.75),
        ],
    ),
    (
        "top_selling_products",
        [
            (re.compile(r"\b(top\s+\d*\s*selling|best[\s\-]?sell(?:ing|ers)|most\s+sold|highest\s+selling|popular\s+products?|top\s+products?)\b", re.I), 0.90),
        ],
    ),
    (
        "inventory_summary",
        [
            (re.compile(r"\b(inventory\s+report|inventory\s+summary|stock\s+balance|stock\s+valuation|inventory\s+valuation|on[\s\-]?hand\s+stock|current\s+stock|how\s+much\s+stock|warehouse\s+stock)\b", re.I), 0.86),
            (re.compile(r"\b(how\s+many\s+units\s+in\s+stock|batches\s+in\s+stock)\b", re.I), 0.80),
        ],
    ),
    (
        "sales_summary",
        [
            (re.compile(r"\b(total\s+sales|sales\s+report|sales\s+summary|revenue|turnover|how\s+much\s+did\s+we\s+sell|daily\s+sales|sales\s+performance|gross\s+sales)\b", re.I), 0.88),
            (re.compile(r"\b(sales\s+for|sales\s+in|sales\s+at|revenue\s+for|revenue\s+at|transactions\s+today)\b", re.I), 0.82),
        ],
    ),
]


def _parse_reference_date(reference_date: Optional[str] = None) -> date:
    if reference_date:
        try:
            return datetime.strptime(reference_date[:10], "%Y-%m-%d").date()
        except ValueError:
            pass
    return date.today()


def _extract_branch_slot(text: str) -> Dict[str, Any]:
    """Extract branch name or branch_id from question text."""
    result: Dict[str, Any] = {}

    # Match "branch #2", "branch 2", "branch id 3"
    m_id = re.search(r"\bbranch\s*(?:id\s*)?#?(\d+)\b", text, re.I)
    if m_id:
        result["branch_id"] = int(m_id.group(1))
        result["branch"] = f"Branch {m_id.group(1)}"
        return result

    # Match "<Name> Branch" (e.g., "Main Branch", "Downtown Branch", "Uttara Branch")
    m_named = re.search(
        r"\b(?:at|in|for|from)\s+(?:the\s+)?([A-Z][a-zA-Z0-9\-]*(?:\s+[A-Z][a-zA-Z0-9\-]*)?)\s+Branch\b",
        text,
    )
    if not m_named:
        m_named = re.search(r"\b([A-Z][a-zA-Z0-9\-]+)\s+Branch\b", text)

    if m_named:
        candidate = m_named.group(1).strip()
        stop_words = {"which", "what", "every", "each", "all", "this", "that", "our"}
        if candidate.lower() not in stop_words:
            result["branch"] = f"{candidate} Branch"
            return result

    return result


def _extract_date_range_slot(text: str, ref_date: date) -> Optional[Dict[str, Any]]:
    """Extract date range slot from natural language time expressions."""
    lower = text.lower()

    # 1. Explicit YYYY-MM-DD to YYYY-MM-DD
    m_iso = re.search(r"\b(\d{4}-\d{2}-\d{2})\s+(?:to|through|until|and|-)\s+(\d{4}-\d{2}-\d{2})\b", lower)
    if m_iso:
        return {
            "period": "custom",
            "start_date": m_iso.group(1),
            "end_date": m_iso.group(2),
        }

    # 2. Single explicit YYYY-MM-DD
    m_single = re.search(r"\bon\s+(\d{4}-\d{2}-\d{2})\b", lower)
    if m_single:
        d_str = m_single.group(1)
        return {
            "period": "custom_day",
            "days": 1,
            "start_date": d_str,
            "end_date": d_str,
        }

    # 3. "today"
    if re.search(r"\btoday\b", lower):
        d_str = ref_date.isoformat()
        return {
            "period": "today",
            "days": 1,
            "start_date": d_str,
            "end_date": d_str,
        }

    # 4. "yesterday"
    if re.search(r"\byesterday\b", lower):
        y_date = (ref_date - timedelta(days=1)).isoformat()
        return {
            "period": "yesterday",
            "days": 1,
            "start_date": y_date,
            "end_date": y_date,
        }

    # 5. "last N days" / "past N days" / "next N days"
    m_days = re.search(r"\b(?:last|past|previous|next)\s+(\d+)\s+days?\b", lower)
    if m_days:
        n_days = max(1, int(m_days.group(1)))
        start_d = (ref_date - timedelta(days=n_days)).isoformat()
        return {
            "period": f"last_{n_days}_days",
            "days": n_days,
            "start_date": start_d,
            "end_date": ref_date.isoformat(),
        }

    # 6. "this week" / "last week" / "weekly"
    if re.search(r"\b(this\s+week|last\s+week|past\s+week|weekly)\b", lower):
        start_d = (ref_date - timedelta(days=7)).isoformat()
        return {
            "period": "last_7_days",
            "days": 7,
            "start_date": start_d,
            "end_date": ref_date.isoformat(),
        }

    # 7. "this month" / "last month" / "monthly"
    if re.search(r"\b(this\s+month|last\s+month|past\s+month|monthly)\b", lower):
        start_d = (ref_date - timedelta(days=30)).isoformat()
        return {
            "period": "last_30_days",
            "days": 30,
            "start_date": start_d,
            "end_date": ref_date.isoformat(),
        }

    return None


def _extract_product_slot(text: str) -> Dict[str, Any]:
    """Extract SKU or product name from question text."""
    result: Dict[str, Any] = {}

    # 1. Explicit SKU pattern (e.g. "SKU MLK-01", "SKU: BRD-100", or code like "MLK-001")
    m_sku = re.search(r"\bsku[:\s]+([A-Z0-9\-]{3,20})\b", text, re.I)
    if not m_sku:
        m_sku = re.search(r"\b([A-Z]{2,5}-\d{2,6})\b", text)
    if m_sku:
        result["sku"] = m_sku.group(1).upper()
        result["product"] = m_sku.group(1).upper()
        return result

    # 2. Quoted product name (e.g. "Fresh Milk 1L" or 'Sliced Bread')
    m_quoted = re.search(r"[\"']([^\"']{2,60})[\"']", text)
    if m_quoted:
        prod = m_quoted.group(1).strip()
        result["product"] = prod
        return result

    # 3. "for product <X>" or "sells with <X>" or "supplier for <X>" or "substitutes for <X>"
    m_for = re.search(
        r"\b(?:product|sells?\s+with|bought\s+with|supplier\s+for|ranking\s+for|forecast\s+for|stock\s+for|anomalies\s+for)\s+([A-Za-z0-9][A-Za-z0-9\s]{1,35}?)(?:\s+(?:at|in|on|during|from|last|this|today|yesterday|branch)\b|[?.!,]|$)",
        text,
        re.I,
    )
    if m_for:
        candidate = m_for.group(1).strip()
        ignore = {
            "all", "our", "the", "any", "each", "every", "items", "products",
            "store", "shop", "supermarket", "branch", "branches",
        }
        if candidate.lower() not in ignore and not candidate.lower().endswith("branch"):
            result["product"] = candidate
            return result

    return result


def _extract_limit_and_risk_slots(text: str) -> Dict[str, Any]:
    """Extract top-N limit and risk level slots."""
    slots: Dict[str, Any] = {}

    m_top = re.search(r"\b(?:top|first|limit)\s+(\d+)\b", text, re.I)
    if m_top:
        slots["limit"] = max(1, min(100, int(m_top.group(1))))

    m_risk = re.search(r"\b(high|medium|low|critical)\s+risk\b", text, re.I)
    if m_risk:
        level = m_risk.group(1).upper()
        slots["risk_level"] = "HIGH" if level == "CRITICAL" else level

    return slots


def resolve_intent(
    query: str,
    reference_date: Optional[str] = None,
) -> Dict[str, Any]:
    """Classify a natural-language business question into a whitelisted intent and extract slots."""
    cleaned = (query or "").strip()
    if not cleaned:
        return {
            "query": "",
            "intent": "unknown",
            "confidence": 0.0,
            "slots": {},
            "filters": {},
            "is_safe": True,
            "fallback_message": SAFE_FALLBACK_MESSAGE,
        }

    # Check for unsafe SQL injection or mutation keywords
    for pattern in _UNSAFE_PATTERNS:
        if pattern.search(cleaned):
            return {
                "query": cleaned,
                "intent": "unknown",
                "confidence": 0.0,
                "slots": {},
                "filters": {},
                "is_safe": False,
                "fallback_message": "Unsafe or unsupported query syntax detected. Only natural-language read-only business questions are permitted.",
            }

    best_intent = "unknown"
    best_score = 0.0

    for intent_name, rules in _INTENT_RULES:
        score = 0.0
        matches = 0
        for regex, weight in rules:
            if regex.search(cleaned):
                score = max(score, weight)
                matches += 1
        if matches > 1:
            score = min(0.99, score + 0.05 * (matches - 1))
        if score > best_score:
            best_score = score
            best_intent = intent_name

    ref_date = _parse_reference_date(reference_date)
    slots: Dict[str, Any] = {}

    branch_slots = _extract_branch_slot(cleaned)
    slots.update(branch_slots)

    date_slot = _extract_date_range_slot(cleaned, ref_date)
    if date_slot:
        slots["date_range"] = date_slot
        slots["start_date"] = date_slot.get("start_date")
        slots["end_date"] = date_slot.get("end_date")
        if "days" in date_slot:
            slots["days"] = date_slot["days"]

    product_slots = _extract_product_slot(cleaned)
    slots.update(product_slots)

    extra_slots = _extract_limit_and_risk_slots(cleaned)
    slots.update(extra_slots)

    if best_intent == "unknown" or best_score < 0.50:
        return {
            "query": cleaned,
            "intent": "unknown",
            "confidence": 0.0,
            "slots": slots,
            "filters": dict(slots),
            "is_safe": True,
            "fallback_message": SAFE_FALLBACK_MESSAGE,
        }

    # Boost confidence slightly when relevant slots are extracted
    slot_boost = 0.03 if slots else 0.0
    final_confidence = round(min(0.99, best_score + slot_boost), 2)

    return {
        "query": cleaned,
        "intent": best_intent,
        "confidence": final_confidence,
        "slots": slots,
        "filters": dict(slots),
        "is_safe": True,
        "fallback_message": None,
    }


def handle_nlq_resolve_request(payload: Dict[str, Any]) -> Tuple[int, Dict[str, Any]]:
    """HTTP endpoint handler for POST /nlq/resolve."""
    if not isinstance(payload, dict):
        return 422, {
            "error": {
                "code": "VALIDATION_FAILED",
                "message": "Request payload must be a JSON object.",
                "details": [{"field": "query", "issue": "Payload must be a JSON object."}],
            }
        }

    raw_query = payload.get("query") if "query" in payload else payload.get("question")
    if raw_query is None or not isinstance(raw_query, str) or not raw_query.strip():
        return 422, {
            "error": {
                "code": "VALIDATION_FAILED",
                "message": "The query field is required.",
                "details": [{"field": "query", "issue": "The query field must be a non-empty string."}],
            }
        }

    ref_date = payload.get("reference_date")
    resolved = resolve_intent(raw_query, reference_date=ref_date if isinstance(ref_date, str) else None)
    return 200, resolved
