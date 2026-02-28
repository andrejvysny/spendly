"""Multi-stage transaction categorization pipeline.

Stages (in priority order):
1. MCC Lookup — card payments with MCC codes from GoCardless
2. Merchant Pattern Match — reuse category from same merchant's history
3. ML Classifier — TF-IDF char n-grams + CalibratedLinearSVC
4. Embedding Similarity — cold-start fallback via category centroids
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

import joblib
import numpy as np
import pandas as pd
from scipy.sparse import hstack
from sklearn.calibration import CalibratedClassifierCV
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.preprocessing import StandardScaler
from sklearn.svm import LinearSVC

from .config import Config
from .feature_engine import (
    build_feature_matrix,
    build_text_feature,
    extract_amount_features,
    extract_mcc_code,
    extract_temporal_features,
    extract_type_features,
)
from .mcc_mapping import match_mcc_to_user_category
from .preprocessor import clean_description

logger = logging.getLogger(__name__)


@dataclass
class Prediction:
    transaction_id: int
    predicted_category_id: int
    confidence: float
    method: str
    needs_review: bool


class TransactionCategorizer:
    """Multi-stage categorizer with per-user models."""

    def __init__(self, user_id: int):
        self.user_id = user_id
        self._model: CalibratedClassifierCV | None = None
        self._vectorizer: TfidfVectorizer | None = None
        self._scaler: StandardScaler | None = None
        self._load_model()

    def _model_path(self) -> str:
        return str(Config.model_path(self.user_id, "categorizer"))

    def _load_model(self) -> None:
        path = self._model_path()
        try:
            artifacts = joblib.load(path)
            self._vectorizer = artifacts["vectorizer"]
            self._model = artifacts["model"]
            self._scaler = artifacts["scaler"]
            logger.info("Loaded categorizer model for user %d", self.user_id)
        except (FileNotFoundError, KeyError):
            logger.info("No trained categorizer model for user %d", self.user_id)

    def train(self, df: pd.DataFrame, categories: list[dict]) -> dict[str, Any]:
        """Train ML classifier (Stage 3) on labeled transactions.

        Returns dict with training status and metrics.
        """
        labeled = df[df["category_id"].notna()].copy()
        n_samples = len(labeled)

        if n_samples < Config.THRESHOLDS.min_training_samples:
            return {
                "status": "skipped",
                "message": f"Need {Config.THRESHOLDS.min_training_samples} labeled, have {n_samples}",
            }

        texts, numeric_features = build_feature_matrix(labeled)
        cleaned_texts = [clean_description(t) for t in texts]
        y = labeled["category_id"].astype(int).values

        # TF-IDF char n-grams — handles Slovak/multilingual text well
        self._vectorizer = TfidfVectorizer(
            analyzer="char_wb",
            ngram_range=(3, 5),
            max_features=5000,
            sublinear_tf=True,
        )
        tfidf_matrix = self._vectorizer.fit_transform(cleaned_texts)

        self._scaler = StandardScaler()
        numeric_scaled = self._scaler.fit_transform(numeric_features.values)

        X = hstack([tfidf_matrix, numeric_scaled])

        base_svc = LinearSVC(max_iter=5000, class_weight="balanced")
        cv_folds = min(5, max(2, n_samples // 5))
        self._model = CalibratedClassifierCV(base_svc, cv=cv_folds)
        self._model.fit(X, y)

        joblib.dump(
            {"vectorizer": self._vectorizer, "model": self._model, "scaler": self._scaler},
            self._model_path(),
        )

        train_score = self._model.score(X, y)
        n_classes = len(set(y))
        logger.info("Trained categorizer: %d samples, %d classes, acc=%.3f", n_samples, n_classes, train_score)

        return {
            "status": "success",
            "message": f"Trained on {n_samples} samples, {n_classes} classes",
            "metrics": {"samples": n_samples, "classes": n_classes, "train_accuracy": round(train_score, 4)},
        }

    def predict(
        self,
        df: pd.DataFrame,
        categories: list[dict],
        merchants: list[dict],
        embedding_service: Any = None,
    ) -> list[Prediction]:
        """Run multi-stage prediction pipeline on uncategorized transactions."""
        predictions: list[Prediction] = []
        threshold = Config.THRESHOLDS.categorizer_auto
        merchant_category_map = self._build_merchant_category_map(df)

        for _, row in df.iterrows():
            if pd.notna(row.get("category_id")):
                continue

            pred = self._predict_single(row, categories, merchant_category_map, embedding_service)
            if pred is not None:
                pred.needs_review = pred.confidence < threshold
                predictions.append(pred)

        return predictions

    def _predict_single(
        self,
        row: pd.Series,
        categories: list[dict],
        merchant_category_map: dict[int, int],
        embedding_service: Any,
    ) -> Prediction | None:
        tx_id = int(row["id"])

        # Stage 1: MCC lookup
        mcc_code = extract_mcc_code(row.get("metadata"))
        if mcc_code:
            cat_id, conf = match_mcc_to_user_category(mcc_code, categories, embedding_service)
            if cat_id is not None:
                return Prediction(tx_id, cat_id, conf, "mcc", False)

        # Stage 2: Merchant pattern match
        merchant_id = row.get("merchant_id")
        if pd.notna(merchant_id) and int(merchant_id) in merchant_category_map:
            cat_id = merchant_category_map[int(merchant_id)]
            return Prediction(tx_id, cat_id, Config.THRESHOLDS.categorizer_merchant_match, "merchant_match", False)

        # Stage 3: ML classifier
        if self._model is not None and self._vectorizer is not None:
            pred = self._predict_ml(row)
            if pred is not None:
                return pred

        # Stage 4: Embedding similarity
        if embedding_service is not None and categories:
            pred = self._predict_embedding(row, categories, embedding_service)
            if pred is not None:
                return pred

        return None

    def _predict_ml(self, row: pd.Series) -> Prediction | None:
        text = clean_description(build_text_feature(row))
        if not text.strip():
            return None

        tfidf = self._vectorizer.transform([text])

        row_df = pd.DataFrame([row])
        parts = [extract_amount_features(row_df), extract_temporal_features(row_df), extract_type_features(row_df)]
        numeric = pd.concat(parts, axis=1).fillna(0)
        numeric_scaled = self._scaler.transform(numeric.values)

        X = hstack([tfidf, numeric_scaled])
        proba = self._model.predict_proba(X)[0]
        best_idx = int(np.argmax(proba))
        confidence = float(proba[best_idx])
        predicted_class = self._model.classes_[best_idx]

        return Prediction(int(row["id"]), int(predicted_class), confidence, "ml_classifier", False)

    def _predict_embedding(
        self, row: pd.Series, categories: list[dict], embedding_service: Any
    ) -> Prediction | None:
        text = clean_description(build_text_feature(row))
        if not text.strip():
            return None

        cat_names = [c["name"] for c in categories]
        scores = embedding_service.similarity(text, cat_names)
        if not scores:
            return None

        best_idx = int(np.argmax(scores))
        confidence = float(scores[best_idx])
        if confidence < 0.40:
            return None

        return Prediction(int(row["id"]), categories[best_idx]["id"], confidence, "embedding_similarity", False)

    @staticmethod
    def _build_merchant_category_map(df: pd.DataFrame) -> dict[int, int]:
        labeled = df[df["category_id"].notna() & df["merchant_id"].notna()]
        if labeled.empty:
            return {}
        return (
            labeled.groupby("merchant_id")["category_id"]
            .agg(lambda x: x.mode().iloc[0] if len(x) > 0 else None)
            .dropna()
            .astype(int)
            .to_dict()
        )
