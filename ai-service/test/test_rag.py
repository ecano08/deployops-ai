from pathlib import Path
from unittest.mock import MagicMock, patch

from fastapi.testclient import TestClient

from auth import INTERNAL_TOKEN_HEADER
from main import app

VALID_TOKEN = "test-ai-service-token"
client = TestClient(app)


def auth_headers(token: str | None = VALID_TOKEN) -> dict[str, str]:
    if token is None:
        return {}

    return {INTERNAL_TOKEN_HEADER: token}


def test_health_remains_public_without_token():
    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "service": "ai-service",
    }


def test_rag_endpoints_require_internal_token():
    payload = {
        "workspace_id": 1,
        "customer_id": 2,
        "deployment_id": 3,
        "query": "rollback",
    }

    missing = client.post("/search", json=payload)
    invalid = client.post("/search", json=payload, headers=auth_headers("wrong-token"))

    assert missing.status_code == 401
    assert invalid.status_code == 401


def test_chunker_splits_long_text():
    from chunker import chunk_text

    text = "word " * 500
    chunks = chunk_text(text)

    assert len(chunks) >= 2
    assert all(chunk.strip() != "" for chunk in chunks)


def test_document_parser_extracts_plain_text():
    from document_parser import extract_text

    content = b"Rollback steps:\n1. Drain traffic."

    assert extract_text("runbook.txt", "text/plain", content) == "Rollback steps:\n1. Drain traffic."


@patch("routes.embedding_client")
@patch("routes.vector_store")
def test_process_document_stores_chunks(mock_vector_store: MagicMock, mock_embedding_client: MagicMock):
    mock_embedding_client.embed_texts.return_value = [[0.1] * 1536, [0.2] * 1536]
    mock_vector_store.replace_document_chunks.return_value = 2

    response = client.post(
        "/documents/process",
        headers=auth_headers(),
        json={
            "workspace_id": 1,
            "customer_id": 2,
            "deployment_id": 3,
            "document_id": 4,
            "filename": "runbook.txt",
            "mime_type": "text/plain",
            "content_base64": "Um9sbGJhY2sgc3RlcHM6Ci0gU3RvcCB0cmFmZmljLg==",
        },
    )

    assert response.status_code == 200
    assert response.json() == {"chunk_count": 2}
    mock_vector_store.replace_document_chunks.assert_called_once()


@patch("routes.embedding_client")
@patch("routes.vector_store")
def test_search_returns_scoped_results(mock_vector_store: MagicMock, mock_embedding_client: MagicMock):
    mock_embedding_client.embed_query.return_value = [0.3] * 1536
    mock_vector_store.search.return_value = [
        {
            "document_id": 4,
            "source_filename": "runbook.txt",
            "chunk_index": 0,
            "content": "Rollback steps: stop traffic.",
            "score": 0.91,
        }
    ]

    response = client.post(
        "/search",
        headers=auth_headers(),
        json={
            "workspace_id": 1,
            "customer_id": 2,
            "deployment_id": 3,
            "query": "rollback",
            "top_k": 3,
        },
    )

    assert response.status_code == 200
    payload = response.json()
    assert payload["results"][0]["source_filename"] == "runbook.txt"
    mock_vector_store.search.assert_called_once_with(
        workspace_id=1,
        customer_id=2,
        deployment_id=3,
        query_embedding=[0.3] * 1536,
        top_k=3,
        document_ids=None,
        lexical_terms=[],
    )


@patch("routes.vector_store")
def test_delete_document_vectors(mock_vector_store: MagicMock):
    response = client.post(
        "/documents/delete",
        headers=auth_headers(),
        json={
            "workspace_id": 1,
            "customer_id": 2,
            "deployment_id": 3,
            "document_id": 4,
        },
    )

    assert response.status_code == 200
    assert response.json() == {"status": "deleted"}
    mock_vector_store.delete_document.assert_called_once()


@patch("routes.vector_store")
def test_list_document_chunks_returns_scoped_chunks(mock_vector_store: MagicMock):
    mock_vector_store.list_document_chunks.return_value = [
        {
            "chunk_index": 0,
            "source_filename": "architecture.pdf",
            "content": "The backend framework is Laravel 13.",
        }
    ]

    response = client.post(
        "/documents/chunks",
        headers=auth_headers(),
        json={
            "workspace_id": 1,
            "customer_id": 2,
            "deployment_id": 3,
            "document_id": 4,
        },
    )

    assert response.status_code == 200
    assert response.json() == {
        "chunks": [
            {
                "chunk_index": 0,
                "source_filename": "architecture.pdf",
                "content": "The backend framework is Laravel 13.",
            }
        ]
    }
    mock_vector_store.list_document_chunks.assert_called_once_with(
        workspace_id=1,
        customer_id=2,
        deployment_id=3,
        document_id=4,
    )


@patch("routes.vector_store")
def test_list_document_chunks_rejects_missing_token(mock_vector_store: MagicMock):
    response = client.post(
        "/documents/chunks",
        json={
            "workspace_id": 1,
            "customer_id": 2,
            "deployment_id": 3,
            "document_id": 4,
        },
    )

    assert response.status_code == 401
    mock_vector_store.list_document_chunks.assert_not_called()


def test_config_loads_openai_api_key_from_root_env():
    from config import ROOT_ENV_PATH, SERVICE_ENV_PATH, settings

    assert ROOT_ENV_PATH == Path(__file__).resolve().parent.parent.parent / ".env"
    assert SERVICE_ENV_PATH == Path(__file__).resolve().parent.parent / ".env"
    assert settings.openai_api_key is None or isinstance(settings.openai_api_key, str)


def test_process_document_rejects_missing_token():
    response = client.post(
        "/documents/process",
        json={
            "workspace_id": 1,
            "customer_id": 2,
            "deployment_id": 3,
            "document_id": 4,
            "filename": "runbook.txt",
            "mime_type": "text/plain",
            "content_base64": "dGVzdA==",
        },
    )

    assert response.status_code == 401


def test_delete_document_rejects_invalid_token():
    response = client.post(
        "/documents/delete",
        headers=auth_headers("invalid-token"),
        json={
            "workspace_id": 1,
            "customer_id": 2,
            "deployment_id": 3,
            "document_id": 4,
        },
    )

    assert response.status_code == 401
