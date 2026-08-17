from contextlib import asynccontextmanager

from fastapi import FastAPI

from routes import router as rag_router
from vector_store import VectorStore


@asynccontextmanager
async def lifespan(_: FastAPI):
    VectorStore().initialize()
    yield


app = FastAPI(
    title="DeployOps AI Service",
    version="0.2.0",
    lifespan=lifespan,
)

app.include_router(rag_router)


@app.get("/health")
def health():
    return {
        "status": "ok",
        "service": "ai-service",
    }
