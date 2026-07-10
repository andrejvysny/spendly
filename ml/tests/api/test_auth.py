"""Bearer-token auth behavior."""

from __future__ import annotations

import httpx

from app.core.config import settings
from tests.conftest import AUTH


async def test_health_endpoints_are_public(client: httpx.AsyncClient) -> None:
    for path in ("/", "/ready", "/api/v1/health"):
        response = await client.get(path)
        assert response.status_code == 200, path


async def test_missing_token_is_401(client: httpx.AsyncClient) -> None:
    response = await client.post("/api/v1/categorize", json={"user_id": 1})
    assert response.status_code == 401


async def test_wrong_token_is_401(client: httpx.AsyncClient) -> None:
    response = await client.post(
        "/api/v1/categorize",
        json={"user_id": 1},
        headers={"Authorization": "Bearer nope"},
    )
    assert response.status_code == 401


async def test_valid_token_passes(client: httpx.AsyncClient, seed) -> None:
    seed.user(1)
    response = await client.post("/api/v1/categorize", json={"user_id": 1}, headers=AUTH)
    assert response.status_code == 200


async def test_unconfigured_token_fails_closed_with_503(
    client: httpx.AsyncClient, monkeypatch
) -> None:
    monkeypatch.setattr(settings, "ML_SERVICE_TOKEN", "")
    response = await client.post("/api/v1/categorize", json={"user_id": 1}, headers=AUTH)
    assert response.status_code == 503
