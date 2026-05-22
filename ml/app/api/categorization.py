"""Categorization API endpoints."""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter
from pydantic import BaseModel, Field

from app.modules.categorization import categorize_transactions_batch, suggest_category
from app.modules.personalization import get_user_vector, retrain_user_vector

router = APIRouter()


class TransactionForCategorization(BaseModel):
    id: int
    description: str
    counterparty_name: Optional[str] = None
    amount: float


class CategorySuggestion(BaseModel):
    transaction_id: int
    suggested_category_id: Optional[int] = None
    suggested_category_name: Optional[str] = None
    confidence: float
    method: str = "keyword"
    alternatives: List[dict] = Field(default_factory=list)


class CategorizeRequest(BaseModel):
    user_id: int
    transactions: List[TransactionForCategorization]


class CategorizeResponse(BaseModel):
    suggestions: List[CategorySuggestion]


class PersonalizedCategorizeRequest(BaseModel):
    user_id: int
    description: str
    counterparty_name: Optional[str] = None
    amount: float = 0.0


class PersonalizedCategorizeResponse(BaseModel):
    suggested_category_id: Optional[int] = None
    suggested_category_name: Optional[str] = None
    confidence: float
    method: str
    alternatives: List[dict] = Field(default_factory=list)


@router.post("/suggest", response_model=CategorizeResponse)
async def suggest_categories(request: CategorizeRequest):
    """Suggest categories for transactions (ML model + keyword fallback)."""
    suggestions = categorize_transactions_batch(
        user_id=request.user_id,
        transactions=[t.model_dump() for t in request.transactions],
    )

    return CategorizeResponse(
        suggestions=[CategorySuggestion(**s) for s in suggestions]
    )


@router.post("/suggest-single", response_model=CategorySuggestion)
async def suggest_single_category(
    user_id: int,
    description: str,
    amount: float,
    counterparty_name: Optional[str] = None,
):
    """Suggest category for a single transaction."""
    result = suggest_category(
        user_id=user_id,
        description=description,
        amount=amount,
        counterparty_name=counterparty_name,
    )
    return CategorySuggestion(**result)


@router.post("/personalized", response_model=PersonalizedCategorizeResponse)
async def suggest_personalized_category(request: PersonalizedCategorizeRequest):
    """Personalized categorization using user's history patterns."""
    vector = get_user_vector(request.user_id)
    if vector.last_trained_at is None:
        await retrain_user_vector(request.user_id)
        vector = get_user_vector(request.user_id)

    prediction = vector.predict_category(
        description=request.description,
        counterparty=request.counterparty_name,
    )

    if prediction["method"] == "personalized" and prediction["category_id"] is not None:
        return PersonalizedCategorizeResponse(
            suggested_category_id=int(prediction["category_id"]),
            suggested_category_name=prediction.get("category_name"),
            confidence=float(prediction["confidence"]),
            method="personalized",
        )

    # Fall back to ML model + keyword
    global_result = suggest_category(
        user_id=request.user_id,
        description=request.description,
        amount=request.amount,
        counterparty_name=request.counterparty_name,
    )
    return PersonalizedCategorizeResponse(
        suggested_category_id=global_result.get("suggested_category_id"),
        suggested_category_name=global_result.get("suggested_category_name"),
        confidence=float(global_result.get("confidence", 0.0)),
        method=global_result.get("method", "keyword"),
        alternatives=global_result.get("alternatives", []),
    )
