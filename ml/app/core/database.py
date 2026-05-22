"""Database connection supporting SQLite and PostgreSQL."""

from __future__ import annotations

import logging
from collections.abc import Sequence

from sqlalchemy import bindparam, text
from sqlalchemy.ext.asyncio import AsyncEngine, create_async_engine

from app.core.config import settings

logger = logging.getLogger(__name__)


def _build_async_url(url: str) -> str:
    """Convert a database URL to its async driver variant."""
    if url.startswith("sqlite"):
        # sqlite:///path -> sqlite+aiosqlite:///path
        return url.replace("sqlite://", "sqlite+aiosqlite://", 1)
    if url.startswith("postgresql://"):
        return url.replace("postgresql://", "postgresql+asyncpg://", 1)
    if url.startswith("postgres://"):
        return url.replace("postgres://", "postgresql+asyncpg://", 1)
    return url


def _create_engine() -> AsyncEngine:
    url = _build_async_url(settings.DATABASE_URL)
    is_sqlite = "sqlite" in url

    kwargs: dict = {"echo": False}
    if not is_sqlite:
        kwargs["pool_size"] = 10
        kwargs["max_overflow"] = 20

    logger.info("Connecting to database: %s", "SQLite" if is_sqlite else "PostgreSQL")
    return create_async_engine(url, **kwargs)


engine: AsyncEngine = _create_engine()


async def get_transactions_for_user(user_id: int, limit: int = 1000) -> list[dict]:
    """Fetch transactions for ML processing."""
    async with engine.connect() as conn:
        result = await conn.execute(
            text("""
                SELECT
                    t.id,
                    t.account_id,
                    t.amount,
                    t.currency,
                    t.description,
                    t.counterparty_name,
                    t.counterparty_iban,
                    t.booked_date as transaction_date,
                    t.category_id,
                    c.name as category_name,
                    c.type as category_type,
                    a.name as account_name,
                    a.currency as account_currency
                FROM transactions t
                LEFT JOIN categories c ON t.category_id = c.id
                LEFT JOIN accounts a ON t.account_id = a.id
                WHERE a.user_id = :user_id
                ORDER BY t.booked_date DESC
                LIMIT :limit
            """),
            {"user_id": user_id, "limit": limit},
        )
        return [dict(row) for row in result.mappings()]


async def get_user_categories(user_id: int) -> list[dict]:
    """Fetch user's custom categories."""
    async with engine.connect() as conn:
        result = await conn.execute(
            text("""
                SELECT id, name, type, parent_id, keywords
                FROM categories
                WHERE user_id = :user_id
                ORDER BY name
            """),
            {"user_id": user_id},
        )
        return [dict(row) for row in result.mappings()]


async def get_user_categorization_history(
    user_id: int, limit: int = 1000
) -> list[dict]:
    """Fetch labeled transactions for training."""
    async with engine.connect() as conn:
        result = await conn.execute(
            text("""
                SELECT
                    t.description,
                    t.counterparty_name,
                    t.amount,
                    t.category_id,
                    c.name AS category_name
                FROM transactions t
                INNER JOIN categories c ON t.category_id = c.id
                INNER JOIN accounts a ON t.account_id = a.id
                WHERE a.user_id = :user_id
                  AND t.category_id IS NOT NULL
                ORDER BY t.booked_date DESC
                LIMIT :limit
            """),
            {"user_id": user_id, "limit": limit},
        )
        return [dict(row) for row in result.mappings()]


def _normalize_ids(ids: Sequence[int] | None) -> list[int]:
    if not ids:
        return []
    normalized: list[int] = []
    for value in ids:
        try:
            parsed = int(value)
        except (TypeError, ValueError):
            continue
        if parsed > 0:
            normalized.append(parsed)
    return normalized


async def get_transactions_for_categorization(
    user_id: int,
    transaction_ids: Sequence[int] | None = None,
    limit: int = 100,
) -> list[dict]:
    """Fetch transactions for category prediction using current Spendly schema."""
    tx_ids = _normalize_ids(transaction_ids)

    sql = text(
        """
            SELECT
                t.id,
                t.description,
                t.partner,
                COALESCE(cp.name, t.partner) AS counterparty_name,
                t.amount,
                t.category_id
            FROM transactions t
            INNER JOIN accounts a ON t.account_id = a.id
            LEFT JOIN counterparties cp ON t.counterparty_id = cp.id
            WHERE a.user_id = :user_id
              AND (:only_uncategorized = 0 OR t.category_id IS NULL)
              AND (:has_ids = 0 OR t.id IN :transaction_ids)
            ORDER BY t.booked_date DESC, t.id DESC
            LIMIT :limit
        """
    ).bindparams(bindparam("transaction_ids", expanding=True))

    params = {
        "user_id": user_id,
        "limit": max(1, min(limit, 5000)),
        "only_uncategorized": 0 if tx_ids else 1,
        "has_ids": 1 if tx_ids else 0,
        "transaction_ids": tx_ids or [0],
    }

    async with engine.connect() as conn:
        result = await conn.execute(sql, params)
        return [dict(row) for row in result.mappings()]


async def get_transactions_for_counterparty_detection(
    user_id: int,
    transaction_ids: Sequence[int] | None = None,
    limit: int = 100,
) -> list[dict]:
    """Fetch transactions that need counterparty prediction."""
    tx_ids = _normalize_ids(transaction_ids)

    sql = text(
        """
            SELECT
                t.id,
                t.description,
                t.partner,
                t.amount,
                t.counterparty_id
            FROM transactions t
            INNER JOIN accounts a ON t.account_id = a.id
            WHERE a.user_id = :user_id
              AND (:only_unassigned = 0 OR t.counterparty_id IS NULL)
              AND (:has_ids = 0 OR t.id IN :transaction_ids)
            ORDER BY t.booked_date DESC, t.id DESC
            LIMIT :limit
        """
    ).bindparams(bindparam("transaction_ids", expanding=True))

    params = {
        "user_id": user_id,
        "limit": max(1, min(limit, 5000)),
        "only_unassigned": 0 if tx_ids else 1,
        "has_ids": 1 if tx_ids else 0,
        "transaction_ids": tx_ids or [0],
    }

    async with engine.connect() as conn:
        result = await conn.execute(sql, params)
        return [dict(row) for row in result.mappings()]


async def get_user_counterparties(user_id: int) -> list[dict]:
    """Fetch existing counterparties for user-side matching."""
    async with engine.connect() as conn:
        result = await conn.execute(
            text(
                """
                    SELECT id, name, type
                    FROM counterparties
                    WHERE user_id = :user_id
                    ORDER BY id ASC
                """
            ),
            {"user_id": user_id},
        )
        return [dict(row) for row in result.mappings()]
