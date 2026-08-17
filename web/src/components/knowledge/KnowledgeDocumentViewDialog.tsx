import { useEffect, useId, useRef, useState } from 'react'
import { Eye } from 'lucide-react'
import { fetchKnowledgeDocument, fetchKnowledgeDocumentContent } from '../../api'
import { renderMarkdown } from '../../lib/renderMarkdown'
import { lifecycleBadgeVariant, statusBadgeVariant } from '../ui/badgeUtils'
import { Alert } from '../ui/Alert'
import { Badge } from '../ui/Badge'
import { Button } from '../ui/Button'
import { ErrorState } from '../ui/ErrorState'
import { Icon } from '../ui/Icon'
import { LoadingState } from '../ui/LoadingState'
import type {
  Customer,
  Deployment,
  KnowledgeDocument,
  KnowledgeDocumentPreviewFormat,
  KnowledgeDocumentVersionSummary,
  Workspace,
} from '../../types'
import { KNOWLEDGE_DOCUMENT_TYPES } from '../../types'

type KnowledgeDocumentViewDialogProps = {
  open: boolean
  workspace: Workspace
  customer: Customer
  deployment: Deployment
  documentId: number | null
  onClose: () => void
  onSelectRevision?: (documentId: number) => void
}

function documentTypeLabel(type: string): string {
  return KNOWLEDGE_DOCUMENT_TYPES.find((entry) => entry.value === type)?.label ?? type
}

function formatEffectiveDate(value: string | null | undefined): string {
  if (!value) {
    return '—'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
  }).format(date)
}

function previewStateMessage(document: KnowledgeDocument): string {
  switch (document.status) {
    case 'pending':
      return 'This revision is queued for processing. Preview will be available once indexing completes.'
    case 'processing':
      return 'This revision is still processing. Preview will be available once indexing completes.'
    case 'failed':
      return document.error_message ?? 'Processing failed, so this revision cannot be previewed.'
    default:
      return 'This revision is not ready for preview yet.'
  }
}

function PreviewUnavailable({
  message,
  detail,
}: {
  message: string
  detail?: string | null
}) {
  return (
    <div className="document-preview__unavailable">
      <Alert variant="info">{message}</Alert>
      {detail && <p className="document-preview__detail">{detail}</p>}
    </div>
  )
}

