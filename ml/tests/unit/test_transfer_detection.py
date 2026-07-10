"""Transfer pair heuristics over DB-backed data."""

from __future__ import annotations

from datetime import datetime, timedelta

from app.modules.transfer_detection import detect_transfer_pairs
from tests.conftest import Seeder


def _two_accounts(seed: Seeder) -> None:
    seed.user(1)
    seed.account(10, 1, iban="SK111")
    seed.account(20, 1, iban="SK222")


async def test_matches_cross_account_pair(seed: Seeder) -> None:
    _two_accounts(seed)
    now = datetime.now() - timedelta(days=2)
    seed.transaction(100, 10, -500.0, "Transfer out", now.strftime("%Y-%m-%d %H:%M:%S"))
    seed.transaction(
        101, 20, 500.0, "Transfer in",
        (now + timedelta(hours=1)).strftime("%Y-%m-%d %H:%M:%S"),
    )

    pairs = await detect_transfer_pairs(1, days_lookback=30)

    assert len(pairs) == 1
    assert pairs[0]["transaction_out_id"] == 100
    assert pairs[0]["transaction_in_id"] == 101
    assert pairs[0]["confidence"] > 0.7


async def test_ignores_same_account_and_stale_credits(seed: Seeder) -> None:
    _two_accounts(seed)
    now = datetime.now() - timedelta(days=2)
    seed.transaction(100, 10, -500.0, "out", now.strftime("%Y-%m-%d %H:%M:%S"))
    # same account -> never a pair
    seed.transaction(
        101, 10, 500.0, "same account credit",
        (now + timedelta(hours=1)).strftime("%Y-%m-%d %H:%M:%S"),
    )
    # other account but 3 days later -> outside the 24h window
    seed.transaction(
        102, 20, 500.0, "late credit",
        (now + timedelta(days=3)).strftime("%Y-%m-%d %H:%M:%S"),
    )

    pairs = await detect_transfer_pairs(1, days_lookback=30)

    assert pairs == []
