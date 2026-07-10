from __future__ import annotations

from collections import defaultdict
from datetime import datetime, timedelta

from app.core.database import get_transactions_for_user


async def detect_recurring_patterns(
    user_id: int, months_lookback: int = 6
) -> list[dict]:
    """Detect recurring payment patterns from transaction history."""
    transactions = await get_transactions_for_user(user_id, limit=5000)

    cutoff = datetime.now() - timedelta(days=months_lookback * 30)
    transactions = [
        t
        for t in transactions
        if t.get("transaction_date") and t["transaction_date"] >= cutoff
    ]

    merchant_groups: dict[str, list] = defaultdict(list)
    for txn in transactions:
        merchant = txn.get("counterparty_name") or txn.get("description", "")
        if merchant:
            merchant_groups[merchant].append(txn)

    patterns = []
    for merchant, txns in merchant_groups.items():
        if len(txns) < 2:
            continue

        amounts = [float(t.get("amount", 0)) for t in txns]
        avg_amount = sum(amounts) / len(amounts)
        if abs(avg_amount) < 0.01:
            continue

        amount_variance = max(abs(a - avg_amount) for a in amounts)
        if amount_variance > abs(avg_amount) * 0.1:
            continue

        dates = sorted(
            [t["transaction_date"] for t in txns if t.get("transaction_date")]
        )
        if len(dates) < 2:
            continue

        intervals = [(dates[i] - dates[i - 1]).days for i in range(1, len(dates))]
        if not intervals:
            continue

        avg_interval = sum(intervals) / len(intervals)
        interval_variance = max(abs(i - avg_interval) for i in intervals)

        if interval_variance > 5:
            continue

        if 25 <= avg_interval <= 35:
            frequency = "monthly"
        elif 6 <= avg_interval <= 8:
            frequency = "weekly"
        else:
            continue

        confidence = min(
            0.9,
            0.5 + len(txns) * 0.1 - (amount_variance / abs(avg_amount)) * 0.2,
        )

        patterns.append(
            {
                "merchant_name": merchant,
                "amount": round(avg_amount, 2),
                "frequency": frequency,
                "confidence": round(confidence, 4),
                "transaction_count": len(txns),
                "transaction_ids": [int(t.get("id", 0)) for t in txns if t.get("id")],
                "next_expected": (dates[-1] + timedelta(days=avg_interval)).isoformat(),
            }
        )

    patterns.sort(key=lambda x: x["confidence"], reverse=True)
    return patterns
