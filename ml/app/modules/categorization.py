"""Transaction categorization with ML model + keyword fallback."""

from __future__ import annotations

import re
from typing import Dict, List, Optional

from app.modules.model_training import get_categorizer

KEYWORD_PATTERNS = {
    "groceries": ["lidl", "billa", "tesco", "kaufland", "dm", "tedi", "biedronka"],
    "dining": ["restaurant", "mcdonald", "kfc", "burger", "pizza", "cafe", "bistro"],
    "transport": [
        "gas station", "omv", "shell", "bp", "public transport",
        "uber", "bolt", "train", "zssk",
    ],
    "utilities": ["electric", "gas", "water", "internet", "phone", "orange", "telekom"],
    "entertainment": [
        "netflix", "spotify", "cinema", "theater", "voyo", "hbo", "disney",
    ],
    "health": ["pharmacy", "dr.max", "benu", "doctor", "hospital", "dental"],
    "shopping": ["alza", "mall", "amazon", "shopping"],
    "income": ["salary", "wage", "payment received", "transfer from"],
    "finance": ["investment", "dividend", "interest", "fee", "charge"],
}


def suggest_category(
    user_id: int,
    description: str,
    amount: float,
    counterparty_name: Optional[str] = None,
) -> Dict:
    """Suggest category — tries ML model first, falls back to keywords."""
    # Try ML model first
    model = get_categorizer(user_id)
    if model is None:
        model = get_categorizer(None)  # Try global model

    if model and model.is_fitted:
        result = model.predict(description, amount, counterparty_name)
        if result["confidence"] > 0.3:
            result["transaction_id"] = 0
            result["suggested_category_id"] = result.pop("category_id")
            result["suggested_category_name"] = result.pop("category_name")
            return result

    # Keyword fallback
    return _keyword_suggest(description, counterparty_name)


def categorize_transactions_batch(
    user_id: int, transactions: List[Dict]
) -> List[Dict]:
    """Batch categorize — tries ML model first, falls back to keywords."""
    # Try ML model for batch
    model = get_categorizer(user_id)
    if model is None:
        model = get_categorizer(None)  # Try global model

    if model and model.is_fitted:
        results = model.predict_batch(transactions)
        # Remap keys for API compatibility
        for r in results:
            r["suggested_category_id"] = r.pop("category_id")
            r["suggested_category_name"] = r.pop("category_name")
        return results

    # Keyword fallback for each transaction
    results = []
    for txn in transactions:
        result = _keyword_suggest(
            txn.get("description", ""),
            txn.get("counterparty_name"),
        )
        result["transaction_id"] = txn.get("id", 0)
        result["method"] = "keyword"
        results.append(result)
    return results


def _keyword_suggest(
    description: str, counterparty_name: Optional[str] = None
) -> Dict:
    """Keyword-based category suggestion (fallback)."""
    text = " ".join(filter(None, [description, counterparty_name])).lower()

    best_category = None
    best_score = 0.0
    alternatives = []

    for category_name, keywords in KEYWORD_PATTERNS.items():
        score = sum(0.3 for kw in keywords if kw in text)

        if score > 0:
            if score > best_score:
                if best_category:
                    alternatives.append({"category": best_category, "score": best_score})
                best_score = score
                best_category = category_name
            elif score > 0.1:
                alternatives.append({"category": category_name, "score": score})

    if best_category:
        confidence = min(best_score * 2, 0.9)
    else:
        best_category = "uncategorized"
        confidence = 0.0

    return {
        "transaction_id": 0,
        "suggested_category_id": None,
        "suggested_category_name": best_category.capitalize(),
        "confidence": confidence,
        "method": "keyword",
        "alternatives": sorted(alternatives, key=lambda x: x["score"], reverse=True)[:3],
    }
