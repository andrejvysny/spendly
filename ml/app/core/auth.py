"""Shared-secret authentication for Laravel-to-ML service calls."""

from __future__ import annotations

import secrets

from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.core.config import settings

_bearer = HTTPBearer(auto_error=False)


async def require_service_token(
    credentials: HTTPAuthorizationCredentials | None = Depends(_bearer),
) -> None:
    """Reject requests without a valid bearer token.

    Fails closed: when ML_SERVICE_TOKEN is unset the service boots (so
    health checks keep working) but every protected endpoint returns 503.
    """
    expected = settings.ML_SERVICE_TOKEN
    if not expected:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="ML_SERVICE_TOKEN is not configured",
        )
    provided = credentials.credentials if credentials else ""
    if not secrets.compare_digest(provided.encode(), expected.encode()):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing service token",
        )
