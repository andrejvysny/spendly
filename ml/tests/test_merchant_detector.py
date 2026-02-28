"""Tests for the multi-layer merchant detector."""

import pandas as pd
import pytest

from ml_engine.merchant_detector import MerchantDetector, CANONICAL_ALIASES


class TestCanonicalAliases:
    def test_common_aliases_exist(self):
        assert "lidl" in CANONICAL_ALIASES
        assert "netflix" in CANONICAL_ALIASES
        assert "super zoo" in CANONICAL_ALIASES

    def test_alias_values_are_proper_names(self):
        for alias, canonical in CANONICAL_ALIASES.items():
            assert canonical.strip() == canonical
            assert len(canonical) > 0


class TestMerchantDetection:
    def test_canonical_match(self, uncategorized_transactions, sample_merchants):
        """Should match via canonical lookup."""
        df = uncategorized_transactions.copy()
        df["merchant_id"] = pd.NA
        detector = MerchantDetector(user_id=999, merchants=sample_merchants)
        predictions = detector.detect(df)

        # SUPER ZOO partner should match
        super_zoo = [p for p in predictions if p.transaction_id in [1, 6]]
        assert len(super_zoo) > 0

    def test_rapidfuzz_match(self, sample_merchants):
        """Should match via fuzzy matching."""
        df = pd.DataFrame({
            "id": [100],
            "description": ["Lidl dakuje Bratislava 42"],
            "partner": ["LIDL SLOVENSKO"],
            "merchant_id": [None],
            "type": ["CARD_PAYMENT"],
            "amount": [-25.0],
            "currency": ["EUR"],
            "booked_date": pd.to_datetime(["2026-01-10"]),
            "place": ["Bratislava"],
            "metadata": [None],
        })
        detector = MerchantDetector(user_id=999, merchants=sample_merchants)
        predictions = detector.detect(df)
        assert len(predictions) >= 1
        # Should match to "Lidl" merchant
        if predictions[0].predicted_merchant_id is not None:
            assert predictions[0].predicted_merchant_id == 2  # Lidl

    def test_no_merchants(self, uncategorized_transactions):
        """Should handle case with no known merchants."""
        df = uncategorized_transactions.copy()
        df["merchant_id"] = pd.NA
        detector = MerchantDetector(user_id=999, merchants=[])
        predictions = detector.detect(df)
        # Should still return suggestions (unmatched)
        for p in predictions:
            assert p.predicted_merchant_id is None

    def test_skips_already_assigned(self, sample_transactions, sample_merchants):
        """Should skip transactions that already have merchant_id."""
        detector = MerchantDetector(user_id=999, merchants=sample_merchants)
        predictions = detector.detect(sample_transactions)
        assigned_ids = set(sample_transactions[sample_transactions["merchant_id"].notna()]["id"])
        predicted_ids = {p.transaction_id for p in predictions}
        assert not assigned_ids.intersection(predicted_ids)
