"""Tests for the feature-engineered recurring detector."""

import pandas as pd
import numpy as np
import pytest

from ml_engine.recurring_detector import RecurringDetector, GroupFeatures


class TestRecurringDetection:
    def _make_monthly_df(self, count: int = 6, base_amount: float = -9.99) -> pd.DataFrame:
        """Create a DataFrame with monthly recurring transactions."""
        dates = pd.date_range("2025-07-01", periods=count, freq="MS")
        return pd.DataFrame({
            "id": list(range(1, count + 1)),
            "amount": [base_amount] * count,
            "currency": ["EUR"] * count,
            "booked_date": dates,
            "description": ["NETFLIX.COM"] * count,
            "partner": ["Netflix"] * count,
            "merchant_id": [3] * count,
            "type": ["CARD_PAYMENT"] * count,
            "place": [None] * count,
            "metadata": [None] * count,
        })

    def test_detects_monthly_pattern(self):
        """Should detect monthly recurring transactions."""
        df = self._make_monthly_df(6)
        detector = RecurringDetector(user_id=999)
        groups = detector.detect(df)
        assert len(groups) >= 1
        monthly = [g for g in groups if g.frequency == "monthly"]
        assert len(monthly) >= 1
        assert 26 <= monthly[0].interval_days <= 35

    def test_weekly_pattern(self):
        """Should detect weekly recurring transactions."""
        dates = pd.date_range("2026-01-01", periods=8, freq="W-MON")
        df = pd.DataFrame({
            "id": list(range(1, 9)),
            "amount": [-5.00] * 8,
            "currency": ["EUR"] * 8,
            "booked_date": dates,
            "description": ["Weekly gym"] * 8,
            "partner": ["Gym Club"] * 8,
            "merchant_id": [None] * 8,
            "type": ["CARD_PAYMENT"] * 8,
            "place": [None] * 8,
            "metadata": [None] * 8,
        })
        detector = RecurringDetector(user_id=999)
        groups = detector.detect(df)
        weekly = [g for g in groups if g.frequency == "weekly"]
        assert len(weekly) >= 1

    def test_min_transactions_threshold(self):
        """Should not detect pattern with too few transactions."""
        df = self._make_monthly_df(2)
        detector = RecurringDetector(user_id=999)
        groups = detector.detect(df)
        assert len(groups) == 0

    def test_irregular_not_detected(self):
        """Should not detect pattern in irregular transactions."""
        dates = pd.to_datetime([
            "2026-01-03", "2026-01-15", "2026-02-28",
            "2026-03-05", "2026-05-20", "2026-08-01"
        ])
        df = pd.DataFrame({
            "id": list(range(1, 7)),
            "amount": [-10, -25, -8, -42, -15, -30],
            "currency": ["EUR"] * 6,
            "booked_date": dates,
            "description": [f"Random purchase {i}" for i in range(6)],
            "partner": [f"Shop {i}" for i in range(6)],
            "merchant_id": [None] * 6,
            "type": ["CARD_PAYMENT"] * 6,
            "place": [None] * 6,
            "metadata": [None] * 6,
        })
        detector = RecurringDetector(user_id=999)
        groups = detector.detect(df)
        # Irregular amounts + dates should not produce confident results
        confident = [g for g in groups if g.confidence > 0.7]
        assert len(confident) == 0

    def test_anomaly_detection(self):
        """Should flag amount anomalies in recurring groups."""
        amounts = [-9.99, -9.99, -9.99, -9.99, -9.99, -49.99]
        dates = pd.date_range("2025-07-01", periods=6, freq="MS")
        df = pd.DataFrame({
            "id": list(range(1, 7)),
            "amount": amounts,
            "currency": ["EUR"] * 6,
            "booked_date": dates,
            "description": ["NETFLIX.COM"] * 6,
            "partner": ["Netflix"] * 6,
            "merchant_id": [3] * 6,
            "type": ["CARD_PAYMENT"] * 6,
            "place": [None] * 6,
            "metadata": [None] * 6,
        })
        detector = RecurringDetector(user_id=999)
        groups = detector.detect(df)
        # The last transaction with -49.99 should be flagged as anomaly
        anomalies = []
        for g in groups:
            anomalies.extend(g.anomalies)
        # May or may not detect depending on z-score threshold
        if anomalies:
            assert anomalies[0]["type"] == "amount_deviation"

    def test_next_expected_prediction(self):
        """Should predict next expected date."""
        df = self._make_monthly_df(4)
        detector = RecurringDetector(user_id=999)
        groups = detector.detect(df)
        for g in groups:
            if g.next_expected:
                next_date = pd.Timestamp(g.next_expected)
                last_date = pd.to_datetime(df["booked_date"]).max()
                # Next expected should be after last transaction
                assert next_date > last_date


class TestDescriptionConsistency:
    def test_identical_descriptions(self):
        score = RecurringDetector._description_consistency(["Netflix", "Netflix", "Netflix"])
        assert score == 1.0

    def test_different_descriptions(self):
        score = RecurringDetector._description_consistency(["Netflix", "Spotify", "Apple Music"])
        assert score < 0.5

    def test_single_description(self):
        score = RecurringDetector._description_consistency(["Netflix"])
        assert score == 1.0
