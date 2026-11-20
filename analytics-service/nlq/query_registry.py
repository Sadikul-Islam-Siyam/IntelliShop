"""Whitelisted Query Registry (#61).

Maps resolved natural-language intents and typed parameters to pre-approved,
parameterized, read-only SQL queries. Strictly prevents SQL injection and
free-form query generation by enforcing whitelisted query definitions,
parameter type sanitization, hard row limits, and query timeouts.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Tuple


@dataclass(frozen=True)
class QueryDefinition:
    """Specification of an approved read-only analytical query."""

    intent: str
    description: str
    allowed_slots: Tuple[str, ...]
    default_limit: int
    max_limit: int
    timeout_seconds: float
    sql_template: str


# Hard ceiling for maximum rows returned by any query
GLOBAL_MAX_ROW_LIMIT: int = 250
DEFAULT_QUERY_TIMEOUT_SECONDS: float = 5.0

# Pre-approved parameterised query templates with typed placeholder bindings
QUERY_REGISTRY: Dict[str, QueryDefinition] = {
    "sales_summary": QueryDefinition(
        intent="sales_summary",
        description="Aggregate sales revenue, transaction count, and average order value",
        allowed_slots=("branch_id", "start_date", "end_date", "limit"),
        default_limit=30,
        max_limit=100,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT DATE(created_at) as sale_date, COUNT(id) as total_transactions, "
            "SUM(total_amount) as total_revenue, AVG(total_amount) as avg_order_value "
            "FROM sales "
            "WHERE created_at >= :start_date AND created_at <= :end_date "
            "AND (:branch_id IS NULL OR branch_id = :branch_id) "
            "GROUP BY DATE(created_at) ORDER BY sale_date DESC LIMIT :row_limit"
        ),
    ),
    "top_selling_products": QueryDefinition(
        intent="top_selling_products",
        description="Top selling products ranked by quantity sold and gross sales",
        allowed_slots=("branch_id", "start_date", "end_date", "limit"),
        default_limit=10,
        max_limit=50,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT si.product_id, p.name as product_name, p.sku, "
            "SUM(si.quantity) as units_sold, SUM(si.subtotal) as total_sales "
            "FROM sale_items si "
            "JOIN sales s ON s.id = si.sale_id "
            "JOIN products p ON p.id = si.product_id "
            "WHERE s.created_at >= :start_date AND s.created_at <= :end_date "
            "AND (:branch_id IS NULL OR s.branch_id = :branch_id) "
            "GROUP BY si.product_id, p.name, p.sku "
            "ORDER BY units_sold DESC LIMIT :row_limit"
        ),
    ),
    "inventory_summary": QueryDefinition(
        intent="inventory_summary",
        description="Current inventory stock on hand and valuation by branch",
        allowed_slots=("branch_id", "product_id", "limit"),
        default_limit=50,
        max_limit=150,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT inv.branch_id, b.name as branch_name, inv.product_id, p.name as product_name, "
            "p.sku, inv.quantity_available, inv.quantity_reserved, p.selling_price, "
            "(inv.quantity_available * p.cost_price) as stock_valuation "
            "FROM inventory inv "
            "JOIN products p ON p.id = inv.product_id "
            "JOIN branches b ON b.id = inv.branch_id "
            "WHERE (:branch_id IS NULL OR inv.branch_id = :branch_id) "
            "AND (:product_id IS NULL OR inv.product_id = :product_id) "
            "ORDER BY inv.quantity_available ASC LIMIT :row_limit"
        ),
    ),
    "low_stock_alerts": QueryDefinition(
        intent="low_stock_alerts",
        description="Products with available inventory below reorder point",
        allowed_slots=("branch_id", "limit"),
        default_limit=30,
        max_limit=100,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT inv.branch_id, b.name as branch_name, p.id as product_id, p.name as product_name, "
            "p.sku, inv.quantity_available, p.reorder_point "
            "FROM inventory inv "
            "JOIN products p ON p.id = inv.product_id "
            "JOIN branches b ON b.id = inv.branch_id "
            "WHERE inv.quantity_available <= p.reorder_point "
            "AND (:branch_id IS NULL OR inv.branch_id = :branch_id) "
            "ORDER BY (inv.quantity_available - p.reorder_point) ASC LIMIT :row_limit"
        ),
    ),
    "stock_out_risk": QueryDefinition(
        intent="stock_out_risk",
        description="Stock predictions with HIGH or CRITICAL stock-out risk",
        allowed_slots=("branch_id", "risk_level", "limit"),
        default_limit=25,
        max_limit=100,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT sp.id, sp.branch_id, b.name as branch_name, sp.product_id, p.name as product_name, "
            "p.sku, sp.current_stock, sp.predicted_demand, sp.safety_stock, sp.reorder_point, "
            "sp.predicted_stockout_date, sp.risk_level, sp.recommended_reorder_qty "
            "FROM stock_predictions sp "
            "JOIN products p ON p.id = sp.product_id "
            "JOIN branches b ON b.id = sp.branch_id "
            "WHERE (:branch_id IS NULL OR sp.branch_id = :branch_id) "
            "AND (:risk_level IS NULL OR sp.risk_level = :risk_level) "
            "ORDER BY sp.predicted_stockout_date ASC LIMIT :row_limit"
        ),
    ),
    "overstock_slow_moving": QueryDefinition(
        intent="overstock_slow_moving",
        description="Products with excess stock and low sell-through velocity",
        allowed_slots=("branch_id", "limit"),
        default_limit=25,
        max_limit=100,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT ar.id, ar.related_entity_id as product_id, p.name as product_name, "
            "p.sku, ar.priority, ar.priority_score, ar.recommendation_text, ar.factors "
            "FROM ai_recommendations ar "
            "JOIN products p ON p.id = ar.related_entity_id "
            "WHERE ar.type = 'overstock' AND ar.status = 'open' "
            "ORDER BY ar.priority_score DESC LIMIT :row_limit"
        ),
    ),
    "expiring_batches_markdown": QueryDefinition(
        intent="expiring_batches_markdown",
        description="Batches expiring soon eligible for zero-waste clearance markdowns",
        allowed_slots=("branch_id", "product_id", "limit"),
        default_limit=25,
        max_limit=100,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT mr.id, mr.branch_id, b.name as branch_name, mr.product_id, p.name as product_name, "
            "p.sku, ib.batch_no, ib.expiry_date, mr.days_remaining, mr.available_qty, "
            "mr.suggested_markdown_pct, mr.status "
            "FROM markdown_recommendations mr "
            "JOIN products p ON p.id = mr.product_id "
            "JOIN branches b ON b.id = mr.branch_id "
            "JOIN inventory_batches ib ON ib.id = mr.batch_id "
            "WHERE mr.status IN ('pending', 'approved') "
            "AND (:branch_id IS NULL OR mr.branch_id = :branch_id) "
            "ORDER BY mr.days_remaining ASC, mr.suggested_markdown_pct DESC LIMIT :row_limit"
        ),
    ),
    "supplier_ranking": QueryDefinition(
        intent="supplier_ranking",
        description="Top evaluated suppliers ranked by Simple Additive Weighting (SAW) score",
        allowed_slots=("product_id", "limit"),
        default_limit=10,
        max_limit=50,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT ps.supplier_id, s.name as supplier_name, ps.product_id, p.name as product_name, "
            "ps.supply_price, ps.lead_time_days, ps.fulfillment_rate, ps.quality_score, ps.is_preferred "
            "FROM product_suppliers ps "
            "JOIN suppliers s ON s.id = ps.supplier_id "
            "JOIN products p ON p.id = ps.product_id "
            "WHERE (:product_id IS NULL OR ps.product_id = :product_id) "
            "ORDER BY ps.is_preferred DESC, ps.fulfillment_rate DESC, ps.supply_price ASC LIMIT :row_limit"
        ),
    ),
    "sales_anomalies": QueryDefinition(
        intent="sales_anomalies",
        description="Statistical sales anomalies detected by modified z-score and Tukey fences",
        allowed_slots=("branch_id", "product_id", "limit"),
        default_limit=20,
        max_limit=100,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT sa.id, sa.product_id, p.name as product_name, sa.branch_id, b.name as branch_name, "
            "sa.detected_date, sa.actual_value, sa.expected_value, sa.deviation_score, sa.status "
            "FROM sales_anomalies sa "
            "JOIN products p ON p.id = sa.product_id "
            "JOIN branches b ON b.id = sa.branch_id "
            "WHERE (:branch_id IS NULL OR sa.branch_id = :branch_id) "
            "AND (:product_id IS NULL OR sa.product_id = :product_id) "
            "ORDER BY sa.detected_date DESC LIMIT :row_limit"
        ),
    ),
    "phantom_inventory": QueryDefinition(
        intent="phantom_inventory",
        description="Phantom inventory discrepancies and cycle count reconciliation tasks",
        allowed_slots=("branch_id", "product_id", "limit"),
        default_limit=20,
        max_limit=100,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT cc.id, cc.branch_id, b.name as branch_name, cc.product_id, p.name as product_name, "
            "p.sku, cc.system_quantity, cc.physical_quantity, cc.variance, cc.trigger_source, cc.status "
            "FROM cycle_counts cc "
            "JOIN products p ON p.id = cc.product_id "
            "JOIN branches b ON b.id = cc.branch_id "
            "WHERE (:branch_id IS NULL OR cc.branch_id = :branch_id) "
            "AND (:product_id IS NULL OR cc.product_id = :product_id) "
            "ORDER BY cc.id DESC LIMIT :row_limit"
        ),
    ),
    "market_basket_cross_sell": QueryDefinition(
        intent="market_basket_cross_sell",
        description="Market basket association rules with support, confidence, and lift",
        allowed_slots=("product_id", "limit"),
        default_limit=10,
        max_limit=50,
        timeout_seconds=5.0,
        sql_template=(
            "SELECT mbr.id, mbr.antecedent_product_id, pa.name as antecedent_name, "
            "mbr.consequent_product_id, pc.name as consequent_name, "
            "mbr.support, mbr.confidence, mbr.lift "
            "FROM market_basket_rules mbr "
            "JOIN products pa ON pa.id = mbr.antecedent_product_id "
            "JOIN products pc ON pc.id = mbr.consequent_product_id "
            "WHERE (:product_id IS NULL OR mbr.antecedent_product_id = :product_id) "
            "ORDER BY mbr.lift DESC, mbr.confidence DESC LIMIT :row_limit"
        ),
    ),
}


def get_query_definition(intent: str) -> Optional[QueryDefinition]:
    """Retrieve pre-approved query definition for a given intent."""
    return QUERY_REGISTRY.get(intent)


def build_parameterized_query(
    intent: str, slots: Dict[str, Any]
) -> Tuple[str, Dict[str, Any], float]:
    """Construct sanitized parameterized query string, parameter bindings, and timeout.

    Enforces row limits (never exceeds max_limit) and strictly disallows free-form SQL.
    """
    definition = get_query_definition(intent)
    if definition is None:
        raise ValueError(f"Intent '{intent}' is not registered in the whitelisted query registry.")

    # Clamp limit strictly between 1 and max_limit
    raw_limit = slots.get("limit")
    if raw_limit is not None:
        try:
            limit = max(1, min(int(raw_limit), definition.max_limit))
        except (ValueError, TypeError):
            limit = definition.default_limit
    else:
        limit = definition.default_limit

    # Prepare bound parameters
    params: Dict[str, Any] = {
        "row_limit": limit,
    }

    if "branch_id" in definition.allowed_slots:
        branch_id = slots.get("branch_id")
        params["branch_id"] = int(branch_id) if branch_id is not None else None

    if "product_id" in definition.allowed_slots:
        product_id = slots.get("product_id")
        params["product_id"] = int(product_id) if product_id is not None else None

    if "start_date" in definition.allowed_slots:
        params["start_date"] = str(slots.get("start_date") or "2000-01-01 00:00:00")
    if "end_date" in definition.allowed_slots:
        params["end_date"] = str(slots.get("end_date") or "2099-12-31 23:59:59")

    if "risk_level" in definition.allowed_slots:
        risk = slots.get("risk_level")
        params["risk_level"] = str(risk).upper() if risk else None

    return definition.sql_template, params, definition.timeout_seconds


def validate_query_safety(intent: str, slots: Dict[str, Any]) -> bool:
    """Validate that query belongs to registry and slot values are typed safely."""
    definition = get_query_definition(intent)
    if definition is None:
        return False

    for key, val in slots.items():
        if val is None:
            continue
        # Check for SQL injection attempts in string values
        if isinstance(val, str):
            val_lower = val.lower()
            if any(tok in val_lower for tok in ("drop ", "delete ", "insert ", "update ", "--", ";", "union select")):
                return False

    return True
