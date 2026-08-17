from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

ROOT_ENV_PATH = Path(__file__).resolve().parent.parent / ".env"
SERVICE_ENV_PATH = Path(__file__).resolve().parent / ".env"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=(
            ROOT_ENV_PATH,
            SERVICE_ENV_PATH,
        ),
        env_file_encoding="utf-8",
        extra="ignore",
    )

    database_url: str = "postgresql://deployops:deployops@127.0.0.1:5432/deployops"
    ai_service_token: str | None = None
    openai_api_key: str | None = None
    openai_embedding_model: str = "text-embedding-3-small"
    embedding_dimensions: int = 1536
    chunk_size: int = 1000
    chunk_overlap: int = 200
    default_top_k: int = 5
    max_top_k: int = 20


settings = Settings()
