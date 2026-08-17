from __future__ import annotations

import re
from typing import Iterable

from config import settings


def normalize_text(text: str) -> str:
    normalized = text.replace("\r\n", "\n").replace("\r", "\n")
    normalized = re.sub(r"\n{3,}", "\n\n", normalized)
    normalized = re.sub(r"[ \t]+", " ", normalized)

    return normalized.strip()


def chunk_text(text: str) -> list[str]:
    normalized = normalize_text(text)

    if normalized == "":
        return []

    chunks: list[str] = []
    start = 0
    length = len(normalized)

    while start < length:
        end = min(start + settings.chunk_size, length)
        chunk = normalized[start:end].strip()

        if chunk != "":
            chunks.append(chunk)

        if end >= length:
            break

        start = max(end - settings.chunk_overlap, start + 1)

    return chunks


def merge_chunk_batches(chunks: Iterable[str]) -> list[str]:
    return [chunk for chunk in chunks if chunk.strip() != ""]