function DocumentPreview({
  workspaceId,
  customerId,
  deploymentId,
  document,
}: {
  workspaceId: number
  customerId: number
  deploymentId: number
  document: KnowledgeDocument
}) {
  const previewFormat = document.preview_format as KnowledgeDocumentPreviewFormat | null | undefined
  const needsFetch = document.status === 'ready' && previewFormat != null
  const [loading, setLoading] = useState(needsFetch)
  const [error, setError] = useState<string | null>(null)
  const [previewDetail, setPreviewDetail] = useState<string | null>(null)
  const [textContent, setTextContent] = useState<string | null>(null)
  const [pdfUrl, setPdfUrl] = useState<string | null>(null)
  const objectUrlRef = useRef<string | null>(null)

  useEffect(() => {
    if (!needsFetch) {
      return
    }

    let cancelled = false

    async function loadPreview() {
      try {
        const blob = await fetchKnowledgeDocumentContent(
          workspaceId,
          customerId,
          deploymentId,
          document.id,
        )

        if (cancelled) {
          return
        }

        if (previewFormat === 'pdf') {
          const objectUrl = URL.createObjectURL(blob)
          objectUrlRef.current = objectUrl
          setPdfUrl(objectUrl)
        } else {
          setTextContent(await blob.text())
        }
      } catch (previewError) {
        if (cancelled) {
          return
        }

        const message =
          previewError instanceof Error ? previewError.message : 'Failed to load document preview.'
        const detail =
          previewError instanceof Error
            ? (previewError as Error & { errorMessage?: string | null }).errorMessage ?? null
            : null

        setError(message)
        setPreviewDetail(detail)
      } finally {
        if (!cancelled) {
          setLoading(false)
        }
      }
    }

    void loadPreview()

    return () => {
      cancelled = true

      if (objectUrlRef.current) {
        URL.revokeObjectURL(objectUrlRef.current)
        objectUrlRef.current = null
      }
    }
  }, [workspaceId, customerId, deploymentId, document.id, needsFetch, previewFormat])

  if (document.status !== 'ready') {
    return (
      <div className="document-preview__content-shell document-preview__content-shell--state">
        <PreviewUnavailable message={previewStateMessage(document)} />
      </div>
    )
  }

  if (!previewFormat) {
    return (
      <div className="document-preview__content-shell document-preview__content-shell--state">
        <PreviewUnavailable
          message="Preview is not supported for this file type."
          detail={document.original_filename}
        />
      </div>
    )
  }

  if (loading) {
    return (
      <div className="document-preview__content-shell document-preview__content-shell--state">
        <LoadingState label="Loading preview…" />
      </div>
    )
  }

  if (error) {
    return (
      <div className="document-preview__content-shell document-preview__content-shell--state">
        <PreviewUnavailable message={error} detail={previewDetail} />
      </div>
    )
  }

  if (previewFormat === 'pdf' && pdfUrl) {
    return (
      <div className="document-preview__pdf-shell">
        <iframe
          className="document-preview__pdf"
          src={`${pdfUrl}#view=FitH`}
          title={`Preview of ${document.title}`}
        />
      </div>
    )
  }

  if (previewFormat === 'markdown' && textContent !== null) {
    return (
      <div className="document-preview__content-shell">
        <article
          className="document-preview__markdown"
          dangerouslySetInnerHTML={{ __html: renderMarkdown(textContent) }}
        />
      </div>
    )
  }

  if (textContent !== null) {
    return (
      <div className="document-preview__content-shell">
        <pre className="document-preview__text">{textContent}</pre>
      </div>
    )
  }

  return (
    <div className="document-preview__content-shell document-preview__content-shell--state">
      <PreviewUnavailable message="Preview is unavailable for this revision." />
    </div>
  )
}

function VersionHistoryList({
  versions,
  activeDocumentId,
  onSelectRevision,
}: {
  versions: KnowledgeDocumentVersionSummary[]
  activeDocumentId: number
  onSelectRevision?: (documentId: number) => void
}) {
  return (
    <ul className="document-version-history">
      {versions.map((version) => {
        const isActive = version.id === activeDocumentId

        return (
          <li key={version.id}>
            <button
              type="button"
              className={`document-version-history__item${isActive ? ' document-version-history__item--active' : ''}`}
              onClick={() => onSelectRevision?.(version.id)}
              disabled={isActive}
            >
              <span className="document-version-history__title">
                Revision {version.revision_number}
                {version.version_label ? ` · ${version.version_label}` : ''}
              </span>
              <span className="document-version-history__meta">
                <Badge variant={lifecycleBadgeVariant(version.lifecycle_status)}>
                  {version.lifecycle_status}
                </Badge>
                <Badge variant={statusBadgeVariant(version.status)}>{version.status}</Badge>
              </span>
            </button>
          </li>
        )
      })}
    </ul>
  )
}

