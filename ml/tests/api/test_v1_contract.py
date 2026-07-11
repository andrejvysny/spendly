"""Contract tests for the /api/v1 surface Laravel's MlService consumes."""

from __future__ import annotations

from datetime import datetime, timedelta

import httpx

from tests.conftest import AUTH, Seeder


def _seed_labeled_corpus(seed: Seeder, *, n_per_class: int = 30) -> None:
    seed.user(1)
    seed.account(10, 1)
    seed.category(1, 1, "Groceries")
    seed.category(2, 1, "Streaming")
    base = datetime(2026, 1, 1)
    tx_id = 1000
    for cat_id, desc, amount in ((1, "LIDL SK", -25.0), (2, "NETFLIX.COM", -9.99)):
        for k in range(n_per_class):
            seed.transaction(
                tx_id, 10, amount, f"{desc} {k}",
                (base + timedelta(days=k)).strftime("%Y-%m-%d %H:%M:%S"),
                partner=desc, category_id=cat_id,
            )
            tx_id += 1


async def test_categorize_contract_shape(client: httpx.AsyncClient, seed: Seeder) -> None:
    _seed_labeled_corpus(seed)
    seed.transaction(1, 10, -19.0, "LIDL SK 42", "2026-03-01 10:00:00", partner="LIDL SK")

    response = await client.post("/api/v1/categorize", json={"user_id": 1}, headers=AUTH)

    assert response.status_code == 200
    rows = response.json()
    assert len(rows) == 1
    row = rows[0]
    # Exact keys MlSuggestionService::annotateTransactions expects
    # (auto_apply is additive — computed sidecar-side, ignored by Laravel for now).
    assert set(row.keys()) == {
        "transaction_id",
        "predicted_category_id",
        "confidence",
        "method",
        "needs_review",
        "auto_apply",
    }
    assert row["transaction_id"] == 1
    assert isinstance(row["auto_apply"], bool)


async def test_train_categorizer_success_and_artifacts(
    client: httpx.AsyncClient, seed: Seeder
) -> None:
    from app.core.config import settings

    _seed_labeled_corpus(seed)

    response = await client.post(
        "/api/v1/train/categorizer", json={"user_id": 1}, headers=AUTH
    )

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "success"
    assert "metrics" in body
    assert list(settings.models_dir.glob("categorizer_user_1_v*.joblib"))


async def test_train_categorizer_without_labels_errors_cleanly(
    client: httpx.AsyncClient, seed: Seeder
) -> None:
    seed.user(1)

    response = await client.post(
        "/api/v1/train/categorizer", json={"user_id": 1}, headers=AUTH
    )

    assert response.status_code == 200
    assert response.json()["status"] == "error"


async def test_detect_counterparties_exact_match(
    client: httpx.AsyncClient, seed: Seeder
) -> None:
    seed.user(1)
    seed.account(10, 1)
    seed.counterparty(5, 1, "Netflix")
    seed.transaction(1, 10, -9.99, "NETFLIX payment", "2026-03-01 10:00:00", partner="Netflix")

    response = await client.post(
        "/api/v1/detect-counterparties", json={"user_id": 1}, headers=AUTH
    )

    assert response.status_code == 200
    rows = response.json()
    assert rows[0]["predicted_counterparty_id"] == 5
    assert rows[0]["method"] == "exact_name_match"


async def test_detect_recurring_shape_and_lookback_validation(
    client: httpx.AsyncClient, seed: Seeder
) -> None:
    seed.user(1)
    seed.account(10, 1)
    base = datetime.now() - timedelta(days=180)
    for i in range(6):
        seed.transaction(
            100 + i, 10, -9.99, "SPOTIFY",
            (base + timedelta(days=30 * i)).strftime("%Y-%m-%d %H:%M:%S"),
            partner="SPOTIFY",
        )

    response = await client.post(
        "/api/v1/detect-recurring",
        json={"user_id": 1, "months_lookback": 12},
        headers=AUTH,
    )
    assert response.status_code == 200
    rows = response.json()
    assert rows and {
        "group_key",
        "frequency",
        "interval_days",
        "confidence",
        "transaction_ids",
        "amount_stats",
    } <= set(rows[0].keys())

    out_of_range = await client.post(
        "/api/v1/detect-recurring",
        json={"user_id": 1, "months_lookback": 99},
        headers=AUTH,
    )
    assert out_of_range.status_code == 422


async def test_detect_transfers_emits_both_directions(
    client: httpx.AsyncClient, seed: Seeder
) -> None:
    seed.user(1)
    seed.account(10, 1)
    seed.account(20, 1)
    now = datetime.now() - timedelta(days=2)
    seed.transaction(100, 10, -500.0, "out", now.strftime("%Y-%m-%d %H:%M:%S"))
    seed.transaction(
        101, 20, 500.0, "in", (now + timedelta(hours=1)).strftime("%Y-%m-%d %H:%M:%S")
    )

    response = await client.post(
        "/api/v1/detect-transfers", json={"user_id": 1}, headers=AUTH
    )

    assert response.status_code == 200
    rows = response.json()
    assert {(r["transaction_id"], r["suggested_pair_id"]) for r in rows} == {
        (100, 101),
        (101, 100),
    }


async def test_train_writes_metrics_history_and_endpoint_serves_it(
    client: httpx.AsyncClient, seed: Seeder
) -> None:
    _seed_labeled_corpus(seed, n_per_class=60)

    train = await client.post("/api/v1/train/categorizer", json={"user_id": 1}, headers=AUTH)
    assert train.status_code == 200
    body = train.json()
    assert body["status"] == "success"
    assert "evaluation" in body

    response = await client.get("/api/v1/models/categorizer/metrics?user_id=1", headers=AUTH)
    assert response.status_code == 200
    payload = response.json()
    assert payload["user_id"] == 1
    assert payload["latest"] is not None
    entry = payload["latest"]
    assert {"version", "trained_at", "training_samples", "evaluation"} <= set(entry.keys())


async def test_metrics_endpoint_empty_without_model(
    client: httpx.AsyncClient, seed: Seeder
) -> None:
    seed.user(1)
    response = await client.get("/api/v1/models/categorizer/metrics?user_id=99", headers=AUTH)
    assert response.status_code == 200
    assert response.json() == {"user_id": 99, "history": [], "latest": None}


async def test_metrics_endpoint_requires_auth(client: httpx.AsyncClient) -> None:
    response = await client.get("/api/v1/models/categorizer/metrics?user_id=1")
    assert response.status_code == 401


async def test_stub_endpoints_answer_without_5xx(
    client: httpx.AsyncClient, seed: Seeder
) -> None:
    seed.user(1)
    for path in (
        "/api/v1/train/counterparty-detector",
        "/api/v1/train/transfer-detector",
        "/api/v1/discover-counterparties",
    ):
        response = await client.post(path, json={"user_id": 1}, headers=AUTH)
        assert response.status_code == 200, path
