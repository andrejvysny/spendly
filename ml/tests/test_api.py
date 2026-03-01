"""FastAPI endpoint integration tests."""

from __future__ import annotations

from unittest.mock import MagicMock, patch

import pytest
from fastapi.testclient import TestClient


@pytest.fixture
def client():
    from api import app
    return TestClient(app)


class TestHealthEndpoint:
    def test_health_returns_ok(self, client):
        response = client.get("/api/v1/health")
        assert response.status_code == 200
        data = response.json()
        assert data["status"] == "ok"
        assert "version" in data
        assert "embedding_model" in data


class TestCategorizeEndpoint:
    @patch("api._get_loader")
    @patch("api._get_embedding_service")
    def test_categorize_empty_transactions(self, mock_emb, mock_loader, client):
        import pandas as pd
        loader = MagicMock()
        loader.load_transactions.return_value = pd.DataFrame()
        mock_loader.return_value = loader
        mock_emb.return_value = None

        response = client.post("/api/v1/categorize", json={"user_id": 1})
        assert response.status_code == 200
        assert response.json() == []


class TestDetectMerchantsEndpoint:
    @patch("api._get_loader")
    @patch("api._get_embedding_service")
    def test_detect_merchants_empty(self, mock_emb, mock_loader, client):
        import pandas as pd
        loader = MagicMock()
        loader.load_transactions.return_value = pd.DataFrame()
        mock_loader.return_value = loader
        mock_emb.return_value = None

        response = client.post("/api/v1/detect-merchants", json={"user_id": 1})
        assert response.status_code == 200
        assert response.json() == []


class TestDetectRecurringEndpoint:
    @patch("api._get_loader")
    def test_detect_recurring_empty(self, mock_loader, client):
        import pandas as pd
        loader = MagicMock()
        loader.load_transactions.return_value = pd.DataFrame()
        loader.load_recurring_groups.return_value = pd.DataFrame()
        mock_loader.return_value = loader

        response = client.post("/api/v1/detect-recurring", json={"user_id": 1})
        assert response.status_code == 200
        assert response.json() == []


class TestDetectTransfersEndpoint:
    @patch("api._get_loader")
    def test_detect_transfers_empty(self, mock_loader, client):
        import pandas as pd
        loader = MagicMock()
        loader.load_transfer_candidates.return_value = pd.DataFrame()
        mock_loader.return_value = loader

        response = client.post("/api/v1/detect-transfers", json={"user_id": 1})
        assert response.status_code == 200
        assert response.json() == []

    @patch("api._get_loader")
    def test_detect_transfers_returns_predictions(self, mock_loader, client, transfer_candidates, all_user_transactions):
        loader = MagicMock()
        loader.load_transfer_candidates.return_value = transfer_candidates
        loader.load_transactions.return_value = all_user_transactions
        mock_loader.return_value = loader

        response = client.post("/api/v1/detect-transfers", json={"user_id": 1, "limit": 100})
        assert response.status_code == 200
        data = response.json()
        assert len(data) > 0
        assert all("transaction_id" in p for p in data)
        assert all("confidence" in p for p in data)
        assert all("method" in p for p in data)


class TestTrainEndpoint:
    @patch("api._get_loader")
    def test_train_no_transactions(self, mock_loader, client):
        import pandas as pd
        loader = MagicMock()
        loader.load_labeled_transactions.return_value = pd.DataFrame()
        loader.load_categories.return_value = []
        mock_loader.return_value = loader

        response = client.post("/api/v1/train/categorizer", json={"user_id": 1})
        assert response.status_code == 400

    @patch("api._get_loader")
    def test_train_transfer_detector_no_transactions(self, mock_loader, client):
        import pandas as pd
        loader = MagicMock()
        loader.load_labeled_transactions.return_value = pd.DataFrame()
        mock_loader.return_value = loader

        response = client.post("/api/v1/train/transfer-detector", json={"user_id": 1})
        assert response.status_code == 400
