"""Recurring detection API endpoints."""

from typing import List
from pydantic import BaseModel
from fastapi import APIRouter

from app.modules.recurring_detection import detect_recurring_patterns

router = APIRouter()


class RecurringPattern(BaseModel):
    merchant_name: str
    amount: float
    frequency: str
    confidence: float
    transaction_count: int
    next_expected: str


class RecurringDetectRequest(BaseModel):
    user_id: int
    months_lookback: int = 6


class RecurringDetectResponse(BaseModel):
    patterns: List[RecurringPattern]
    total_detected: int


@router.post("/detect", response_model=RecurringDetectResponse)
async def detect_recurring(request: RecurringDetectRequest):
    """Detect recurring payment patterns."""
    patterns = await detect_recurring_patterns(
        user_id=request.user_id, months_lookback=request.months_lookback
    )

    return RecurringDetectResponse(
        patterns=[RecurringPattern(**p) for p in patterns], total_detected=len(patterns)
    )
