"""Duplicate transaction detector — rapidfuzz upgrade (MIT license)."""

from __future__ import annotations

import pandas as pd
from rapidfuzz import fuzz


class DuplicateDetector:
    def detect(self, df: pd.DataFrame, window_days: int = 3) -> pd.DataFrame:
        """Detect duplicate transactions within a time window."""
        duplicates: list[dict] = []

        df = df.sort_values("booked_date")

        for _, group in df.groupby("amount"):
            if len(group) < 2:
                continue

            for i in range(len(group)):
                tx1 = group.iloc[i]
                for j in range(i + 1, len(group)):
                    tx2 = group.iloc[j]

                    days_diff = abs((tx1["booked_date"] - tx2["booked_date"]).days)
                    if days_diff > window_days:
                        break

                    desc_score = fuzz.ratio(str(tx1["description"]), str(tx2["description"]))

                    if desc_score > 80:
                        duplicates.append({
                            "tx1_id": tx1["id"],
                            "tx2_id": tx2["id"],
                            "score": desc_score,
                            "days_diff": days_diff,
                            "amount": tx1["amount"],
                        })

        return pd.DataFrame(duplicates)
