"""Personalization and model training API endpoints."""

from __future__ import annotations

import logging

from fastapi import APIRouter
from pydantic import BaseModel

from app.core.database import get_user_categorization_history
from app.modules.model_training import (
    get_or_create_categorizer,
    update_cache,
)
from app.modules.personalization import get_user_vector, retrain_user_vector

logger = logging.getLogger(__name__)

router = APIRouter()


class RetrainRequest(BaseModel):
    user_id: int


class RetrainResponse(BaseModel):
    success: bool
    transactions_used: int
    vector_version: str
    model_version: int | None = None
    model_metrics: dict | None = None
    message: str


@router.post("/retrain", response_model=RetrainResponse)
async def retrain_user_model(request: RetrainRequest):
    """Retrain both personalization vector and ML categorization model."""
    # 1. Retrain personalization vector (frequency-based)
    vector_status = await retrain_user_vector(request.user_id)

    # 2. Train ML categorization model
    history = await get_user_categorization_history(
        user_id=request.user_id, limit=5000
    )

    model_version = None
    model_metrics = None

    if history:
        categorizer = get_or_create_categorizer(request.user_id)
        train_result = categorizer.train(history)

        if train_result.get("success"):
            categorizer.save(user_id=request.user_id)
            update_cache(request.user_id, categorizer)
            model_version = train_result.get("version")
            model_metrics = train_result.get("metrics")
            logger.info(
                "Trained ML model for user %d: %d samples, metrics=%s",
                request.user_id,
                train_result.get("training_samples", 0),
                model_metrics,
            )

    return RetrainResponse(
        success=vector_status["success"],
        transactions_used=vector_status["transactions_used"],
        vector_version=vector_status["vector_version"],
        model_version=model_version,
        model_metrics=model_metrics,
        message=vector_status["message"],
    )


@router.post("/models/retrain", response_model=RetrainResponse)
async def retrain_user_model_legacy(request: RetrainRequest):
    """Legacy endpoint — redirects to /retrain."""
    return await retrain_user_model(request)


@router.get("/status/{user_id}")
async def get_personalization_status(user_id: int):
    """Get personalization status for a user."""
    vector = get_user_vector(user_id)

    from app.modules.model_training import get_categorizer

    model = get_categorizer(user_id)

    return {
        "has_personalization_data": vector.transactions_used > 0,
        "last_trained_at": vector.last_trained_at,
        "vector_version": vector.version,
        "ml_model": {
            "is_fitted": model.is_fitted if model else False,
            "version": model.version if model else None,
            "trained_at": model.trained_at if model else None,
            "training_samples": model.training_samples if model else 0,
            "metrics": model.metrics if model else None,
        },
    }
