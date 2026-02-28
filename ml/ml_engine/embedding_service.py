"""Sentence-transformer wrapper with caching for multilingual embeddings."""

from __future__ import annotations

import hashlib
from functools import lru_cache
from typing import TYPE_CHECKING

import numpy as np

from .config import Config

if TYPE_CHECKING:
    from sentence_transformers import SentenceTransformer


class EmbeddingService:
    """Singleton wrapper around sentence-transformers with LRU cache."""

    _instance: EmbeddingService | None = None
    _model: SentenceTransformer | None = None

    def __new__(cls) -> EmbeddingService:
        if cls._instance is None:
            cls._instance = super().__new__(cls)
        return cls._instance

    @property
    def model(self) -> SentenceTransformer:
        """Lazy-load the embedding model on first use."""
        if self._model is None:
            from sentence_transformers import SentenceTransformer
            self._model = SentenceTransformer(
                Config.EMBEDDING_MODEL,
                backend="onnx",
            )
        return self._model

    @property
    def available(self) -> bool:
        """Check if embedding model can be loaded."""
        try:
            _ = self.model
            return True
        except Exception:
            return False

    def encode(self, texts: list[str], prefix: str = "query: ") -> np.ndarray:
        """Batch encode texts to embeddings.

        For E5 models, prepend 'query: ' for queries and 'passage: ' for corpus.
        """
        prefixed = [f"{prefix}{t}" for t in texts]
        return self.model.encode(prefixed, normalize_embeddings=True, show_progress_bar=False)

    def similarity(self, text: str, candidates: list[str]) -> list[float]:
        """Compute cosine similarity between text and candidate strings.

        Returns list of similarity scores in [0, 1].
        """
        if not candidates:
            return []
        query_emb = self.encode([text], prefix="query: ")
        candidate_embs = self.encode(candidates, prefix="passage: ")
        scores = (query_emb @ candidate_embs.T).flatten().tolist()
        return scores

    def batch_similarity(
        self, queries: list[str], corpus: list[str]
    ) -> np.ndarray:
        """Compute pairwise similarity matrix (queries x corpus).

        Returns ndarray of shape (len(queries), len(corpus)).
        """
        if not queries or not corpus:
            return np.array([])
        q_emb = self.encode(queries, prefix="query: ")
        c_emb = self.encode(corpus, prefix="passage: ")
        return q_emb @ c_emb.T
