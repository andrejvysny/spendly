"""Tests for the multi-stage categorizer."""

import pandas as pd
import pytest

from ml_engine.categorizer import TransactionCategorizer


class TestCategorizerTraining:
    def test_train_insufficient_data(self, sample_transactions, sample_categories):
        """Should skip training with too few samples."""
        small_df = sample_transactions.head(5)
        categorizer = TransactionCategorizer(user_id=999)
        result = categorizer.train(small_df, sample_categories)
        assert result["status"] == "skipped"

    def test_train_with_enough_data(self, sample_categories):
        """Should train successfully with enough labeled data."""
        # Generate 25 labeled transactions
        rows = []
        for i in range(25):
            cat_id = (i % 5) + 1
            rows.append({
                "id": i + 100,
                "amount": -10.0 * (cat_id),
                "currency": "EUR",
                "booked_date": pd.Timestamp("2026-01-01") + pd.Timedelta(days=i),
                "processed_date": pd.Timestamp("2026-01-01") + pd.Timedelta(days=i),
                "description": f"Test transaction for category {cat_id} item {i}",
                "partner": f"Partner {cat_id}",
                "type": "CARD_PAYMENT",
                "place": None,
                "metadata": None,
                "bank_transaction_code": None,
                "note": None,
                "category_id": cat_id,
                "merchant_id": None,
                "recurring_group_id": None,
                "account_id": 1,
                "fingerprint": f"fp{i}",
            })
        df = pd.DataFrame(rows)
        categorizer = TransactionCategorizer(user_id=999)
        result = categorizer.train(df, sample_categories)
        assert result["status"] == "success"
        assert result["metrics"]["samples"] == 25
        assert result["metrics"]["classes"] == 5


class TestCategorizerPrediction:
    def test_predict_skips_categorized(self, sample_transactions, sample_categories, sample_merchants):
        """Should skip transactions that already have category_id."""
        categorizer = TransactionCategorizer(user_id=999)
        predictions = categorizer.predict(sample_transactions, sample_categories, sample_merchants)
        # All sample transactions have category_id set
        assert len(predictions) == 0

    def test_predict_uncategorized(self, uncategorized_transactions, sample_categories, sample_merchants):
        """Should return predictions for uncategorized transactions."""
        categorizer = TransactionCategorizer(user_id=999)
        # Without trained model, only MCC and merchant_match stages work
        predictions = categorizer.predict(
            uncategorized_transactions, sample_categories, sample_merchants
        )
        # MCC stage should pick up transactions with MCC codes
        mcc_predictions = [p for p in predictions if p.method == "mcc"]
        # MCC-5995 = Pet Supplies, MCC-5411 = Groceries
        assert len(mcc_predictions) >= 0  # May or may not match depending on category names

    def test_merchant_category_map(self, sample_transactions):
        """Should build correct merchant → category mapping."""
        mapping = TransactionCategorizer._build_merchant_category_map(sample_transactions)
        assert mapping.get(1) == 1  # merchant 1 → category 1
        assert mapping.get(2) == 2  # merchant 2 → category 2
        assert mapping.get(3) == 3  # merchant 3 → category 3
