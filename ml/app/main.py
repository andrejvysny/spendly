"""FastAPI main application for Spendly ML Service."""

from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.api import (
    categorization,
    health,
    merchants,
    personalization,
    recurring,
    transfers,
    v1,
)
from app.core.config import settings
from app.core.database import engine


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan handler."""
    yield
    await engine.dispose()


app = FastAPI(
    title="Spendly ML Service",
    description="Machine learning microservice for Spendly personal finance tracker",
    version="2.0.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.CORS_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(health.router, tags=["health"])
app.include_router(merchants.router, prefix="/merchants", tags=["merchants"])
app.include_router(
    categorization.router, prefix="/categorization", tags=["categorization"]
)
app.include_router(
    personalization.router, prefix="/personalization", tags=["personalization"]
)
app.include_router(recurring.router, prefix="/recurring", tags=["recurring"])
app.include_router(transfers.router, prefix="/transfers", tags=["transfers"])
app.include_router(v1.router, prefix="/api/v1", tags=["v1"])
