"""Compatibility API v1 endpoints used by Laravel MlService."""

from __future__ import annotations

import re
from datetime import date, datetime

from fastapi import APIRouter
from pydantic import BaseModel, Field

from app.core.database import (
    get_transactions_for_categorization,
    get_transactions_for_counterparty_detection,
    get_user_categorization_history,
    get_user_counterparties,
)
from app.modules.categorization import categorize_transactions_batch
from app.modules.merchant_extraction import extract_merchant_single
from app.modules.model_training import get_or_create_categorizer, update_cache
from app.modules.recurring_detection import detect_recurring_patterns
from app.modules.transfer_detection import detect_transfer_pairs

# Health stays tokenless so Laravel's isAvailable() and Docker healthchecks work.
health_router = APIRouter()

router = APIRouter()


class CategorizeRequest(BaseModel):
    user_id: int
    transaction_ids: list[int] | None = None
    limit: int = 100


class CounterpartyRequest(BaseModel):
    user_id: int
    transaction_ids: list[int] | None = None
    limit: int = 100


class RecurringRequest(BaseModel):
    user_id: int
    months_lookback: int = Field(default=12, ge=1, le=36)


class TransfersRequest(BaseModel):
    user_id: int
    from_date: str | None = Field(default=None, alias="from")
    to_date: str | None = Field(default=None, alias="to")
    limit: int = 500

    model_config = {"populate_by_name": True}


class TrainRequest(BaseModel):
    user_id: int


def _normalize_name(value: str | None) -> str:
    if not value:
        return ""
    value = value.strip().lower()
    value = re.sub(r"\s+", " ", value)
    return value


def _as_date(value: str | None) -> date | None:
    if not value:
        return None
    parsed = datetime.fromisoformat(value)
    return parsed.date()


@health_router.get("/health")
async def health_v1() -> dict:
    return {"status": "ok", "service": "spendly-ml"}


@router.post("/categorize")
async def categorize_v1(request: CategorizeRequest) -> list[dict]:
    transactions = await get_transactions_for_categorization(
        user_id=request.user_id,
        transaction_ids=request.transaction_ids,
        limit=request.limit,
    )

    if not transactions:
        return []

    ml_input = [
        {
            "id": int(txn["id"]),
            "description": str(txn.get("description") or txn.get("partner") or ""),
            "counterparty_name": txn.get("counterparty_name"),
            "amount": float(txn.get("amount", 0)),
        }
        for txn in transactions
    ]

    suggestions = categorize_transactions_batch(request.user_id, ml_input)

    result: list[dict] = []
    for suggestion in suggestions:
        confidence = float(suggestion.get("confidence", 0.0))
        predicted = suggestion.get("suggested_category_id")
        result.append(
            {
                "transaction_id": int(suggestion.get("transaction_id", 0)),
                "predicted_category_id": int(predicted)
                if predicted is not None
                else None,
                "confidence": confidence,
                "method": suggestion.get("method", "keyword"),
                "needs_review": predicted is None or confidence < 0.75,
            }
        )

    return result


@router.post("/detect-counterparties")
async def detect_counterparties_v1(request: CounterpartyRequest) -> list[dict]:
    transactions = await get_transactions_for_counterparty_detection(
        user_id=request.user_id,
        transaction_ids=request.transaction_ids,
        limit=request.limit,
    )
    if not transactions:
        return []

    counterparties = await get_user_counterparties(request.user_id)
    normalized_lookup = {
        _normalize_name(str(counterparty.get("name") or "")): counterparty
        for counterparty in counterparties
        if counterparty.get("name")
    }

    predictions: list[dict] = []
    for txn in transactions:
        extracted = extract_merchant_single(
            description=str(txn.get("description") or ""),
            counterparty_name=str(txn.get("partner") or "") or None,
        )

        candidate_text = (
            extracted.get("merchant_name")
            or extracted.get("merchant_normalized")
            or txn.get("partner")
            or txn.get("description")
        )
        normalized = _normalize_name(str(candidate_text or ""))
        match = normalized_lookup.get(normalized)

        if match is not None:
            predictions.append(
                {
                    "transaction_id": int(txn.get("id", 0)),
                    "predicted_counterparty_id": int(match["id"]),
                    "suggested_counterparty_name": match.get("name"),
                    "confidence": 0.95,
                    "method": "exact_name_match",
                }
            )
            continue

        predictions.append(
            {
                "transaction_id": int(txn.get("id", 0)),
                "predicted_counterparty_id": None,
                "suggested_counterparty_name": candidate_text,
                "confidence": float(extracted.get("confidence", 0.5)),
                "method": "merchant_extraction",
            }
        )

    return predictions


