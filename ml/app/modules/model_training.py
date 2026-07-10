"""ML model training pipeline for transaction categorization.

Uses TF-IDF (word + char n-grams) + SGDClassifier for:
- Fast training and inference (<10ms per transaction)
- Incremental learning via partial_fit()
- Calibrated probability estimates via modified_huber loss
"""

from __future__ import annotations

import json
import logging
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import joblib
import numpy as np
from scipy.sparse import hstack
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import SGDClassifier
from sklearn.metrics import accuracy_score, f1_score
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder
from unidecode import unidecode

from app.core.config import settings

logger = logging.getLogger(__name__)

MODELS_DIR = settings.models_dir
MIN_TRAINING_SAMPLES = 50
MIN_SAMPLES_PER_CLASS = 3


def _prepare_text(description: str, counterparty: str | None = None) -> str:
    """Combine and normalize text features for TF-IDF."""
    parts = []
    if description:
        parts.append(description.strip())
    if counterparty:
        parts.append(counterparty.strip())
    text = " ".join(parts).lower()
    # ASCII-fold for diacritics (Slovak/Czech text)
    text = unidecode(text)
    return text


def _amount_bucket(amount: float) -> str:
    """Discretize amount into buckets."""
    abs_amount = abs(amount)
    if abs_amount < 5:
        return "tiny"
    elif abs_amount < 20:
        return "small"
    elif abs_amount < 50:
        return "medium"
    elif abs_amount < 100:
        return "large"
    elif abs_amount < 500:
        return "xlarge"
    else:
        return "huge"


def _direction(amount: float) -> str:
    return "credit" if amount > 0 else "debit"


