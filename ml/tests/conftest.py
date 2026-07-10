"""Shared fixtures.

Environment must be configured before any ``app.*`` import because
``app.core.config.settings`` is a module-level singleton.
"""

from __future__ import annotations

import os
import sqlite3
import tempfile
from collections.abc import AsyncIterator, Iterator
from pathlib import Path

_TMP_ROOT = Path(tempfile.mkdtemp(prefix="spendly-ml-tests-"))
_DB_PATH = _TMP_ROOT / "test.sqlite"

os.environ["DATABASE_URL"] = f"sqlite:///{_DB_PATH}"
os.environ["ML_DATA_DIR"] = str(_TMP_ROOT / "data")
os.environ["ML_SERVICE_TOKEN"] = "test-token"

import httpx  # noqa: E402
import pytest  # noqa: E402

from app.core.database import reset_engine  # noqa: E402
from app.main import app  # noqa: E402

SCHEMA_PATH = Path(__file__).parent / "fixtures" / "schema.sql"

TOKEN = "test-token"
AUTH = {"Authorization": f"Bearer {TOKEN}"}


def _connect() -> sqlite3.Connection:
    return sqlite3.connect(_DB_PATH)


@pytest.fixture(autouse=True)
def fresh_db() -> Iterator[sqlite3.Connection]:
    """Recreate the schema before each test; dispose the async engine after."""
    if _DB_PATH.exists():
        _DB_PATH.unlink()
    conn = _connect()
    conn.executescript(SCHEMA_PATH.read_text())
    conn.commit()
    yield conn
    conn.close()


@pytest.fixture(autouse=True)
async def _engine_reset() -> AsyncIterator[None]:
    """Each test runs in its own event loop; never reuse a loop-bound engine."""
    await reset_engine()
    yield
    await reset_engine()


@pytest.fixture
async def client() -> AsyncIterator[httpx.AsyncClient]:
    transport = httpx.ASGITransport(app=app)
    async with httpx.AsyncClient(transport=transport, base_url="http://test") as c:
        yield c


class Seeder:
    """Minimal insert helpers matching the real Spendly schema."""

    def __init__(self, conn: sqlite3.Connection) -> None:
        self.conn = conn

    def user(self, user_id: int = 1) -> int:
        self.conn.execute(
            "INSERT INTO users (id, name, email, password) VALUES (?, ?, ?, 'x')",
            (user_id, f"User {user_id}", f"user{user_id}@example.test"),
        )
        self.conn.commit()
        return user_id

    def account(self, account_id: int, user_id: int, iban: str | None = None) -> int:
        self.conn.execute(
            """INSERT INTO accounts (id, user_id, name, iban, currency, balance)
               VALUES (?, ?, ?, ?, 'EUR', 0)""",
            (account_id, user_id, f"Account {account_id}", iban),
        )
        self.conn.commit()
        return account_id

    def category(self, category_id: int, user_id: int, name: str) -> int:
        self.conn.execute(
            "INSERT INTO categories (id, user_id, name) VALUES (?, ?, ?)",
            (category_id, user_id, name),
        )
        self.conn.commit()
        return category_id

    def counterparty(self, cp_id: int, user_id: int, name: str) -> int:
        self.conn.execute(
            "INSERT INTO counterparties (id, user_id, name) VALUES (?, ?, ?)",
            (cp_id, user_id, name),
        )
        self.conn.commit()
        return cp_id

    def transaction(
        self,
        tx_id: int,
        account_id: int,
        amount: float,
        description: str,
        booked_date: str,
        *,
        partner: str | None = None,
        category_id: int | None = None,
        counterparty_id: int | None = None,
        currency: str = "EUR",
        tx_type: str = "PAYMENT",
    ) -> int:
        self.conn.execute(
            """INSERT INTO transactions
               (id, account_id, transaction_id, amount, currency, booked_date,
                processed_date, description, partner, type, category_id, counterparty_id)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                tx_id,
                account_id,
                f"TRX-{tx_id}",
                amount,
                currency,
                booked_date,
                booked_date,
                description,
                partner,
                tx_type,
                category_id,
                counterparty_id,
            ),
        )
        self.conn.commit()
        return tx_id


@pytest.fixture
def seed(fresh_db: sqlite3.Connection) -> Seeder:
    return Seeder(fresh_db)