@router.post("/detect-recurring")
async def detect_recurring_v1(request: RecurringRequest) -> list[dict]:
    patterns = await detect_recurring_patterns(
        user_id=request.user_id, months_lookback=request.months_lookback
    )

    mapped: list[dict] = []
    for pattern in patterns:
        frequency = pattern.get("frequency") or "monthly"
        interval_days = 30.0 if frequency == "monthly" else 7.0
        merchant_name = str(pattern.get("merchant_name") or "unknown")
        merchant_slug = (
            re.sub(r"[^a-z0-9]+", "_", merchant_name.lower()).strip("_") or "unknown"
        )

        mapped.append(
            {
                "group_key": f"{merchant_slug}_{frequency}",
                "frequency": frequency,
                "interval_days": interval_days,
                "confidence": float(pattern.get("confidence", 0.0)),
                "transaction_ids": pattern.get("transaction_ids", []),
                "amount_stats": {
                    "mean": float(pattern.get("amount", 0.0)),
                    "std": 0.0,
                },
                "next_expected": pattern.get("next_expected"),
                "anomalies": [],
            }
        )

    return mapped


@router.post("/detect-transfers")
async def detect_transfers_v1(request: TransfersRequest) -> list[dict]:
    from_date = _as_date(request.from_date)
    to_date = _as_date(request.to_date)

    if from_date and to_date and to_date >= from_date:
        days_lookback = max(1, (to_date - from_date).days + 1)
    else:
        days_lookback = 30

    pairs = await detect_transfer_pairs(
        user_id=request.user_id, days_lookback=days_lookback
    )

    results: list[dict] = []
    for pair in pairs:
        out_id = int(pair.get("transaction_out_id", 0))
        in_id = int(pair.get("transaction_in_id", 0))
        confidence = float(pair.get("confidence", 0.0))

        results.append(
            {
                "transaction_id": out_id,
                "is_transfer": True,
                "confidence": confidence,
                "method": "heuristic_pair",
                "suggested_pair_id": in_id,
            }
        )
        results.append(
            {
                "transaction_id": in_id,
                "is_transfer": True,
                "confidence": confidence,
                "method": "heuristic_pair",
                "suggested_pair_id": out_id,
            }
        )

    return results[: max(1, min(request.limit, 5000))]


@router.post("/train/categorizer")
async def train_categorizer_v1(request: TrainRequest) -> dict:
    history = await get_user_categorization_history(user_id=request.user_id, limit=5000)
    if not history:
        return {
            "status": "error",
            "message": "No labeled transactions found for training",
        }

    categorizer = get_or_create_categorizer(request.user_id)
    train_result = categorizer.train(history)
    if not train_result.get("success"):
        return {
            "status": "error",
            "message": train_result.get("error", "Training failed"),
        }

    categorizer.save(user_id=request.user_id)
    update_cache(request.user_id, categorizer)

    return {
        "status": "success",
        "message": "Categorizer trained",
        "metrics": train_result.get("metrics", {}),
    }


@router.post("/train/counterparty-detector")
async def train_counterparty_detector_v1(_request: TrainRequest) -> dict:
    return {
        "status": "success",
        "message": "Counterparty detector training not implemented yet",
    }


@router.post("/train/transfer-detector")
async def train_transfer_detector_v1(_request: TrainRequest) -> dict:
    return {
        "status": "success",
        "message": "Transfer detector training not implemented yet",
    }


@router.post("/discover-counterparties")
async def discover_counterparties_v1(_request: TrainRequest) -> list[dict]:
    return []
