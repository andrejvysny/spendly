"""Application configuration."""

from __future__ import annotations

from pathlib import Path

from pydantic_settings import BaseSettings

_PACKAGE_ROOT = Path(__file__).resolve().parent.parent.parent


class Settings(BaseSettings):
    """Application settings loaded from environment."""

    DATABASE_URL: str = "sqlite:///database/database.sqlite"

    # Shared secret required by all non-health endpoints. Empty = fail closed (503).
    ML_SERVICE_TOKEN: str = ""

    LOG_LEVEL: str = "INFO"

    # Filesystem root for model artifacts and personalization vectors
    ML_DATA_DIR: str = str(_PACKAGE_ROOT / "data")

    class Config:
        env_file = ".env"
        case_sensitive = True

    @property
    def models_dir(self) -> Path:
        return Path(self.ML_DATA_DIR) / "models"

    @property
    def vectors_dir(self) -> Path:
        return Path(self.ML_DATA_DIR) / "vectors"


settings = Settings()
