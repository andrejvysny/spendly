"""Multi-layer merchant detection and discovery.

Layers (in priority order):
1. Preprocessing — clean description via preprocessor
2. Canonical Lookup — exact match against known aliases
3. RapidFuzz Multi-Scorer — token_set_ratio + partial_ratio
4. Embedding Similarity — sentence-transformer cosine similarity
5. New Merchant Discovery — HDBSCAN clustering (batch mode)
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

import numpy as np
import pandas as pd
from rapidfuzz import fuzz, process

from .config import Config
from .preprocessor import clean_description, normalize_merchant

logger = logging.getLogger(__name__)

# Canonical aliases: normalized pattern → canonical merchant name
CANONICAL_ALIASES: dict[str, str] = {
    "lidl dakuje": "Lidl",
    "lidl": "Lidl",
    "kaufland": "Kaufland",
    "tesco": "Tesco",
    "billa": "Billa",
    "coop jednota": "COOP Jednota",
    "albert": "Albert",
    "penny market": "Penny Market",
    "dm drogerie": "dm",
    "rossmann": "Rossmann",
    "ikea": "IKEA",
    "decathlon": "Decathlon",
    "shell": "Shell",
    "omv": "OMV",
    "slovnaft": "Slovnaft",
    "bolt": "Bolt",
    "uber": "Uber",
    "wolt": "Wolt",
    "bolt food": "Bolt Food",
    "spotify": "Spotify",
    "netflix": "Netflix",
    "apple": "Apple",
    "google": "Google",
    "amazon": "Amazon",
    "aliexpress": "AliExpress",
    "mcdonalds": "McDonald's",
    "mcdonald": "McDonald's",
    "starbucks": "Starbucks",
    "kfc": "KFC",
    "super zoo": "Super ZOO",
    "action": "Action",
    "pepco": "Pepco",
    "reserved": "Reserved",
    "hm": "H&M",
    "h m": "H&M",
    "zara": "Zara",
}


@dataclass
class MerchantPrediction:
    transaction_id: int
    predicted_merchant_id: int | None
    suggested_merchant_name: str | None
    confidence: float
    method: str


class MerchantDetector:
    """Multi-layer merchant detection with fuzzy matching and embeddings."""

    def __init__(self, user_id: int, merchants: list[dict]):
        self.user_id = user_id
        self.merchants = merchants  # [{"id": ..., "name": ...}]
        self._merchant_names = {m["name"]: m["id"] for m in merchants}
        self._normalized_map: dict[str, int] = {
            normalize_merchant(m["name"]): m["id"] for m in merchants
        }

    def detect(
        self,
        df: pd.DataFrame,
        embedding_service: Any = None,
    ) -> list[MerchantPrediction]:
        """Run multi-layer merchant detection on transactions."""
        predictions: list[MerchantPrediction] = []

        for _, row in df.iterrows():
            if pd.notna(row.get("merchant_id")):
                continue

            pred = self._detect_single(row, embedding_service)
            if pred is not None:
                predictions.append(pred)

        return predictions

    def _detect_single(
        self, row: pd.Series, embedding_service: Any
    ) -> MerchantPrediction | None:
        tx_id = int(row["id"])
        desc = str(row.get("description", "") or "")
        partner = str(row.get("partner", "") or "")

        # Prefer partner field for merchant name, fall back to description
        raw_name = partner if partner.strip() else desc
        cleaned = clean_description(raw_name)
        normalized = normalize_merchant(raw_name)

        if not normalized:
            return None

        # Layer 2: Canonical lookup
        canonical = CANONICAL_ALIASES.get(normalized)
        if canonical and canonical in self._merchant_names:
            return MerchantPrediction(
                tx_id, self._merchant_names[canonical], canonical, 0.95, "canonical"
            )

        # Layer 2b: Exact normalized match
        if normalized in self._normalized_map:
            mid = self._normalized_map[normalized]
            name = next(m["name"] for m in self.merchants if m["id"] == mid)
            return MerchantPrediction(tx_id, mid, name, 0.95, "canonical")

        # Layer 3: RapidFuzz multi-scorer
        if self._merchant_names:
            pred = self._match_rapidfuzz(tx_id, cleaned)
            if pred is not None:
                return pred

        # Layer 4: Embedding similarity
        if embedding_service is not None and self.merchants:
            pred = self._match_embedding(tx_id, cleaned, embedding_service)
            if pred is not None:
                return pred

        # No match — suggest cleaned name as new merchant
        suggested = canonical or cleaned.title() if cleaned else None
        if suggested:
            return MerchantPrediction(tx_id, None, suggested, 0.30, "unmatched")

        return None

    def _match_rapidfuzz(self, tx_id: int, cleaned: str) -> MerchantPrediction | None:
        names = list(self._merchant_names.keys())

        # Try token_set_ratio first (handles word reordering)
        result = process.extractOne(cleaned, names, scorer=fuzz.token_set_ratio)
        if result is not None:
            match_name, score, _ = result
            if score >= Config.THRESHOLDS.merchant_rapidfuzz_auto:
                return MerchantPrediction(
                    tx_id, self._merchant_names[match_name], match_name, score / 100.0, "rapidfuzz"
                )

        # Fallback: partial_ratio (handles substring matches)
        result = process.extractOne(cleaned, names, scorer=fuzz.partial_ratio)
        if result is not None:
            match_name, score, _ = result
            if score >= Config.THRESHOLDS.merchant_rapidfuzz_fallback:
                return MerchantPrediction(
                    tx_id, self._merchant_names[match_name], match_name, score / 100.0, "rapidfuzz"
                )

        return None

    def _match_embedding(
        self, tx_id: int, cleaned: str, embedding_service: Any
    ) -> MerchantPrediction | None:
        merchant_names = [m["name"] for m in self.merchants]
        scores = embedding_service.similarity(cleaned, merchant_names)
        if not scores:
            return None

        best_idx = int(np.argmax(scores))
        best_score = float(scores[best_idx])

        if best_score >= Config.THRESHOLDS.merchant_embedding_auto:
            m = self.merchants[best_idx]
            return MerchantPrediction(tx_id, m["id"], m["name"], best_score, "embedding")

        if best_score >= Config.THRESHOLDS.merchant_embedding_suggest:
            m = self.merchants[best_idx]
            return MerchantPrediction(tx_id, m["id"], m["name"], best_score, "embedding")

        return None

    @staticmethod
    def discover_merchants(
        df: pd.DataFrame,
        embedding_service: Any,
        min_cluster_size: int = 3,
    ) -> list[dict]:
        """Run HDBSCAN clustering on unmatched transactions to discover new merchants.

        Returns list of cluster suggestions:
        [{"cluster_id": int, "suggested_name": str, "transaction_ids": [...], "confidence": float}]
        """
        if embedding_service is None:
            return []

        # Filter to transactions without merchant
        unmatched = df[df["merchant_id"].isna()].copy()
        if len(unmatched) < min_cluster_size:
            return []

        # Build text features
        texts = []
        for _, row in unmatched.iterrows():
            partner = str(row.get("partner", "") or "")
            desc = str(row.get("description", "") or "")
            raw = partner if partner.strip() else desc
            texts.append(clean_description(raw))

        # Filter empty texts
        valid_mask = [bool(t.strip()) for t in texts]
        valid_texts = [t for t, v in zip(texts, valid_mask) if v]
        valid_indices = unmatched.index[valid_mask].tolist()

        if len(valid_texts) < min_cluster_size:
            return []

        # Encode and cluster
        embeddings = embedding_service.encode(valid_texts, prefix="passage: ")

        try:
            from sklearn.cluster import HDBSCAN
            clusterer = HDBSCAN(min_cluster_size=min_cluster_size, metric="cosine")
            labels = clusterer.fit_predict(embeddings)
        except Exception:
            logger.warning("HDBSCAN clustering failed")
            return []

        # Build cluster suggestions
        suggestions: list[dict] = []
        unique_labels = set(labels)
        unique_labels.discard(-1)  # noise

        for label in sorted(unique_labels):
            cluster_mask = labels == label
            cluster_texts = [t for t, m in zip(valid_texts, cluster_mask) if m]
            cluster_indices = [idx for idx, m in zip(valid_indices, cluster_mask) if m]
            cluster_tx_ids = unmatched.loc[cluster_indices, "id"].tolist()

            # Shortest cleaned text = suggested name
            suggested_name = min(cluster_texts, key=len).title()

            suggestions.append({
                "cluster_id": int(label),
                "suggested_name": suggested_name,
                "transaction_ids": cluster_tx_ids,
                "confidence": round(len(cluster_tx_ids) / len(valid_texts), 2),
                "count": len(cluster_tx_ids),
            })

        logger.info("Discovered %d merchant clusters from %d unmatched transactions", len(suggestions), len(valid_texts))
        return suggestions
