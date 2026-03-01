"""Data loader for reading from shared SQLite database (read-only)."""

from __future__ import annotations

import pandas as pd
from sqlalchemy import create_engine, text

from .config import Config


class DataLoader:
    """Loads transaction and reference data from SQLite."""

    def __init__(self, db_uri: str | None = None):
        uri = db_uri or Config.SQLALCHEMY_DATABASE_URI
        # Remove immutable flag for WAL compatibility if needed
        clean_uri = uri.split("?")[0]
        self.engine = create_engine(clean_uri, echo=False)
        self._ensure_wal()

    def _ensure_wal(self) -> None:
        """Enable WAL mode for concurrent read access."""
        with self.engine.connect() as conn:
            conn.execute(text("PRAGMA journal_mode=WAL"))
            conn.commit()

    def load_transactions(
        self,
        user_id: int,
        limit: int | None = None,
        only_uncategorized: bool = False,
        transaction_ids: list[int] | None = None,
    ) -> pd.DataFrame:
        """Load transactions scoped by user_id.

        Fields: id, transaction_id, amount, currency, booked_date, processed_date,
        description, partner, type, place, metadata, bank_transaction_code, note,
        category_id, merchant_id, recurring_group_id, account_id, fingerprint
        """
        query = """
            SELECT
                t.id,
                t.transaction_id,
                t.amount,
                t.currency,
                t.booked_date,
                t.processed_date,
                t.description,
                t.partner,
                t.type,
                t.place,
                t.metadata,
                t.bank_transaction_code,
                t.note,
                t.category_id,
                t.merchant_id,
                t.recurring_group_id,
                t.account_id,
                t.fingerprint
            FROM transactions t
            JOIN accounts a ON t.account_id = a.id
            WHERE a.user_id = :user_id
        """

        params: dict = {"user_id": user_id}

        if only_uncategorized:
            query += " AND t.category_id IS NULL"

        if transaction_ids:
            placeholders = ",".join(str(int(tid)) for tid in transaction_ids)
            query += f" AND t.id IN ({placeholders})"

        query += " ORDER BY t.booked_date DESC"

        if limit:
            query += " LIMIT :limit"
            params["limit"] = limit

        df = pd.read_sql(query, self.engine, params=params)

        if "booked_date" in df.columns:
            df["booked_date"] = pd.to_datetime(df["booked_date"])
        if "processed_date" in df.columns:
            df["processed_date"] = pd.to_datetime(df["processed_date"], errors="coerce")

        return df

    def load_categories(self, user_id: int) -> list[dict]:
        """Load user's categories as list of dicts."""
        query = """
            SELECT id, name, parent_category_id
            FROM categories
            WHERE user_id = :user_id
            ORDER BY name
        """
        df = pd.read_sql(query, self.engine, params={"user_id": user_id})
        return df.to_dict("records")

    def load_merchants(self, user_id: int) -> list[dict]:
        """Load user's merchants as list of dicts."""
        query = """
            SELECT id, name
            FROM merchants
            WHERE user_id = :user_id
            ORDER BY name
        """
        df = pd.read_sql(query, self.engine, params={"user_id": user_id})
        return df.to_dict("records")

    def load_recurring_groups(self, user_id: int) -> pd.DataFrame:
        """Load recurring groups for training feedback."""
        query = """
            SELECT
                rg.id, rg.name, rg.interval, rg.interval_days,
                rg.amount_min, rg.amount_max, rg.scope,
                rg.merchant_id, rg.normalized_description,
                rg.status, rg.first_date, rg.last_date
            FROM recurring_groups rg
            WHERE rg.user_id = :user_id
        """
        return pd.read_sql(query, self.engine, params={"user_id": user_id})

    def load_labeled_transactions(self, user_id: int) -> pd.DataFrame:
        """Load transactions that have category_id assigned (for training)."""
        return self.load_transactions(user_id, only_uncategorized=False)

    def load_transfer_candidates(self, user_id: int, limit: int = 1000) -> pd.DataFrame:
        """Load non-transfer, unpaired transactions with IBAN info for ML transfer detection."""
        query = """
            SELECT
                t.id,
                t.transaction_id,
                t.amount,
                t.currency,
                t.booked_date,
                t.description,
                t.partner,
                t.type,
                t.place,
                t.source_iban,
                t.target_iban,
                t.account_id,
                t.fingerprint,
                a.name AS account_name,
                a.iban AS account_iban
            FROM transactions t
            JOIN accounts a ON t.account_id = a.id
            WHERE a.user_id = :user_id
              AND t.type != 'TRANSFER'
              AND t.transfer_pair_transaction_id IS NULL
            ORDER BY t.booked_date DESC
            LIMIT :limit
        """
        df = pd.read_sql(query, self.engine, params={"user_id": user_id, "limit": limit})
        if "booked_date" in df.columns:
            df["booked_date"] = pd.to_datetime(df["booked_date"])
        return df

    def load_user_accounts(self, user_id: int) -> list[dict]:
        """Load user's accounts with IBANs for cross-account matching."""
        query = """
            SELECT id, name, iban
            FROM accounts
            WHERE user_id = :user_id
        """
        df = pd.read_sql(query, self.engine, params={"user_id": user_id})
        return df.to_dict("records")
