from __future__ import annotations

import json
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
    ) -> list[dict[str, Any]]:
        with self._connection_context() as connection:
            with connection.cursor(row_factory=dict_row) as cursor:
                cursor.execute(
                    """
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
                    ORDER BY embedding <=> %s::vector
                    LIMIT %s
                    """,
                    (
                        self._format_vector(query_embedding),
                        workspace_id,
                        customer_id,
                        deployment_id,
                        self._format_vector(query_embedding),
                        top_k,
                    ),
                )

                rows = cursor.fetchall()

        return [
            {
                "document_id": int(row["document_id"]),
                "source_filename": str(row["source_filename"]),
                "chunk_index": int(row["chunk_index"]),
                "content": str(row["content"]),
                "score": float(row["score"]),
            }
            for row in rows
        ]

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
