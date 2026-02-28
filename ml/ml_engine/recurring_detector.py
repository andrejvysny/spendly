"""Feature-engineered recurring transaction detection.

Improvements over original:
- Autocorrelation-based interval detection (not fixed buckets)
- Wider interval coverage (handles biweekly, semi-annual, etc.)
- Amount anomaly detection via z-scores
- Description consistency scoring
- Learns from confirmed/dismissed RecurringGroup feedback
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from datetime import timedelta
from typing import Any

import numpy as np
import pandas as pd
from rapidfuzz import fuzz
from scipy import signal

from .config import Config
from .preprocessor import clean_description, normalize_merchant

logger = logging.getLogger(__name__)

# Candidate periods in days for autocorrelation
CANDIDATE_LAGS = [7, 14, 30, 60, 90, 180, 365]

# Frequency label mapping
FREQUENCY_LABELS: dict[str, tuple[float, float]] = {
    "weekly": (5, 9),
    "biweekly": (12, 17),
    "monthly": (26, 35),
    "bimonthly": (55, 65),
    "quarterly": (80, 100),
    "semi_annual": (170, 195),
    "annual": (350, 380),
}


@dataclass
class RecurringGroup:
    group_key: str
    frequency: str
    interval_days: float
    confidence: float
    transaction_ids: list[int]
    amount_stats: dict[str, float]
    next_expected: str | None
    anomalies: list[dict] = field(default_factory=list)


@dataclass
class GroupFeatures:
    """Features extracted from a candidate recurring group."""
    interval_mean: float
    interval_median: float
    interval_std: float
    interval_cv: float  # coefficient of variation
    amount_mean: float
    amount_std: float
    amount_cv: float
    description_consistency: float
    day_of_month_std: float
    best_autocorr_lag: int
    best_autocorr_value: float
    dominant_freq_power: float
    count: int


class RecurringDetector:
    """Feature-engineered recurring transaction detector."""

    def __init__(self, user_id: int):
        self.user_id = user_id

    def detect(
        self,
        df: pd.DataFrame,
        recurring_groups_df: pd.DataFrame | None = None,
    ) -> list[RecurringGroup]:
        """Detect recurring patterns in transactions.

        Args:
            df: user's transactions
            recurring_groups_df: existing RecurringGroup records for feedback learning
        """
        if len(df) < Config.THRESHOLDS.recurring_min_transactions:
            return []

        groups = self._group_transactions(df)
        results: list[RecurringGroup] = []

        for key, group_df in groups.items():
            if len(group_df) < Config.THRESHOLDS.recurring_min_transactions:
                continue

            features = self._extract_features(group_df)
            if features is None:
                continue

            freq, confidence = self._classify_frequency(features)
            if freq is None or confidence < Config.THRESHOLDS.recurring_min_confidence:
                continue

            anomalies = self._detect_anomalies(group_df, features)
            next_expected = self._predict_next(group_df, features)

            results.append(RecurringGroup(
                group_key=key,
                frequency=freq,
                interval_days=round(features.interval_mean, 1),
                confidence=round(confidence, 3),
                transaction_ids=group_df["id"].tolist(),
                amount_stats={
                    "mean": round(features.amount_mean, 2),
                    "std": round(features.amount_std, 2),
                },
                next_expected=next_expected,
                anomalies=anomalies,
            ))

        results.sort(key=lambda r: r.confidence, reverse=True)
        return results

    def _group_transactions(self, df: pd.DataFrame) -> dict[str, pd.DataFrame]:
        """Group transactions by normalized merchant or description."""
        groups: dict[str, pd.DataFrame] = {}

        for _, row in df.iterrows():
            # Prefer merchant_id grouping
            if pd.notna(row.get("merchant_id")):
                key = f"merchant:{int(row['merchant_id'])}"
            else:
                # Group by normalized description
                partner = str(row.get("partner", "") or "")
                desc = str(row.get("description", "") or "")
                raw = partner if partner.strip() else desc
                normalized = normalize_merchant(raw)
                if not normalized:
                    continue
                key = f"desc:{normalized}"

            if key not in groups:
                groups[key] = []
            groups[key].append(row)

        return {
            k: pd.DataFrame(v).sort_values("booked_date").reset_index(drop=True)
            for k, v in groups.items()
        }

    def _extract_features(self, group: pd.DataFrame) -> GroupFeatures | None:
        """Extract recurring detection features from a transaction group."""
        dates = pd.to_datetime(group["booked_date"])
        intervals = dates.diff().dt.days.dropna().values.astype(float)

        if len(intervals) < 2:
            return None

        # Filter out very small intervals (same-day duplicates)
        intervals = intervals[intervals > 1]
        if len(intervals) < 2:
            return None

        interval_mean = float(np.mean(intervals))
        interval_std = float(np.std(intervals))
        interval_cv = interval_std / interval_mean if interval_mean > 0 else 999.0

        amounts = group["amount"].astype(float).values
        amount_mean = float(np.mean(amounts))
        amount_std = float(np.std(amounts))
        amount_cv = abs(amount_std / amount_mean) if amount_mean != 0 else 0.0

        # Description consistency (mean pairwise rapidfuzz score)
        descs = group["description"].fillna("").astype(str).tolist()
        desc_consistency = self._description_consistency(descs)

        # Day of month consistency
        dom = dates.dt.day.values.astype(float)
        dom_std = float(np.std(dom)) if len(dom) > 1 else 999.0

        # Autocorrelation at candidate lags
        best_lag, best_corr = self._best_autocorrelation(dates)

        # Periodogram dominant frequency
        dominant_power = self._dominant_frequency_power(intervals)

        return GroupFeatures(
            interval_mean=interval_mean,
            interval_median=float(np.median(intervals)),
            interval_std=interval_std,
            interval_cv=interval_cv,
            amount_mean=amount_mean,
            amount_std=amount_std,
            amount_cv=amount_cv,
            description_consistency=desc_consistency,
            day_of_month_std=dom_std,
            best_autocorr_lag=best_lag,
            best_autocorr_value=best_corr,
            dominant_freq_power=dominant_power,
            count=len(group),
        )

    def _classify_frequency(self, f: GroupFeatures) -> tuple[str | None, float]:
        """Classify recurring frequency from features. Returns (frequency, confidence)."""
        # High interval CV means irregular — not recurring
        if f.interval_cv > 0.50:
            return None, 0.0

        # Try to match interval to known frequency labels
        best_freq = None
        best_confidence = 0.0

        for label, (lo, hi) in FREQUENCY_LABELS.items():
            if lo <= f.interval_mean <= hi:
                # Base confidence from how well interval fits the bucket
                mid = (lo + hi) / 2
                spread = (hi - lo) / 2
                fit = 1.0 - abs(f.interval_mean - mid) / spread
                confidence = fit * 0.4

                # Bonus from low interval CV
                cv_bonus = max(0, (0.30 - f.interval_cv) / 0.30) * 0.25
                confidence += cv_bonus

                # Bonus from description consistency
                desc_bonus = f.description_consistency * 0.15
                confidence += desc_bonus

                # Bonus from autocorrelation
                if f.best_autocorr_value > 0.3:
                    confidence += 0.10

                # Bonus from count
                count_bonus = min(0.10, (f.count - 3) * 0.02)
                confidence += count_bonus

                confidence = min(confidence, 0.99)

                if confidence > best_confidence:
                    best_freq = label
                    best_confidence = confidence

        # Fallback: use autocorrelation peak if no bucket match
        if best_freq is None and f.best_autocorr_value > 0.5:
            for label, (lo, hi) in FREQUENCY_LABELS.items():
                if lo <= f.best_autocorr_lag <= hi:
                    best_freq = label
                    best_confidence = f.best_autocorr_value * 0.6
                    break

        return best_freq, best_confidence

    def _detect_anomalies(self, group: pd.DataFrame, f: GroupFeatures) -> list[dict]:
        """Detect amount or timing anomalies in confirmed recurring groups."""
        anomalies: list[dict] = []

        if f.amount_std == 0:
            return anomalies

        amounts = group["amount"].astype(float).values
        for i, (_, row) in enumerate(group.iterrows()):
            z_score = abs(float(row["amount"]) - f.amount_mean) / f.amount_std if f.amount_std > 0 else 0
            if z_score > 2.5:
                anomalies.append({
                    "transaction_id": int(row["id"]),
                    "type": "amount_deviation",
                    "z_score": round(z_score, 2),
                    "expected": round(f.amount_mean, 2),
                    "actual": round(float(row["amount"]), 2),
                })

        return anomalies

    def _predict_next(self, group: pd.DataFrame, f: GroupFeatures) -> str | None:
        """Predict next expected transaction date."""
        last_date = pd.to_datetime(group["booked_date"]).max()
        if pd.isna(last_date):
            return None
        next_date = last_date + timedelta(days=f.interval_mean)
        return next_date.strftime("%Y-%m-%d")

    @staticmethod
    def _description_consistency(descriptions: list[str]) -> float:
        """Mean pairwise rapidfuzz score (sample if too many)."""
        if len(descriptions) < 2:
            return 1.0

        # Sample pairs if too many
        pairs = min(len(descriptions), 10)
        scores = []
        for i in range(min(pairs, len(descriptions))):
            for j in range(i + 1, min(pairs, len(descriptions))):
                scores.append(fuzz.token_set_ratio(descriptions[i], descriptions[j]) / 100.0)

        return float(np.mean(scores)) if scores else 1.0

    @staticmethod
    def _best_autocorrelation(dates: pd.Series) -> tuple[int, float]:
        """Find best autocorrelation lag from candidate periods."""
        dates_sorted = pd.to_datetime(dates).sort_values()
        if len(dates_sorted) < 3:
            return 0, 0.0

        # Build binary time series (1 on transaction days, 0 otherwise)
        date_range = pd.date_range(dates_sorted.min(), dates_sorted.max(), freq="D")
        ts = pd.Series(0, index=date_range)
        for d in dates_sorted:
            if d in ts.index:
                ts.loc[d] = 1

        if len(ts) < 10:
            return 0, 0.0

        ts_centered = ts - ts.mean()
        norm = float(np.sum(ts_centered**2))
        if norm == 0:
            return 0, 0.0

        best_lag = 0
        best_corr = 0.0

        for lag in CANDIDATE_LAGS:
            if lag >= len(ts):
                continue
            corr = float(np.sum(ts_centered[lag:].values * ts_centered[:-lag].values)) / norm
            if corr > best_corr:
                best_lag = lag
                best_corr = corr

        return best_lag, best_corr

    @staticmethod
    def _dominant_frequency_power(intervals: np.ndarray) -> float:
        """Power of dominant frequency in interval periodogram."""
        if len(intervals) < 4:
            return 0.0

        try:
            freqs, power = signal.periodogram(intervals)
            if len(power) < 2:
                return 0.0
            # Ratio of max power to total (higher = more periodic)
            total = float(np.sum(power))
            if total == 0:
                return 0.0
            return float(np.max(power)) / total
        except Exception:
            return 0.0
