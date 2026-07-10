from __future__ import annotations

from datetime import datetime, timedelta

from app.core.database import get_transactions_for_user


async def detect_transfer_pairs(user_id: int, days_lookback: int = 30) -> list[dict]:
    """Detect cross-account transfer pairs by matching debits to credits."""
    transactions = await get_transactions_for_user(user_id, limit=5000)

    cutoff = datetime.now() - timedelta(days=days_lookback)
    transactions = [
        t
        for t in transactions
        if t.get("transaction_date") and t["transaction_date"] >= cutoff
    ]

    out_txns = [t for t in transactions if float(t.get("amount", 0)) < 0]
    in_txns = [t for t in transactions if float(t.get("amount", 0)) > 0]

    pairs = []
    matched_in_ids: set[int] = set()

    for out_txn in out_txns:
        out_amount = abs(float(out_txn.get("amount", 0)))
        out_date = out_txn.get("transaction_date")
        out_account = out_txn.get("account_id")

        best_match = None
        best_score = 0.0
        best_time_diff = 0.0

        for in_txn in in_txns:
            if in_txn.get("id") in matched_in_ids:
                continue

            # Must be different accounts
            if in_txn.get("account_id") == out_account:
                continue

            in_amount = float(in_txn.get("amount", 0))
            in_date = in_txn.get("transaction_date")

            amount_diff = abs(out_amount - in_amount)
            if amount_diff > 1.0:
                continue

            if out_date and in_date:
                time_diff = abs((in_date - out_date).total_seconds() / 60)
                if time_diff > 1440:  # 24 hours
                    continue
            else:
                time_diff = 0.0

            score = 1.0 - (amount_diff / max(out_amount, 0.01)) - (time_diff / 1440) * 0.5

            if score > best_score:
                best_score = score
                best_match = in_txn
                best_time_diff = time_diff

        if best_match and best_score > 0.7:
            matched_in_ids.add(int(best_match["id"]))

            pairs.append(
                {
                    "transaction_out_id": out_txn.get("id"),
                    "transaction_in_id": best_match.get("id"),
                    "from_account": out_txn.get("account_name", "Unknown"),
                    "to_account": best_match.get("account_name", "Unknown"),
                    "amount": out_amount,
                    "confidence": round(best_score, 4),
                    "time_diff_minutes": int(best_time_diff),
                }
            )

    return sorted(pairs, key=lambda x: x["confidence"], reverse=True)
