"""Tests for the transfer detection pipeline."""

from __future__ import annotations

import pandas as pd
import pytest

from ml_engine.transfer_detector import (
    TransferClassifier,
    TransferDetector,
    TransferPrediction,
    _find_cross_account_pair,
    _regex_score,
)


class TestRegexScore:
    def test_high_confidence_slovak(self):
        assert _regex_score("PREVOD NA UCET 12345") == 0.85

    def test_high_confidence_english(self):
        assert _regex_score("Own Transfer to savings") == 0.85

    def test_high_confidence_internal(self):
        assert _regex_score("Internal transfer") == 0.85

    def test_high_confidence_sporenie(self):
        assert _regex_score("Sporenie mesacne") == 0.85

    def test_low_confidence_generic_prevod(self):
        assert _regex_score("Prevod - najomne") == 0.65

    def test_low_confidence_generic_transfer(self):
        assert _regex_score("Transfer to account") == 0.65

    def test_low_confidence_uberweisung(self):
        assert _regex_score("Überweisung an Konto") == 0.65

    def test_no_match(self):
        assert _regex_score("Platba kartou LIDL") == 0.0

    def test_empty_string(self):
        assert _regex_score("") == 0.0

    def test_none_like(self):
        assert _regex_score("") == 0.0

    def test_case_insensitive(self):
        assert _regex_score("vlastny PREVOD na sporenie") == 0.85


class TestCrossAccountPair:
    @pytest.fixture
    def all_transactions(self):
        return pd.DataFrame({
            "id": [100, 200, 300],
            "amount": [-50.0, 50.0, 50.0],
            "booked_date": pd.to_datetime(["2026-01-10", "2026-01-10", "2026-01-15"]),
            "account_id": [1, 2, 2],
            "description": ["Prevod na ucet", "Prijem prevod", "Prijem iny"],
        })

    def test_finds_same_day_opposite_amount(self, all_transactions):
        pair_id = _find_cross_account_pair(
            txn_id=100,
            txn_amount=-50.0,
            txn_date=pd.Timestamp("2026-01-10"),
            txn_account_id=1,
            txn_desc="Prevod na ucet",
            all_transactions=all_transactions,
        )
        assert pair_id == 200

    def test_no_match_same_account(self):
        df = pd.DataFrame({
            "id": [100, 200],
            "amount": [-50.0, 50.0],
            "booked_date": pd.to_datetime(["2026-01-10", "2026-01-10"]),
            "account_id": [1, 1],
            "description": ["Prevod", "Prijem"],
        })
        pair_id = _find_cross_account_pair(
            txn_id=100, txn_amount=-50.0,
            txn_date=pd.Timestamp("2026-01-10"),
            txn_account_id=1, txn_desc="Prevod",
            all_transactions=df,
        )
        assert pair_id is None

    def test_no_match_outside_window(self):
        df = pd.DataFrame({
            "id": [100, 200],
            "amount": [-50.0, 50.0],
            "booked_date": pd.to_datetime(["2026-01-10", "2026-01-20"]),
            "account_id": [1, 2],
            "description": ["Prevod", "Prijem"],
        })
        pair_id = _find_cross_account_pair(
            txn_id=100, txn_amount=-50.0,
            txn_date=pd.Timestamp("2026-01-10"),
            txn_account_id=1, txn_desc="Prevod",
            all_transactions=df,
        )
        assert pair_id is None

    def test_no_match_different_amount(self):
        df = pd.DataFrame({
            "id": [100, 200],
            "amount": [-50.0, 60.0],
            "booked_date": pd.to_datetime(["2026-01-10", "2026-01-10"]),
            "account_id": [1, 2],
            "description": ["Prevod", "Prijem"],
        })
        pair_id = _find_cross_account_pair(
            txn_id=100, txn_amount=-50.0,
            txn_date=pd.Timestamp("2026-01-10"),
            txn_account_id=1, txn_desc="Prevod",
            all_transactions=df,
        )
        assert pair_id is None


