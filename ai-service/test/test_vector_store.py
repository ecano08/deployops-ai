import os

import psycopg
import pytest

from auth import INTERNAL_TOKEN_HEADER
from embeddings import EmbeddingClient
from vector_store import VectorStore


def database_available() -> bool:
    database_url = os.getenv("DATABASE_URL")

    if database_url is None:
        return False

    try:
        with psycopg.connect(database_url, autocommit=True):
            return True
    except Exception:
        return False


pytestmark = pytest.mark.skipif(not database_available(), reason="PostgreSQL is not available")


@pytest.fixture()
def vector_store():
    store = VectorStore()
    store.initialize()

    with psycopg.connect(os.environ["DATABASE_URL"], autocommit=True) as connection:
        with connection.cursor() as cursor:
            cursor.execute("DELETE FROM knowledge_chunks")

    yield store

    with psycopg.connect(os.environ["DATABASE_URL"], autocommit=True) as connection:
        with connection.cursor() as cursor:
            cursor.execute("DELETE FROM knowledge_chunks")


def test_vector_store_blocks_cross_tenant_retrieval(vector_store: VectorStore, monkeypatch: pytest.MonkeyPatch):
    embedding = [0.0] * 1536
    embedding[0] = 1.0

    monkeypatch.setattr(EmbeddingClient, "embed_query", lambda self, query: embedding)

    vector_store.replace_document_chunks(
        workspace_id=1,
        customer_id=1,
        deployment_id=1,
        document_id=10,
        source_filename="tenant-a.txt",
        chunks=["Tenant A rollback steps."],
        embeddings=[embedding],
    )

    vector_store.replace_document_chunks(
        workspace_id=2,
        customer_id=2,
        deployment_id=2,
        document_id=20,
        source_filename="tenant-b.txt",
        chunks=["Tenant B rollback steps."],
        embeddings=[embedding],
    )

    tenant_a_results = vector_store.search(
        workspace_id=1,
        customer_id=1,
        deployment_id=1,
        query_embedding=embedding,
        top_k=5,
    )
    tenant_b_results = vector_store.search(
        workspace_id=2,
        customer_id=2,
        deployment_id=2,
        query_embedding=embedding,
        top_k=5,
    )

    assert [result["source_filename"] for result in tenant_a_results] == ["tenant-a.txt"]
    assert [result["source_filename"] for result in tenant_b_results] == ["tenant-b.txt"]


def test_internal_token_header_name_is_stable():
    assert INTERNAL_TOKEN_HEADER == "X-AI-Service-Token"
