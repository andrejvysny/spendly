"""Transfer detection API endpoints."""

from typing import List
from pydantic import BaseModel
from fastapi import APIRouter

from app.modules.transfer_detection import detect_transfer_pairs

router = APIRouter()


class TransferPair(BaseModel):
    transaction_out_id: int
    transaction_in_id: int
    from_account: str
    to_account: str
    amount: float
    confidence: float
    time_diff_minutes: int


class TransferDetectRequest(BaseModel):
    user_id: int
    days_lookback: int = 30


class TransferDetectResponse(BaseModel):
    pairs: List[TransferPair]
    total_detected: int


@router.post("/detect", response_model=TransferDetectResponse)
async def detect_transfers(request: TransferDetectRequest):
    """Detect cross-account transfer pairs."""
    pairs = await detect_transfer_pairs(
        user_id=request.user_id, days_lookback=request.days_lookback
    )

    return TransferDetectResponse(
        pairs=[TransferPair(**p) for p in pairs], total_detected=len(pairs)
    )
