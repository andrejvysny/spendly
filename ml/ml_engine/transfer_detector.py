"""Transfer detection pipeline: regex → ML classifier → cross-account matching."""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass
from datetime import timedelta

import joblib
import numpy as np
import pandas as pd
from scipy.sparse import hstack
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression

from .config import Config
from .feature_engine import build_text_feature, extract_amount_features, extract_type_features
from .preprocessor import clean_description

logger = logging.getLogger(__name__)


@dataclass
class TransferPrediction:
    transaction_id: int
    is_transfer: bool
    confidence: float
    method: str  # "regex" | "ml_classifier" | "cross_account_match"
    suggested_pair_id: int | None


# ── Stage 1: Regex patterns (run on RAW description, before clean_description strips prefixes) ──

_TRANSFER_PATTERNS_HIGH: list[re.Pattern] = [
    re.compile(r"prevod na ucet", re.IGNORECASE),
    re.compile(r"vlastny prevod", re.IGNORECASE),
    re.compile(r"prevody medzi", re.IGNORECASE),
    re.compile(r"own transfer", re.IGNORECASE),
    re.compile(r"internal transfer", re.IGNORECASE),
    re.compile(r"between accounts", re.IGNORECASE),
    re.compile(r"sporenie", re.IGNORECASE),
]

_TRANSFER_PATTERNS_LOW: list[re.Pattern] = [
    re.compile(r"\bprevod\b", re.IGNORECASE),
    re.compile(r"\btransfer\b", re.IGNORECASE),
    re.compile(r"\büberweisung\b", re.IGNORECASE),
    re.compile(r"\bvirement\b", re.IGNORECASE),
    re.compile(r"\bbonifico\b", re.IGNORECASE),
]


def _regex_score(raw_description: str) -> float:
    """Return regex confidence for transfer-like description. 0.0 if no match."""
    if not raw_description:
        return 0.0
    for pat in _TRANSFER_PATTERNS_HIGH:
        if pat.search(raw_description):
            return Config.THRESHOLDS.transfer_regex_high
    for pat in _TRANSFER_PATTERNS_LOW:
        if pat.search(raw_description):
            return Config.THRESHOLDS.transfer_regex_low
    return 0.0


# ── Stage 2: ML classifier (TF-IDF + LogisticRegression) ──

class TransferClassifier:
    """Binary classifier: is this transaction a transfer?"""

    def __init__(self, user_id: int):
        self.user_id = user_id
        self.model_path = Config.model_path(user_id, "transfer_detector")
        self.vectorizer: TfidfVectorizer | None = None
        self.classifier: LogisticRegression | None = None
        self.type_columns: list[str] = []
        self._load()

    def _load(self) -> None:
        if self.model_path.exists():
            try:
                bundle = joblib.load(self.model_path)
                self.vectorizer = bundle["vectorizer"]
                self.classifier = bundle["classifier"]
                self.type_columns = bundle.get("type_columns", [])
            except Exception as e:
                logger.warning("Failed to load transfer model: %s", e)

    @property
    def is_trained(self) -> bool:
        return self.classifier is not None and self.vectorizer is not None

    def train(self, df: pd.DataFrame) -> dict:
        """Train binary transfer classifier on user's labeled data.

        Positive class: type == 'TRANSFER'. Negative: everything else.
        """
        if df.empty:
            return {"status": "error", "message": "No training data"}

        labels = (df["type"] == "TRANSFER").astype(int)
        n_positive = int(labels.sum())
        n_negative = int((~labels.astype(bool)).sum())

        if n_positive < Config.THRESHOLDS.transfer_min_training_samples:
            return {
                "status": "error",
                "message": f"Need at least {Config.THRESHOLDS.transfer_min_training_samples} "
                           f"transfers for training, found {n_positive}",
            }

        # Text features (cleaned)
        texts = df.apply(lambda r: clean_description(build_text_feature(r)), axis=1).tolist()

        self.vectorizer = TfidfVectorizer(max_features=3000, ngram_range=(1, 2), sublinear_tf=True)
        tfidf = self.vectorizer.fit_transform(texts)

        # Numeric features
        amount_feats = extract_amount_features(df)[["amount_sign"]].values
        type_df = extract_type_features(df)
        self.type_columns = list(type_df.columns)
        type_feats = type_df.values

        X = hstack([tfidf, amount_feats, type_feats])

        self.classifier = LogisticRegression(
            max_iter=500,
            class_weight="balanced",
            C=1.0,
        )
        self.classifier.fit(X, labels)

        # Persist
        joblib.dump(
            {"vectorizer": self.vectorizer, "classifier": self.classifier, "type_columns": self.type_columns},
            self.model_path,
        )

        # Metrics
        proba = self.classifier.predict_proba(X)[:, 1]
        from sklearn.metrics import accuracy_score, f1_score
        preds = (proba >= 0.5).astype(int)

        return {
            "status": "success",
            "message": f"Trained on {len(df)} samples ({n_positive} transfers, {n_negative} non-transfers)",
            "metrics": {
                "accuracy": round(float(accuracy_score(labels, preds)), 4),
                "f1_score": round(float(f1_score(labels, preds, zero_division=0)), 4),
                "positive_samples": n_positive,
                "negative_samples": n_negative,
            },
        }

    def predict(self, df: pd.DataFrame) -> list[tuple[int, float]]:
        """Return list of (transaction_id, transfer_probability)."""
        if not self.is_trained or df.empty:
            return []

        texts = df.apply(lambda r: clean_description(build_text_feature(r)), axis=1).tolist()
        tfidf = self.vectorizer.transform(texts)

        amount_feats = extract_amount_features(df)[["amount_sign"]].values
        type_df = extract_type_features(df)
        # Align type columns with training
        for col in self.type_columns:
            if col not in type_df.columns:
                type_df[col] = 0
        type_df = type_df.reindex(columns=self.type_columns, fill_value=0)
        type_feats = type_df.values

        X = hstack([tfidf, amount_feats, type_feats])
        proba = self.classifier.predict_proba(X)[:, 1]

        return list(zip(df["id"].tolist(), proba.tolist()))