class TestTransferClassifier:
    def test_untrained_returns_empty(self):
        classifier = TransferClassifier(user_id=99999)
        df = pd.DataFrame({
            "id": [1],
            "amount": [-50.0],
            "description": ["test"],
            "partner": [""],
            "place": [None],
            "type": ["CARD_PAYMENT"],
        })
        result = classifier.predict(df)
        assert result == []

    def test_is_trained_false_by_default(self):
        classifier = TransferClassifier(user_id=99999)
        assert classifier.is_trained is False

    def test_train_insufficient_samples(self):
        df = pd.DataFrame({
            "id": range(5),
            "amount": [-10.0] * 5,
            "description": ["test"] * 5,
            "partner": [""] * 5,
            "place": [None] * 5,
            "type": ["CARD_PAYMENT"] * 5,
            "booked_date": pd.to_datetime(["2026-01-01"] * 5),
            "currency": ["EUR"] * 5,
        })
        classifier = TransferClassifier(user_id=99999)
        result = classifier.train(df)
        assert result["status"] == "error"
        assert "Need at least" in result["message"]

    def test_train_and_predict(self, tmp_path, monkeypatch):
        monkeypatch.setattr("ml_engine.transfer_detector.Config.MODEL_DIR", tmp_path)

        n = 40
        df = pd.DataFrame({
            "id": range(n),
            "amount": [-50.0] * (n // 2) + [50.0] * (n // 2),
            "description": ["Prevod na ucet sporenie"] * (n // 2) + ["Platba kartou LIDL"] * (n // 2),
            "partner": [""] * n,
            "place": [None] * n,
            "type": ["TRANSFER"] * (n // 2) + ["CARD_PAYMENT"] * (n // 2),
            "booked_date": pd.to_datetime(["2026-01-01"] * n),
            "currency": ["EUR"] * n,
        })

        classifier = TransferClassifier(user_id=99999)
        result = classifier.train(df)
        assert result["status"] == "success"
        assert classifier.is_trained

        # Predict on new data
        test_df = pd.DataFrame({
            "id": [100, 101],
            "amount": [-50.0, -10.0],
            "description": ["Prevod na ucet", "Platba kartou"],
            "partner": ["", ""],
            "place": [None, None],
            "type": ["CARD_PAYMENT", "CARD_PAYMENT"],
            "booked_date": pd.to_datetime(["2026-01-01", "2026-01-01"]),
            "currency": ["EUR", "EUR"],
        })
        predictions = classifier.predict(test_df)
        assert len(predictions) == 2
        assert all(isinstance(p, tuple) and len(p) == 2 for p in predictions)


class TestTransferDetector:
    @pytest.fixture
    def candidates(self):
        return pd.DataFrame({
            "id": [1, 2, 3],
            "amount": [-100.0, -50.0, -30.0],
            "booked_date": pd.to_datetime(["2026-01-10", "2026-01-10", "2026-01-10"]),
            "account_id": [1, 1, 1],
            "description": [
                "PREVOD NA UCET sporenie",
                "Platba kartou LIDL",
                "Prevod - najomne",
            ],
            "partner": ["", "LIDL", ""],
            "place": [None, "Bratislava", None],
            "type": ["CARD_PAYMENT", "CARD_PAYMENT", "CARD_PAYMENT"],
            "currency": ["EUR", "EUR", "EUR"],
        })

    def test_regex_detects_transfer(self, candidates):
        detector = TransferDetector(user_id=99999)
        predictions = detector.detect(candidates)

        transfer_ids = {p.transaction_id for p in predictions if p.is_transfer}
        # "PREVOD NA UCET" → high confidence regex match
        assert 1 in transfer_ids
        # "Prevod - najomne" → low confidence regex match
        assert 3 in transfer_ids

    def test_regex_methods(self, candidates):
        detector = TransferDetector(user_id=99999)
        predictions = detector.detect(candidates)

        by_id = {p.transaction_id: p for p in predictions}
        assert by_id[1].method == "regex"
        assert by_id[1].confidence == 0.85
        assert by_id[3].method == "regex"
        assert by_id[3].confidence == 0.65

    def test_empty_candidates(self):
        detector = TransferDetector(user_id=99999)
        predictions = detector.detect(pd.DataFrame())
        assert predictions == []

    def test_cross_account_match_with_all_txns(self):
        candidates = pd.DataFrame({
            "id": [1],
            "amount": [-100.0],
            "booked_date": pd.to_datetime(["2026-01-10"]),
            "account_id": [1],
            "description": ["Platba"],
            "partner": [""],
            "place": [None],
            "type": ["CARD_PAYMENT"],
            "currency": ["EUR"],
        })
        all_txns = pd.DataFrame({
            "id": [1, 50],
            "amount": [-100.0, 100.0],
            "booked_date": pd.to_datetime(["2026-01-10", "2026-01-10"]),
            "account_id": [1, 2],
            "description": ["Platba", "Prijem"],
        })
        detector = TransferDetector(user_id=99999)
        predictions = detector.detect(candidates, all_txns)

        cross_matches = [p for p in predictions if p.method == "cross_account_match"]
        assert len(cross_matches) == 1
        assert cross_matches[0].suggested_pair_id == 50
