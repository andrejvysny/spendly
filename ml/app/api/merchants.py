"""Merchant extraction API endpoints."""

from typing import List, Optional
from pydantic import BaseModel
from fastapi import APIRouter

from app.modules.merchant_extraction import (
    extract_merchants_batch,
    extract_merchant_single,
)

router = APIRouter()


class TransactionInput(BaseModel):
    id: int
    description: str
    counterparty_name: Optional[str] = None


class MerchantResult(BaseModel):
    transaction_id: int
    merchant_name: Optional[str]
    merchant_normalized: Optional[str]
    entity_type: str
    confidence: float


class MerchantExtractRequest(BaseModel):
    user_id: int
    transactions: List[TransactionInput]


class MerchantExtractResponse(BaseModel):
    results: List[MerchantResult]
    processed_count: int


@router.post("/extract", response_model=MerchantExtractResponse)
async def extract_merchants(request: MerchantExtractRequest):
    """Extract merchants from transaction descriptions."""
    results = extract_merchants_batch(
        user_id=request.user_id, transactions=[t.dict() for t in request.transactions]
    )

    return MerchantExtractResponse(
        results=[MerchantResult(**r) for r in results], processed_count=len(results)
    )


@router.post("/extract-single")
async def extract_single_merchant(
    description: str, counterparty_name: Optional[str] = None
):
    """Extract merchant from a single transaction text."""
    result = extract_merchant_single(description, counterparty_name)
    return MerchantResult(**result)