# ── Stage 3: Cross-account amount correlation ──

def _find_cross_account_pair(
    txn_id: int,
    txn_amount: float,
    txn_date: pd.Timestamp,
    txn_account_id: int,
    txn_desc: str,
    all_transactions: pd.DataFrame,
) -> int | None:
    """Find a matching counterpart on a different account within ±N days."""
    window = timedelta(days=Config.THRESHOLDS.transfer_cross_account_window_days)
    tolerance = Config.THRESHOLDS.transfer_amount_tolerance
    target_amount = -txn_amount  # opposite sign

    candidates = all_transactions[
        (all_transactions["id"] != txn_id)
        & (all_transactions["account_id"] != txn_account_id)
        & (abs(all_transactions["amount"].astype(float) - target_amount) <= tolerance)
        & (abs(all_transactions["booked_date"] - txn_date) <= window)
    ]

    if candidates.empty:
        return None

    # Score by date proximity (prefer same-day)
    date_dist = abs(candidates["booked_date"] - txn_date).dt.total_seconds() / 86400.0
    scores = 1.0 / (1.0 + date_dist)

    # Optionally boost by description similarity
    try:
        from rapidfuzz import fuzz
        clean_txn = clean_description(txn_desc)
        desc_scores = candidates.apply(
            lambda r: fuzz.ratio(clean_txn, clean_description(str(r.get("description", "")))) / 100.0,
            axis=1,
        )
        scores = scores * 0.6 + desc_scores * 0.4
    except ImportError:
        pass

    best_idx = scores.idxmax()
    return int(candidates.loc[best_idx, "id"])


# ── Main pipeline ──

class TransferDetector:
    """Three-stage transfer detection pipeline."""

    def __init__(self, user_id: int):
        self.user_id = user_id
        self.classifier = TransferClassifier(user_id)

    def detect(
        self,
        candidates: pd.DataFrame,
        all_transactions: pd.DataFrame | None = None,
    ) -> list[TransferPrediction]:
        """Run full pipeline on candidate transactions.

        Args:
            candidates: transactions to evaluate (non-transfer, unpaired)
            all_transactions: all user transactions for cross-account matching (optional)
        """
        if candidates.empty:
            return []

        predictions: list[TransferPrediction] = []
        seen_ids: set[int] = set()

        # Stage 1: Regex on raw description
        for _, row in candidates.iterrows():
            txn_id = int(row["id"])
            raw_desc = str(row.get("description", "") or "")
            score = _regex_score(raw_desc)
            if score > 0:
                pair_id = None
                if all_transactions is not None:
                    pair_id = _find_cross_account_pair(
                        txn_id,
                        float(row["amount"]),
                        row["booked_date"],
                        int(row["account_id"]),
                        raw_desc,
                        all_transactions,
                    )
                predictions.append(TransferPrediction(
                    transaction_id=txn_id,
                    is_transfer=True,
                    confidence=score,
                    method="regex",
                    suggested_pair_id=pair_id,
                ))
                seen_ids.add(txn_id)

        # Stage 2: ML classifier on remaining
        remaining = candidates[~candidates["id"].isin(seen_ids)]
        if not remaining.empty and self.classifier.is_trained:
            ml_results = self.classifier.predict(remaining)
            for txn_id, proba in ml_results:
                if proba >= Config.THRESHOLDS.transfer_ml_auto:
                    row = remaining[remaining["id"] == txn_id].iloc[0]
                    pair_id = None
                    if all_transactions is not None:
                        pair_id = _find_cross_account_pair(
                            txn_id,
                            float(row["amount"]),
                            row["booked_date"],
                            int(row["account_id"]),
                            str(row.get("description", "")),
                            all_transactions,
                        )
                    predictions.append(TransferPrediction(
                        transaction_id=txn_id,
                        is_transfer=True,
                        confidence=round(proba, 3),
                        method="ml_classifier",
                        suggested_pair_id=pair_id,
                    ))
                    seen_ids.add(txn_id)

        # Stage 3: Cross-account amount matching on still-remaining
        if all_transactions is not None:
            still_remaining = candidates[~candidates["id"].isin(seen_ids)]
            for _, row in still_remaining.iterrows():
                txn_id = int(row["id"])
                pair_id = _find_cross_account_pair(
                    txn_id,
                    float(row["amount"]),
                    row["booked_date"],
                    int(row["account_id"]),
                    str(row.get("description", "")),
                    all_transactions,
                )
                if pair_id is not None:
                    predictions.append(TransferPrediction(
                        transaction_id=txn_id,
                        is_transfer=True,
                        confidence=0.60,
                        method="cross_account_match",
                        suggested_pair_id=pair_id,
                    ))

        return predictions

    def train(self, df: pd.DataFrame) -> dict:
        """Train the ML classifier component."""
        return self.classifier.train(df)
