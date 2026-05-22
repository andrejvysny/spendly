"""Application configuration."""

from __future__ import annotations

from typing import List

from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Application settings loaded from environment."""

    DATABASE_URL: str = "sqlite:///database/database.sqlite"

    CORS_ORIGINS: List[str] = ["*"]

    ML_TIMEOUT: int = 300

    LOG_LEVEL: str = "INFO"

    # Filesystem paths for ML data
    ML_DATA_DIR: str = "data"

    class Config:
        env_file = ".env"
        case_sensitive = True


settings = Settings()
