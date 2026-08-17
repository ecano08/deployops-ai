from __future__ import annotations

from openai import OpenAI

from config import settings


class EmbeddingClient:
    def __init__(self, client: OpenAI | None = None) -> None:
        self._client = client

    def embed_texts(self, texts: list[str]) -> list[list[float]]:
        if texts == []:
            return []

        client = self._client or OpenAI(api_key=settings.openai_api_key)
        response = client.embeddings.create(
            model=settings.openai_embedding_model,
            input=texts,
        )

        return [list(item.embedding) for item in response.data]

    def embed_query(self, query: str) -> list[float]:
        embeddings = self.embed_texts([query])

        if embeddings == []:
            raise RuntimeError("Failed to generate query embedding.")

        return embeddings[0]
