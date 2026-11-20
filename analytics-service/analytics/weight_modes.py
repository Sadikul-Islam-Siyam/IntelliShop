"""Context-Aware Weight Selector for Supplier Evaluation (#68)."""

from __future__ import annotations

from typing import Any, Dict, Optional


MODE_NORMAL = "NORMAL"
MODE_WARNING = "WARNING"
MODE_PANIC = "PANIC"

DEFAULT_WEIGHT_MODES: Dict[str, Dict[str, float]] = {
    MODE_NORMAL: {"price": 0.70, "speed": 0.10, "reliability": 0.20},
    MODE_WARNING: {"price": 0.30, "speed": 0.30, "reliability": 0.40},
    MODE_PANIC: {"price": 0.10, "speed": 0.70, "reliability": 0.20},
}

DEFAULT_PANIC_RATIO_OF_ROP = 0.10


def normalize_weights(weights: Dict[str, Any]) -> Dict[str, float]:
    """Ensure price, speed, and reliability weights are non-negative and sum to 1.0."""
    w_price = max(0.0, float(weights.get("price", 0.70)))
    w_speed = max(0.0, float(weights.get("speed", weights.get("lead_time", 0.10))))
    w_rel = max(0.0, float(weights.get("reliability", weights.get("fulfillment", 0.20))))

    total = w_price + w_speed + w_rel
    if total <= 0:
        return dict(DEFAULT_WEIGHT_MODES[MODE_NORMAL])

    return {
        "price": round(w_price / total, 4),
        "speed": round(w_speed / total, 4),
        "reliability": round(w_rel / total, 4),
    }


def select_context_weights(
    available_stock: float,
    reorder_point: float,
    panic_ratio_of_rop: float = DEFAULT_PANIC_RATIO_OF_ROP,
    custom_mode_weights: Optional[Dict[str, Dict[str, float]]] = None,
    explicit_mode: Optional[str] = None,
) -> Dict[str, Any]:
    """
    Select operating mode and criterion weights based on current stock vs. Reorder Point (ROP) (#68):
      - stock > ROP            -> NORMAL  (price 0.70, speed 0.10, reliability 0.20)
      - stock <= ROP           -> WARNING (price 0.30, speed 0.30, reliability 0.40)
      - stock <= 10% of ROP    -> PANIC   (price 0.10, speed 0.70, reliability 0.20)
    """
    mode_table = dict(DEFAULT_WEIGHT_MODES)
    if custom_mode_weights:
        for k, v in custom_mode_weights.items():
            key_upper = str(k).strip().upper()
            if key_upper in mode_table and isinstance(v, dict):
                mode_table[key_upper] = normalize_weights(v)

    if explicit_mode and str(explicit_mode).strip().upper() in mode_table:
        chosen_mode = str(explicit_mode).strip().upper()
    else:
        stock = float(available_stock)
        rop = max(0.0, float(reorder_point))
        ratio = max(0.0, float(panic_ratio_of_rop))
        panic_threshold = rop * ratio

        if stock <= panic_threshold and (rop > 0 or stock <= 0):
            chosen_mode = MODE_PANIC
        elif stock <= rop:
            chosen_mode = MODE_WARNING
        else:
            chosen_mode = MODE_NORMAL

    weights = normalize_weights(mode_table[chosen_mode])

    return {
        "mode": chosen_mode,
        "weights": weights,
        "available_stock": round(float(available_stock), 2),
        "reorder_point": round(float(reorder_point), 2),
        "panic_threshold": round(max(0.0, float(reorder_point)) * float(panic_ratio_of_rop), 2),
    }

