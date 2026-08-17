from fastapi import APIRouter, Depends, HTTPException

from auth import verify_internal_token
from chunker import chunk_text
from config import settings
from document_parser import extract_text_from_base64
from embeddings import EmbeddingClient
from schemas import (
    DeleteDocumentRequest,
    ProcessDocumentRequest,
    ProcessDocumentResponse,
    SearchRequest,
    SearchResponse,
    SearchResult,
)
from vector_store import VectorStore

router = APIRouter(dependencies=[Depends(verify_internal_token)])
vector_store = VectorStore()
embedding_client = EmbeddingClient()


@router.post("/documents/process", response_model=ProcessDocumentResponse)
def process_document(request: ProcessDocumentRequest) -> ProcessDocumentResponse:
    try:
        text = extract_text_from_base64(
            request.filename,
            request.mime_type,
            request.content_base64,
        )
    except ValueError as exception:
        raise HTTPException(status_code=422, detail=str(exception)) from exception

    chunks = chunk_text(text)

    if chunks == []:
        vector_store.delete_document(
            workspace_id=request.workspace_id,
            customer_id=request.customer_id,
            deployment_id=request.deployment_id,
            document_id=request.document_id,
        )

        return ProcessDocumentResponse(chunk_count=0)

    embeddings = embedding_client.embed_texts(chunks)
    chunk_count = vector_store.replace_document_chunks(
        workspace_id=request.workspace_id,
        customer_id=request.customer_id,
        deployment_id=request.deployment_id,
        document_id=request.document_id,
        source_filename=request.filename,
        chunks=chunks,
        embeddings=embeddings,
    )

    return ProcessDocumentResponse(chunk_count=chunk_count)


@router.post("/documents/delete")
def delete_document(request: DeleteDocumentRequest) -> dict[str, str]:
    vector_store.delete_document(
        workspace_id=request.workspace_id,
        customer_id=request.customer_id,
        deployment_id=request.deployment_id,
        document_id=request.document_id,
    )

    return {"status": "deleted"}


@router.post("/search", response_model=SearchResponse)
def search(request: SearchRequest) -> SearchResponse:
    top_k = request.top_k or settings.default_top_k
    top_k = max(1, min(top_k, settings.max_top_k))

    query_embedding = embedding_client.embed_query(request.query.strip())
    results = vector_store.search(
        workspace_id=request.workspace_id,
        customer_id=request.customer_id,
        deployment_id=request.deployment_id,
        query_embedding=query_embedding,
        top_k=top_k,
    )

    return SearchResponse(results=[SearchResult(**result) for result in results])
