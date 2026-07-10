from __future__ import annotations

import json
import logging
import re
from collections import Counter, defaultdict
from datetime import UTC, datetime

from app.core.config import settings
from app.core.database import get_user_categorization_history

logger = logging.getLogger(__name__)

VECTORS_DIR = settings.vectors_dir


def _normalize_text(value: str | None) -> str:
    if not value:
        return ""
    return re.sub(r"\s+", " ", value.strip().lower())


def _description_pattern(description: str | None) -> str:
    text = _normalize_text(description)
    if not text:
        return ""
    words = text.split(" ")
    return " ".join(words[:5])


class UserPersonalizationVector:
    def __init__(self, user_id: int):
        self.user_id = user_id
        self.category_weights: dict[str, float] = {}
        self.merchant_mappings: dict[str, dict] = {}
        self.description_mappings: dict[str, dict] = {}
        self.category_names: dict[int, str] = {}
        self.transactions_used = 0
        self.last_trained_at: str | None = None
        self.version = "1.0"

    def train_from_history(self, transactions: list) -> None:
        self.category_weights = {}
        self.merchant_mappings = {}
        self.description_mappings = {}
        self.category_names = {}
        self.transactions_used = 0

        category_counter: Counter[int] = Counter()
        merchant_counter: dict[str, Counter[int]] = defaultdict(Counter)
        pattern_counter: dict[str, Counter[int]] = defaultdict(Counter)

        for transaction in transactions:
            category_id = transaction.get("category_id")
            if category_id is None:
                continue

            category_id = int(category_id)
            category_name = str(transaction.get("category_name") or "")
            merchant_key = _normalize_text(transaction.get("counterparty_name"))
            pattern_key = _description_pattern(transaction.get("description"))

            category_counter[category_id] += 1
            self.transactions_used += 1

            if merchant_key:
                merchant_counter[merchant_key][category_id] += 1
            if pattern_key:
                pattern_counter[pattern_key][category_id] += 1

            if category_name:
                self.category_names[category_id] = category_name

        total = sum(category_counter.values())
        if total > 0:
            for category_id, count in category_counter.items():
                self.category_weights[str(category_id)] = round(count / total, 6)

        for merchant, counts in merchant_counter.items():
            category_id, count = counts.most_common(1)[0]
            total_for_merchant = sum(counts.values())
            self.merchant_mappings[merchant] = {
                "category_id": category_id,
                "category_name": self.category_names.get(category_id, ""),
                "confidence": round(count / total_for_merchant, 6),
                "count": count,
            }

        for pattern, counts in pattern_counter.items():
            category_id, count = counts.most_common(1)[0]
            total_for_pattern = sum(counts.values())
            self.description_mappings[pattern] = {
                "category_id": category_id,
                "category_name": self.category_names.get(category_id, ""),
                "confidence": round(count / total_for_pattern, 6),
                "count": count,
            }

        self.last_trained_at = datetime.now(UTC).isoformat()

    def get_vector(self) -> dict:
        return {
            "user_id": self.user_id,
            "category_weights": self.category_weights,
            "merchant_mappings": self.merchant_mappings,
            "description_mappings": self.description_mappings,
            "last_trained_at": self.last_trained_at,
            "transactions_used": self.transactions_used,
            "version": self.version,
        }

    def predict_category(self, description: str, counterparty: str | None) -> dict:
        merchant_key = _normalize_text(counterparty)
        if merchant_key and merchant_key in self.merchant_mappings:
            mapping = self.merchant_mappings[merchant_key]
            return {
                "category_id": int(mapping["category_id"]),
                "category_name": str(mapping.get("category_name") or ""),
                "confidence": float(mapping["confidence"]),
                "method": "personalized",
            }

        pattern_key = _description_pattern(description)
        if pattern_key and pattern_key in self.description_mappings:
            mapping = self.description_mappings[pattern_key]
            return {
                "category_id": int(mapping["category_id"]),
                "category_name": str(mapping.get("category_name") or ""),
                "confidence": float(mapping["confidence"]),
                "method": "personalized",
            }

        return {
            "category_id": None,
            "category_name": None,
            "confidence": 0.0,
            "method": "global",
        }

    def save_to_disk(self) -> None:
        """Persist vector to JSON file."""
        VECTORS_DIR.mkdir(parents=True, exist_ok=True)
        path = VECTORS_DIR / f"user_{self.user_id}.json"
        path.write_text(json.dumps(self.get_vector(), indent=2))
        logger.info("Saved personalization vector for user %d to %s", self.user_id, path)

    @classmethod
    def load_from_disk(cls, user_id: int) -> UserPersonalizationVector | None:
        """Load vector from JSON file if it exists."""
        path = VECTORS_DIR / f"user_{user_id}.json"
        if not path.exists():
            return None

        try:
            data = json.loads(path.read_text())
            vector = cls(user_id)
            vector.category_weights = data.get("category_weights", {})
            vector.merchant_mappings = data.get("merchant_mappings", {})
            vector.description_mappings = data.get("description_mappings", {})
            vector.transactions_used = data.get("transactions_used", 0)
            vector.last_trained_at = data.get("last_trained_at")
            vector.version = data.get("version", "1.0")
            # Rebuild category_names from merchant_mappings
            for mapping in vector.merchant_mappings.values():
                cat_id = mapping.get("category_id")
                cat_name = mapping.get("category_name")
                if cat_id is not None and cat_name:
                    vector.category_names[int(cat_id)] = cat_name
            logger.info("Loaded personalization vector for user %d", user_id)
            return vector
        except (json.JSONDecodeError, KeyError) as e:
            logger.warning("Failed to load vector for user %d: %s", user_id, e)
            return None


_vector_cache: dict[int, UserPersonalizationVector] = {}


def get_user_vector(user_id: int) -> UserPersonalizationVector:
    if user_id not in _vector_cache:
        # Try loading from disk first
        loaded = UserPersonalizationVector.load_from_disk(user_id)
        if loaded:
            _vector_cache[user_id] = loaded
        else:
            _vector_cache[user_id] = UserPersonalizationVector(user_id)
    return _vector_cache[user_id]


async def retrain_user_vector(user_id: int) -> dict:
    history = await get_user_categorization_history(user_id=user_id)
    vector = get_user_vector(user_id)
    vector.train_from_history(history)
    vector.save_to_disk()
    _vector_cache[user_id] = vector

    return {
        "success": True,
        "transactions_used": vector.transactions_used,
        "vector_version": vector.version,
        "message": "Personalization vector trained"
        if vector.transactions_used > 0
        else "No categorized transactions found for user",
    }
