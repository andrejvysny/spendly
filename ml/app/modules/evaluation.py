"""Categorizer evaluation: temporal holdout + CV-based per-class thresholds.

Temporal holdout is the primary metric — it answers "does the model categorize
next month's import correctly", which random splits overstate by leaking
near-duplicate merchant strings across the split.
"""

from __future__ import annotations

import logging
from collections import defaultdict
from datetime import datetime

from sklearn.model_selection import StratifiedKFold

from app.modules.model_training import MIN_TRAINING_SAMPLES, TransactionCategorizer

logger = logging.getLogger(__name__)

MIN_TEST_SAMPLES = 20
CV_FOLDS = 5
THRESHOLD_PRECISION_TARGET = 0.95
THRESHOLD_FALLBACK = 0.75


def _valid_rows(history: list[dict]) -> list[dict]:
    return [
        t for t in history
        if t.get("category_id") is not None and t.get("category_name")
    ]


def _sort_temporal(rows: list[dict]) -> list[dict]:
    def key(t: dict) -> datetime:
        value = t.get("booked_date")
        if isinstance(value, datetime):
            return value
        try:
            return datetime.fromisoformat(str(value))
        except (TypeError, ValueError):
            return datetime.min

    return sorted(rows, key=key)


def _predict_rows(categorizer: TransactionCategorizer, rows: list[dict]) -> list[dict]:
    batch = [
        {
            "transaction_id": i,
            "description": t.get("description", ""),
            "counterparty_name": t.get("counterparty_name"),
            "amount": float(t.get("amount", 0)),
        }
        for i, t in enumerate(rows)
    ]
    return categorizer.predict_batch(batch)


def _score(rows: list[dict], predictions: list[dict]) -> dict:
    """Accuracy, per-class P/R/F1 and confusion matrix over true vs predicted ids."""
    per_class: dict[str, dict[str, int]] = defaultdict(
        lambda: {"tp": 0, "fp": 0, "fn": 0, "support": 0}
    )
    confusion: dict[str, dict[str, int]] = defaultdict(lambda: defaultdict(int))
    correct = 0

    for t, p in zip(rows, predictions, strict=True):
        true_id = str(t["category_id"])
        pred_id = str(p.get("category_id"))
        confusion[true_id][pred_id] += 1
        per_class[true_id]["support"] += 1
        if pred_id == true_id:
            correct += 1
            per_class[true_id]["tp"] += 1
        else:
            per_class[true_id]["fn"] += 1
            per_class[pred_id]["fp"] += 1

    classes = {}
    f1_weighted_num = 0.0
    f1_macro_sum = 0.0
    supported = 0
    for cls, c in per_class.items():
        precision = c["tp"] / (c["tp"] + c["fp"]) if (c["tp"] + c["fp"]) else 0.0
        recall = c["tp"] / (c["tp"] + c["fn"]) if (c["tp"] + c["fn"]) else 0.0
        f1 = 2 * precision * recall / (precision + recall) if (precision + recall) else 0.0
        classes[cls] = {
            "precision": round(precision, 4),
            "recall": round(recall, 4),
            "f1": round(f1, 4),
            "support": c["support"],
        }
        if c["support"]:
            f1_weighted_num += f1 * c["support"]
            f1_macro_sum += f1
            supported += 1

    total = len(rows)
    return {
        "samples": total,
        "accuracy": round(correct / total, 4) if total else 0.0,
        "f1_weighted": round(f1_weighted_num / total, 4) if total else 0.0,
        "f1_macro": round(f1_macro_sum / supported, 4) if supported else 0.0,
        "per_class": classes,
        "confusion": {k: dict(v) for k, v in confusion.items()},
    }


def _compute_thresholds(oof: list[tuple[str, str, float]]) -> dict[str, float]:
    """Per predicted class: min confidence where cumulative precision >= target.

    oof rows: (true_id, pred_id, confidence). Walk predictions of each class
    from most to least confident; the threshold is the lowest confidence whose
    prefix still satisfies the precision target.
    """
    by_pred: dict[str, list[tuple[float, bool]]] = defaultdict(list)
    for true_id, pred_id, conf in oof:
        by_pred[pred_id].append((conf, true_id == pred_id))

    thresholds: dict[str, float] = {}
    for cls, rows in by_pred.items():
        rows.sort(key=lambda r: -r[0])
        best: float | None = None
        hits = 0
        for i, (conf, ok) in enumerate(rows, start=1):
            hits += int(ok)
            if hits / i >= THRESHOLD_PRECISION_TARGET:
                best = conf
        thresholds[cls] = round(best, 4) if best is not None else THRESHOLD_FALLBACK
    return thresholds


def evaluate_categorizer(history: list[dict]) -> dict:
    """Full evaluation. Returns {} when there is too little data to say anything."""
    rows = _valid_rows(history)
    result: dict = {"samples": len(rows)}

    # temporal holdout
    ordered = _sort_temporal(rows)
    split = int(len(ordered) * 0.8)
    train_rows, test_rows = ordered[:split], ordered[split:]
    if len(train_rows) >= MIN_TRAINING_SAMPLES and len(test_rows) >= MIN_TEST_SAMPLES:
        categorizer = TransactionCategorizer()
        trained = categorizer.train(train_rows)
        if trained.get("success"):
            result["temporal"] = _score(test_rows, _predict_rows(categorizer, test_rows))
        else:
            result["temporal"] = {"error": trained.get("error", "training failed")}
    else:
        result["temporal"] = {"error": "not enough data for temporal holdout"}

    # stratified CV out-of-fold predictions -> per-class thresholds
    labels = [str(t["category_id"]) for t in rows]
    label_counts: dict[str, int] = defaultdict(int)
    for label in labels:
        label_counts[label] += 1
    cv_rows = [t for t in rows if label_counts[str(t["category_id"])] >= CV_FOLDS]
    if len(cv_rows) >= MIN_TRAINING_SAMPLES + MIN_TEST_SAMPLES:
        oof: list[tuple[str, str, float]] = []
        cv_labels = [str(t["category_id"]) for t in cv_rows]
        skf = StratifiedKFold(n_splits=CV_FOLDS, shuffle=True, random_state=42)
        for train_idx, test_idx in skf.split(cv_rows, cv_labels):
            fold_train = [cv_rows[i] for i in train_idx]
            fold_test = [cv_rows[i] for i in test_idx]
            categorizer = TransactionCategorizer()
            if not categorizer.train(fold_train).get("success"):
                continue
            for t, p in zip(fold_test, _predict_rows(categorizer, fold_test), strict=True):
                pred = p.get("category_id")
                if pred is not None:
                    oof.append((str(t["category_id"]), str(pred), float(p.get("confidence", 0.0))))
        result["thresholds"] = _compute_thresholds(oof) if oof else {}
    else:
        result["thresholds"] = {}

    return result
