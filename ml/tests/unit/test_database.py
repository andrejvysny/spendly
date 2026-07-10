"""Query-layer tests against the real Spendly schema."""

from __future__ import annotations

import sqlite3
from datetime import datetime
from pathlib import Path

from app.core.database import (
    get_transactions_for_categorization,
    get_transactions_for_user,
    get_user_categories,
    get_user_categorization_history,
    get_user_counterparties,
)
from tests.conftest import Seeder

EXPECTED_TRANSACTION_COLUMNS = {
    "id",
    "account_id",
    "transaction_id",
    "amount",
    "currency",
    "booked_date",
    "processed_date",
    "description",
    "target_iban",
    "source_iban",
    "partner",
    "type",
    "counterparty_id",
    "category_id",
    "transfer_pair_transaction_id",
    "recurring_group_id",
    "native_amount",
}

EXPECTED_CATEGORY_COLUMNS = {"id", "user_id", "name", "parent_category_id"}


def test_fixture_schema_matches_expected_columns(fresh_db: sqlite3.Connection) -> None:
    """Guard against fixture drift from real migrations (the bug class that
    silently broke training/recurring/transfer endpoints)."""
    tx_cols = {row[1] for row in fresh_db.execute("PRAGMA table_info(transactions)")}
    missing = EXPECTED_TRANSACTION_COLUMNS - tx_cols
    assert not missing, f"transactions fixture missing columns: {missing}"

    cat_cols = {row[1] for row in fresh_db.execute("PRAGMA table_info(categories)")}
    missing = EXPECTED_CATEGORY_COLUMNS - cat_cols
    assert not missing, f"categories fixture missing columns: {missing}"
    # Columns the code must NOT rely on (they never existed in Spendly).
    assert "counterparty_name" not in tx_cols
    assert "keywords" not in cat_cols


def test_schema_fixture_header_names_regeneration_source() -> None:
    text = (Path(__file__).parent.parent / "fixtures" / "schema.sql").read_text()
    assert "database/migrations" in text


async def test_get_transactions_for_user_coerces_dates_and_coalesces_name(
    seed: Seeder,
) -> None:
    seed.user(1)
    seed.account(10, 1)
    seed.counterparty(5, 1, "Netflix International")
    seed.transaction(
        100, 10, -9.99, "NETFLIX.COM 123", "2026-01-15 08:00:00",
        partner="NETFLIX.COM", counterparty_id=5,
    )
    seed.transaction(101, 10, -3.50, "Coffee", "2026-01-16 09:00:00", partner="Cafe Bar")

    rows = await get_transactions_for_user(1)

    assert len(rows) == 2
    by_id = {r["id"]: r for r in rows}
    # linked counterparty wins over raw partner
    assert by_id[100]["counterparty_name"] == "Netflix International"
    # falls back to partner when no counterparty is linked
    assert by_id[101]["counterparty_name"] == "Cafe Bar"
    # aiosqlite returns strings; the layer must hand out datetimes
    assert isinstance(by_id[100]["transaction_date"], datetime)


async def test_get_transactions_for_user_scopes_by_user(seed: Seeder) -> None:
    seed.user(1)
    seed.user(2)
    seed.account(10, 1)
    seed.account(20, 2)
    seed.transaction(100, 10, -1.0, "mine", "2026-01-01 00:00:00")
    seed.transaction(200, 20, -1.0, "theirs", "2026-01-01 00:00:00")

    rows = await get_transactions_for_user(1)

    assert [r["id"] for r in rows] == [100]


async def test_categorization_history_returns_only_labeled(seed: Seeder) -> None:
    seed.user(1)
    seed.account(10, 1)
    seed.category(3, 1, "Groceries")
    seed.transaction(100, 10, -20.0, "LIDL", "2026-01-10 00:00:00", category_id=3)
    seed.transaction(101, 10, -5.0, "unlabeled", "2026-01-11 00:00:00")

    rows = await get_user_categorization_history(1)

    assert len(rows) == 1
    assert rows[0]["category_id"] == 3
    assert rows[0]["category_name"] == "Groceries"
    assert isinstance(rows[0]["booked_date"], datetime)


async def test_get_user_categories_real_columns(seed: Seeder) -> None:
    seed.user(1)
    seed.category(3, 1, "Groceries")

    rows = await get_user_categories(1)

    assert rows == [{"id": 3, "name": "Groceries", "parent_category_id": None}]


async def test_categorization_query_filters_by_ids_or_uncategorized(seed: Seeder) -> None:
    seed.user(1)
    seed.account(10, 1)
    seed.category(3, 1, "Groceries")
    seed.transaction(100, 10, -20.0, "LIDL", "2026-01-10 00:00:00", category_id=3)
    seed.transaction(101, 10, -5.0, "uncategorized", "2026-01-11 00:00:00")

    default_rows = await get_transactions_for_categorization(1)
    assert [r["id"] for r in default_rows] == [101]

    explicit = await get_transactions_for_categorization(1, transaction_ids=[100])
    assert [r["id"] for r in explicit] == [100]


async def test_get_user_counterparties(seed: Seeder) -> None:
    seed.user(1)
    seed.counterparty(5, 1, "Netflix")

    rows = await get_user_counterparties(1)

    assert rows[0]["name"] == "Netflix"
