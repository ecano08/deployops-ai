from __future__ import annotations

import json
import re
from contextlib import contextmanager
from typing import Any, Iterator

import psycopg
from psycopg.rows import dict_row

from config import settings


class VectorStore:
    def __init__(self, connection: psycopg.Connection | None = None) -> None:
        self._connection = connection

    def initialize(self) -> None:
        with self._connection_context() as connection:
            with connection.cursor() as cursor:
                cursor.execute("CREATE EXTENSION IF NOT EXISTS vector")
                cursor.execute(
                    f"""
                    CREATE TABLE IF NOT EXISTS knowledge_chunks (
                        id BIGSERIAL PRIMARY KEY,
                        workspace_id BIGINT NOT NULL,
                        customer_id BIGINT NOT NULL,
                        deployment_id BIGINT NOT NULL,
                        document_id BIGINT NOT NULL,
                        chunk_index INT NOT NULL,
                        source_filename TEXT NOT NULL,
                        content TEXT NOT NULL,
                        embedding vector({settings.embedding_dimensions}) NOT NULL,
                        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                    )
                    """
                )
                cursor.execute(
                    """
                    CREATE INDEX IF NOT EXISTS knowledge_chunks_scope_idx
                    ON knowledge_chunks (workspace_id, customer_id, deployment_id)
                    """
                )
                cursor.execute(
                    """
                    CREATE INDEX IF NOT EXISTS knowledge_chunks_document_idx
                    ON knowledge_chunks (workspace_id, customer_id, deployment_id, document_id)
                    """
                )

    def replace_document_chunks(
        self,
        *,
        workspace_id: int,
        customer_id: int,
        deployment_id: int,
        document_id: int,
        source_filename: str,
        chunks: list[str],
        embeddings: list[list[float]],
    ) -> int:
        if len(chunks) != len(embeddings):
            raise ValueError("Chunk and embedding counts must match.")

        with self._connection_context() as connection:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    DELETE FROM knowledge_chunks
                    WHERE workspace_id = %s
                      AND customer_id = %s
                      AND deployment_id = %s
                      AND document_id = %s
                    """,
                    (workspace_id, customer_id, deployment_id, document_id),
                )

                for index, (chunk, embedding) in enumerate(zip(chunks, embeddings, strict=True)):
                    cursor.execute(
                        """
                        INSERT INTO knowledge_chunks (
                            workspace_id,
                            customer_id,
                            deployment_id,
                            document_id,
                            chunk_index,
                            source_filename,
                            content,
                            embedding
                        ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s::vector)
                        """,
                        (
                            workspace_id,
                            customer_id,
                            deployment_id,
                            document_id,
                            index,
                            source_filename,
                            chunk,
                            self._format_vector(embedding),
                        ),
                    )

        return len(chunks)

    def delete_document(
        self,
        *,
        workspace_id: int,
        customer_id: int,
        deployment_id: int,
        document_id: int,
    ) -> None:
        with self._connection_context() as connection:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    DELETE FROM knowledge_chunks
                    WHERE workspace_id = %s
                      AND customer_id = %s
                      AND deployment_id = %s
                      AND document_id = %s
                    """,
                    (workspace_id, customer_id, deployment_id, document_id),
                )

    def search(
        self,
        *,
        workspace_id: int,
        customer_id: int,
        deployment_id: int,
        query_embedding: list[float],
        top_k: int,
        document_ids: list[int] | None = None,
        lexical_terms: list[str] | None = None,
    ) -> list[dict[str, Any]]:
        if document_ids is not None and document_ids == []:
            return []

        normalized_terms = self._normalize_lexical_terms(lexical_terms)
        candidate_k = top_k

        if normalized_terms != []:
            candidate_k = min(
                max(top_k, top_k * settings.hybrid_candidate_multiplier),
                settings.max_top_k * 2,
            )

        with self._connection_context() as connection:
            with connection.cursor(row_factory=dict_row) as cursor:
                document_filter = ""
                params: list[Any] = [
                    self._format_vector(query_embedding),
                    workspace_id,
                    customer_id,
                    deployment_id,
                ]

                if document_ids is not None:
                    document_filter = "AND document_id = ANY(%s)"
                    params.append(document_ids)

                params.extend([
                    self._format_vector(query_embedding),
                    candidate_k,
                ])

                cursor.execute(
                    f"""
                    SELECT
                        document_id,
                        source_filename,
                        chunk_index,
                        content,
                        1 - (embedding <=> %s::vector) AS score
                    FROM knowledge_chunks
                    WHERE workspace_id = %s
                      AND customer_id = %s
                      AND deployment_id = %s
                      {document_filter}
                    ORDER BY embedding <=> %s::vector
                    LIMIT %s
                    """,
                    params,
                )

                rows = cursor.fetchall()

        results = [
            {
                "document_id": int(row["document_id"]),
                "source_filename": str(row["source_filename"]),
                "chunk_index": int(row["chunk_index"]),
                "content": str(row["content"]),
                "score": float(row["score"]),
            }
            for row in rows
        ]

        if normalized_terms == []:
            return results

        return self._rerank_with_lexical(results, normalized_terms, top_k)

    @contextmanager
    def _connection_context(self) -> Iterator[psycopg.Connection]:
        if self._connection is not None:
            yield self._connection
            return

        with psycopg.connect(settings.database_url, autocommit=True) as connection:
            yield connection

    @staticmethod
    def _format_vector(values: list[float]) -> str:
        return json.dumps(values)

    @staticmethod
    def _normalize_lexical_terms(lexical_terms: list[str] | None) -> list[str]:
        if lexical_terms is None:
            return []

        normalized: list[str] = []

        for term in lexical_terms:
            cleaned = term.strip()

            if cleaned == "":
                continue

            normalized.append(cleaned)

        return list(dict.fromkeys(normalized))

    def _rerank_with_lexical(
        self,
        results: list[dict[str, Any]],
        lexical_terms: list[str],
        top_k: int,
    ) -> list[dict[str, Any]]:
        reranked: list[dict[str, Any]] = []

        for result in results:
            lexical_score = self._lexical_score(str(result["content"]), lexical_terms)
            semantic_score = float(result["score"])
            combined_score = (
                settings.hybrid_semantic_weight * semantic_score
                + settings.hybrid_lexical_weight * lexical_score
            )

            reranked.append({
                **result,
                "score": combined_score,
            })

        reranked.sort(key=lambda item: item["score"], reverse=True)

        return reranked[:top_k]

    def _lexical_score(self, content: str, lexical_terms: list[str]) -> float:
        if lexical_terms == []:
            return 0.0

        content_lower = content.lower()
        matched = 0.0

        for term in lexical_terms:
            term_lower = term.lower()

            if term_lower in content_lower:
                matched += 1.0
                continue

            if re.search(r"\d", term) and re.search(rf"\b{re.escape(term)}\b", content_lower):
                matched += 1.0

        return matched / len(lexical_terms)
