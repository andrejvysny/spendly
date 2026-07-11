#!/usr/bin/env python3
"""Offline categorizer backtest over an exported JSONL dataset.

Dev-only (not shipped in the Docker image). Point it at a gitignored export:

    php artisan ml:export-dataset categories --user=1 --output=ml-intern/data
    python ml/scripts/backtest.py ml-intern/data/categories.jsonl

Optional --classifier sgd runs the legacy SGD(modified_huber) pipeline for
before/after comparison against the current LogisticRegression default.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from sklearn.linear_model import SGDClassifier  # noqa: E402

from app.modules import model_training  # noqa: E402
from app.modules.evaluation import evaluate_categorizer  # noqa: E402


def load_jsonl(path: Path) -> list[dict]:
    rows = []
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line:
            continue
        row = json.loads(line)
        rows.append(
            {
                "description": row.get("description", ""),
                "counterparty_name": row.get("partner") or None,
                "amount": float(row.get("amount", 0)),
                "category_id": row.get("category_id"),
                "category_name": row.get("category", ""),
                "booked_date": row.get("booked_date"),
            }
        )
    return rows


def use_sgd() -> None:
    """Patch the categorizer back to the legacy SGD pipeline for comparison."""
    original_init = model_training.TransactionCategorizer.__init__

    def patched(self) -> None:  # type: ignore[no-untyped-def]
        original_init(self)
        self.classifier = SGDClassifier(
            loss="modified_huber",
            penalty="l2",
            alpha=1e-4,
            max_iter=1000,
            tol=1e-3,
            random_state=42,
            class_weight="balanced",
        )

    model_training.TransactionCategorizer.__init__ = patched  # type: ignore[method-assign]


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("dataset", type=Path, help="categories.jsonl export")
    parser.add_argument("--classifier", choices=["lr", "sgd"], default="lr")
    args = parser.parse_args()

    if args.classifier == "sgd":
        use_sgd()

    rows = load_jsonl(args.dataset)
    print(f"dataset: {args.dataset} rows={len(rows)} classifier={args.classifier}")

    result = evaluate_categorizer(rows)
    temporal = result.get("temporal", {})
    if "error" in temporal:
        print("temporal:", temporal["error"])
        return

    print(f"temporal holdout ({temporal['samples']} test rows):")
    print(f"  accuracy    {temporal['accuracy']:.4f}")
    print(f"  f1_weighted {temporal['f1_weighted']:.4f}")
    print(f"  f1_macro    {temporal['f1_macro']:.4f}")
    print(f"{'class':<8}{'prec':>8}{'rec':>8}{'f1':>8}{'n':>6}")
    for cls, c in sorted(
        temporal["per_class"].items(), key=lambda kv: -kv[1]["support"]
    ):
        print(f"{cls:<8}{c['precision']:>8.2f}{c['recall']:>8.2f}{c['f1']:>8.2f}{c['support']:>6}")


if __name__ == "__main__":
    main()
