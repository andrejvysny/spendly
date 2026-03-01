"""ML Engine configuration."""

import os
from dataclasses import dataclass, field
from pathlib import Path
from dotenv import load_dotenv

# Load .env from project root
_PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
load_dotenv(_PROJECT_ROOT / ".env")


@dataclass(frozen=True)
class Thresholds:
    """Per-task confidence thresholds."""
    categorizer_auto: float = 0.70
    categorizer_mcc: float = 0.90
    categorizer_merchant_match: float = 0.85
    merchant_rapidfuzz_auto: int = 92
    merchant_rapidfuzz_fallback: int = 85
    merchant_embedding_auto: float = 0.85
    merchant_embedding_suggest: float = 0.70
    recurring_min_transactions: int = 3
    recurring_min_confidence: float = 0.60
    duplicate_window_days: int = 3
    min_training_samples: int = 20
    min_embedding_samples: int = 3
    transfer_regex_high: float = 0.85
    transfer_regex_low: float = 0.65
    transfer_ml_auto: float = 0.70
    transfer_cross_account_window_days: int = 3
    transfer_amount_tolerance: float = 0.01
    transfer_min_training_samples: int = 10


@dataclass(frozen=True)
class ApiSettings:
    """FastAPI settings."""
    host: str = "0.0.0.0"
    port: int = 8001
    cors_origins: list[str] = field(default_factory=lambda: ["http://localhost:8000", "http://localhost:5173"])


class Config:
    PROJECT_ROOT = _PROJECT_ROOT

    # Database
    DB_DATABASE = os.getenv("DB_DATABASE", str(_PROJECT_ROOT / "database" / "database.sqlite"))
    if not os.path.isabs(DB_DATABASE):
        DB_DATABASE = str(_PROJECT_ROOT / DB_DATABASE)
    SQLALCHEMY_DATABASE_URI = f"sqlite:///{DB_DATABASE}?mode=ro&immutable=1"

    # Model storage (per-user)
    MODEL_DIR = Path(__file__).resolve().parent.parent / "models"

    # Embedding model
    EMBEDDING_MODEL = os.getenv("ML_EMBEDDING_MODEL", "intfloat/multilingual-e5-small")

    # API
    ML_ENABLED = os.getenv("ML_ENABLED", "false").lower() == "true"
    ML_API_URL = os.getenv("ML_API_URL", "http://localhost:8001")

    # Thresholds
    THRESHOLDS = Thresholds()
    API = ApiSettings()

    @classmethod
    def model_path(cls, user_id: int, task: str) -> Path:
        """Get model artifact path for a user+task."""
        path = cls.MODEL_DIR / f"user_{user_id}"
        path.mkdir(parents=True, exist_ok=True)
        return path / f"{task}.joblib"

    @classmethod
    def ensure_dirs(cls) -> None:
        cls.MODEL_DIR.mkdir(parents=True, exist_ok=True)


Config.ensure_dirs()
