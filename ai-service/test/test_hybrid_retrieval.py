"""Hybrid retrieval regression tests for knowledge search."""

import os

import psycopg
import pytest

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


def _embedding(primary: float, secondary: float = 0.0) -> list[float]:
    values = [0.0] * 1536
    values[0] = primary
    values[1] = secondary

    return values


def test_hybrid_search_prefers_reservation_chunk_over_discount_pricing(vector_store: VectorStore):
  """Regression: payment-timeout queries must rank reservation policy above pricing."""
  payment_query_embedding = _embedding(0.92, 0.08)
  discount_embedding = _embedding(0.98, 0.02)
  reservation_embedding = _embedding(0.82, 0.18)

  vector_store.replace_document_chunks(
      workspace_id=1,
      customer_id=1,
      deployment_id=1,
      document_id=101,
      source_filename="pricing-guide.pdf",
      chunks=[
          "Volume discount pricing applies when order totals exceed $500. "
          "Special pricing tiers are available for enterprise customers.",
      ],
      embeddings=[discount_embedding],
  )

  vector_store.replace_document_chunks(
      workspace_id=1,
      customer_id=1,
      deployment_id=1,
      document_id=102,
      source_filename="reservation-policy.pdf",
      chunks=[
          "Cart reservation lasts 15 minutes. "
          "If the user does not pay, the reservation is automatically released.",
      ],
      embeddings=[reservation_embedding],
  )

  lexical_terms = [
      "15",
      "15 minutes",
      "minutes",
      "reservation",
      "pay",
      "released",
      "automatically",
  ]

  results = vector_store.search(
      workspace_id=1,
      customer_id=1,
      deployment_id=1,
      query_embedding=payment_query_embedding,
      top_k=1,
      lexical_terms=lexical_terms,
  )

  assert results != []
  assert results[0]["source_filename"] == "reservation-policy.pdf"
  assert "15 minutes" in results[0]["content"]
  assert "automatically released" in results[0]["content"]


def test_pure_semantic_search_without_lexical_terms_keeps_vector_order(vector_store: VectorStore):
  payment_query_embedding = _embedding(0.92, 0.08)
  discount_embedding = _embedding(0.98, 0.02)
  reservation_embedding = _embedding(0.82, 0.18)

  vector_store.replace_document_chunks(
      workspace_id=1,
      customer_id=1,
      deployment_id=1,
      document_id=101,
      source_filename="pricing-guide.pdf",
      chunks=["Discount pricing for bulk orders."],
      embeddings=[discount_embedding],
  )

  vector_store.replace_document_chunks(
      workspace_id=1,
      customer_id=1,
      deployment_id=1,
      document_id=102,
      source_filename="reservation-policy.pdf",
      chunks=["Cart reservation lasts 15 minutes."],
      embeddings=[reservation_embedding],
  )

  results = vector_store.search(
      workspace_id=1,
      customer_id=1,
      deployment_id=1,
      query_embedding=payment_query_embedding,
      top_k=1,
  )

  assert results[0]["source_filename"] == "pricing-guide.pdf"
