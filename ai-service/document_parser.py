from __future__ import annotations

import base64
import io

from pypdf import PdfReader


def extract_text(filename: str, mime_type: str, content: bytes) -> str:
    extension = filename.rsplit(".", 1)[-1].lower() if "." in filename else ""

    if mime_type == "application/pdf" or extension == "pdf":
        return _extract_pdf(content)

    if mime_type in {"text/plain", "text/markdown", "text/x-markdown"} or extension in {"txt", "md"}:
        return content.decode("utf-8", errors="replace")

    raise ValueError("Unsupported document type.")


def extract_text_from_base64(filename: str, mime_type: str, content_base64: str) -> str:
    content = base64.b64decode(content_base64.encode("utf-8"))
    return extract_text(filename, mime_type, content)


def _extract_pdf(content: bytes) -> str:
    reader = PdfReader(io.BytesIO(content))
    pages: list[str] = []

    for page in reader.pages:
        page_text = page.extract_text() or ""
        if page_text.strip() != "":
            pages.append(page_text)

    return "\n\n".join(pages)
