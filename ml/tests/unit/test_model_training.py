"""Categorizer training/persistence tests (synthetic data, tmp model dir)."""

from __future__ import annotations

from app.core.config import settings
from app.modules.model_training import (
    MIN_TRAINING_SAMPLES,
    TransactionCategorizer,
)


def _history(n_per_class: int = 30) -> list[dict]:
    rows: list[dict] = []
    classes = [
        (1, "Groceries", "LIDL SK BRATISLAVA", -25.0),
        (2, "Streaming", "NETFLIX.COM", -9.99),
        (3, "Salary", "ACME SOFTWARE PAYROLL", 2500.0),
    ]
    i = 0
    for cat_id, cat_name, desc, amount in classes:
        for k in range(n_per_class):
            rows.append(
                {
                    "description": f"{desc} {k}",
                    "counterparty_name": desc.split()[0],
                    "amount": amount,
                    "category_id": cat_id,
                    "category_name": cat_name,
                }
            )
            i += 1
    return rows


def test_train_rejects_too_few_samples() -> None:
    categorizer = TransactionCategorizer()
    result = categorizer.train(_history(n_per_class=5))

    assert result["success"] is False
    assert str(MIN_TRAINING_SAMPLES) in str(result.get("error", ""))


def test_train_predict_roundtrip() -> None:
    categorizer = TransactionCategorizer()
    result = categorizer.train(_history())

    assert result["success"] is True

    prediction = categorizer.predict("LIDL SK BRATISLAVA 99", -30.0, counterparty_name="LIDL")
    assert prediction["category_id"] == 1
    assert 0.0 <= prediction["confidence"] <= 1.0


def test_save_load_roundtrip_in_tmp_dir() -> None:
    categorizer = TransactionCategorizer()
    assert categorizer.train(_history())["success"] is True

    path = categorizer.save(user_id=42)
    assert path.exists()
    assert str(settings.models_dir) in str(path)

    loaded = TransactionCategorizer.load(user_id=42)
    assert loaded is not None
    prediction = loaded.predict("NETFLIX.COM 1", -9.99, counterparty_name="NETFLIX.COM")
    assert prediction["category_id"] == 2


def test_save_keeps_only_last_two_versions() -> None:
    categorizer = TransactionCategorizer()
    # version increments on each train(); retention prunes to the last 2
    for _ in range(3):
        assert categorizer.train(_history())["success"] is True
        categorizer.save(user_id=7)

    artifacts = sorted(settings.models_dir.glob("categorizer_user_7_v*.joblib"))
    assert len(artifacts) == 2
