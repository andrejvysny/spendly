"""CLI entry point for Spendly ML Engine.

Usage:
    python main.py train-categorizer --user_id=3
    python main.py predict-categories --user_id=3
    python main.py detect-merchants --user_id=3
    python main.py detect-recurring --user_id=3
    python main.py discover-merchants --user_id=3
    python main.py serve [--host=0.0.0.0] [--port=8001]
"""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import asdict


def main() -> None:
    parser = argparse.ArgumentParser(description="Spendly ML Engine")
    parser.add_argument(
        "task",
        choices=[
            "train-categorizer",
            "predict-categories",
            "detect-merchants",
            "detect-recurring",
            "detect-duplicates",
            "discover-merchants",
            "serve",
        ],
    )
    parser.add_argument("--user_id", type=int, help="User ID (required for ML tasks)")
    parser.add_argument("--limit", type=int, default=500, help="Transaction limit")
    parser.add_argument("--host", default="0.0.0.0", help="API host (serve mode)")
    parser.add_argument("--port", type=int, default=8001, help="API port (serve mode)")
    args = parser.parse_args()

    if args.task == "serve":
        _serve(args.host, args.port)
        return

    if not args.user_id:
        print(json.dumps({"error": "--user_id is required"}))
        sys.exit(1)

    from ml_engine.data_loader import DataLoader

    loader = DataLoader()

    try:
        result = _dispatch(args, loader)
        print(json.dumps(result, default=str, indent=2))
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)


def _dispatch(args: argparse.Namespace, loader) -> object:
    user_id = args.user_id
    limit = args.limit
    embedding_svc = _get_embedding_service()

    if args.task == "train-categorizer":
        from ml_engine.categorizer import TransactionCategorizer
        df = loader.load_labeled_transactions(user_id)
        categories = loader.load_categories(user_id)
        categorizer = TransactionCategorizer(user_id)
        return categorizer.train(df, categories)

    if args.task == "predict-categories":
        from ml_engine.categorizer import TransactionCategorizer
        df = loader.load_transactions(user_id, limit=limit, only_uncategorized=True)
        categories = loader.load_categories(user_id)
        merchants = loader.load_merchants(user_id)
        categorizer = TransactionCategorizer(user_id)
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

    if args.task == "detect-merchants":
        from ml_engine.merchant_detector import MerchantDetector
        df = loader.load_transactions(user_id, limit=limit)
        merchants = loader.load_merchants(user_id)
        detector = MerchantDetector(user_id, merchants)
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

    if args.task == "detect-recurring":
        from ml_engine.recurring_detector import RecurringDetector
        df = loader.load_transactions(user_id, limit=5000)
        recurring_groups_df = loader.load_recurring_groups(user_id)
        detector = RecurringDetector(user_id)
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

    if args.task == "detect-duplicates":
        from ml_engine.duplicate_detector import DuplicateDetector
        df = loader.load_transactions(user_id, limit=limit)
        detector = DuplicateDetector()
        result_df = detector.detect(df)
        if result_df.empty:
            return []
        return result_df.to_dict("records")

    if args.task == "discover-merchants":
        if embedding_svc is None:
            return {"error": "Embedding service not available"}
        from ml_engine.merchant_detector import MerchantDetector
        df = loader.load_transactions(user_id, limit=5000)
        return MerchantDetector.discover_merchants(df, embedding_svc)

    return {"error": f"Unknown task: {args.task}"}


def _get_embedding_service():
    try:
        from ml_engine.embedding_service import EmbeddingService
        svc = EmbeddingService()
        if svc.available:
            return svc
    except Exception:
        pass
    return None


def _serve(host: str, port: int) -> None:
    import uvicorn
    uvicorn.run("api:app", host=host, port=port, reload=False)


if __name__ == "__main__":
    main()
