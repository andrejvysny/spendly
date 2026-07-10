"""Recurring detection over DB-backed data (regression: string booked_date)."""

from __future__ import annotations

from datetime import datetime, timedelta

from app.modules.recurring_detection import detect_recurring_patterns
from tests.conftest import Seeder


def _seed_monthly(seed: Seeder, *, months: int = 6, amount: float = -9.99) -> None:
    seed.user(1)
    seed.account(10, 1)
    base = datetime.now() - timedelta(days=30 * months)
    for i in range(months):
        day = base + timedelta(days=30 * i)
        seed.transaction(
            100 + i, 10, amount, "NETFLIX.COM", day.strftime("%Y-%m-%d %H:%M:%S"),
            partner="NETFLIX.COM",
        )


async def test_detects_monthly_pattern_from_sqlite_string_dates(seed: Seeder) -> None:
    """booked_date arrives as TEXT from SQLite; before the coercion fix this
    crashed on str >= datetime comparison."""
    _seed_monthly(seed)

    patterns = await detect_recurring_patterns(1, months_lookback=12)

    assert len(patterns) == 1
    assert patterns[0]["frequency"] == "monthly"
    assert patterns[0]["transaction_count"] == 6
    assert patterns[0]["amount"] == -9.99


async def test_rejects_unstable_amounts(seed: Seeder) -> None:
    seed.user(1)
    seed.account(10, 1)
    base = datetime.now() - timedelta(days=120)
    for i, amount in enumerate([-10.0, -50.0, -3.0, -80.0]):
        day = base + timedelta(days=30 * i)
        seed.transaction(
            100 + i, 10, amount, "RANDOM SHOP", day.strftime("%Y-%m-%d %H:%M:%S"),
            partner="RANDOM SHOP",
        )

    patterns = await detect_recurring_patterns(1, months_lookback=12)

    assert patterns == []
