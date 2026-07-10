"""Personalization vector (frequency counters) tests."""

from __future__ import annotations

from app.core.config import settings
from app.modules.personalization import UserPersonalizationVector


def _history() -> list[dict]:
    rows = []
    for i in range(5):
        rows.append(
            {
                "description": f"NETFLIX.COM payment {i}",
                "counterparty_name": "Netflix",
                "amount": -9.99,
                "category_id": 2,
                "category_name": "Streaming",
            }
        )
    return rows


def test_merchant_mapping_prediction() -> None:
    vector = UserPersonalizationVector(user_id=1)
    vector.train_from_history(_history())

    prediction = vector.predict_category("NETFLIX.COM payment 99", "Netflix")

    assert prediction["category_id"] == 2
    assert prediction["confidence"] > 0.5


def test_save_load_roundtrip_uses_settings_dir() -> None:
    vector = UserPersonalizationVector(user_id=9)
    vector.train_from_history(_history())
    vector.save_to_disk()

    assert (settings.vectors_dir / "user_9.json").exists()

    loaded = UserPersonalizationVector.load_from_disk(9)
    assert loaded is not None
    assert loaded.predict_category("NETFLIX.COM", "Netflix")["category_id"] == 2