class TransactionCategorizer:
    """TF-IDF + SGDClassifier for transaction categorization."""

    def __init__(self) -> None:
        self.word_vectorizer = TfidfVectorizer(
            analyzer="word",
            ngram_range=(1, 3),
            max_features=10000,
            sublinear_tf=True,
        )
        self.char_vectorizer = TfidfVectorizer(
            analyzer="char_wb",
            ngram_range=(3, 5),
            max_features=10000,
            sublinear_tf=True,
        )
        self.classifier = SGDClassifier(
            loss="modified_huber",  # Gives calibrated probabilities
            penalty="l2",
            alpha=1e-4,
            max_iter=1000,
            tol=1e-3,
            random_state=42,
            class_weight="balanced",
        )
        self.label_encoder = LabelEncoder()
        self.is_fitted = False
        self.version = 1
        self.trained_at: str | None = None
        self.training_samples = 0
        self.metrics: dict[str, float | str] = {}
        self.classes_: list[str] = []

    def _extract_features(
        self,
        texts: list[str],
        amounts: list[float],
        fit: bool = False,
    ) -> Any:
        """Extract combined feature matrix."""
        if fit:
            word_features = self.word_vectorizer.fit_transform(texts)
            char_features = self.char_vectorizer.fit_transform(texts)
        else:
            word_features = self.word_vectorizer.transform(texts)
            char_features = self.char_vectorizer.transform(texts)

        # Amount bucket and direction as one-hot via TF-IDF on single tokens
        amount_texts = [
            f"{_amount_bucket(a)} {_direction(a)}" for a in amounts
        ]
        if fit:
            self._amount_vectorizer = TfidfVectorizer(analyzer="word")
            amount_features = self._amount_vectorizer.fit_transform(amount_texts)
        else:
            amount_features = self._amount_vectorizer.transform(amount_texts)

        return hstack([word_features, char_features, amount_features])

    def train(self, transactions: list[dict]) -> dict:
        """Train the categorizer from labeled transaction data.

        Args:
            transactions: List of dicts with keys:
                description, counterparty_name, amount, category_id, category_name

        Returns:
            Training metrics dict.
        """
        # Filter to valid samples
        valid = [
            t for t in transactions
            if t.get("category_id") is not None and t.get("category_name")
        ]

        if len(valid) < MIN_TRAINING_SAMPLES:
            return {
                "success": False,
                "error": (
                    f"Need at least {MIN_TRAINING_SAMPLES} labeled transactions, "
                    f"got {len(valid)}"
                ),
                "training_samples": len(valid),
            }

        # Filter classes with too few samples
        from collections import Counter
        class_counts = Counter(str(t["category_id"]) for t in valid)
        valid_classes = {
            cls for cls, count in class_counts.items()
            if count >= MIN_SAMPLES_PER_CLASS
        }
        valid = [t for t in valid if str(t["category_id"]) in valid_classes]

        if len(valid) < MIN_TRAINING_SAMPLES:
            return {
                "success": False,
                "error": f"After filtering rare classes, only {len(valid)} samples remain",
                "training_samples": len(valid),
            }

        texts = [
            _prepare_text(t.get("description", ""), t.get("counterparty_name"))
            for t in valid
        ]
        amounts = [float(t.get("amount", 0)) for t in valid]
        labels = [str(t["category_id"]) for t in valid]

        # Build category name mapping
        self._category_names: dict[str, str] = {}
        for t in valid:
            self._category_names[str(t["category_id"])] = str(t["category_name"])

        # Encode labels
        encoded_labels = self.label_encoder.fit_transform(labels)
        self.classes_ = list(self.label_encoder.classes_)

        # Extract features and train
        X = self._extract_features(texts, amounts, fit=True)

        # Train/test split for metrics (if enough data)
        if len(valid) >= 100:
            X_train, X_test, y_train, y_test = train_test_split(
                X, encoded_labels, test_size=0.2, random_state=42, stratify=encoded_labels
            )
            self.classifier.fit(X_train, y_train)
            y_pred = self.classifier.predict(X_test)
            self.metrics = {
                "accuracy": round(float(accuracy_score(y_test, y_pred)), 4),
                "f1_weighted": round(
                    float(f1_score(y_test, y_pred, average="weighted", zero_division=0)), 4
                ),
            }
            # Refit on all data
            self.classifier.fit(X, encoded_labels)
        else:
            self.classifier.fit(X, encoded_labels)
            self.metrics = {"accuracy": 0.0, "f1_weighted": 0.0, "note": "too_few_for_eval"}

        self.is_fitted = True
        self.training_samples = len(valid)
        self.trained_at = datetime.now(UTC).isoformat()
        self.version += 1

        logger.info(
            "Trained categorizer: %d samples, %d classes, acc=%.3f, f1=%.3f",
            self.training_samples,
            len(self.classes_),
            self.metrics.get("accuracy", 0),
            self.metrics.get("f1_weighted", 0),
        )

        return {
            "success": True,
            "training_samples": self.training_samples,
            "num_classes": len(self.classes_),
            "metrics": self.metrics,
            "version": self.version,
        }

    def partial_train(self, transactions: list[dict]) -> dict:
        """Incrementally update the model with new labeled data.

        Uses SGDClassifier.partial_fit() for online learning.
        """
        if not self.is_fitted:
            return self.train(transactions)

        valid = [
            t for t in transactions
            if t.get("category_id") is not None and t.get("category_name")
        ]
        if not valid:
            return {"success": False, "error": "No valid labeled transactions"}

        texts = [
            _prepare_text(t.get("description", ""), t.get("counterparty_name"))
            for t in valid
        ]
        amounts = [float(t.get("amount", 0)) for t in valid]
        labels = [str(t["category_id"]) for t in valid]

        # Update category names
        for t in valid:
            self._category_names[str(t["category_id"])] = str(t["category_name"])

        # Handle new classes
        new_classes = set(labels) - set(self.classes_)
        if new_classes:
            all_classes = sorted(set(self.classes_) | new_classes)
            self.label_encoder.classes_ = np.array(all_classes)
            self.classes_ = all_classes

        encoded_labels = self.label_encoder.transform(labels)
        X = self._extract_features(texts, amounts, fit=False)

        self.classifier.partial_fit(
            X, encoded_labels, classes=np.arange(len(self.classes_))
        )

        self.training_samples += len(valid)
        self.trained_at = datetime.now(UTC).isoformat()

        logger.info(
            "Partial trained with %d new samples (total: %d)", len(valid), self.training_samples
        )

        return {
            "success": True,
            "new_samples": len(valid),
            "total_samples": self.training_samples,
            "version": self.version,
        }

    def predict(
        self,
        description: str,
        amount: float,
        counterparty_name: str | None = None,
    ) -> dict:
        """Predict category for a single transaction."""
        if not self.is_fitted:
            return {
                "category_id": None,
                "category_name": None,
                "confidence": 0.0,
                "method": "no_model",
                "alternatives": [],
            }

        text = _prepare_text(description, counterparty_name)
        X = self._extract_features([text], [amount], fit=False)

        proba = self.classifier.predict_proba(X)[0]
        top_indices = np.argsort(proba)[::-1]

        best_idx = top_indices[0]
        best_class = self.classes_[best_idx]
        best_confidence = float(proba[best_idx])

        alternatives = []
        for idx in top_indices[1:4]:  # Top 3 alternatives
            if proba[idx] > 0.05:
                alt_class = self.classes_[idx]
                alternatives.append({
                    "category_id": int(alt_class),
                    "category_name": self._category_names.get(alt_class, ""),
                    "score": round(float(proba[idx]), 4),
                })

        return {
            "category_id": int(best_class),
            "category_name": self._category_names.get(best_class, ""),
            "confidence": round(best_confidence, 4),
            "method": "ml_model",
            "alternatives": alternatives,
        }

    def predict_batch(self, transactions: list[dict]) -> list[dict]:
        """Predict categories for a batch of transactions."""
        if not self.is_fitted:
            return [
                {
                    "transaction_id": t.get("id", 0),
                    "category_id": None,
                    "category_name": None,
                    "confidence": 0.0,
                    "method": "no_model",
                    "alternatives": [],
                }
                for t in transactions
            ]

        texts = [
            _prepare_text(t.get("description", ""), t.get("counterparty_name"))
            for t in transactions
        ]
        amounts = [float(t.get("amount", 0)) for t in transactions]

        X = self._extract_features(texts, amounts, fit=False)
        probas = self.classifier.predict_proba(X)

        results = []
        for txn, proba in zip(transactions, probas, strict=False):
            top_indices = np.argsort(proba)[::-1]
            best_idx = top_indices[0]
            best_class = self.classes_[best_idx]

            alternatives = []
            for idx in top_indices[1:4]:
                if proba[idx] > 0.05:
                    alt_class = self.classes_[idx]
                    alternatives.append({
                        "category_id": int(alt_class),
                        "category_name": self._category_names.get(alt_class, ""),
                        "score": round(float(proba[idx]), 4),
                    })

            results.append({
                "transaction_id": txn.get("id", 0),
                "category_id": int(best_class),
                "category_name": self._category_names.get(best_class, ""),
                "confidence": round(float(proba[best_idx]), 4),
                "method": "ml_model",
                "alternatives": alternatives,
            })

        return results

    def save(self, user_id: int | None = None) -> Path:
        """Save model to disk."""
        MODELS_DIR.mkdir(parents=True, exist_ok=True)
        suffix = f"user_{user_id}" if user_id else "global"
        model_path = MODELS_DIR / f"categorizer_{suffix}_v{self.version}.joblib"

        model_data = {
            "word_vectorizer": self.word_vectorizer,
            "char_vectorizer": self.char_vectorizer,
            "amount_vectorizer": getattr(self, "_amount_vectorizer", None),
            "classifier": self.classifier,
            "label_encoder": self.label_encoder,
            "classes": self.classes_,
            "category_names": getattr(self, "_category_names", {}),
            "version": self.version,
            "trained_at": self.trained_at,
            "training_samples": self.training_samples,
            "metrics": self.metrics,
        }
        joblib.dump(model_data, model_path)

        # Write manifest
        manifest_path = MODELS_DIR / f"categorizer_{suffix}_manifest.json"
        manifest = {
            "model_file": model_path.name,
            "version": self.version,
            "trained_at": self.trained_at,
            "training_samples": self.training_samples,
            "num_classes": len(self.classes_),
            "metrics": self.metrics,
        }
        manifest_path.write_text(json.dumps(manifest, indent=2))

        # Clean old versions (keep last 2)
        for old_file in sorted(MODELS_DIR.glob(f"categorizer_{suffix}_v*.joblib")):
            if old_file != model_path:
                version_str = old_file.stem.split("_v")[-1]
                try:
                    if int(version_str) < self.version - 1:
                        old_file.unlink()
                        logger.info("Cleaned old model: %s", old_file)
                except ValueError:
                    pass

        logger.info("Saved model to %s", model_path)
        return model_path

    @classmethod
    def load(cls, user_id: int | None = None) -> TransactionCategorizer | None:
        """Load the latest model from disk."""
        suffix = f"user_{user_id}" if user_id else "global"
        manifest_path = MODELS_DIR / f"categorizer_{suffix}_manifest.json"

        if not manifest_path.exists():
            return None

        try:
            manifest = json.loads(manifest_path.read_text())
            model_path = MODELS_DIR / manifest["model_file"]

            if not model_path.exists():
                logger.warning("Model file %s not found", model_path)
                return None

            data = joblib.load(model_path)

            categorizer = cls()
            categorizer.word_vectorizer = data["word_vectorizer"]
            categorizer.char_vectorizer = data["char_vectorizer"]
            categorizer._amount_vectorizer = data.get("amount_vectorizer")
            categorizer.classifier = data["classifier"]
            categorizer.label_encoder = data["label_encoder"]
            categorizer.classes_ = data.get("classes", [])
            categorizer._category_names = data.get("category_names", {})
            categorizer.version = data.get("version", 1)
            categorizer.trained_at = data.get("trained_at")
            categorizer.training_samples = data.get("training_samples", 0)
            categorizer.metrics = data.get("metrics", {})
            categorizer.is_fitted = True

            logger.info(
                "Loaded categorizer for %s (v%d, %d samples)",
                suffix, categorizer.version, categorizer.training_samples,
            )
            return categorizer
        except Exception as e:
            logger.warning("Failed to load model for %s: %s", suffix, e)
            return None


# In-memory model cache
_model_cache: dict[int | None, TransactionCategorizer] = {}


def get_categorizer(user_id: int | None = None) -> TransactionCategorizer | None:
    """Get cached or load categorizer model."""
    if user_id not in _model_cache:
        loaded = TransactionCategorizer.load(user_id)
        if loaded:
            _model_cache[user_id] = loaded
    return _model_cache.get(user_id)


def get_or_create_categorizer(user_id: int | None = None) -> TransactionCategorizer:
    """Get existing or create new categorizer."""
    model = get_categorizer(user_id)
    if model is None:
        model = TransactionCategorizer()
        _model_cache[user_id] = model
    return model


def update_cache(user_id: int | None, model: TransactionCategorizer) -> None:
    """Update the in-memory cache after training."""
    _model_cache[user_id] = model
