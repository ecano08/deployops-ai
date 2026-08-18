from pydantic import BaseModel, Field


class ProcessDocumentRequest(BaseModel):
    workspace_id: int
    customer_id: int
    deployment_id: int
    document_id: int
    filename: str
    mime_type: str
    content_base64: str


class ProcessDocumentResponse(BaseModel):
    chunk_count: int


class DeleteDocumentRequest(BaseModel):
    workspace_id: int
    customer_id: int
    deployment_id: int
    document_id: int


class ListDocumentChunksRequest(BaseModel):
    workspace_id: int
    customer_id: int
    deployment_id: int
    document_id: int


class DocumentChunk(BaseModel):
    chunk_index: int
    source_filename: str
    content: str


class ListDocumentChunksResponse(BaseModel):
    chunks: list[DocumentChunk]


class SearchRequest(BaseModel):
    workspace_id: int
    customer_id: int
    deployment_id: int
    query: str = Field(min_length=1)
    top_k: int | None = None
    document_ids: list[int] | None = None
    lexical_terms: list[str] | None = None


class SearchResult(BaseModel):
    document_id: int
    source_filename: str
    chunk_index: int
    content: str
    score: float


class SearchResponse(BaseModel):
    results: list[SearchResult]
