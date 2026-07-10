"""FastAPI main application for Spendly ML Service."""

from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI

from app.api import health, v1
from app.core.auth import require_service_token
from app.core.database import reset_engine


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan handler."""
    yield
    await reset_engine()


app = FastAPI(
    title="Spendly ML Service",
    description="Machine learning microservice for Spendly personal finance tracker",
    version="2.0.0",
    lifespan=lifespan,
)

app.include_router(health.router, tags=["health"])
app.include_router(v1.health_router, prefix="/api/v1", tags=["v1"])
app.include_router(
    v1.router,
    prefix="/api/v1",
    tags=["v1"],
    dependencies=[Depends(require_service_token)],
)
