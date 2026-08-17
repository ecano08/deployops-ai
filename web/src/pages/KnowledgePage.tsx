import { useState } from 'react'
import { Badge } from '../components/ui/Badge'
import { statusBadgeVariant } from '../components/ui/badgeUtils'
import { Button } from '../components/ui/Button'
import { Card } from '../components/ui/Card'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'
import { EmptyState } from '../components/ui/EmptyState'
import { ErrorState } from '../components/ui/ErrorState'
import { LoadingState } from '../components/ui/LoadingState'
import { Alert } from '../components/ui/Alert'
import type { Deployment, KnowledgeDocument } from '../types'

type KnowledgePageProps = {
  deployment: Deployment | null
  documents: KnowledgeDocument[]
  loading: boolean
  error: string | null
  uploadMessage: string | null
  onUpload: (file: File) => Promise<void>
  onDelete: (documentId: number) => Promise<void>
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`
  }

  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function KnowledgePage({
  deployment,
  documents,
  loading,
  error,
  uploadMessage,
  onUpload,
  onDelete,
}: KnowledgePageProps) {
  const [deleteTarget, setDeleteTarget] = useState<KnowledgeDocument | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [uploading, setUploading] = useState(false)

  if (!deployment) {
    return (
      <EmptyState
        title="Select a deployment"
        description="Upload runbooks and customer docs to power RAG for the copilot."
      />
    )
  }

  async function handleUpload(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]

    if (!file) {
      return
    }

    setUploading(true)

    try {
      await onUpload(file)
    } finally {
      setUploading(false)
      event.target.value = ''
    }
  }

  async function confirmDelete() {
    if (!deleteTarget) {
      return
    }

    setDeleting(true)

    try {
      await onDelete(deleteTarget.id)
      setDeleteTarget(null)
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div className="page-stack">
      {uploadMessage && <Alert variant="info">{uploadMessage}</Alert>}

      <Card
        title="Knowledge documents"
        description="PDF, Markdown, and text files indexed for copilot RAG"
        actions={
          <label className="btn btn--primary btn--sm upload-button">
            {uploading ? 'Uploading…' : 'Upload file'}
            <input
              type="file"
              accept=".pdf,.txt,.md,text/plain,text/markdown,application/pdf"
              onChange={handleUpload}
              disabled={uploading}
              hidden
            />
          </label>
        }
      >
        {loading && <LoadingState label="Loading documents…" />}
        {error && <ErrorState message={error} />}
        {!loading && !error && documents.length === 0 && (
          <EmptyState
            title="No documents yet"
            description="Upload customer runbooks, API docs, or deployment guides."
          />
        )}
        {!loading && documents.length > 0 && (
          <ul className="data-list">
            {documents.map((document) => (
              <li key={document.id} className="data-list__item data-list__item--stacked">
                <div className="data-list__primary">
                  <div className="data-list__title-row">
                    <strong>{document.original_filename}</strong>
                    <Badge variant={statusBadgeVariant(document.status)}>{document.status}</Badge>
                  </div>
                  <span className="data-list__meta">
                    {formatBytes(document.size_bytes)} · {document.chunk_count} chunks ·{' '}
                    {document.mime_type}
                  </span>
                  {document.error_message && (
                    <span className="data-list__meta data-list__meta--error">
                      {document.error_message}
                    </span>
                  )}
                </div>
                <Button variant="danger" size="sm" onClick={() => setDeleteTarget(document)}>
                  Delete
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <ConfirmDialog
        open={deleteTarget !== null}
        title="Delete document?"
        description={`This will remove "${deleteTarget?.original_filename}" and its vector index. This cannot be undone.`}
        confirmLabel="Delete"
        loading={deleting}
        onConfirm={confirmDelete}
        onCancel={() => setDeleteTarget(null)}
      />
    </div>
  )
}