function KnowledgeDocumentViewBody({
  workspace,
  customer,
  deployment,
  documentId,
  onSelectRevision,
}: {
  workspace: Workspace
  customer: Customer
  deployment: Deployment
  documentId: number
  onSelectRevision?: (documentId: number) => void
}) {
  const [document, setDocument] = useState<KnowledgeDocument | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    fetchKnowledgeDocument(workspace.id, customer.id, deployment.id, documentId)
      .then((response) => {
        if (!cancelled) {
          setDocument(response.data)
        }
      })
      .catch((fetchError) => {
        if (!cancelled) {
          setDocument(null)
          setError(fetchError instanceof Error ? fetchError.message : 'Failed to load document.')
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [documentId, workspace.id, customer.id, deployment.id])

  if (loading) {
    return <LoadingState label="Loading document…" />
  }

  if (error) {
    return <ErrorState message={error} />
  }

  if (!document) {
    return <ErrorState message="Failed to load document." />
  }

  return (
    <div className="document-view">
      <section className="document-view__preview" aria-label="Document preview">
        <div className="document-view__preview-header">
          <Icon icon={Eye} size="sm" />
          <h3 className="document-view__section-title">Preview</h3>
        </div>
        <div className="document-view__preview-pane">
          <DocumentPreview
            key={document.id}
            workspaceId={workspace.id}
            customerId={customer.id}
            deploymentId={deployment.id}
            document={document}
          />
        </div>
      </section>

      <aside className="document-view__sidebar" aria-label="Document details">
        <div className="document-view__heading">
          <h3 className="document-view__title">{document.title}</h3>
          <p className="document-view__subtitle">
            Revision {document.revision_number}
            {document.version_label ? ` · ${document.version_label}` : ''}
          </p>
        </div>

        <dl className="document-meta-grid document-meta-grid--sidebar">
          <div>
            <dt>Type</dt>
            <dd>{documentTypeLabel(document.document_type)}</dd>
          </div>
          <div>
            <dt>Revision</dt>
            <dd>{document.revision_number}</dd>
          </div>
          <div>
            <dt>Lifecycle</dt>
            <dd>
              <Badge variant={lifecycleBadgeVariant(document.lifecycle_status)}>
                {document.lifecycle_status}
              </Badge>
            </dd>
          </div>
          <div>
            <dt>Processing</dt>
            <dd>
              <Badge variant={statusBadgeVariant(document.status)}>{document.status}</Badge>
            </dd>
          </div>
          <div>
            <dt>Effective date</dt>
            <dd>{formatEffectiveDate(document.effective_at)}</dd>
          </div>
          <div>
            <dt>File</dt>
            <dd className="document-meta-grid__filename">{document.original_filename}</dd>
          </div>
          <div>
            <dt>Chunks</dt>
            <dd>{document.chunk_count.toLocaleString()}</dd>
          </div>
        </dl>

        {document.supersedes && (
          <p className="document-view__relationship">
            Replaces revision {document.supersedes.revision_number}
            {document.supersedes.title ? ` of ${document.supersedes.title}` : ''}
          </p>
        )}

        {document.version_history && document.version_history.length > 0 && (
          <div className="document-view__history">
            <h3 className="document-view__section-title">Version history</h3>
            <VersionHistoryList
              versions={document.version_history}
              activeDocumentId={document.id}
              onSelectRevision={onSelectRevision}
            />
          </div>
        )}
      </aside>
    </div>
  )
}

export function KnowledgeDocumentViewDialog({
  open,
  workspace,
  customer,
  deployment,
  documentId,
  onClose,
  onSelectRevision,
}: KnowledgeDocumentViewDialogProps) {
  const titleId = useId()
  const closeRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!open) {
      return
    }

    closeRef.current?.focus()

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        onClose()
      }
    }

    window.document.addEventListener('keydown', onKeyDown)

    return () => window.document.removeEventListener('keydown', onKeyDown)
  }, [open, onClose])

  if (!open || documentId === null) {
    return null
  }

  return (
    <div className="dialog-backdrop" role="presentation" onClick={onClose}>
      <div
        className="dialog dialog--viewer"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="dialog__header dialog__header--split dialog__header--viewer">
          <h2 id={titleId} className="dialog__title dialog__title--viewer">
            Document preview
          </h2>
          <Button ref={closeRef} type="button" variant="ghost" size="sm" onClick={onClose}>
            Close
          </Button>
        </div>

        <div className="dialog__body dialog__body--viewer">
          <KnowledgeDocumentViewBody
            key={documentId}
            workspace={workspace}
            customer={customer}
            deployment={deployment}
            documentId={documentId}
            onSelectRevision={onSelectRevision}
          />
        </div>
      </div>
    </div>
  )
}
