"""FastAPI application for the Spendly ML Engine sidecar."""

from __future__ import annotations

import logging
from typing import Any

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

from ml_engine.config import Config

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
logger = logging.getLogger(__name__)

app = FastAPI(
    title="Spendly ML Engine",
    version="0.1.0",
    docs_url="/docs",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=Config.API.cors_origins,
    allow_methods=["*"],
    allow_headers=["*"],
)


# ── Request models ──────────────────────────────────────────────────────────

class PredictRequest(BaseModel):
    user_id: int
    transaction_ids: list[int] | None = None
    limit: int = 100


class TrainRequest(BaseModel):
    user_id: int


class DiscoverRequest(BaseModel):
    user_id: int
    min_cluster_size: int = 3


# ── Helpers ─────────────────────────────────────────────────────────────────

def _get_loader():
    from ml_engine.data_loader import DataLoader
    return DataLoader()


def _get_embedding_service():
    """Return embedding service if available, else None."""
    try:
        from ml_engine.embedding_service import EmbeddingService
        svc = EmbeddingService()
        if svc.available:
            return svc
    except Exception as e:
        logger.warning("Embedding service unavailable: %s", e)
    return None


# ── Endpoints ───────────────────────────────────────────────────────────────

@app.get("/api/v1/health")
def health() -> dict[str, Any]:
    embedding_available = False
    try:
        from ml_engine.embedding_service import EmbeddingService
        embedding_available = EmbeddingService().available
    except Exception:
        pass

    return {
        "status": "ok",
        "version": "0.1.0",
        "embedding_model": Config.EMBEDDING_MODEL,
        "embedding_available": embedding_available,
    }


@app.post("/api/v1/categorize")
def categorize(req: PredictRequest) -> list[dict]:
    loader = _get_loader()
    df = loader.load_transactions(
        user_id=req.user_id,
        transaction_ids=req.transaction_ids,
        limit=req.limit,
        only_uncategorized=req.transaction_ids is None,
    )

    if df.empty:
        return []

    categories = loader.load_categories(req.user_id)
    merchants = loader.load_merchants(req.user_id)
    embedding_svc = _get_embedding_service()

    from ml_engine.categorizer import TransactionCategorizer
    categorizer = TransactionCategorizer(req.user_id)
    predictions = categorizer.predict(df, categories, merchants, embedding_svc)

    return [
        {
            "transaction_id": p.transaction_id,
            "predicted_category_id": p.predicted_category_id,
            "confidence": round(p.confidence, 3),
            "method": p.method,
            "needs_review": p.needs_review,
        }
        for p in predictions
    ]


@app.post("/api/v1/detect-merchants")
def detect_merchants(req: PredictRequest) -> list[dict]:
    loader = _get_loader()
    df = loader.load_transactions(
        user_id=req.user_id,
        transaction_ids=req.transaction_ids,
        limit=req.limit,
    )

    if df.empty:
        return []

    merchants = loader.load_merchants(req.user_id)
    embedding_svc = _get_embedding_service()

    from ml_engine.merchant_detector import MerchantDetector
    detector = MerchantDetector(req.user_id, merchants)
    predictions = detector.detect(df, embedding_svc)

    return [
        {
            "transaction_id": p.transaction_id,
            "predicted_merchant_id": p.predicted_merchant_id,
            "suggested_merchant_name": p.suggested_merchant_name,
            "confidence": round(p.confidence, 3),
            "method": p.method,
        }
        for p in predictions
    ]


@app.post("/api/v1/detect-recurring")
def detect_recurring(req: PredictRequest) -> list[dict]:
    loader = _get_loader()
    df = loader.load_transactions(user_id=req.user_id, limit=5000)

    if df.empty:
        return []

    recurring_groups_df = loader.load_recurring_groups(req.user_id)

    from ml_engine.recurring_detector import RecurringDetector
    detector = RecurringDetector(req.user_id)
    groups = detector.detect(df, recurring_groups_df)

    return [
        {
            "group_key": g.group_key,
            "frequency": g.frequency,
            "interval_days": g.interval_days,
            "confidence": g.confidence,
            "transaction_ids": g.transaction_ids,
            "amount_stats": g.amount_stats,
            "next_expected": g.next_expected,
            "anomalies": g.anomalies,
        }
        for g in groups
    ]


@app.post("/api/v1/train/categorizer")
def train_categorizer(req: TrainRequest) -> dict[str, Any]:
    loader = _get_loader()
    df = loader.load_labeled_transactions(req.user_id)
    categories = loader.load_categories(req.user_id)

    if df.empty:
        raise HTTPException(400, "No transactions found for this user")

    from ml_engine.categorizer import TransactionCategorizer
    categorizer = TransactionCategorizer(req.user_id)
    result = categorizer.train(df, categories)
    return result


@app.post("/api/v1/train/merchant-detector")
def train_merchant_detector(req: TrainRequest) -> dict[str, Any]:
    # Merchant detector doesn't need training per se — it uses live data
    # This endpoint rebuilds the merchant index / validates readiness
    loader = _get_loader()
    merchants = loader.load_merchants(req.user_id)
    return {
        "status": "success",
        "message": f"Merchant index ready with {len(merchants)} merchants",
    }


@app.post("/api/v1/detect-transfers")
def detect_transfers(req: PredictRequest) -> list[dict]:
    loader = _get_loader()
    candidates = loader.load_transfer_candidates(req.user_id, limit=req.limit)

    if candidates.empty:
        return []

    # Load all user transactions for cross-account matching
    all_txns = loader.load_transactions(user_id=req.user_id, limit=5000)

    from ml_engine.transfer_detector import TransferDetector
    detector = TransferDetector(req.user_id)
    predictions = detector.detect(candidates, all_txns)

    return [
        {
            "transaction_id": p.transaction_id,
            "is_transfer": p.is_transfer,
            "confidence": round(p.confidence, 3),
            "method": p.method,
            "suggested_pair_id": p.suggested_pair_id,
        }
        for p in predictions
    ]


@app.post("/api/v1/train/transfer-detector")
def train_transfer_detector(req: TrainRequest) -> dict[str, Any]:
    loader = _get_loader()
    df = loader.load_labeled_transactions(req.user_id)

    if df.empty:
        raise HTTPException(400, "No transactions found for this user")

    from ml_engine.transfer_detector import TransferDetector
    detector = TransferDetector(req.user_id)
    result = detector.train(df)
    return result


@app.post("/api/v1/discover-merchants")
def discover_merchants(req: DiscoverRequest) -> list[dict]:
    embedding_svc = _get_embedding_service()
    if embedding_svc is None:
        raise HTTPException(503, "Embedding service not available for merchant discovery")

    loader = _get_loader()
    df = loader.load_transactions(user_id=req.user_id, limit=5000)

    if df.empty:
        return []

    from ml_engine.merchant_detector import MerchantDetector
    suggestions = MerchantDetector.discover_merchants(df, embedding_svc, req.min_cluster_size)
    return suggestions
