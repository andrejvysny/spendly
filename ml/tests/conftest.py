"""Shared test fixtures for ML engine tests."""

from __future__ import annotations

import os
import sys
from pathlib import Path

import pandas as pd
import pytest

# Ensure ml_engine is importable
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))


@pytest.fixture
def sample_transactions() -> pd.DataFrame:
    """Sample transactions DataFrame for testing."""
    return pd.DataFrame(
        {
            "id": [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            "transaction_id": [f"TRN{i}" for i in range(1, 11)],
            "amount": [-4.04, -15.50, -9.99, -120.00, 1500.00, -4.10, -15.80, -9.99, -122.00, 1500.00],
            "currency": ["EUR"] * 10,
            "booked_date": pd.to_datetime(
                [
                    "2026-01-03",
                    "2026-01-05",
                    "2026-01-15",
                    "2026-01-20",
                    "2026-01-25",
                    "2026-02-03",
                    "2026-02-05",
                    "2026-02-15",
                    "2026-02-20",
                    "2026-02-25",
                ]
            ),
            "processed_date": pd.to_datetime(
                [
                    "2026-01-03",
                    "2026-01-05",
                    "2026-01-15",
                    "2026-01-20",
                    "2026-01-25",
                    "2026-02-03",
                    "2026-02-05",
                    "2026-02-15",
                    "2026-02-20",
                    "2026-02-25",
                ]
            ),
            "description": [
                "3520_SUPER ZOO",
                "Platba kartou LIDL dakuje 165",
                "NETFLIX.COM",
                "Prevod na ucet - najomne",
                "Prijem - vyplata FIRMA s.r.o.",
                "3520_SUPER ZOO",
                "Platba kartou LIDL dakuje 170",
                "NETFLIX.COM",
                "Prevod na ucet - najomne",
                "Prijem - vyplata FIRMA s.r.o.",
            ],
            "partner": [
                "SUPER ZOO",
                "LIDL",
                "Netflix",
                "Bytovy podnik",
                "FIRMA s.r.o.",
                "SUPER ZOO",
                "LIDL",
                "Netflix",
                "Bytovy podnik",
                "FIRMA s.r.o.",
            ],
            "type": [
                "CARD_PAYMENT",
                "CARD_PAYMENT",
                "CARD_PAYMENT",
                "TRANSFER",
                "TRANSFER",
                "CARD_PAYMENT",
                "CARD_PAYMENT",
                "CARD_PAYMENT",
                "TRANSFER",
                "TRANSFER",
            ],
            "place": [None, "Bratislava", None, None, None, None, "Bratislava", None, None, None],
            "metadata": [
                '{"remittanceInformationUnstructured": "MCC-5995"}',
                '{"remittanceInformationUnstructured": "MCC-5411"}',
                None,
                None,
                None,
                '{"remittanceInformationUnstructured": "MCC-5995"}',
                '{"remittanceInformationUnstructured": "MCC-5411"}',
                None,
                None,
                None,
            ],
            "bank_transaction_code": [
                "PMNT-MCRD-POSP",
                "PMNT-MCRD-POSP",
                "PMNT-MCRD-POSP",
                "PMNT-ICDT-ESCT",
                "PMNT-RCDT-ESCT",
                "PMNT-MCRD-POSP",
                "PMNT-MCRD-POSP",
                "PMNT-MCRD-POSP",
                "PMNT-ICDT-ESCT",
                "PMNT-RCDT-ESCT",
            ],
            "note": [None] * 10,
            "category_id": [1, 2, 3, 4, 5, 1, 2, 3, 4, 5],
            "merchant_id": [1, 2, 3, None, None, 1, 2, 3, None, None],
            "recurring_group_id": [None] * 10,
            "account_id": [1] * 10,
            "fingerprint": [f"fp{i}" for i in range(1, 11)],
        }
    )


@pytest.fixture
def sample_categories() -> list[dict]:
    return [
        {"id": 1, "name": "Pet Supplies", "parent_category_id": None},
        {"id": 2, "name": "Groceries", "parent_category_id": None},
        {"id": 3, "name": "Entertainment", "parent_category_id": None},
        {"id": 4, "name": "Housing", "parent_category_id": None},
        {"id": 5, "name": "Income", "parent_category_id": None},
    ]


@pytest.fixture
def sample_merchants() -> list[dict]:
    return [
        {"id": 1, "name": "Super ZOO"},
        {"id": 2, "name": "Lidl"},
        {"id": 3, "name": "Netflix"},
    ]


@pytest.fixture
def uncategorized_transactions(sample_transactions: pd.DataFrame) -> pd.DataFrame:
    """Transactions with category_id stripped for prediction testing."""
    df = sample_transactions.copy()
    df["category_id"] = pd.NA
    return df


@pytest.fixture
def transfer_candidates() -> pd.DataFrame:
    """Non-transfer transactions for ML transfer detection testing."""
    return pd.DataFrame(
        {
            "id": [101, 102, 103, 104],
            "amount": [-200.00, -50.00, -15.00, 100.00],
            "currency": ["EUR"] * 4,
            "booked_date": pd.to_datetime(["2026-01-10", "2026-01-10", "2026-01-10", "2026-01-10"]),
            "description": [
                "PREVOD NA UCET sporenie",
                "Vlastny prevod na druhy ucet",
                "Platba kartou LIDL dakuje 165",
                "Internal transfer from savings",
            ],
            "partner": ["", "", "LIDL", ""],
            "type": ["CARD_PAYMENT", "CARD_PAYMENT", "CARD_PAYMENT", "CARD_PAYMENT"],
            "place": [None, None, "Bratislava", None],
            "account_id": [1, 1, 1, 2],
            "source_iban": [None, None, None, None],
            "target_iban": [None, None, None, None],
            "account_name": ["Main", "Main", "Main", "Savings"],
            "account_iban": ["SK1234", "SK1234", "SK1234", "SK5678"],
            "fingerprint": [f"fp{i}" for i in range(101, 105)],
        }
    )


@pytest.fixture
def all_user_transactions() -> pd.DataFrame:
    """All transactions for cross-account matching."""
    return pd.DataFrame(
        {
            "id": [101, 102, 103, 104, 201, 202],
            "amount": [-200.00, -50.00, -15.00, 100.00, 200.00, 50.00],
            "booked_date": pd.to_datetime([
                "2026-01-10", "2026-01-10", "2026-01-10", "2026-01-10",
                "2026-01-10", "2026-01-11",
            ]),
            "account_id": [1, 1, 1, 2, 2, 2],
            "description": [
                "PREVOD NA UCET sporenie",
                "Vlastny prevod",
                "Platba kartou LIDL",
                "Internal transfer",
                "Prijem prevod",
                "Prijem prevod 2",
            ],
        }
    )
