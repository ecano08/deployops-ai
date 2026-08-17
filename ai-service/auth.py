from __future__ import annotations

import secrets
from pathlib import Path

from fastapi import HTTPException, Security
from fastapi.security import APIKeyHeader

from config import settings

INTERNAL_TOKEN_HEADER = "X-AI-Service-Token"
header_scheme = APIKeyHeader(name=INTERNAL_TOKEN_HEADER, auto_error=False)


def verify_internal_token(token: str | None = Security(header_scheme)) -> None:
    expected = settings.ai_service_token

    if expected is None or expected == "":
        raise HTTPException(status_code=500, detail="AI service token is not configured.")

    if token is None or not secrets.compare_digest(token, expected):
        raise HTTPException(status_code=401, detail="Unauthorized.")
