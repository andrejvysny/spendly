"""Evaluation harness tests (synthetic data)."""

from __future__ import annotations

from datetime import datetime, timedelta

from app.modules.evaluation import (
    THRESHOLD_FALLBACK,
    _compute_thresholds,
    _sort_temporal,
    evaluate_categorizer,
)


def _history(n_per_class: int = 60) -> list[dict]:
    base = datetime(2025, 1, 1)
    rows: list[dict] = []
    classes = [
        (1, "Groceries", "LIDL SK BRATISLAVA", -25.0),
        (2, "Streaming", "NETFLIX.COM", -9.99),
        (3, "Salary", "ACME PAYROLL", 2500.0),
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
                    "booked_date": base + timedelta(days=i % 300),
                }
            )
            i += 1
    return rows


def test_sort_temporal_orders_by_booked_date_and_handles_strings() -> None:
    rows = [
        {"booked_date": "2025-03-01 10:00:00"},
        {"booked_date": datetime(2025, 1, 1)},
        {"booked_date": None},
    ]
    ordered = _sort_temporal(rows)
    assert ordered[0]["booked_date"] is None  # unparseable sorts first
    assert ordered[1]["booked_date"] == datetime(2025, 1, 1)


def test_evaluate_produces_temporal_metrics_and_thresholds() -> None:
    result = evaluate_categorizer(_history())

    assert result["samples"] == 180
    temporal = result["temporal"]
    assert "error" not in temporal
    assert temporal["samples"] == 36
    assert 0.0 <= temporal["accuracy"] <= 1.0
    assert temporal["per_class"]
    # separable synthetic data should be near-perfect
    assert temporal["f1_weighted"] > 0.9
    assert result["thresholds"]  # CV produced per-class thresholds


def test_evaluate_degrades_gracefully_on_tiny_dataset() -> None:
    result = evaluate_categorizer(_history(n_per_class=5))

    assert result["temporal"]["error"]
    assert result["thresholds"] == {}


def test_compute_thresholds_precision_target() -> None:
    # class "1": 3 correct at high conf, 1 wrong at low conf ->
    # threshold excludes the low-confidence mistake region
    oof = [
        ("1", "1", 0.99),
        ("1", "1", 0.95),
        ("1", "1", 0.90),
        ("2", "1", 0.40),  # wrong prediction of class 1 at low confidence
    ]
    thresholds = _compute_thresholds(oof)
    assert thresholds["1"] == 0.90


def test_compute_thresholds_fallback_when_target_unreachable() -> None:
    oof = [("2", "1", 0.99), ("2", "1", 0.98)]  # class 1 always wrong
    thresholds = _compute_thresholds(oof)
    assert thresholds["1"] == THRESHOLD_FALLBACK
